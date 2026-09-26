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
 *
 * ⭐ A SEAT-RECORD SLOT ALSO CARRIES `session_id` AND `nudged_at` (rt#562). `idle_since` holds
 * the offer's `turn_ended_at`, `session_id` completes the `(agent, session_id, turn_ended_at)`
 * key, and `nudged_at` — the bridge's own clock at the notice — is what the `cooldown_s` between
 * two notices is measured from. A Mezzanine slot carries neither, so a file written before
 * either existed loads unchanged.
 */
final class IdleNudgeState
{
    public const FILE = 'idle-nudge.json';

    /**
     * @param  array<string, array{idle_since: int, nudged_at: ?int, session_id?: ?string}>  $slots  epoch ms
     */
    private function __construct(private array $slots) {}

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

        $loaded = [];
        foreach ($slots as $agent => $slot) {
            $parsed = is_string($agent) && is_array($slot) ? self::slot($slot) : null;
            if ($parsed === null) {
                throw new IdleNudgeUnmeasured('the dedupe state file '.self::path().' is malformed — nothing is pushed until it is repaired or removed');
            }
            $loaded[$agent] = $parsed;
        }

        return new self($loaded);
    }

    /**
     * @param  array<mixed>  $slot
     * @return array{idle_since: int, nudged_at: ?int, session_id?: ?string}|null
     */
    private static function slot(array $slot): ?array
    {
        $idleSince = FleetSnapshot::instantMs($slot['idle_since'] ?? null);
        $nudgedAt = array_key_exists('nudged_at', $slot) ? FleetSnapshot::instantMs($slot['nudged_at']) : null;
        if ($idleSince === null || (array_key_exists('nudged_at', $slot) && $nudgedAt === null)) {
            return null;
        }
        $parsed = ['idle_since' => $idleSince, 'nudged_at' => $nudgedAt];
        if (array_key_exists('session_id', $slot)) {
            if ($slot['session_id'] !== null && ! is_string($slot['session_id'])) {
                return null;
            }
            $parsed['session_id'] = $slot['session_id'];
        }

        return $parsed;
    }

    /** @return array<string, int> agent => the `idle_since` it was last nudged for, epoch ms */
    public function nudged(): array
    {
        return array_map(fn (array $slot): int => $slot['idle_since'], $this->slots);
    }

    /** @return array{idle_since: int, nudged_at: ?int, session_id?: ?string}|null */
    public function slotOf(string $agent): ?array
    {
        return $this->slots[$agent] ?? null;
    }

    /** Record the slot for this idle period and persist it — call BEFORE the push. */
    public function markAndSave(string $agent, int $idleSinceMs): void
    {
        $this->slots[$agent] = ['idle_since' => $idleSinceMs, 'nudged_at' => null];
        $this->save();
    }

    /** Record the slot for this seat offer and persist it — call BEFORE the push. */
    public function markOfferAndSave(SeatOfferPlan $plan): void
    {
        $this->slots[$plan->agent] = ['idle_since' => $plan->turnEndedAtMs, 'nudged_at' => $plan->decidedAtMs, 'session_id' => $plan->sessionId];
        $this->save();
    }

    /**
     * Drop slots for agents this install no longer declares; persists only when one went.
     *
     * @param  list<string>  $declared
     */
    public function forgetUndeclared(array $declared): void
    {
        $kept = array_intersect_key($this->slots, array_flip($declared));
        if (count($kept) !== count($this->slots)) {
            $this->slots = $kept;
            $this->save();
        }
    }

    private function save(): void
    {
        ksort($this->slots);
        $slots = [];
        foreach ($this->slots as $agent => $slot) {
            $out = ['idle_since' => FleetSnapshot::canonicalInstant($slot['idle_since'])];
            if (array_key_exists('session_id', $slot)) {
                $out['session_id'] = $slot['session_id'];
            }
            if ($slot['nudged_at'] !== null) {
                $out['nudged_at'] = FleetSnapshot::canonicalInstant($slot['nudged_at']);
            }
            $slots[$agent] = $out;
        }

        BridgePaths::ensureDir(BridgePaths::stateDir());
        BridgePaths::writeFileAtomic(self::path(), (string) json_encode(['nudged' => (object) $slots], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
