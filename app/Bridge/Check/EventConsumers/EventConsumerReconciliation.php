<?php

namespace App\Bridge\Check\EventConsumers;

/**
 * One run's event-consumer reconciliation across every subscribed github scope
 * (DL-249 stage 9).
 *
 * {@see $error} IS PART OF THE VALUE, NOT AN EXCEPTION CHANNEL. The reconciler is
 * fail-soft — a DB hiccup must never break `bridge:check` — and a fail-soft producer
 * that returns only its successes hands every consumer an empty list that reads as
 * *"nothing to report"*. An empty {@see $scopes} with a non-null {@see $error} is a
 * measurement that did not happen; an empty one with a null error is a clean install.
 * A consumer that cannot tell those apart is the false-clean shape this whole program
 * exists to remove, so the two are separate fields rather than one.
 *
 * SCOPES COMPLETED BEFORE THE FAILURE ARE RETAINED, which is why the error does not
 * simply discard the run: the reconciliation walks scopes in order and the first throw
 * ends the walk, exactly as the inline loop it replaces did. The findings for the scopes
 * that got through are still owed to the operator.
 */
final class EventConsumerReconciliation
{
    /**
     * @param  list<EventConsumerScope>  $scopes  in `CheckContext::$githubScopeConsumers` order; truncated at the failing scope when {@see $error} is set
     * @param  string|null  $error  the message of the throwable that ended the walk, or null when every scope reconciled
     */
    public function __construct(
        public readonly array $scopes = [],
        public readonly ?string $error = null,
    ) {}
}
