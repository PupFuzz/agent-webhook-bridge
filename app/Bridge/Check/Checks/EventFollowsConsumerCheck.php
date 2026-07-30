<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Support\Finding;
use App\Models\WebhookEvent;
use Throwable;

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
 * subscribed/arriving event. The observed set is the bridge's OWN inbound
 * history (no GitHub hooks-API call — the least-privilege reconcile token can't
 * read `/repos/{repo}/hooks`; §3 of the design). That history is unbounded
 * until pruned — retention is event-gated (DL-199) or manual, so a single
 * long-remediated stray can WARN indefinitely. The WARN therefore carries the
 * occurrence count + last-seen timestamp (card #4321): an old last-seen is
 * remediated history, a fresh one is live drift — WITHOUT bounding the set by
 * a recency window, which would let rare-but-real drift older than the window
 * read CLEAN and invert the check's false-clean-impossible invariant.
 *
 * Fail-soft: wrapped so a DB hiccup can never throw out of `bridge:check`;
 * emits only `warn`/`ok`/`unvalidated`, never `fail`. An empty `webhook_events`
 * for a scope ⇒ no warns (nothing has been dropped yet — correct). A SKIPPED run
 * (the catch) is `unvalidated`, not green: the advisory did not run, and card 5170
 * is the ruling that a check which did not run is never reported as one that passed.
 *
 * THE FAIL-SOFT `catch` IS INSIDE THIS GENERATOR, AND THAT IS THE STAGE-3b
 * CONSTRAINT, NOT A STYLE CHOICE. {@see CheckRunner} MATERIALIZES a check's findings
 * before the caller renders any of them, whereas the inline code this replaced had
 * already PRINTED every line above the throw. A `catch` left in the caller (an
 * envelope, as stage 3a ruled for `writeback.json`) would therefore DISCARD the
 * findings this check had already yielded — the scopes it got through before the DB
 * hiccup. {@see WritebackBoardStateCheck} records the same rule for its per-mapping
 * case; it is stated again here because NO golden fixture reaches this catch, so the
 * corpus cannot tell the two placements apart — only the unit test asserting the
 * pre-throw findings AND the `unvalidated` line together can.
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
        if ($ctx->githubScopeConsumers === []) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        try {
            foreach ($ctx->githubScopeConsumers as $scope => $consumers) {
                // observed: top-level event types actually received for this scope
                // (normalized off the `.action` suffix — webhook_events stores
                // `pull_request.opened`), each with its occurrence count + last-seen
                // (the datum separating remediated history from live drift, #4321).
                /** @var array<string, array{count: int, last: string}> $observed */
                $observed = [];
                // Per-full-type rows are RETAINED (card #4354): the action inventory
                // below needs the pre-collapse `issues.closed`-granularity counts the
                // top-level projection destroys.
                /** @var array<string, array<string, array{count: int, last: string}>> $observedActions */
                $observedActions = [];   // top-level => action => {count,last}
                $rows = WebhookEvent::query()
                    ->where('provider', 'github')
                    ->where('scope_id', (string) $scope)
                    ->groupBy('event_type')
                    ->selectRaw('event_type, COUNT(*) as occurrences, MAX(received_at) as last_seen')
                    ->toBase()
                    ->get();
                foreach ($rows as $row) {
                    $eventType = is_string($row->event_type ?? null) ? $row->event_type : '';
                    if ($eventType === '') {
                        continue;
                    }
                    $parts = explode('.', $eventType, 2);
                    $top = $parts[0];
                    // Seconds precision: received_at is timestamp(3) and MariaDB's
                    // MAX() returns the fractional part while SQLite returns the
                    // stored string — trim to the driver-independent 19 chars.
                    $last = is_scalar($row->last_seen ?? null) ? substr((string) $row->last_seen, 0, 19) : '';
                    $count = (int) ($row->occurrences ?? 0);
                    $prev = $observed[$top] ?? ['count' => 0, 'last' => ''];
                    $observed[$top] = [
                        'count' => $prev['count'] + $count,
                        'last' => max($prev['last'], $last),
                    ];
                    // Actionless types (`push`) never enter the action inventory —
                    // there is no action to compare (card #4354 design, edge 7a).
                    if (isset($parts[1]) && $parts[1] !== '') {
                        $observedActions[$top][$parts[1]] = ['count' => $count, 'last' => $last];
                    }
                }
                if ($observed === []) {
                    continue;   // nothing arrived → nothing dropped (not a false clean)
                }

                // consumed: union across EVERY enabled classifier subscribed to the
                // scope (not one-per-scope — the AIMLA case, two agents on one scope).
                // A declaration may be BARE (`issues` — the type is owned, all actions
                // covered) or QUALIFIED (`issues.opened`, card #4354). The WARN compare
                // PROJECTS qualified entries to their top level, so WARN semantics are
                // unchanged for every conforming install (bare-only declarations are
                // the identity under projection); qualified-only coverage additionally
                // feeds the action inventory below.
                $consumed = [];        // top-level projection (WARN compare)
                $bareConsumed = [];    // top-level types declared BARE by some consumer
                $qualifiedActions = []; // top-level => action => true (union)
                $undeclared = [];   // classifiers with no DeclaresConsumedEvents (disambiguation)
                foreach ($consumers as $c) {
                    foreach ($c['consumed'] as $eventType) {
                        $parts = explode('.', $eventType, 2);
                        $consumed[$parts[0]] = true;
                        if (isset($parts[1]) && $parts[1] !== '') {
                            $qualifiedActions[$parts[0]][$parts[1]] = true;
                        } else {
                            $bareConsumed[$parts[0]] = true;
                        }
                    }
                    if (! $c['declared']) {
                        $undeclared[$c['class'].' (agent '.$c['agent'].')'] = true;
                    }
                }

                // Action inventory (card #4354, INFO — deliberately NEVER a warn):
                // GitHub has no per-action unsubscribe, and deliberately-unhandled
                // actions are the majority class, so an action-level ALARM would train
                // operators to ignore the check. One aggregated line per scope+type,
                // only where the type is consumed ONLY via qualified declarations
                // (a bare declaration means the type is owned — all actions covered).
                foreach ($observedActions as $top => $actions) {
                    if (! isset($consumed[$top]) || isset($bareConsumed[$top])) {
                        continue;   // unconsumed types WARN below; bare-owned types are covered
                    }
                    $unlisted = array_diff_key($actions, $qualifiedActions[$top] ?? []);
                    if ($unlisted === []) {
                        continue;
                    }
                    uasort($unlisted, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
                    $detail = implode(', ', array_map(
                        static fn (string $action, array $d): string => "{$action} ({$d['count']}x, last ".($d['last'] !== '' ? $d['last'].' UTC' : 'unknown').')',
                        array_keys($unlisted),
                        array_values($unlisted),
                    ));
                    $caveat = $undeclared !== [] ? ' An undeclared classifier on this scope may consume some of these (possible false inventory).' : '';

                    yield Finding::ok("event-consumer: github:{$scope} '{$top}' actions observed but not action-declared by any family: {$detail} — arrived-and-dropped at the action level (informational; the type itself is consumed).{$caveat}");
                }

                $unconsumed = array_values(array_diff(array_keys($observed), array_keys($consumed)));
                if ($unconsumed === []) {
                    continue;
                }

                // Disambiguation (sola's #22): an undeclared classifier on the scope
                // MIGHT consume the event without declaring it, so a warn below may be
                // a false positive — say so, keeping it actionable. Moot for the
                // reference classifiers (all declare); matters only for custom impls.
                foreach (array_keys($undeclared) as $desc) {
                    yield Finding::warn("event-consumer: scope github:{$scope} has an enabled classifier {$desc} that does not declare its consumed events (App\\Bridge\\Contracts\\DeclaresConsumedEvents) — the following unconsumed-event WARNING(s) MAY be a false positive if that classifier actually consumes them");
                }

                $subscribed = implode(', ', array_values(array_unique(array_map(
                    static fn (array $c): string => $c['agent'],
                    $consumers,
                ))));
                foreach ($unconsumed as $eventType) {
                    $count = $observed[$eventType]['count'];
                    $last = $observed[$eventType]['last'] !== '' ? $observed[$eventType]['last'].' UTC' : 'unknown';

                    yield Finding::warn("event-consumer: github:{$scope} has received '{$eventType}' ({$count}x, last {$last}) but no enabled classifier consumes it — the event is silently dropped on arrival (agent(s) subscribed: {$subscribed}). A last-seen predating your subscription fix is remediated history, not live drift. Add a consuming family, or drop '{$eventType}' from the subscription via coord:setup-bridge.");
                }
            }
        } catch (Throwable $e) {
            // Fail-soft: this advisory must never break the install check. But a
            // skipped advisory is a check that did NOT run, so it goes through the
            // `unvalidated` vocabulary (card 5170) rather than rendering green via
            // `ok` and vanishing from the tally. `unvalidated` never flips the exit
            // (the renderer's `fail` arm is the only one that does), so yielding it
            // here cannot make a DB hiccup fail the install check.
            yield Finding::unvalidated('event-consumer: check skipped — '.$e->getMessage());
        }
    }
}
