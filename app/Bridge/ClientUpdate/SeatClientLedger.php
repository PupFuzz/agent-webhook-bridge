<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\ToolsCallStdio;
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

    /**
     * At most this many bytes of lines per `client_report` — the sum of the lines' own lengths.
     * DERIVED from the smallest door's body cap ({@see ToolsCallStdio::MAX_STDIN_BYTES}, the ssh
     * door) so that ANY report within both limits fits it: the lines ride as JSON strings, which
     * at most doubles them (every byte of a JSON line is either plain or a `"` / `\` that gains one
     * backslash; a client encoding non-ASCII as `\u…` must send it raw instead), plus a fixed
     * allowance for the envelope and each entry's quotes and comma. A seat splits its backlog by
     * whichever limit it reaches first — the entry count or these bytes.
     */
    public const MAX_REPORT_BYTES = (ToolsCallStdio::MAX_STDIN_BYTES - self::ENVELOPE_ALLOWANCE - 3 * self::MAX_REPORT_ENTRIES) >> 1;

    /** `{"op":"client_report","install_id":"<≤64>","entries":[…]}` with room to spare. */
    private const ENVELOPE_ALLOWANCE = 1024;

    /**
     * LOWER CASE ONLY: the database's collation compares ids case-insensitively while this code
     * compares them exactly, so `abc` and `ABC` would be one install to the store and two to the
     * row — a permanent false re-send conflict. Refusing upper case keeps the two agreeing.
     */
    private const INSTALL_ID = '/\A[0-9a-z-]{1,64}\z/';

    /**
     * Record what one board-tools call said about its caller.
     *
     * ⛔ AN EXEMPT CALL (one declaring an {@see ExemptCaller} case) STAMPS `last_exempt_*` AND
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
     * EVERY LINE IS STORED as received — the log is evidence, and dropping the part that disagrees
     * would hide the thing worth seeing — except a seq this bridge already holds: the same bytes are
     * skipped (the seat retried after a lost answer), different bytes are recorded as a bridge
     * `resend_conflict` event naming that install and seq, and the first copy is kept. A line newer
     * than the head moves the head and the row's facts; a line older than the head (a late gap fill)
     * is stored and moves neither.
     *
     * THE MARK is then one rule, {@see self::chainBreak()}: `log_discontinuity` says the CURRENT
     * install's stored log is not a whole, unaltered chain — a missing seq, a line whose
     * `prev_sha256` is not the previous line's sha256, or a recorded re-send conflict. It is a pure
     * function of what is stored, so a gap that a late line fills reads clean again, a re-send
     * conflict keeps that install marked for good, and a new install id starts clean (logged as a
     * `rebootstrap` event).
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
            throw new InstallLogRefused('client_report needs `install_id`, the seat\'s install id ([0-9a-z-], lower case, at most 64 characters)');
        }
        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new InstallLogRefused('client_report needs `entries`, a non-empty list of install-log lines');
        }
        $bytes = array_sum(array_map(static fn (mixed $line): int => is_string($line) ? strlen($line) : 0, $entries));
        if (count($entries) > self::MAX_REPORT_ENTRIES || $bytes > self::MAX_REPORT_BYTES) {
            throw new InstallLogRefused('client_report carries '.count($entries)." entries totalling {$bytes} bytes; a report holds at most ".self::MAX_REPORT_ENTRIES.' entries AND at most '.self::MAX_REPORT_BYTES.' bytes of lines — split the backlog by both and send the rest in later reports; nothing was stored');
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

            $resumed = false;
            if ($row->install_id !== null && $row->install_id !== $installId) {
                self::appendBridgeEvent($agent, 'rebootstrap', [
                    'reason' => "the seat reported a new install id {$installId}; the log of install {$row->install_id} ends at seq ".($row->log_seq ?? 0),
                ]);
                // An install id this bridge already holds lines of (a restored root) resumes at its
                // own stored head, so its re-sent lines are recognised rather than re-inserted.
                $held = SeatClientEvent::query()->where('agent', $agent)->where('install_id', $installId)->orderByDesc('seq')->first();
                $row->log_seq = $held?->seq;
                $row->log_head_sha256 = $held?->line_sha256;
                $resumed = true;
            }
            $row->install_id = $installId;

            $headSeq = $row->log_seq;
            $headSha = $row->log_head_sha256;
            $wasWhole = ! $resumed && ! $row->log_discontinuity;
            $stored = 0;
            foreach ($parsed as $entry) {
                if ($row->log_seq !== null && $entry->seq <= $row->log_seq) {
                    $held = SeatClientEvent::query()->where('agent', $agent)->where('install_id', $installId)->where('seq', $entry->seq)->value('line_sha256');
                    if ($held === null) {
                        self::storeEntry($agent, $entry, $now);
                        $stored++;
                    } elseif ($held !== $entry->lineSha256) {
                        self::recordResendConflict($agent, $installId, $entry->seq);
                    }

                    continue;
                }
                self::storeEntry($agent, $entry, $now);
                self::applyEntry($row, $entry, $now);
                $stored++;
                $row->log_seq = $entry->seq;
                $row->log_head_sha256 = $entry->lineSha256;
            }

            // The whole stored log is read only when the prefix up to the old head is not already
            // known whole (see chainBreak()'s docblock for why the bounded read is equivalent).
            $break = $wasWhole
                ? self::chainBreak($agent, $installId, $headSeq ?? 0, $headSha)
                : self::chainBreak($agent, $installId);
            $row->log_discontinuity = $break !== null;
            $row->log_discontinuity_reason = $break;
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

    /**
     * THE ONE DEFINITION of a broken install log: the first break in `$installId`'s STORED lines, or
     * null when they are a whole, unaltered chain. A break is, in order: a missing seq (including a
     * first stored seq above 1), a line whose `prev_sha256` is not the previous stored line's sha256,
     * or — the chain being otherwise whole — a `resend_conflict` event recorded for this install.
     * The reasons are worded here and in {@see self::conflictReason()} — the one wording of a
     * re-send conflict, which the recorder also calls — and nowhere else.
     *
     * COST, AND THE BOUNDED READ. A full read is every stored line of the install, under the row
     * lock the report holds. The live path reads only the lines after the old head (`$afterSeq`,
     * `$afterSha`) when the row was NOT marked before this report and the install was not just
     * resumed. That is equivalent to the full read: the mark is this function of the stored lines,
     * recomputed after every report, so an unmarked row means lines 1…head are all stored as a whole
     * chain with no conflict — so this report can add nothing at or below the head (no late line is
     * possible) and cannot change that prefix — and a conflict recorded in THIS report is found by
     * the conflict read, which is never bounded. Resuming an install or a row already marked forces
     * the full read.
     */
    private static function chainBreak(string $agent, string $installId, int $afterSeq = 0, ?string $afterSha = null): ?string
    {
        $lines = SeatClientEvent::query()->where('agent', $agent)->where('install_id', $installId)->where('seq', '>', $afterSeq)
            ->orderBy('seq')->get(['seq', 'prev_sha256', 'line_sha256']);
        $expectedSeq = $afterSeq + 1;
        $expectedPrev = $afterSha;
        foreach ($lines as $line) {
            if ($line->seq !== $expectedSeq) {
                return $expectedSeq === 1
                    ? "the first entry of install {$installId} this bridge holds is seq {$line->seq}, so seq 1–".($line->seq - 1).' never arrived'
                    : "seq {$expectedSeq}–".($line->seq - 1)." of install {$installId} never arrived";
            }
            if ($line->prev_sha256 !== $expectedPrev) {
                return "seq {$line->seq} of install {$installId} does not chain to the entry before it (its prev_sha256 is not that line's sha256)";
            }
            $expectedSeq = $line->seq + 1;
            $expectedPrev = $line->line_sha256;
        }

        $conflict = SeatClientEvent::query()->where('agent', $agent)->where('install_id', self::BRIDGE_INSTALL_ID)
            ->where('action', 'resend_conflict')->where('source', $installId)->min('subject_seq');

        return $conflict === null ? null : self::conflictReason($installId, (int) $conflict);
    }

    private static function conflictReason(string $installId, int $seq): string
    {
        return "seq {$seq} of install {$installId} arrived again with different content than the bridge already holds";
    }

    /**
     * A seq this bridge holds arrived again with different bytes: record it once per (install, seq),
     * as a bridge event whose `source` is the seat install and `subject_seq` the seq it is about, so
     * the break outlives the report — the store keeps only the first copy, and nothing else would
     * remember the second.
     */
    private static function recordResendConflict(string $agent, string $installId, int $seq): void
    {
        $held = SeatClientEvent::query()->where('agent', $agent)->where('install_id', self::BRIDGE_INSTALL_ID)
            ->where('action', 'resend_conflict')->where('source', $installId)->where('subject_seq', $seq)->exists();
        if (! $held) {
            self::appendBridgeEvent($agent, 'resend_conflict', ['source' => $installId, 'subject_seq' => $seq, 'reason' => self::conflictReason($installId, $seq)]);
        }
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
     * are grouped by `launch_id`, which every `actor: launch` line carries ({@see InstallLogEntry}).
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
        if ($entry->launchId !== $row->last_launch_id) {
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
     * @param  array<string, string|int|null>  $fields
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
