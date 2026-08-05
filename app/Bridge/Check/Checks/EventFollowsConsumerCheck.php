<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\EventConsumers\EventConsumerReconciler;
use App\Bridge\Check\EventConsumers\EventConsumerScope;
use App\Bridge\Check\Silence;
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
 * TWO INDEPENDENT REASONS THE CONSUMER LIST CAN BE SHORT, and each gets its own arm
 * because each has its own fix: an agent the run never finished READING
 * ({@see CheckContext::$agentScopeCoverage}, DL-255) and a classifier that implements
 * `DeclaresConsumedEvents` and THREW when asked ({@see EventConsumerScope::$unreadable},
 * DL-257). Both short-change `consumed` in the same direction, so both downgrade the
 * unconsumed verdict to `unvalidated` rather than warning; neither can make an EMPTY
 * `unconsumed` wrong, which is why that case stays silent under both. BOTH ARMS SUPPRESS
 * {@see self::undeclaredDisclosure()}, and that is inherited rather than chosen: it exists
 * to qualify the unconsumed WARNINGS by name, and neither arm emits any — the undeclared
 * set is still carried on the JSON surface for a consumer that wants it.
 *
 * ONE BOUND REMAINS ON THE card#5698 COVERAGE DISCLOSURE BELOW. The other — that the
 * action inventory was NOT gated on coverage, deferred because it is `Finding::ok` and the
 * `ok` population had never been audited against `App\Bridge\Support\Severity`'s rule — is
 * CLOSED: DL-259 ran that audit whole and the inventory now reports `unvalidated` when
 * either shortener applies, per corollary (A) (a green line may disclose what a measured
 * fact implies, never that it could not look). The deferral was right at the time: gating
 * the one site ahead of the audit is how an audit stops happening.
 *  - **A scope only an UNREAD agent subscribes to has no entry here at all**, so nothing —
 *    not even a disclosure — is said about arrivals on it. Reaching it would mean deriving
 *    arrivals for a scope no consumer list mentions, which is the reconciler's job and not
 *    this renderer's.
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
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $reconciliation = $ctx->eventConsumers;
        if ($reconciliation === null) {
            yield Silence::because('no event-consumer reconciliation was derived for this run, so there is no arrived-vs-consumed comparison to report on — the envelope that skipped it records why');

            return;
        }

        foreach ($reconciliation->scopes as $scope) {
            // A scope nothing has arrived on is SILENT, not clean-looking: nothing was
            // dropped there yet. The declaration half of `$scope` is still populated —
            // the JSON surface needs it — and deliberately says nothing here.
            if ($scope->observed === []) {
                continue;
            }

            yield from $this->actionInventory($scope, $ctx);

            $unconsumed = $scope->unconsumed();
            if ($unconsumed === []) {
                continue;
            }

            // card#5698: the reconciliation's consumer list per scope comes from
            // `CheckContext::$githubScopeConsumers`, which an aborted agent never reached —
            // so an unread agent's classifier is missing from `consumed` and every type it
            // covers shows up here as dropped. The check is asymmetric on purpose and the
            // asymmetry is what bounds this to the warn path: an unread consumer can only
            // ADD to `consumed`, so an EMPTY `unconsumed` (handled above) is robust to the
            // gap and needs no disclosure, while a non-empty one cannot be stood behind.
            if ($ctx->agentScopeCoverage->mayCover($scope->scope)) {
                yield Finding::unvalidated("event-consumer: github:{$scope->scope} has received '".implode("', '", $unconsumed)."' with no consumer among the agents this run read, but ".$ctx->agentScopeCoverage->gapClause($scope->scope).' — an agent it never finished reading could consume them, so whether these arrivals are being silently dropped could not be determined. Fix the error(s) above and re-run.');

                continue;
            }

            // card#5698, the same treatment one arm up for the same reason. An agent whose
            // classifier implements the interface but threw when asked contributes no
            // `consumed`, so every type it covers surfaces here — and the run cannot tell
            // that from a type nothing consumes. The asymmetry that bounds this to the
            // warn path is the one above's: an unread declaration can only ADD to
            // `consumed`, so the empty `unconsumed` handled earlier stays empty under it
            // and needs no disclosure.
            if ($scope->unreadable !== []) {
                yield from $this->unreadableDisclosure($scope);

                yield Finding::unvalidated("event-consumer: github:{$scope->scope} has received '".implode("', '", $unconsumed)."' with no consumer among the declarations this run could read, but the classifier(s) named above could consume them, so whether these arrivals are being silently dropped could not be determined. Fix the classifier error(s) and re-run.");

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

        yield Silence::because('the reconciliation completed and every subscribed scope came back with nothing to say — either nothing has arrived on it yet (the per-scope silence the loop above declares in prose), or everything that arrived is consumed and action-declared');
    }

    /**
     * Action inventory (card#4354, INFO — deliberately NEVER a warn): GitHub has no
     * per-action unsubscribe, and deliberately-unhandled actions are the majority class,
     * so an action-level ALARM would train operators to ignore the check. One aggregated
     * line per scope+type.
     *
     * @return iterable<Finding>
     */
    private function actionInventory(EventConsumerScope $scope, CheckContext $ctx): iterable
    {
        // THE TWO CAVEATS SPLIT THE FINDING'S SEVERITY, because they are different KINDS
        // of doubt (DL-259 settled this; the rule is corollary (A) in `Severity`, which
        // owns it — do not re-argue it here).
        //
        // `undeclared` is world-ambiguity: the instanceof was MEASURED, and what it
        // implies about consumption is what stays open. The inventory is a real
        // measurement, so it keeps its `ok` and carries the disclosure.
        //
        // `unreadable` is measurement-ambiguity: the classifier WAS asked and the
        // derivation threw, so this run never learned what it consumes and the inventory
        // it computed may list actions that ARE consumed. A green line disclosing that it
        // could not look is the shape the vocabulary exists to prevent, so the whole
        // finding drops to `unvalidated` — which is NOT the action-level alarm this leg
        // refuses to raise (card#4354): `unvalidated` renders plain, never yellow, and
        // never touches the exit. The INFO ruling is about `warn`, and is untouched.
        // BOTH SHORTENERS OF THE CONSUMER LIST REACH THIS LINE, and fixing only one would
        // leave the site still asserting past its evidence. `unlistedActions()` reads
        // `consumed` / `bare` / `qualified`, all unioned across the scope's consumers, so a
        // declaration this run never obtained — whether because the AGENT was never read
        // (DL-255) or because the classifier was asked and THREW (DL-257) — can make an
        // action look unlisted when it is declared, and a bare declaration would have
        // removed the whole type from this inventory.
        $unmeasured = $ctx->agentScopeCoverage->mayCover($scope->scope) || $scope->unreadable !== [];
        $caveat = ($scope->undeclared !== [] ? ' An undeclared classifier on this scope may consume some of these (possible false inventory).' : '')
            .($scope->unreadable !== [] ? ' A classifier on this scope did not answer what it consumes, so this inventory could not be stood behind (it may list actions that ARE consumed).' : '')
            .($ctx->agentScopeCoverage->mayCover($scope->scope) ? ' An agent this run never finished reading may declare some of these, so this inventory could not be stood behind (it may list actions that ARE consumed).' : '');

        foreach ($scope->unlistedActions() as $top => $unlisted) {
            $detail = implode(', ', array_map(
                static fn (string $action, array $d): string => "{$action} ({$d['count']}x, last ".($d['last'] !== '' ? $d['last'].' UTC' : 'unknown').')',
                array_keys($unlisted),
                array_values($unlisted),
            ));

            $message = "event-consumer: github:{$scope->scope} '{$top}' actions observed but not action-declared by any family: {$detail} — arrived-and-dropped at the action level (informational; the type itself is consumed).{$caveat}";

            yield $unmeasured ? Finding::unvalidated($message) : Finding::ok($message);
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

    /**
     * The truthful counterpart of {@see self::undeclaredDisclosure()} for a classifier
     * that DOES implement the interface and threw when asked (card#5698).
     *
     * IT IS A `warn` AND NOT AN `unvalidated`, which is the distinction the whole card
     * turns on: *the declaration could not be read* is a MEASURED fact — the run caught the
     * throw — and it is the operator's actual defect to fix. What could not be measured is
     * the consequence, and that is what the `unvalidated` line above it carries.
     *
     * @return iterable<Finding>
     */
    private function unreadableDisclosure(EventConsumerScope $scope): iterable
    {
        foreach ($scope->unreadable as $u) {
            $desc = $u['class'].' (agent '.$u['agent'].')';

            yield Finding::warn("event-consumer: scope github:{$scope->scope} has an enabled classifier {$desc} that implements App\\Bridge\\Contracts\\DeclaresConsumedEvents but threw when asked which events it consumes — its declarations are missing from this run, so nothing below can say whether an arriving event is unconsumed. consumedEventTypes() must be a pure \$cfg → event-types map (no I/O, no lazy loading); fix it and re-run.");
        }
    }
}
