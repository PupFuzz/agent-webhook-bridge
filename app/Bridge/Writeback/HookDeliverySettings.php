<?php

namespace App\Bridge\Writeback;

/**
 * What the hooks that deliver to THIS install's receiver say about two delivery settings
 * (card#11283), folded over every such hook in one walk of a repo's hook list.
 *
 * ⛔ ONLY TWO BOOLEANS COME OUT, for {@see GitHubHookListAnswer}'s fleet-leak reason: the hook
 * list is the whole fleet's, and nothing read from a hook — its id, its URL, its event list — may
 * leave the client. Each is a property OF this install's hooks, not a value from them.
 *
 *  - `active`: is at least one matching hook active? A hook GitHub holds inactive delivers
 *    nothing.
 *  - `workflowRun`: does at least one ACTIVE matching hook send `workflow_run` — subscribed to it
 *    by name, or to every event (`*`)? That event is what settles a seat's `ci_await` without a
 *    poll.
 *
 * Either is null when a matching hook did not carry a readable `active` (a strict bool) or
 * `events` (a list) and no readable hook already answered `true` — an unknown is never read as
 * `false`.
 */
final class HookDeliverySettings
{
    private function __construct(
        public readonly ?bool $active,
        public readonly ?bool $workflowRun,
    ) {}

    /**
     * Fold one more matching hook (the raw list entry) into what the walk has seen so far.
     */
    public static function fold(?self $seen, mixed $hook): self
    {
        $active = is_array($hook) && is_bool($hook['active'] ?? null) ? $hook['active'] : null;
        $events = is_array($hook) ? ($hook['events'] ?? null) : null;
        $events = is_array($events) && array_is_list($events) ? $events : null;
        $sendsRun = $events === null ? null : (in_array('*', $events, true) || in_array('workflow_run', $events, true));
        // A hook that is not active sends nothing, whatever its events say.
        $thisRun = $active === false ? false : ($active === null ? null : $sendsRun);

        return new self(self::any($seen?->active, $active, $seen !== null), self::any($seen?->workflowRun, $thisRun, $seen !== null));
    }

    /** OR over three values: true wins, then an unknown, then false. */
    private static function any(?bool $soFar, ?bool $next, bool $hadOne): ?bool
    {
        if (! $hadOne) {
            return $next;
        }
        if ($soFar === true || $next === true) {
            return true;
        }

        return $soFar === null || $next === null ? null : false;
    }
}
