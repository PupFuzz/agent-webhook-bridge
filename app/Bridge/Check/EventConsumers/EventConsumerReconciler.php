<?php

namespace App\Bridge\Check\EventConsumers;

use App\Models\WebhookEvent;
use Throwable;

/**
 * Reconciles what ARRIVED on each subscribed github scope against what the scope's
 * enabled classifiers DECLARE they consume (card#4183 / DL-196), as data
 * (card#5229 / DL-249 stage 9).
 *
 * EXTRACTED WHOLE OUT OF `EventFollowsConsumerCheck`, which now renders this result
 * rather than deriving it. Two front doors read one derivation: the check's findings and
 * the `--format=json` document. Shipping a second derivation for the JSON surface is the
 * one thing card#5229 rules out by name.
 *
 * THE OBSERVED SET IS THE BRIDGE'S OWN INBOUND HISTORY — no GitHub hooks-API call (the
 * least-privilege reconcile token cannot read `/repos/{repo}/hooks`). It is therefore
 * bounded to events that were both SUBSCRIBED and DELIVERED: an event nobody subscribed
 * to never arrives, so it can never appear here, and no diff over this set can see it.
 * That history is unbounded until pruned, so each entry carries its occurrence count and
 * last-seen (card#4321) — an old last-seen is remediated history, a fresh one is live
 * drift — rather than being bounded by a recency window, which would let rare-but-real
 * drift older than the window read CLEAN.
 *
 * FAIL-SOFT, AND THE SHAPE OF THE FAIL-SOFT IS INHERITED, NOT CHOSEN. The `catch` wraps
 * the whole scope walk, so the first throw ends it and the scopes already reconciled are
 * kept — byte-for-byte the disposition of the inline loop this replaces
 * ({@see EventConsumerReconciliation::$error}). A per-scope `catch` would be a behavior
 * change dressed as an extraction: it would keep walking past a DB failure and report on
 * scopes the run never actually measured.
 */
final class EventConsumerReconciler
{
    /**
     * @param  array<string, list<array{agent: string, class: string, consumed: list<string>, declared: ?bool}>>  $scopeConsumers
     */
    public function reconcile(array $scopeConsumers): EventConsumerReconciliation
    {
        $scopes = [];

        try {
            foreach ($scopeConsumers as $scope => $consumers) {
                // The DB read runs FIRST, before the pure declaration walk below, so a
                // failure truncates this scope exactly where the inline loop did.
                [$observed, $observedActions] = $this->arrivals((string) $scope);
                $declared = $this->declarations($consumers);

                $scopes[] = new EventConsumerScope(
                    scope: (string) $scope,
                    observed: $observed,
                    observedActions: $observedActions,
                    consumed: $declared['consumed'],
                    bare: $declared['bare'],
                    qualified: $declared['qualified'],
                    undeclared: $declared['undeclared'],
                    unreadable: $declared['unreadable'],
                    agents: $declared['agents'],
                );
            }
        } catch (Throwable $e) {
            return new EventConsumerReconciliation($scopes, $e->getMessage());
        }

        return new EventConsumerReconciliation($scopes);
    }

    /**
     * Top-level event types received for one scope, and the per-action breakdown.
     *
     * Per-full-type rows are RETAINED (card#4354): the action tier needs the
     * `issues.closed`-granularity counts the top-level projection destroys.
     *
     * @return array{0: array<string, array{count: int, last: string}>, 1: array<string, array<string, array{count: int, last: string}>>}
     */
    private function arrivals(string $scope): array
    {
        /** @var array<string, array{count: int, last: string}> $observed */
        $observed = [];
        /** @var array<string, array<string, array{count: int, last: string}>> $observedActions */
        $observedActions = [];

        $rows = WebhookEvent::query()
            ->where('provider', 'github')
            ->where('scope_id', $scope)
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
            // Seconds precision: received_at is timestamp(3) and MariaDB's MAX() returns
            // the fractional part while SQLite returns the stored string — trim to the
            // driver-independent 19 chars. The is_scalar guard is driver-boundary
            // narrowing of a selectRaw value, not a state reachable through app code:
            // received_at is NOT NULL, DB-defaulted, and never written by it, so
            // downstream renderers trust `last` unconditionally (card#5555).
            $last = is_scalar($row->last_seen ?? null) ? substr((string) $row->last_seen, 0, 19) : '';
            $count = (int) ($row->occurrences ?? 0);
            $prev = $observed[$top] ?? ['count' => 0, 'last' => ''];
            $observed[$top] = [
                'count' => $prev['count'] + $count,
                'last' => max($prev['last'], $last),
            ];
            // Actionless types (`push`) never enter the action inventory — there is no
            // action to compare (card#4354 design, edge 7a).
            if (isset($parts[1]) && $parts[1] !== '') {
                $observedActions[$top][$parts[1]] = ['count' => $count, 'last' => $last];
            }
        }

        return [$observed, $observedActions];
    }

    /**
     * What one scope's consumers declare, unioned across EVERY enabled classifier
     * subscribed to it — not one per scope; two agents can share a scope and the bridge
     * dispatches to both.
     *
     * @param  list<array{agent: string, class: string, consumed: list<string>, declared: ?bool}>  $consumers
     * @return array{consumed: list<string>, bare: list<string>, qualified: array<string, list<string>>, undeclared: list<array{agent: string, class: string}>, unreadable: list<array{agent: string, class: string}>, agents: list<string>}
     */
    private function declarations(array $consumers): array
    {
        /** @var array<string, true> $consumed */
        $consumed = [];
        /** @var array<string, true> $bare */
        $bare = [];
        /** @var array<string, array<string, true>> $qualified */
        $qualified = [];
        /** @var array<string, array{agent: string, class: string}> $undeclared */
        $undeclared = [];
        /** @var array<string, array{agent: string, class: string}> $unreadable */
        $unreadable = [];
        /** @var array<string, true> $agents */
        $agents = [];

        foreach ($consumers as $c) {
            foreach ($c['consumed'] as $eventType) {
                $parts = explode('.', $eventType, 2);
                $consumed[$parts[0]] = true;
                if (isset($parts[1]) && $parts[1] !== '') {
                    $qualified[$parts[0]][$parts[1]] = true;
                } else {
                    $bare[$parts[0]] = true;
                }
            }
            // Keyed on the (class, agent) pair the renderer names, so one classifier
            // shared by two agents is disclosed once per agent — each is a separate
            // thing the operator can fix.
            //
            // THE `null` ARM IS TESTED FIRST AND SEPARATELY, because `! $c['declared']` is
            // true for BOTH `false` and `null` — the identity that made the two states one
            // bucket before card#5698. A classifier whose declaration threw is not a
            // classifier that declares nothing, and only the second of those is `undeclared`.
            if ($c['declared'] === null) {
                $unreadable[$c['class'].'|'.$c['agent']] = ['agent' => $c['agent'], 'class' => $c['class']];
            } elseif (! $c['declared']) {
                $undeclared[$c['class'].'|'.$c['agent']] = ['agent' => $c['agent'], 'class' => $c['class']];
            }
            $agents[$c['agent']] = true;
        }

        return [
            'consumed' => array_keys($consumed),
            'bare' => array_keys($bare),
            'qualified' => array_map(
                static fn (array $actions): array => array_keys($actions),
                $qualified,
            ),
            'undeclared' => array_values($undeclared),
            'unreadable' => array_values($unreadable),
            'agents' => array_keys($agents),
        ];
    }
}
