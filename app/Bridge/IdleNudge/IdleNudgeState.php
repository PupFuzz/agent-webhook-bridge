<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;
use JsonException;

/**
 * The idle nudge's dedupe record: for each local agent, the `idle_since` it was last nudged
 * for (card#9422 / DL-380).
 *
 * ⭐ KEYED ON THE LOCAL AGENT NAME, VALUED BY `idle_since`. The join guarantees at most one seat
 * per agent, so no snapshot identity field is ever part of a key. Mezzanine mints a fresh
 * `idle_since` on every entry into idle, so the next idle period re-arms by INEQUALITY and
 * overwrites the slot — including an idle→working→idle blip no pass observed. Nothing is ever
 * pruned on what a snapshot did or did not show: a seat missing from one pass and back with
 * the same `idle_since` is the same idle period, and pruning on its absence would nudge it
 * twice. The only tidy-up is an agent this install no longer declares.
 *
 * ⚑ THE SLOT IS WRITTEN BEFORE THE PUSH: at most one nudge per idle period. A push that throws
 * may still have landed (DL-370), so a retry could double it; the reconcile layer stays the
 * recovery.
 *
 * ⛔ A FILE THAT CANNOT BE PARSED IS NEVER WRITTEN OVER. {@see load()} throws, the pass is
 * unmeasured, and nothing is pushed — overwriting it with an empty map would re-arm every
 * agent at once.
 *
 * ⚑ ONE WRITER. Only the job writes this file, and every ingress runs the job inside the
 * scheduler's non-blocking pass lock, so no read-modify-write interleaves.
 */
final class IdleNudgeState
{
    public const FILE = 'idle-nudge.json';

    /**
     * @param  array<string, int>  $nudged  agent => idle_since, epoch ms
     */
    private function __construct(private array $nudged) {}

    public static function path(): string
    {
        return BridgePaths::stateDir().'/'.self::FILE;
    }

    /**
     * @throws IdleNudgeUnmeasured when the file is present and unreadable or malformed
     */
    public static function load(): self
    {
        try {
            $raw = FileContents::read(self::path(), 'idle nudge dedupe state');
        } catch (UnreadableFileException) {
            throw new IdleNudgeUnmeasured('the dedupe state file '.self::path().' could not be read — nothing is pushed until it can');
        }
        if ($raw === null) {
            return new self([]);
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $slots = is_array($decoded) ? ($decoded['nudged'] ?? null) : null;
        if (! is_array($slots)) {
            throw new IdleNudgeUnmeasured('the dedupe state file '.self::path().' is malformed — nothing is pushed until it is repaired or removed');
        }

        $nudged = [];
        foreach ($slots as $agent => $slot) {
            $ms = is_array($slot) ? FleetSnapshot::instantMs($slot['idle_since'] ?? null) : null;
            if (! is_string($agent) || $ms === null) {
                throw new IdleNudgeUnmeasured('the dedupe state file '.self::path().' is malformed — nothing is pushed until it is repaired or removed');
            }
            $nudged[$agent] = $ms;
        }

        return new self($nudged);
    }

    /** @return array<string, int> */
    public function nudged(): array
    {
        return $this->nudged;
    }

    /** Record the slot for this idle period and persist it — call BEFORE the push. */
    public function markAndSave(string $agent, int $idleSinceMs): void
    {
        $this->nudged[$agent] = $idleSinceMs;
        $this->save();
    }

    /**
     * Drop slots for agents this install no longer declares; persists only when one went.
     *
     * @param  list<string>  $declared
     */
    public function forgetUndeclared(array $declared): void
    {
        $kept = array_intersect_key($this->nudged, array_flip($declared));
        if (count($kept) !== count($this->nudged)) {
            $this->nudged = $kept;
            $this->save();
        }
    }

    private function save(): void
    {
        ksort($this->nudged);
        $slots = [];
        foreach ($this->nudged as $agent => $ms) {
            $slots[$agent] = ['idle_since' => FleetSnapshot::canonicalInstant($ms)];
        }

        BridgePaths::ensureDir(BridgePaths::stateDir());
        BridgePaths::writeFileAtomic(self::path(), (string) json_encode(['nudged' => (object) $slots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
