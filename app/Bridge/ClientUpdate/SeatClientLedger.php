<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Models\SeatClientEvent;
use App\Models\SeatClientState;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fleet ledger's ONE writer (card#10567 B4, DL owning it: see CLAUDE_DECISIONS.md "fleet
 * ledger"). Three things write a seat's row, each through one method here:
 *
 *   {@see recordCall()}   every board-tools call, from {@see BoardToolDispatcher}: what client the
 *                         caller runs and which launch it belongs to.
 *   {@see report()}       the updater's `client_report`: its install-log lines, chain-checked.
 *   {@see approve()}      `bridge:client-approve`: an approval of one pack's CONTENT for one agent.
 *
 * {@see ClientFleet} is the reader that turns a row into a state; nothing here derives one.
 */
final class SeatClientLedger
{
    /** The `install_id` of an event the bridge itself appends (an approval, a re-bootstrap). */
    public const BRIDGE_INSTALL_ID = 'bridge';

    /** At most this many lines per `client_report`; a seat with more sends several reports. */
    public const MAX_REPORT_ENTRIES = 200;

    private const INSTALL_ID = '/\A[0-9A-Za-z-]{1,64}\z/';

    /**
     * Record what one board-tools call said about its caller.
     *
     * ⛔ AN EXEMPT CALL (a probe, a self-certification, an operator) STAMPS `last_exempt_*` AND
     * NOTHING ELSE. It never writes `last_call_*` or `running_*` — a probe sends no version and no
     * launch, and letting it overwrite a seat's report with nulls is the defect design review r2
     * M-3 found. A seat's call writes `last_call_*`; only a call carrying a `launch` object writes
     * `running_*`.
     *
     * ⚑ BEST-EFFORT, like every ledger the dispatcher writes: the call is the job, this is an
     * observation about it, and a failed write is logged and costs the caller nothing.
     */
    public static function recordCall(string $agent, ?string $clientVersion, CallerReport $caller): void
    {
        try {
            self::withRow($agent, static function (SeatClientState $row) use ($clientVersion, $caller): void {
                $now = Carbon::now();
                if ($caller->caller !== null) {
                    $row->last_exempt_call_at = $now;
                    $row->last_exempt_caller = $caller->caller->value;

                    return;
                }
                $row->last_call_at = $now;
                $row->last_call_client_version = $clientVersion;
                $row->last_call_launch_id = $caller->launchId;
                if ($caller->launchId !== null) {
                    if ($row->running_launch_id !== $caller->launchId) {
                        $row->running_launch_id = $caller->launchId;
                        $row->running_launch_first_seen_at = $now;
                    }
                    $row->running_bridge_release = $caller->launchBridgeRelease;
                    $row->running_client_version = $clientVersion;
                    $row->running_seen_at = $now;
                }
            });
        } catch (Throwable $e) {
            Log::warning('agent-tools: the fleet ledger could not record this call — bridge:client-fleet shows the seat as of its last recorded call', [
                'agent' => $agent, 'error' => RedactedErrorText::of($e),
            ]);
        }
    }

