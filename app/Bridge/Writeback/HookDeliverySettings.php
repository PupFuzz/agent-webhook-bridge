<?php

namespace App\Bridge\Writeback;

/**
 * What the hooks that deliver to THIS install's receiver say about their delivery settings
 * (card#11283; card#11579 added the last two), folded over every such hook in one walk of a repo's
 * hook list.
 *
 * ⛔ ONLY BOOLEANS COME OUT, for {@see GitHubHookListAnswer}'s fleet-leak reason: the hook
 * list is the whole fleet's, and nothing read from a hook — its id, its URL, its event list — may
 * leave the client. Each is a property OF this install's hooks, not a value from them.
 *
 *  - `active`: is at least one matching hook active? A hook GitHub holds inactive delivers
 *    nothing.
 *  - `workflowRun`: does at least one ACTIVE matching hook send `workflow_run` — subscribed to it
 *    by name, or to every event (`*`)? That event is what settles a seat's `ci_await` without a
 *    poll.
 *  - `json`: does at least one ACTIVE matching hook send `config.content_type` `json`? The receiver
 *    parses the body as JSON, so a `form` hook's every delivery is refused (`invalid_envelope`).
 *  - `lastDelivery2xx`: did at least one ACTIVE matching hook's MOST RECENT delivery get a 2xx
 *    (`last_response.code`)? GitHub reports a hook that has never delivered as `code: null`, which
 *    is `false` here: no 2xx has been seen. This is the LAST delivery, not a recency — how long ago
 *    is `github.delivery_history`'s question, off this install's own record.
 *
 * Each is null when a matching hook did not carry the field readably (`active` a strict bool,
 * `events` a list, `content_type` a string, `last_response` an object carrying `code`) and no
 * readable hook already answered `true` — an unknown is never read as `false`. Each is folded on its
 * own, so two hooks whose defects complement each other read as one healthy hook; a repo holding two
 * hooks to one receiver is the case where that bound bites.
 */
final class HookDeliverySettings
{
    private function __construct(
        public readonly ?bool $active,
        public readonly ?bool $workflowRun,
        public readonly ?bool $json,
        public readonly ?bool $lastDelivery2xx,
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
        $config = is_array($hook) ? ($hook['config'] ?? null) : null;
        $contentType = is_array($config) && is_string($config['content_type'] ?? null) ? $config['content_type'] : null;
        $sendsJson = $contentType === null ? null : $contentType === 'json';
        $last = is_array($hook) ? ($hook['last_response'] ?? null) : null;
        $code = is_array($last) && array_key_exists('code', $last) ? $last['code'] : false;
        $delivered2xx = match (true) {
            $code === null => false,
            is_int($code) => $code >= 200 && $code < 300,
            default => null,
        };
        // A hook that is not active sends nothing, whatever its other settings say.
        $ifActive = static fn (?bool $setting): ?bool => $active === false ? false : ($active === null ? null : $setting);
        $had = $seen !== null;

        return new self(
            self::any($seen?->active, $active, $had),
            self::any($seen?->workflowRun, $ifActive($sendsRun), $had),
            self::any($seen?->json, $ifActive($sendsJson), $had),
            self::any($seen?->lastDelivery2xx, $ifActive($delivered2xx), $had),
        );
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
