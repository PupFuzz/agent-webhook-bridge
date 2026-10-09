<?php

namespace App\Bridge\Writeback;

/**
 * What one walk of a repo's webhook list established (card#9717): the three-way verdict
 * {@see GitHubReadClient::hasRepoWebhookFor()} has always answered, plus — on the one path that
 * earns it — HOW MANY hooks the repo carries.
 *
 * ⛔ A COUNT — AND, SINCE card#11283, BOOLEANS ABOUT THIS INSTALL'S OWN HOOKS — IS ALL IT WILL
 * EVER CARRY BESIDE THE VERDICT, and that is the fleet-leak
 * boundary rather than a shape preference. The hook list this is derived from is the WHOLE
 * FLEET's: every other install's receiver endpoint is in it. The match is made inside the client
 * and only a verdict comes out, which is what makes *no other endpoint can reach an operator log,
 * a finding or a traceback* true BY CONSTRUCTION (CLAUDE_DECISIONS.md DL-368 Decision 6). An
 * `int` preserves that exactly — it is a property of the list and not a value FROM it, so no
 * interpolation of this object anywhere can publish the fleet. A field holding a URL, a host, an
 * id or a list of any of them would put the guarantee back on every consumer's discipline, and
 * the first consumer to print it would publish the fleet. Adding one is not a widening of this
 * class; it is the removal of the reason it exists.
 *
 * ⚠ THE COUNT IS MEANINGFUL ONLY ON THE EXHAUSTED PATH, which is why {@see self::found()} takes
 * none. A hit returns mid-walk, so a count there would be *hooks seen before the match* wearing
 * the name *hooks on the repo* — a half-truth, and the kind a consumer cannot tell from the whole
 * one. The undetermined path establishes nothing at all, including a count.
 */
final class GitHubHookListAnswer
{
    private function __construct(
        /**
         * Does a hook on this repo deliver to the receiver URL asked about? `null` is the third
         * answer — THIS READ ESTABLISHED NEITHER — and {@see GitHubReadClient::hasRepoWebhookFor()}
         * owns why collapsing it into `false` would convict a healthy install.
         */
        public readonly ?bool $found,
        /**
         * How many webhooks the repo carries, counted by an enumeration that RAN TO THE END.
         * Null on every other verdict, because no other verdict counted the whole list.
         */
        public readonly ?int $hookCount = null,
        /**
         * card#11283, set on {@see found()} only: is at least one hook delivering to this receiver
         * ACTIVE? Null when it could not be read, and on every other verdict. It never changes
         * {@see $found}, which still means exactly "a hook delivers here".
         */
        public readonly ?bool $active = null,
        /**
         * card#11283, set on {@see found()} only: does at least one ACTIVE hook delivering here
         * send `workflow_run` (by name or as `*`)? Null when it could not be read — including when
         * the walk past the match was cut short — and on every other verdict.
         */
        public readonly ?bool $workflowRun = null,
        /** card#11579, set on {@see found()} only: {@see HookDeliverySettings::$json}. */
        public readonly ?bool $json = null,
        /** card#11579, set on {@see found()} only: {@see HookDeliverySettings::$lastDelivery2xx}. */
        public readonly ?bool $lastDelivery2xx = null,
    ) {}

    /**
     * A hook delivering to the asked-about receiver is on the list. `$settings` is
     * {@see HookDeliverySettings} folded over every such hook the walk read — booleans about THIS
     * install's hooks, which is why they may sit beside the verdict when a URL may not. Null when
     * the walk past the match was cut short: every setting is then unknown.
     */
    public static function found(?HookDeliverySettings $settings = null): self
    {
        return new self(true, null, $settings?->active, $settings?->workflowRun, $settings?->json, $settings?->lastDelivery2xx);
    }

    /**
     * The list was read TO THE END and no hook on it delivers to the asked-about receiver —
     * and it holds `$hookCount` hooks, which is what tells a genuinely unwired repo from one
     * whose hooks all belong to something else (card#9717).
     */
    public static function exhausted(int $hookCount): self
    {
        return new self(false, $hookCount);
    }

    /** This read established neither — a body that was not a hook list, an unreadable entry, or the page bound. */
    public static function undetermined(): self
    {
        return new self(null);
    }
}