    /**
     * Store one `client_report`: the seat's install-log lines after the head this bridge holds.
     *
     * THE CHAIN. Each line names the sha256 of the line before it. The first new line must chain to
     * the stored head (or be seq 1 of an install this bridge has not seen), and each later line to
     * the one before it. A line that does not — a gap, a broken link, a seq re-sent with different
     * bytes — is STILL STORED, and the seat's row is marked `log_discontinuity` with the first
     * such reason: the log is evidence, and losing the part that disagrees would hide the thing
     * worth seeing. It stays marked until the seat re-bootstraps (a new install id starts a new
     * chain, logged as a `rebootstrap` event).
     *
     * A line re-sent with the SAME bytes (the seat retried after a lost answer) is skipped.
     *
     * @param  mixed  $installId  the report's `install_id`, as sent
     * @param  mixed  $entries  the report's `entries`, as sent
     * @return array{log_head: array{install_id: string, seq: int, sha256: string}|null, stored: int, discontinuity: bool}
     *
     * @throws InstallLogRefused the report is malformed; nothing was stored
     */
    public static function report(string $agent, mixed $installId, mixed $entries): array
    {
        if (! is_string($installId) || preg_match(self::INSTALL_ID, $installId) !== 1 || $installId === self::BRIDGE_INSTALL_ID) {
            throw new InstallLogRefused('client_report needs `install_id`, the seat\'s install id ([0-9A-Za-z-], at most 64 characters)');
        }
        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new InstallLogRefused('client_report needs `entries`, a non-empty list of install-log lines');
        }
        if (count($entries) > self::MAX_REPORT_ENTRIES) {
            throw new InstallLogRefused('client_report carries more than '.self::MAX_REPORT_ENTRIES.' entries — send the rest in a later report');
        }
        $parsed = [];
        foreach ($entries as $i => $line) {
            try {
                $entry = InstallLogEntry::parse($line, $installId);
            } catch (InstallLogRefused $e) {
                throw new InstallLogRefused("client_report entry {$i} ".$e->getMessage().'; nothing was stored', 0, $e);
            }
            if ($parsed !== [] && $entry->seq <= $parsed[count($parsed) - 1]->seq) {
                throw new InstallLogRefused("client_report entry {$i} has seq {$entry->seq}, not above the entry before it — entries go oldest first; nothing was stored");
            }
            $parsed[] = $entry;
        }

