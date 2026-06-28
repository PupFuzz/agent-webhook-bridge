<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Validation\SocketPath;
use Illuminate\Support\Facades\Http;
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
 * The send VALIDATION is intentionally duplicated from ChannelPushHandler (the
 * socket/localhost-url checks). A future shared extract is the clean fix; it is
 * not done here to avoid touching the security-critical ChannelPushHandler.
 */
final class WritebackAlertNotifier
{
    public function notify(string $repo, string $outcome, ?int $cardId, string $reason): void
    {
        // BEST-EFFORT, STRUCTURALLY: the ENTIRE body is wrapped so nothing — a bad
        // channel config, a connection refusal, an HTTP error, OR an internal
        // failure like an unwritable state dir (`mkdir` warns → Laravel rethrows as
        // ErrorException) — can ever throw out of the handler. A throw here would
        // 5xx → redelivery-storm a permanently-unmovable event, the one outcome
        // FR-4 must not cause. The caller's own Log::warning already ran regardless;
        // only this additive push is at stake.
        $marker = null;
        try {
            $channel = $this->alertChannel();
            if ($channel === null) {
                return;   // opt-out / log-only — including the writeback-not-configured branch
            }
            $marker = $this->claimSignature($repo, $outcome, $reason);
            if ($marker === null) {
                return;   // already alerted (dedup), or couldn't dedup safely → don't risk a per-event storm
            }
            $this->push($channel, [
                'type' => 'writeback_move_failed',
                'repo' => $repo,
                'outcome' => $outcome,
                'card_id' => $cardId,
                'reason' => $reason,
            ]);
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
            Log::warning('writeback alert push failed', [
                'repo' => $repo, 'outcome' => $outcome, 'reason' => $reason, 'error' => $e->getMessage(),
            ]);
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
     * Atomically claim the `(repo, outcome, reason)` signature so a recurring
     * failure alerts ONCE. An O_EXCL marker file (`fopen(..., 'x')`) is the dedup
     * primitive — exclusive-create succeeds for exactly one caller. Returns the
     * marker PATH to PROCEED (first occurrence — the caller unlinks it again if the
     * push then fails), or NULL to SKIP: a pre-existing marker ⇒ already alerted; a
     * create failure for ANY OTHER reason (FS error) ⇒ we can't dedup, so skip
     * rather than risk a per-event storm. The caller's own Log::warning ran
     * regardless (only the push is gated). FS warnings are suppressed so they can't
     * be rethrown as ErrorException by Laravel's handler (the caller's outer catch
     * is the structural backstop; this keeps the common path clean).
     */
    private function claimSignature(string $repo, string $outcome, string $reason): ?string
    {
        $signature = sha1($repo."\x00".$outcome."\x00".$reason);
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
            Http::connectTimeout(1)->timeout(2)
                ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]])
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('http://localhost/', $body)
                ->throw();

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
        Http::connectTimeout(1)->timeout(2)
            ->withHeaders($headers)
            ->post($url, $body)
            ->throw();
    }

    /**
     * Mirror of ChannelPushHandler's socket validation (duplicated by design —
     * see the class doc). The alert socket is OPERATOR config, so it is exempt
     * from the classifier-supplied `allowed_socket_dir` prefix gate.
     */
    private function validateSocketPath(string $path): void
    {
        if (! SocketPath::isValid($path)) {
            throw new \RuntimeException("writeback alert socket is not a valid absolute path (no '..'): {$path}");
        }
        clearstatcache(true, $path);
        if (! file_exists($path)) {
            throw new \RuntimeException("writeback alert socket does not exist (start the channel server first): {$path}");
        }
        // lstat-based: a symlink at the socket path is a TOCTOU vector.
        if (is_link($path)) {
            throw new \RuntimeException("writeback alert socket must not be a symlink: {$path}");
        }
        if (filetype($path) !== 'socket') {
            throw new \RuntimeException("writeback alert socket is not a Unix domain socket: {$path}");
        }
    }

    /** Mirror of ChannelPushHandler::validateLocalhostUrl (duplicated by design). */
    private function validateLocalhostUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http') {
            throw new \RuntimeException('writeback alert_channel url must be http:// (loopback only)');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('writeback alert_channel url must not contain a userinfo component');
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('writeback alert_channel url must point at 127.0.0.1, localhost, or [::1]');
        }
    }
}
