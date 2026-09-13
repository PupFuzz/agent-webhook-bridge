<?php

namespace Tests\Unit\Docs;

use App\Bridge\Tools\ClientVersion;

/**
 * The first channel-server snapshot that reports `client_version` at all, wherever this tree
 * STATES it for an operator, held in lockstep with the pin that defines it (card#9336).
 *
 * ⭐ WHY THE FIGURE IS WRITTEN OUT RATHER THAN POINTED AT, which is the whole of the defect this
 * guard ships with. The operator who needs the floor is reconciling a fleet of channel-server
 * snapshots, and the seat holding the snapshot commonly has NO bridge checkout — so a pointer to
 * `App\Bridge\Tools\ClientVersion` is correct in form and empty in content for exactly the reader
 * who needs it. Measured on a peer fleet 2026-09-13: three seats reconciled correctly and live,
 * `client_version` NULL on all three, one of them freshly re-pinned to a snapshot below the floor
 * — every `bridge:check` line `ok`, and nothing anywhere saying why. That is a reconcile run to
 * completion for nothing, and the fix is a number in the two docs the operator actually reads.
 *
 * ⛔ IT CANNOT BE DERIVED FROM THE BUNDLED SNAPSHOT, so a "compute it" alternative is not
 * available here. {@see ClientVersion::FIRST_REPORTING_SNAPSHOT} is a FROZEN PIN on a historical
 * release; a pin that follows `examples/channel-servers/package.json` restores the `0.9.14`
 * collision DL-364 exists to prevent, where the check tells an operator running a
 * non-reporting release that their client is older than it. The prose therefore carries the
 * figure, and this guard carries the cost of that — which is the canon's stated exception for a
 * load-bearing threshold, on its own terms: a pin, and something that reds when the two disagree.
 *
 * ⭐ THE MECHANISM IS {@see DocFigureLockstepTestCase}'s — the tracked-file population re-derived
 * on every run, the HISTORY and excluded-prefix bounds, and the presence witness. Its docblock
 * owns the reasoning; only the subject-specific parts are below.
 *
 * ⚠ Two bounds worth naming for THIS subject, because both look like drift and are not:
 *   - `docs/CHANGELOG.md` and `CLAUDE_DECISIONS.md` record the `0.9.14 → 0.9.15` bump as the event
 *     it was. Those are HISTORY (bound (b)) and are correct frozen.
 *   - The golden `bridge:check` captures under `tests/Fixtures/` contain the figure because the
 *     leg RENDERS the constant into its finding. They are excluded (bound (c)) and they red on
 *     drift by themselves, which is the property that makes excluding them safe.
 */
class ClientVersionFloorLockstepTest extends DocFigureLockstepTestCase
{
    protected function marker(): string
    {
        // Anchored on the TRAILING WORDS, so the constant's own declaration is not read as a copy
        // of itself, and on the BACKTICKS, so a history entry's `0.9.14 -> 0.9.15` prose is not
        // read as an operator-facing statement of the floor. The version character class is
        // ClientVersion's own whitelist, narrowed to a leading digit.
        return '/`([0-9][0-9A-Za-z.+-]*)` is the first reporting snapshot\b/';
    }

    protected function houseSpelling(): string
    {
        return '"`<version>` is the first reporting snapshot"';
    }

    protected function constantName(): string
    {
        return 'ClientVersion::FIRST_REPORTING_SNAPSHOT';
    }

    protected function constantValue(): string
    {
        return ClientVersion::FIRST_REPORTING_SNAPSHOT;
    }

    protected function subject(): string
    {
        return 'the first reporting channel-server snapshot';
    }

    public function test_the_predicate_discriminates(): void
    {
        // A real restatement, spelled as both operator surfaces spell it.
        $this->assertSame(['0.9.15'], $this->figuresIn('and there is a floor: `0.9.15` is the first reporting snapshot — the first release that'));
        // The CONSTANT'S OWN DECLARATION is not a restatement — if this matched, the guard would
        // compare the pin against itself and could never fail.
        $this->assertSame([], $this->figuresIn("public const FIRST_REPORTING_SNAPSHOT = '0.9.15';"));
        // ⛔ THE DEFECT'S OWN PROSE, pinned as a near miss: the sentence that names the threshold
        // and states no figure is what both docs said before this card, and it is INVISIBLE here.
        // The guard makes every stated copy true; it cannot make an unstated floor stated, and a
        // reader who reverts to this wording gets a green run and an empty pointer again.
        $this->assertSame([], $this->figuresIn('a client older than the first reporting snapshot'));
        // A HISTORY spelling: the release record of the bump that introduced the field. Out of the
        // census by bound (b) AND by this predicate, so neither leg is carrying it alone.
        $this->assertSame([], $this->figuresIn('reference snapshot **0.9.14 → 0.9.15** (DL-038)'));
    }
}
