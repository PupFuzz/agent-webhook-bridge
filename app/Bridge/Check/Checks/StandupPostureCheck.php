<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Standup\StandupConfig;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\FaultMarker;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * The standup digest's preflight posture (card#8683 / DL-345) — the READER the digest's
 * fault marker never had.
 *
 * ⛔ THE DEFECT THIS LEG EXISTS TO CLOSE IS NOT A MISSING FEATURE, IT IS A FALSE CLAIM.
 * `StandupGate::ERROR_KEY` shipped with a docblock saying the marker exists because *"a
 * caught throw that left only a log line would rebuild DL-012 — the marker is what lets the
 * preflight surface the stuck state"*. Nothing read it. The write site had no read site
 * anywhere in `app/`, so a wedged digest left a cache entry nobody looked at and an
 * operator reading that docblock believed `bridge:check` would tell them — confidence
 * instead of a question, which is worse than the silence it described. Its two siblings
 * ({@see RetentionPostureCheck}, {@see JobsPostureCheck}) each had their reader from the
 * start; this is the third, and it makes the sentence true rather than editing it away.
 *
 * ⭐ SILENT ON AN INSTALL THAT DID NOT ADOPT THE DIGEST, which is the default and the common
 * case. `bridge.standup.enabled` is off unless an operator turns it on (DL-306: a pass makes
 * outbound board reads and a channel push, so it is opt-in per install), and an install that
 * never wanted a digest is not failing by not pushing one. That mirrors
 * {@see JobsPostureCheck} rather than {@see RetentionPostureCheck}: retention is ON by
 * default, so ITS disabled arm is a `warn` about stores that will now grow, and there is no
 * equivalent cost to leaving this off.
 *
 * ⚠ THE DISABLED ARM DOES NOT READ THE FAULT MARKER, and that is inherited from both
 * siblings rather than decided afresh here. A marker standing under a digest the operator
 * has since switched off states a fault about work that is no longer wanted; it expires on
 * its own ({@see FaultMarker::TTL_FLOOR}). Reporting it would put a line an operator cannot
 * act on onto every preflight of an install that already made its decision.
 *
 * ⚑ IT NEVER YIELDS `fail`, for the same reason retention's leg does not: every posture it
 * can report leaves the receiver serving correctly. A misconfigured digest pushes nothing
 * and backs off a full day; a failed push costs a report, not a delivery. `fail` flips
 * `bridge:check`'s exit code, and this command gates deployment runbooks — an opt-in report
 * being down must not red a deploy. (The asymmetry with {@see JobsPostureCheck}'s `fail` on
 * a misconfigured cadence is the subject's: there, the install's ENTIRE periodic population
 * is dead on both ingresses.)
 *
 * ⚠ NO STALENESS RULE IS INVENTED HERE. The marker's `at` is printed and no freshness
 * verdict is derived from it — a marker can outlive the condition on a since-quieted install
 * (it is cleared by the next successful pass, and otherwise by its TTL), and neither sibling
 * makes that distinction. Stating a rule this leg cannot check would be the same shape of
 * claim the leg was written to retire.
 */
final class StandupPostureCheck implements Check
{
    public function id(): string
    {
        return 'standup.posture';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $cfg = StandupConfig::fromConfig();

        if (! $cfg->enabled) {
            yield Silence::because('the standup digest is off (its default), so this install pushes no digest, has none to be stuck on, and is not failing by not having one');

            return;
        }

        if (! $cfg->isUsable()) {
            yield Finding::warn('standup: enabled but MISCONFIGURED — '.(string) $cfg->problem
                .'. NOTHING is pushed, and the gate backs off a full DAY once it has seen this, so the only other trace is one log line per day. Fix it and re-run `php artisan config:cache`.');
        } else {
            yield Finding::ok('standup: on ('.$cfg->summary().')');
        }

        // Config being valid does NOT mean the digest is being DELIVERED. The gate arms its
        // interval marker BEFORE the push, so a pass that throws — a dead channel server on
        // the target seat is the ordinary case — backs off a full interval and the seat
        // simply stops receiving digests with nothing anywhere saying so: DL-012's silent
        // inertness, which is the blind spot the marker was written to close and could not,
        // having no reader. The catch stays with the leg that needs it: `CheckRunner`
        // deliberately does not isolate, so hoisting it would turn an unreachable cache
        // backend into an aborted `bridge:check` rather than one degraded line.
        try {
            $lastError = FaultMarker::lastFault(StandupGate::ERROR_KEY);
            if ($lastError !== null) {
                yield Finding::warn('standup: the LAST PASS FAILED and no digest has been pushed since ('
                    .$lastError.'). The push is retried at most once per standup.interval, so a seat whose channel server stays down just stops receiving digests. Check that seat is up; `php artisan bridge:standup --dry-run` builds the digest and pushes nothing. The marker clears itself on the next clean pass.');
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated('standup: could not read the last-failure marker ('.$e->getMessage()
                .') — the cache backend the standup gate depends on may be unreachable, so this run says nothing about whether the last pass succeeded.');
        }
    }
}
