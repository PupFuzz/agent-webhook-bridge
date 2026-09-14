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
 * ⛔ BRIDGE VOCABULARY ONLY: verdict codes, bucket names, counts, local agent names and the
 * {@see IdleNudgeUnmeasured} reason. No snapshot value.
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
     * @param  list<string>  $failedAgents  agents whose push in THIS pass threw
     */
    public static function measured(Evaluation $evaluation, int $accepted, array $failedAgents): void
    {
        $agents = [];
        foreach ($evaluation->verdicts as $v) {
            $agents[$v->agent] = $v->code;
        }

        self::write([
            'measured' => true,
            'seats' => $evaluation->seatTally,
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