        return DB::transaction(static function () use ($agent, $installId, $parsed): array {
            $row = SeatClientState::query()->where('agent', $agent)->lockForUpdate()->first() ?? new SeatClientState(['agent' => $agent]);
            $now = Carbon::now();

            if ($row->install_id !== null && $row->install_id !== $installId) {
                self::appendBridgeEvent($agent, 'rebootstrap', [
                    'reason' => "the seat reported a new install id {$installId}; the log of install {$row->install_id} ends at seq ".($row->log_seq ?? 0),
                ]);
                // An install id this bridge already holds lines of (a restored root) resumes at its
                // own stored head, so its re-sent lines are recognised rather than re-inserted.
                $held = SeatClientEvent::query()->where('agent', $agent)->where('install_id', $installId)->orderByDesc('seq')->first();
                $row->log_seq = $held?->seq;
                $row->log_head_sha256 = $held?->line_sha256;
                $row->log_discontinuity = false;
                $row->log_discontinuity_reason = null;
            }
            $row->install_id = $installId;

            $expectedSeq = ($row->log_seq ?? 0) + 1;
            $expectedPrev = $row->log_head_sha256;
            $stored = 0;
            foreach ($parsed as $entry) {
                if ($entry->seq < $expectedSeq) {
                    $held = SeatClientEvent::query()->where('agent', $agent)->where('install_id', $installId)->where('seq', $entry->seq)->value('line_sha256');
                    if ($held !== $entry->lineSha256) {
                        self::discontinuity($row, "seq {$entry->seq} of install {$installId} arrived again with different content than the bridge already holds");
                    }

                    continue;
                }
                if ($entry->seq !== $expectedSeq) {
                    self::discontinuity($row, $expectedSeq === 1
                        ? "the first entry of install {$installId} this bridge received is seq {$entry->seq}, so seq 1–".($entry->seq - 1).' never arrived'
                        : "seq {$expectedSeq}–".($entry->seq - 1)." of install {$installId} never arrived");
                } elseif ($entry->prevSha256 !== $expectedPrev) {
                    self::discontinuity($row, "seq {$entry->seq} of install {$installId} does not chain to the entry before it (its prev_sha256 is not that line's sha256)");
                }
                self::storeEntry($agent, $entry, $now);
                self::applyEntry($row, $entry, $now);
                $stored++;
                $expectedSeq = $entry->seq + 1;
                $expectedPrev = $entry->lineSha256;
                $row->log_seq = $entry->seq;
                $row->log_head_sha256 = $entry->lineSha256;
            }
            $row->last_report_at = $now;
            $row->save();

            return ['log_head' => self::headOf($row), 'stored' => $stored, 'discontinuity' => $row->log_discontinuity];
        });
    }

    /**
     * The install-log head this bridge holds for `$agent`: what the seat's next report starts after.
     *
     * @return array{install_id: string, seq: int, sha256: string}|null
     */
    public static function logHead(string $agent): ?array
    {
        $row = SeatClientState::query()->where('agent', $agent)->first();

        return $row === null ? null : self::headOf($row);
    }

    /**
     * Approve the currently published pack's CONTENT for one agent (operator ruling, card#10567
     * comment 6774: approval is keyed on the pack's `files_json_sha256`, so a later release whose
     * client bytes are unchanged owes none). Appends one `approve` event; an approval that already
     * exists for this agent and digest appends nothing and is returned as it stands.
     *
     * @return array{event: SeatClientEvent, new: bool}
     */
    public static function approve(string $agent, PublishedClientPack $published, string $actor, string $reason): array
    {
        return DB::transaction(static function () use ($agent, $published, $actor, $reason): array {
            $held = SeatClientEvent::query()
                ->where('agent', $agent)->where('install_id', self::BRIDGE_INSTALL_ID)->where('action', 'approve')
                ->where('files_json_sha256', $published->filesJsonSha256)
                ->orderBy('seq')->first();
            if ($held !== null) {
                return ['event' => $held, 'new' => false];
            }

            return ['event' => self::appendBridgeEvent($agent, 'approve', [
                'to_bridge_release' => $published->bridgeRelease,
                'client_version' => $published->clientVersion,
                'pack_sha256' => $published->packSha256,
                'files_json_sha256' => $published->filesJsonSha256,
                'actor' => $actor,
                'result' => 'ok',
                'reason' => $reason,
            ]), 'new' => true];
        });
    }

    /**
     * Every approved content digest, per agent.
     *
     * @return array<string, list<string>> agent => approved `files_json_sha256` values
     */
    public static function approvals(): array
    {
        $out = [];
        $rows = SeatClientEvent::query()->where('install_id', self::BRIDGE_INSTALL_ID)->where('action', 'approve')->whereNotNull('files_json_sha256')->get(['agent', 'files_json_sha256']);
        foreach ($rows as $row) {
            $out[$row->agent][] = (string) $row->files_json_sha256;
        }

        return $out;
    }

    public static function isApproved(string $agent, string $filesJsonSha256): bool
    {
        return SeatClientEvent::query()
            ->where('agent', $agent)->where('install_id', self::BRIDGE_INSTALL_ID)->where('action', 'approve')
            ->where('files_json_sha256', $filesJsonSha256)->exists();
    }

    /**
     * The content digest each agent's own install log recorded per release — the newest successful
     * bootstrap/install of each.
     *
     * @return array<string, array<string, string>> agent => [bridge_release => files_json_sha256]
     */
    public static function installedDigests(): array
    {
        $out = [];
        $rows = SeatClientEvent::query()
            ->where('install_id', '!=', self::BRIDGE_INSTALL_ID)
            ->whereIn('action', ['bootstrap', 'install'])->where('result', 'ok')
            ->whereNotNull('to_bridge_release')->whereNotNull('files_json_sha256')
            ->orderBy('id')->get(['agent', 'to_bridge_release', 'files_json_sha256']);
        foreach ($rows as $row) {
            $out[$row->agent][(string) $row->to_bridge_release] = (string) $row->files_json_sha256;
        }

        return $out;
    }

    /**
     * @return array{install_id: string, seq: int, sha256: string}|null
     */
    private static function headOf(SeatClientState $row): ?array
    {
        if ($row->install_id === null || $row->log_seq === null || $row->log_head_sha256 === null) {
            return null;
        }

        return ['install_id' => $row->install_id, 'seq' => $row->log_seq, 'sha256' => $row->log_head_sha256];
    }

    /** The first reason sticks: later breaks in the same chain are consequences of it more often than not. */
    private static function discontinuity(SeatClientState $row, string $reason): void
    {
        if ($row->log_discontinuity) {
            return;
        }
        $row->log_discontinuity = true;
        $row->log_discontinuity_reason = $reason;
    }

    private static function storeEntry(string $agent, InstallLogEntry $entry, Carbon $now): void
    {
        SeatClientEvent::query()->insert([
            'agent' => $agent,
            'install_id' => $entry->installId,
            'seq' => $entry->seq,
            'action' => $entry->action,
            'from_bridge_release' => $entry->fromBridgeRelease,
            'to_bridge_release' => $entry->toBridgeRelease,
            'client_version' => $entry->clientVersion,
            'pack_sha256' => $entry->packSha256,
            'files_json_sha256' => $entry->filesJsonSha256,
            'source' => $entry->source,
            'actor' => $entry->actor,
            'result' => $entry->result,
            'reason' => $entry->reason,
            'launch_id' => $entry->launchId,
            'prev_sha256' => $entry->prevSha256,
            'line_sha256' => $entry->lineSha256,
            'occurred_at' => $entry->time,
            'received_at' => $now,
        ]);
    }

    /**
     * What one stored line changes on the seat's row.
     *
     * A launch's OUTCOME is `failed` if any of its lines failed or refused, `ok` otherwise. Lines
     * are grouped by `launch_id`; a line with none stands for itself.
     */
    private static function applyEntry(SeatClientState $row, InstallLogEntry $entry, Carbon $now): void
    {
        if (in_array($entry->action, ['bootstrap', 'install'], true) && $entry->result === 'ok' && $entry->toBridgeRelease !== null) {
            $row->installed_bridge_release = $entry->toBridgeRelease;
            $row->installed_client_version = $entry->clientVersion;
            $row->installed_files_json_sha256 = $entry->filesJsonSha256;
        }
        if ($entry->actor !== 'launch') {
            return;
        }
        $failed = in_array($entry->result, ['failed', 'refused'], true);
        if ($entry->launchId === null || $entry->launchId !== $row->last_launch_id) {
            $row->last_launch_id = $entry->launchId;
            $row->last_launch_first_reported_at = $now;
            $row->last_launch_result = $failed ? 'failed' : 'ok';
            $row->last_launch_error = $failed ? ($entry->reason ?? $entry->action) : null;

            return;
        }
        if ($failed && $row->last_launch_result !== 'failed') {
            $row->last_launch_result = 'failed';
            $row->last_launch_error = $entry->reason ?? $entry->action;
        }
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function appendBridgeEvent(string $agent, string $action, array $fields): SeatClientEvent
    {
        $seq = (int) SeatClientEvent::query()->where('agent', $agent)->where('install_id', self::BRIDGE_INSTALL_ID)->max('seq') + 1;
        $event = new SeatClientEvent(['agent' => $agent, 'install_id' => self::BRIDGE_INSTALL_ID, 'seq' => $seq, 'action' => $action, 'received_at' => Carbon::now()] + $fields);
        $event->save();

        return $event;
    }

    /**
     * Read, change and save one agent's row. Two doors can serve one agent at once, so a first
     * write can lose the insert race; the loser re-reads and applies its change to the winner's row.
     *
     * @param  callable(SeatClientState): void  $change
     */
    private static function withRow(string $agent, callable $change): void
    {
        for ($attempt = 1; ; $attempt++) {
            $row = SeatClientState::query()->where('agent', $agent)->first() ?? new SeatClientState(['agent' => $agent]);
            $change($row);
            try {
                $row->save();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }
}
