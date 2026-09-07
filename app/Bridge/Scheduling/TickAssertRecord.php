<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Support\FaultMarker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When something last ASKED whether this install's tick horizon was met — the READER half
 * of death-is-the-alarm (card#8425 / DL-351, rt#341).
 *
 * ⛔ THE DEFECT THIS CLOSES IS A DEAD ALARM, AND A DEAD ALARM READS AS COVERAGE. An install
 * can set `BRIDGE_JOBS_TICK_EXPECTED_EVERY` and wire NO consumer of `--assert-tick`
 * anywhere. The horizon is declared, {@see TickPosture} resolves all four states correctly,
 * `stale` is computed exactly — and nothing ever asks. That is indistinguishable from having
 * declared no horizon at all, and it is WORSE than that, because the declaration reads as
 * coverage to anyone auditing the config: the operator believes the clock is watched. This
 * record is what turns that belief into a checkable claim: `App\Bridge\Check\Checks\JobsPostureCheck`
 * warns when a horizon is declared and this record is absent. (Named rather than `{@see}`-linked:
 * pint's docblock fixer turns a fully-qualified `{@see}` into a real import, and a Scheduling
 * primitive importing a preflight CHECK inverts the dependency this class is on the safe side of.)
 *
 * ⚑ A SIBLING OF {@see TickRecord}, NOT A SECOND KEY ON IT, and the three reasons are the
 * design rather than a filing preference:
 *  1. **Different subject and different writer.** `TickRecord` leads with *"it is stamped by
 *     `bridge:tick` and by NOTHING else"*, and that invariant is load-bearing: it is why the
 *     record answers *"is the clock alive?"* rather than *"is anything arriving?"*. A second
 *     stamp written by a different process at a different moment makes that sentence need a
 *     qualifier, and a qualified invariant is one a later reader can talk themselves past.
 *  2. **Different lifetime rule** — see below. The two records must expire differently, and a
 *     class holding two records with two TTLs states neither clearly.
 *  3. **Different failure meaning.** An absent tick record is *the clock may be dead*; an
 *     absent assert record is *nobody is listening*. Two alarms, two remedies (fix the
 *     crontab line; wire the hook), and the check reports them as two lines.
 *
 * ⭐ IT DOES NOT EXPIRE, AND THE TTL IS THE WHOLE REASON IT IS NOT ONE. `TickRecord` keeps 30
 * days deliberately, so an install that stopped ticking a fortnight ago still has an ANSWER.
 * The question HERE is not *when did this last happen* but *is this install WIRED to ask* —
 * a property of the install's hook configuration, which no clock invalidates. A TTL would
 * silently convert **asserted, long ago** into **no record of an assert ever**, and those are
 * two different states this subsystem reports differently: the first gets an age and no
 * verdict, the second gets the warn. Collapsing them by expiry would put a sentence saying
 * NOTHING HAS EVER ASKED in front of an operator whose hook simply ran last month.
 *
 * ⚠ NO CADENCE IS DECLARED FOR THE ASSERT ITSELF, so none is invented here. The age is
 * recorded and reported; no staleness verdict is derived from it. A rule invented at this end
 * would be exactly the fleet-wide constant the tick horizon refuses — only the seat knows how
 * often its own hook fires, and nothing declares that to the bridge.
 *
 * ⛔ `bridge:check`'s OWN LEG MUST NEVER STAMP THIS. `bridge:jobs --assert-tick` is the entry
 * point that exits non-zero, and it is the only writer. A preflight leg that stamped the
 * record while reading it would extinguish its own alarm on first run: the warn would appear
 * once, the operator would re-run `bridge:check`, and it would be gone with nothing wired.
 *
 * ⚑ A CLEARED OR UNREACHABLE STORE READS AS "NO RECORD" — loud, never as watched. That is the
 * same failure direction {@see TickRecord} is built on and it is chosen for the same reason:
 * the store is the one the DL-199 gates already depend on, and the one state this must never
 * produce is a false claim that somebody is reading the alarm.
 */
final class TickAssertRecord
{
    public const KEY = 'bridge:jobs:last-tick-assert';

    /**
     * ⛔ A STAMP THAT CANNOT BE WRITTEN IS NEVER A THROW. This runs on the assertion path a
     * session-start hook drives, and that hook's job is to report the TICK's state — killing
     * it with a cache fault would break the alarm this record exists to prove is armed.
     */
    public static function stamp(): void
    {
        try {
            Cache::forever(self::KEY, now()->toIso8601String());
        } catch (Throwable $e) {
            FaultMarker::log('the tick-assert stamp could not be written; this install will read as having no reader for its tick horizon', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * When `bridge:jobs --assert-tick` was last run here, or null when the bridge holds no
     * record of it ever being run — which includes a store that was cleared or cannot be
     * read. Null is reported as *no record*, never as *never invoked*, because those are the
     * same observation and only one of them is a claim this class can make.
     */
    public static function lastAt(): ?Carbon
    {
        try {
            $raw = Cache::get(self::KEY);

            return is_string($raw) ? Carbon::parse($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Seconds since the last recorded assertion, or null when there is no record. */
    public static function ageS(): ?int
    {
        $at = self::lastAt();

        return $at === null ? null : max(0, now()->getTimestamp() - $at->getTimestamp());
    }
}
