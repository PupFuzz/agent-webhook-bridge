<?php

namespace App\Bridge\Contracts;

use App\Bridge\Support\ClassifierConfig;

/**
 * A {@see Classifier} that declares the GitHub event types it consumes for a
 * given config. An entry is either BARE — `pull_request`, `push`, `issues` (the
 * type is OWNED: every `.action` is covered, unlisted actions are deliberate
 * no-ops) — or QUALIFIED — `issues.opened` (card #4354): the classifier consumes
 * exactly the listed actions, and `bridge:check` may surface OTHER observed
 * actions of that type as an informational action inventory. `bridge:check`
 * projects both forms to the top level (the granularity the GitHub hook
 * `events[]` keys on) and warns (never fails) when an event type has ARRIVED for
 * a scope but nothing consumes it — the event is silently dropped on arrival
 * (card#4183 / DL-196). The action inventory is INFO, never a warn: GitHub has
 * no per-action unsubscribe, so there is no remedy to alarm about. Sibling of
 * {@see EmitsWritebackReactions}: the same "is there code that activates this
 * config artifact?" question, asked of a subscribed/arriving event.
 *
 * HARD CONTRACT — `consumedEventTypes()` MUST be a pure `$cfg` → event-types map:
 * no lazy class-loading, no side effects, no I/O. It runs INSIDE `bridge:check`,
 * on an instance already gated by `ClassifierResolver::probeLoadable` (DL-025) — a
 * `catch (\Throwable)` guards the call, but a compile error is NOT a `Throwable`,
 * so any impl that lazy-loads an un-probed dependency can fatal PAST the catch and
 * kill the check itself. `probeLoadable` guarantees only that THIS class compiles;
 * the pure-map contract is what extends that guarantee to cover this call without
 * an out-of-process value probe. The reference impls are immune (static maps).
 *
 * Implemented by classifiers, never instantiated for the declaration. The
 * implementing check ({@see EmitsWritebackReactions}) is detected OUT OF PROCESS
 * (ClassifierResolver::probeImplements) so a stale/incompatible custom classifier
 * can't E_COMPILE_ERROR-kill the check (DL-025).
 */
interface DeclaresConsumedEvents
{
    /**
     * The GitHub event types this classifier consumes under `$cfg` — bare
     * (`['pull_request', 'push']`, type owned) and/or qualified
     * (`['issues.opened', 'issues.reopened']`, exactly these actions; card
     * #4354). MUST be a pure map — see the class docblock's HARD CONTRACT.
     *
     * @return list<string>
     */
    public function consumedEventTypes(ClassifierConfig $cfg): array;
}
