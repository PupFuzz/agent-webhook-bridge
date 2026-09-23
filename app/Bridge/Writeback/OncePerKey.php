<?php

namespace App\Bridge\Writeback;

/**
 * One attempt per key, for the life of this instance — the in-memory guard the bridge's best-effort
 * GitHub writes hold their fan-out down with. Hoisted at its second caller ({@see PrCorrelationCommenter},
 * {@see ProtocolInvalidLabeler}), the {@see GitHubApi} precedent.
 *
 * ⛔ CLAIMING MARKS, WHATEVER COMES OF THE ATTEMPT. The caller claims the key BEFORE it tries, so a
 * refusal, a timeout or a throw consumes the claim exactly as a success does. That is the point: the
 * dispatcher classifies one event once per subscribed agent and each of them emits the same write
 * target, so a second attempt inside one delivery could only repeat a failure just logged, or race a
 * write just made. The NEXT event for the same key tries again.
 *
 * ⛔ THE LIFETIME IS THE OWNING INSTANCE'S, and for these callers that is the handler singleton: one
 * delivery in the receiver (a process per request under FPM), one `bridge:replay` run. A persistent
 * container (Octane-style) would widen it to that process's lifetime — unexercised here, and the
 * same property both call sites had before this was hoisted.
 *
 * ⛔ THE CONDITION IS ON THE PARTS, NOT ON ANY CALLER'S NOUNS. The key is the `\x00`-joined tuple,
 * so two distinct tuples can only collide if some part CONTAINS that byte — which is the obligation
 * every caller takes on, including one passing FREE TEXT. Read it that way rather than as a list of
 * today's part kinds: a caller whose part can carry a NUL merges two attempts into one, and the
 * attempt it silently drops is a write that never happens. The shipped callers pass repo names,
 * issue/PR numbers, comment ids and the DL-390 correlation MARKER (a literal-prefixed, bridge-built
 * HTML comment), none of which can carry it. The key never leaves this class (it is not hashed, sent
 * or printed), which is why it is ruled in the control-byte census as unable to reach a console.
 */
final class OncePerKey
{
    /** @var array<string, true> every key claimed so far */
    private array $claimed = [];

    /**
     * Claim `$parts` for this attempt: true the first time, false every time after. A null part is
     * the empty string, so "no comment id" is one key rather than a fresh one per call.
     */
    public function claim(string|int|null ...$parts): bool
    {
        $key = implode("\x00", array_map(static fn (string|int|null $part): string => (string) $part, $parts));
        if (isset($this->claimed[$key])) {
            return false;
        }

        return $this->claimed[$key] = true;
    }
}
