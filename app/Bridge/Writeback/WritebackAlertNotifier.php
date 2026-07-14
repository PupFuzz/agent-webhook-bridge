<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelPushTransport;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Validation\LocalhostUrl;
use App\Bridge\Validation\SocketEndpoint;
use App\Bridge\Validation\SocketPath;
use Illuminate\Support\Facades\Log;

/**
 * Emits a LOUD per-event signal to the configured `writeback.alert_channel` when
 * the writeback hits a PERMANENT move-failure (FR-4) — in ADDITION to the log
 * line the caller always writes (log = durable record; push = live wake). Opt-in
 * (no `alert_channel` ⇒ no-op), best-effort (an undeliverable alert is caught and
 * logged, never thrown — a 5xx redelivery storm of an unmovable card must not be
 * possible), and DEDUPED per `(repo, outcome, reason)` so one recurring failure
 * wakes the operator once, not per redelivery.
 *
 * It loads `writeback.json` ITSELF (not the handler's already-loaded config) so
 * it works at the handler's earliest branches — which fire BEFORE the handler
 * loads writeback, and at the "writeback not configured" branch where the file is
 * absent (⇒ null ⇒ this degrades to log-only; documented in docs/writeback.md).
 *
 * The send VALIDATION (socket/localhost-url) is shared with ChannelPushHandler
 * via {@see SocketEndpoint} / {@see LocalhostUrl}; the alert socket is OPERATOR
 * config, so it is exempt from the classifier-supplied `allowed_socket_dir`
 * prefix gate the handler applies.
 */
final class WritebackAlertNotifier
{
    public function notify(string $repo, string $outcome, ?int $cardId, string $reason): void
    {
        // Deduped per (repo, outcome, reason) so one recurring permanent move-failure
        // wakes the operator once, not per redelivery. The raw signature tuple (NOT a
        // pre-hashed key) is handed to emit → claimSignature, which hashes it exactly
        // once — byte-identical to the pre-refactor sha1 input.
        $this->emit('writeback_move_failed', $repo."\x00".$outcome."\x00".$reason, [
            'repo' => $repo,
            'outcome' => $outcome,
            'card_id' => $cardId,
            'reason' => $reason,
        ]);
    }

    /**
     * Signal that a PINNED card was auto-unparked (DL-194) from a parked stage on a
     * branch-cut `started` event — the compensating "we overrode a human hold"
     * notification. Emitted AFTER a confirmed move (the handler places it between the
     * move and the stamp) and with NO dedup: a card can be re-parked and re-unparked
     * across distinct branch-cuts, and each is a real override worth surfacing.
     * Redelivery is bounded to one alert per successful unpark by the handler's
     * idempotent already-in-stage short-circuit, not by a persistent marker here.
     */
    public function notifyUnpark(string $repo, int $cardId, ?int $fromStage): void
    {
        $this->emit('writeback_auto_unparked', null, [
            'repo' => $repo,
            'card_id' => $cardId,
            'from_stage' => $fromStage,
            'reason' => 'auto_unparked',
        ]);
    }

    /**
     * Push one alert to the configured channel. BEST-EFFORT, STRUCTURALLY: the ENTIRE
     * body is wrapped so nothing — a bad channel config, a connection refusal, an HTTP
     * error, OR an internal failure like an unwritable state dir (`mkdir` warns →
     * Laravel rethrows as ErrorException) — can ever throw out of the handler. A throw
     * here would 5xx → redelivery-storm a permanently-unmovable/unparked event, the one
     * outcome these alerts must not cause. The caller's own Log line already ran
     * regardless; only this additive push is at stake.
     *
     * $dedupKey === null ⇒ skip the O_EXCL dedup entirely and ALWAYS push (the unpark
     * path). A non-null key is the raw signature string claimSignature hashes.
     *
     * @param  array<string, mixed>  $body
     */
    private function emit(string $type, ?string $dedupKey, array $body): void
    {
        $body = ['type' => $type] + $body;
        $marker = null;
        try {
            $channel = $this->alertChannel();
            if ($channel === null) {
                return;   // opt-out / log-only — including the writeback-not-configured branch
            }
            if ($dedupKey !== null) {
                $marker = $this->claimSignature($dedupKey);
                if ($marker === null) {
                    return;   // already alerted (dedup), or couldn't dedup safely → don't risk a per-event storm
                }
            }
            $this->push($channel, $body);
        } catch (\Throwable $e) {
            // Release the dedup marker on a FAILED push so a later redelivery
            // re-attempts: claim-before-push (the dedup primitive) must not turn one
            // dropped packet into permanent silence — a channel being down is exactly
            // correlated with the trouble these alerts exist to surface. A concurrent
            // racer could then double-alert; an extra alert is strictly better than a
            // forever-suppressed one.
            if ($marker !== null) {
                @unlink($marker);
            }
            // Context is drawn from $body (present on BOTH the notify and notifyUnpark
            // paths), never from a caller's locals — the unpark path has no
            // $outcome/$reason locals to reference.
            Log::warning('writeback alert push failed', $body + ['error' => $e->getMessage()]);
        }
    }

