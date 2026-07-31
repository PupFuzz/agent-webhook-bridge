<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\EventConsumers\EventConsumerReconciler;
use App\Bridge\Check\EventConsumers\EventConsumerScope;
use App\Bridge\Support\Finding;

/**
 * card#4183 (DL-196): "event follows consumer". Per `github:<scope>`, WARN when
 * a top-level event type has been RECEIVED (in `webhook_events`, provider
 * github) but no enabled classifier of any agent subscribed to that scope
 * consumes it — the event arrives and is silently dropped. WARN, never
 * error/fail (a hygiene smell, not a broken install), consistent with every
 * advisory in this command. Migrated out of `CheckCommand` whole in DL-242 stage 7a.
 *
 * Structurally the sibling of the orphaned-writeback-mapping check: both ask
 * "is there classifier code that activates this config artifact?", here of a
 * subscribed/arriving event.
 *
 * IT IS NOW A RENDERER, NOT A DERIVATION (DL-249 stage 9). The reconciliation moved to
 * {@see EventConsumerReconciler}, which `CheckCommand` runs as derivation and publishes
 * on {@see CheckContext::$eventConsumers}; the `--format=json` document emits the same
 * object as data. That split is card#5229's requirement and the reason this class holds
 * no query and no set arithmetic: a second derivation behind the second front door is
 * how the prose and the data start disagreeing.
 *
 * THE WORDING LIVES HERE AND ONLY HERE, which is the same rule stage 8 applied to the
 * inventory line. A sentence on {@see EventConsumerScope} would put this renderer's voice
 * inside the JSON one — and the wording is exactly what a downstream parser must never
 * be keyed on, since DL-236 reworded findings in this very command.
 *
 * Emits only `warn`/`ok`/`unvalidated`, never `fail`. An empty `webhook_events`
 * for a scope ⇒ no warns (nothing has been dropped yet — correct). A reconciliation that
 * FAILED is `unvalidated`, not green: the advisory did not run, and card#5170 is the
 * ruling that a check which did not run is never reported as one that passed.
 *
 * THE ERROR IS REPORTED LAST AND THE SCOPES BEFORE IT STILL PRINT, which is the stage-3b
 * constraint restated at its new address. {@see CheckRunner}
 * MATERIALIZES a check's findings before the caller renders any of them, whereas the
 * inline code this replaced had already PRINTED every line above the throw. The
 * reconciler therefore keeps the scopes it completed and carries the failure as a value
 * rather than throwing — an exception crossing this boundary would discard them. NO
 * golden fixture reaches the failure path, so the corpus cannot tell a correct
 * arrangement from a lossy one; only the unit test asserting the pre-failure findings AND
 * the `unvalidated` line together can.
 */
final class EventFollowsConsumerCheck implements Check
{
    public function id(): string
    {
        return 'event.follows_consumer';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $reconciliation = $ctx->eventConsumers;
        if ($reconciliation === null) {
            return;   // nothing was derived for this run; the inventory records the disposition (DL-242 stage 8)
        }

        foreach ($reconciliation->scopes as $scope) {
            // A scope nothing has arrived on is SILENT, not clean-looking: nothing was
            // dropped there yet. The declaration half of `$scope` is still populated —
            // the JSON surface needs it — and deliberately says nothing here.
            if ($scope->observed === []) {
                continue;
            }

            yield from $this->actionInventory($scope);

            $unconsumed = $scope->unconsumed();
            if ($unconsumed === []) {
                continue;
            }

            yield from $this->undeclaredDisclosure($scope);

            $subscribed = implode(', ', $scope->agents);
            foreach ($unconsumed as $eventType) {
                $count = $scope->observed[$eventType]['count'];
                $last = $scope->observed[$eventType]['last'] !== '' ? $scope->observed[$eventType]['last'].' UTC' : 'unknown';

                yield Finding::warn("event-consumer: github:{$scope->scope} has received '{$eventType}' ({$count}x, last {$last}) but no enabled classifier consumes it — the event is silently dropped on arrival (agent(s) subscribed: {$subscribed}). A last-seen predating your subscription fix is remediated history, not live drift. Add a consuming family, or drop '{$eventType}' from the subscription via coord:setup-bridge.");
            }
        }

        if ($reconciliation->error !== null) {
            // Fail-soft: this advisory must never break the install check. But a skipped
            // advisory is a check that did NOT run, so it goes through the `unvalidated`
            // vocabulary (card#5170) rather than rendering green via `ok` and vanishing
            // from the tally. `unvalidated` never flips the exit (the renderer's `fail`
            // arm is the only one that does), so yielding it here cannot make a DB hiccup
            // fail the install check.
            yield Finding::unvalidated('event-consumer: check skipped — '.$reconciliation->error);
        }
    }

    /**
     * Action inventory (card#4354, INFO — deliberately NEVER a warn): GitHub has no
     * per-action unsubscribe, and deliberately-unhandled actions are the majority class,
     * so an action-level ALARM would train operators to ignore the check. One aggregated
     * line per scope+type.
     *
     * @return iterable<Finding>
     */
    private function actionInventory(EventConsumerScope $scope): iterable
    {
        $caveat = $scope->undeclared !== [] ? ' An undeclared classifier on this scope may consume some of these (possible false inventory).' : '';

        foreach ($scope->unlistedActions() as $top => $unlisted) {
            $detail = implode(', ', array_map(
                static fn (string $action, array $d): string => "{$action} ({$d['count']}x, last ".($d['last'] !== '' ? $d['last'].' UTC' : 'unknown').')',
                array_keys($unlisted),
                array_values($unlisted),
            ));

            yield Finding::ok("event-consumer: github:{$scope->scope} '{$top}' actions observed but not action-declared by any family: {$detail} — arrived-and-dropped at the action level (informational; the type itself is consumed).{$caveat}");
        }
    }

    /**
     * Disambiguation (roundtable #22): an undeclared classifier on the scope MIGHT
     * consume the event without declaring it, so a warn below may be a false positive —
     * say so, keeping it actionable. Moot for the reference classifiers (all declare);
     * matters only for custom impls.
     *
     * @return iterable<Finding>
     */
    private function undeclaredDisclosure(EventConsumerScope $scope): iterable
    {
        foreach ($scope->undeclared as $u) {
            $desc = $u['class'].' (agent '.$u['agent'].')';

            yield Finding::warn("event-consumer: scope github:{$scope->scope} has an enabled classifier {$desc} that does not declare its consumed events (App\\Bridge\\Contracts\\DeclaresConsumedEvents) — the following unconsumed-event WARNING(s) MAY be a false positive if that classifier actually consumes them");
        }
    }
}
