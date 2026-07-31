<?php

namespace App\Bridge\Check\EventConsumers;

/**
 * One github scope's observed-vs-consumed reconciliation, AS DATA (DL-249 stage 9).
 *
 * THE ONE DERIVATION BEHIND TWO FRONT DOORS. `EventFollowsConsumerCheck` renders these
 * fields as operator prose; the `--format=json` document emits them verbatim. Neither
 * derives anything the other does not see, which is card#5229's binding constraint —
 * a second derivation is how the two surfaces start disagreeing, and the DL-223
 * `BoardToolDispatcher` split is the precedent this follows.
 *
 * WHY THE STRUCTURE IS THE DELIVERABLE, not a JSON encoding of the WARN text. Before
 * this, the only machine-readable form of the reconciliation was the WARN sentence, and
 * DL-236 had already REWORDED findings in this command. A consumer keyed on
 * *"but no enabled classifier consumes it"* breaks on the next rewording, and it breaks
 * toward a FALSE CLEAN: a parser that matches nothing reports nothing.
 *
 * THE TYPE/ACTION TIER IS THE POINT OF {@see $bare} + {@see $qualified}, not decoration.
 * A declaration is BARE (`issues` — the type is owned, every action covered) or
 * QUALIFIED (`issues.opened`). A consumer that flattens the two opens with hundreds of
 * findings on a healthy install — one fleet seat measured `labeled (468x), edited (14x)`
 * on a type whose bare declaration already covers them — which is the cry-wolf failure
 * the whole design exists to avoid.
 *
 * THE DECLARATION HALF IS COMPUTED EVEN WHEN NOTHING ARRIVED, and that is deliberate
 * rather than incidental: {@see $observed} can only ever show events that were both
 * SUBSCRIBED and DELIVERED, so it structurally cannot answer *"what does this scope's
 * classifier consume"* — which is the half a `/coord:update`-style consumer reads. The
 * text renderer still says nothing for a scope with no arrivals; the data does not
 * inherit that bound.
 */
final class EventConsumerScope
{
    /**
     * @param  string  $scope  the github scope id (a repo full_name)
     * @param  array<string, array{count: int, last: string}>  $observed  top-level event type ⇒ arrivals
     * @param  array<string, array<string, array{count: int, last: string}>>  $observedActions  top-level ⇒ action ⇒ arrivals; actionless types (`push`) never appear
     * @param  list<string>  $consumed  every declaration projected to its top level, unioned across the scope's consumers
     * @param  list<string>  $bare  the subset of {@see $consumed} some consumer declared WITHOUT an action
     * @param  array<string, list<string>>  $qualified  top-level ⇒ the actions declared for it, unioned
     * @param  list<array{agent: string, class: string}>  $undeclared  enabled classifiers that do not implement `DeclaresConsumedEvents`
     * @param  list<string>  $agents  the agents subscribed to this scope, deduplicated
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $observed,
        public readonly array $observedActions,
        public readonly array $consumed,
        public readonly array $bare,
        public readonly array $qualified,
        public readonly array $undeclared,
        public readonly array $agents,
    ) {}

    /**
     * Top-level types that ARRIVED and no consumer covers — the WARN population.
     *
     * In {@see $observed}'s order, which is the order the warns print in.
     *
     * @return list<string>
     */
    public function unconsumed(): array
    {
        return array_values(array_diff(array_keys($this->observed), $this->consumed));
    }

    /**
     * Actions that arrived on a type consumed ONLY via qualified declarations — the
     * informational action-tier rows, most-frequent first.
     *
     * A BARE declaration means the type is owned, so every action on it is covered and
     * the type is absent here. So is a type nothing consumes: that is
     * {@see self::unconsumed()}'s alarm, and reporting it twice at two tiers would be
     * the flattening this class exists to prevent.
     *
     * @return array<string, array<string, array{count: int, last: string}>>
     */
    public function unlistedActions(): array
    {
        $out = [];
        foreach ($this->observedActions as $top => $actions) {
            if (! in_array($top, $this->consumed, true) || in_array($top, $this->bare, true)) {
                continue;
            }
            $unlisted = array_diff_key($actions, array_flip($this->qualified[$top] ?? []));
            if ($unlisted === []) {
                continue;
            }
            uasort($unlisted, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $out[$top] = $unlisted;
        }

        return $out;
    }
}