    /** The configured alert channel, or null when writeback / alert_channel is absent. */
    private function alertChannel(): ?AlertChannel
    {
        $configDir = (string) config('bridge.config_dir');
        if ($configDir === '') {
            return null;
        }
        // A malformed writeback.json throws here; the move handler surfaces that
        // as a 5xx and bridge:check as an error — for the alert path it just means
        // no channel, so swallow it (the alert is purely additive to the log).
        try {
            $writeback = WritebackConfig::load($configDir);
        } catch (\Throwable) {
            return null;
        }

        return $writeback?->alertChannel;
    }

    /**
     * Atomically claim a dedup signature so a recurring failure alerts ONCE. An
     * O_EXCL marker file (`fopen(..., 'x')`) is the dedup primitive — exclusive-create
     * succeeds for exactly one caller. Returns the marker PATH to PROCEED (first
     * occurrence — the caller unlinks it again if the push then fails), or NULL to
     * SKIP: a pre-existing marker ⇒ already alerted; a create failure for ANY OTHER
     * reason (FS error) ⇒ we can't dedup, so skip rather than risk a per-event storm.
     * The caller's own Log line ran regardless (only the push is gated). FS warnings
     * are suppressed so they can't be rethrown as ErrorException by Laravel's handler
     * (the caller's outer catch is the structural backstop; this keeps the common path
     * clean). $dedupKey is the RAW signature string — hashed here EXACTLY once.
     */
    private function claimSignature(string $dedupKey): ?string
    {
        $signature = sha1($dedupKey);
        $dir = BridgePaths::stateDir().'/writeback-alerts';
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            Log::warning('writeback alert dedup-dir could not be created — skipping push to avoid a per-event storm', [
                'dir' => $dir, 'error' => error_get_last()['message'] ?? 'unknown',
            ]);

            return null;
        }
        $path = $dir.'/'.$signature;

        // Suppress the EEXIST warning — error_get_last distinguishes "already
        // alerted" (the file exists) from a real FS error, since both make
        // fopen('x') return false.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);

            return $path;   // first occurrence of this signature → alert
        }

        if (is_file($path)) {
            return null;   // already alerted → dedup
        }

        Log::warning('writeback alert dedup-marker could not be created — skipping push to avoid a per-event storm', [
            'path' => $path,
            'error' => error_get_last()['message'] ?? 'unknown',
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function push(AlertChannel $channel, array $body): void
    {
        $socket = is_string($channel->socket) && $channel->socket !== '' ? $channel->socket : null;
        $url = is_string($channel->url) && $channel->url !== '' ? $channel->url : null;

        if (($socket !== null) === ($url !== null)) {
            // Both set or neither set — a malformed alert_channel (bridge:check warns).
            throw new \RuntimeException('writeback alert_channel must specify exactly one of socket or url');
        }

        if ($socket !== null) {
            $this->validateSocketPath($socket);
            ChannelPushTransport::send($socket, null, 'POST', ['Content-Type' => 'application/json'], $body, 2.0);

            return;
        }

        $this->validateLocalhostUrl($url);
        $headers = ['Content-Type' => 'application/json'];
        if ($channel->tokenPath !== null) {
            try {
                $headers['Authorization'] = 'Bearer '.ChannelToken::read($channel->tokenPath);
            } catch (ChannelTokenException $e) {
                throw new \RuntimeException('writeback alert_channel token: '.$e->getMessage(), 0, $e);
            }
        }
        ChannelPushTransport::send(null, $url, 'POST', $headers, $body, 2.0);
    }

    /**
     * Format gate (absolute / no-`..`) for the alert socket, then the shared
     * filesystem checks. The alert socket is OPERATOR config (uid-agnostic
     * ${XDG_RUNTIME_DIR} expansion applies, DL-039/v0.43.3), so it gets the
     * uid-mismatch diagnostic.
     */
    private function validateSocketPath(string $path): void
    {
        if (! SocketPath::isValid($path)) {
            throw new \RuntimeException("writeback alert socket is not a valid absolute path (no '..'): {$path}");
        }
        SocketEndpoint::assertValid(
            $path,
            subject: 'writeback alert socket',
            parentSubject: 'writeback alert socket',
            configField: 'alert_channel.socket',
            diagnoseUidMismatch: true,
        );
    }

    private function validateLocalhostUrl(string $url): void
    {
        LocalhostUrl::assertValid($url, 'writeback alert_channel url');
    }
}
