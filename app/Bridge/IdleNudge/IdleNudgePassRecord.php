<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;

/**
 * What the LAST idle nudge pass concluded, structured, for the `bridge:check` leg (card#9422).
 *
 * ⚑ ITS OWN FILE, NOT A MEMBER OF THE DEDUPE STATE. It is written on every pass — including one
 * that could not parse the dedupe file — and a pass that cannot parse that file must never
 * write it. The job row's `last_summary` carries the same facts as PROSE; a check never parses
 * prose, so the structure lives here and the row stays the record of WHEN and WHETHER.
 *
 * ⛔ BRIDGE VOCABULARY ONLY: verdict codes, bucket names, counts, local agent names, the
 * {@see IdleNudgeUnmeasured} reason, and each seat-record agent's path as the pass resolved it
 * and the name it compared the record's `agent` against — both from the operator's own YAML
 * values. No snapshot value, and nothing a seat record holds.
 */
final class IdleNudgePassRecord
{
    public const FILE = 'idle-nudge-pass.json';

    public static function path(): string
    {
        return BridgePaths::stateDir().'/'.self::FILE;
    }

    public static function unmeasured(string $reason): void
    {
        self::write(['measured' => false, 'reason' => $reason]);
    }

    /**
     * `seats` is null when no fleet snapshot was read; `fleet_unmeasured` is the reason a read
     * that WAS needed did not measure (the Mezzanine-sourced agents then read `fleet_unmeasured`),
     * and null otherwise.
     *
     * ⚑ `seat_records` IS THE PATH THIS PASS READ, resolved in the TICK's process — the one
     * `bridge:check` must print, since it may run as another OS user with another home. Null for
     * an agent whose `~` could not be resolved (`seat_record_home_unresolved`), or which was not
     * read because another agent claims the same seat (`seat_record_seat_claimed_twice`).
     *
     * ⚑ `record_agents` IS THE NAME THIS PASS COMPARED EACH RECORD'S `agent` AGAINST
     * ({@see IdleNudgeSources::recordAgentOf()}), so a verdict printed after the YAML changed
     * still names the value that produced it.
     *
     * @param  list<string>  $failedAgents  agents whose push in THIS pass threw
     * @param  array<string, ?string>  $seatRecords  seat-record agent => the path the pass read
     * @param  array<string, string>  $recordAgents  seat-record agent => the name its record had to carry
     */
    public static function measured(Evaluation $evaluation, int $accepted, array $failedAgents, ?string $fleetUnmeasured = null, array $seatRecords = [], array $recordAgents = []): void
    {
        $agents = [];
        foreach ($evaluation->verdicts as $v) {
            $agents[$v->agent] = $v->code;
        }

        self::write([
            'measured' => true,
            'seats' => $evaluation->seatTally,
            'fleet_unmeasured' => $fleetUnmeasured,
            'seat_records' => (object) $seatRecords,
            'record_agents' => (object) $recordAgents,
            'verdicts' => $evaluation->verdictTally(),
            'agents' => (object) $agents,
            'pushes' => ['accepted_by_transport' => $accepted, 'failed' => count($failedAgents)],
            'failed_agents' => $failedAgents,
        ]);
    }

    /**
     * @return array<string, mixed>|null null when no pass has been recorded
     *
     * @throws UnreadableFileException
     */
    public static function read(): ?array
    {
        $raw = FileContents::read(self::path(), 'idle nudge pass record');
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && is_bool($decoded['measured'] ?? null) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $record */
    private static function write(array $record): void
    {
        BridgePaths::ensureDir(BridgePaths::stateDir());
        BridgePaths::writeFileAtomic(self::path(), (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
