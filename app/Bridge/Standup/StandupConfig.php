<?php

namespace App\Bridge\Standup;

use App\Bridge\Retention\RetentionConfig;

/**
 * The resolved, validated `bridge.standup.*` posture (DL-306) — the sibling of
 * {@see RetentionConfig}, and for the same reason: there are two
 * readers (the gate that acts on it and `bridge:standup` that reports it), and a
 * second copy of the rules would let the command cheerfully report a posture the
 * receiver is not running.
 *
 * A misconfigured posture pushes NOTHING. There is no partial digest and no default
 * recipient: the one destructive direction for a report is to send a fleet snapshot
 * to whoever a fat-fingered name happens to resolve to.
 */
final class StandupConfig
{
    /**
     * The agent-name shape this will build a `<config_dir>/<agent>.yml` path from.
     * A name is operator-written in `.env`, and `AgentConfig::load()` concatenates it
     * into a path — so `../other-install/pm` would read a YAML outside the config dir
     * and push this install's snapshot at whatever channel it names. The `.yml`
     * filename convention is the whole naming rule, so pinning it here costs nothing.
     */
    private const AGENT_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $agent,
        public readonly int $interval,
        /** Why this config pushes nothing, in operator vocabulary; null when usable. */
        public readonly ?string $problem,
        /**
         * True when the reason is that nobody named a recipient, AND the recipient is the ONLY
         * problem, AND nobody wrote `BRIDGE_STANDUP_ENABLED` at all — the NOT-SET-UP
         * state every install starts in since the digest ships on (card#10918 / DL-441), as
         * opposed to a value somebody set wrongly, an ALSO-bad interval, or an operator who
         * explicitly turned the digest on and stopped short of naming a recipient (the third is
         * MISCONFIGURED like the second: an explicit enable, in any spelling, is an act, not a
         * default nobody touched — review round 1 of card#10918 caught this collapsing the two).
         */
        public readonly bool $recipientUnset,
    ) {}

    public static function fromConfig(): self
    {
        $rawAgent = config('bridge.standup.agent');
        $agent = is_string($rawAgent) ? trim($rawAgent) : null;
        $interval = (int) config('bridge.standup.interval');
        $explicit = config('bridge.standup.enabled_explicit');

        $agentUnset = $rawAgent === null || $agent === '';
        // `$explicit` is null only when nobody wrote BRIDGE_STANDUP_ENABLED; `env()` passes
        // `1`/`yes`/`on` through as strings, so "explicitly set" is `!== null`, not `=== true`.
        $recipientUnset = $agentUnset
            && $interval >= 1
            && $explicit === null;

        return new self(
            enabled: (bool) config('bridge.standup.enabled'),
            agent: $agent === '' ? null : $agent,
            interval: $interval,
            problem: self::problemWith($rawAgent, $agent, $interval),
            recipientUnset: $recipientUnset,
        );
    }

    public function isUsable(): bool
    {
        return $this->problem === null;
    }

    /**
     * ⚑ EVERY APPLICABLE REASON IS REPORTED, INTERVAL FIRST. The interval and the recipient are
     * independent axes, so each contributes its own reason and `problem` joins them with `; ` —
     * the composition `IdleNudgeConfig::fromConfig()` uses. Returning on the first hit
     * (review round 4, card#10918) left the second problem to surface only on the next run,
     * after the operator fixed the first. Within the recipient axis exactly ONE reason is
     * reported — not a string, then unset, then malformed — because all three describe the
     * same unusable value.
     *
     * The interval leads, and `recipientUnset` stays gated on the interval also being valid
     * (review round 1): an earlier cut let an unset recipient claim the friendlier NOT-SET-UP
     * `warn`, which never mentions the interval, so a bad interval SURVIVED with nothing
     * pointing at it. A bad interval therefore always drops the recipient-unset branch to the
     * MISCONFIGURED one that reads `problem` back.
     */
    private static function problemWith(mixed $rawAgent, ?string $agent, int $interval): ?string
    {
        $reasons = [];
        if ($interval < 1) {
            $reasons[] = "standup.interval must be a positive number of seconds, got {$interval}";
        }
        $agentReason = match (true) {
            $rawAgent !== null && ! is_string($rawAgent) => 'standup.agent must be an agent name (a quoted string in .env) — a bare true/false is read as a boolean, not a name',
            $agent === null || $agent === '' => 'standup is enabled but standup.agent names no seat (BRIDGE_STANDUP_AGENT) — there is no default recipient for a fleet snapshot',
            preg_match(self::AGENT_NAME, $agent) !== 1 => "standup.agent '{$agent}' is not an agent name — it must match the <agent>.yml filename convention (letters, digits, '.', '_', '-')",
            default => null,
        };
        if ($agentReason !== null) {
            $reasons[] = $agentReason;
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /** One-line operator summary of what this install will actually do. */
    public function summary(): string
    {
        return sprintf('push to %s, every %ds (on the first delivery after)', (string) $this->agent, $this->interval);
    }
}
