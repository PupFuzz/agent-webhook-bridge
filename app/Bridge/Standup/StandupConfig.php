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
    ) {}

    public static function fromConfig(): self
    {
        $rawAgent = config('bridge.standup.agent');
        $agent = is_string($rawAgent) ? trim($rawAgent) : null;
        $interval = (int) config('bridge.standup.interval');

        return new self(
            enabled: (bool) config('bridge.standup.enabled'),
            agent: $agent === '' ? null : $agent,
            interval: $interval,
            problem: self::problemWith($rawAgent, $agent, $interval),
        );
    }

    public function isUsable(): bool
    {
        return $this->problem === null;
    }

    private static function problemWith(mixed $rawAgent, ?string $agent, int $interval): ?string
    {
        if ($rawAgent !== null && ! is_string($rawAgent)) {
            return 'standup.agent must be an agent name (a quoted string in .env) — a bare true/false is read as a boolean, not a name';
        }
        if ($agent === null || $agent === '') {
            return 'standup is enabled but standup.agent names no seat (BRIDGE_STANDUP_AGENT) — there is no default recipient for a fleet snapshot';
        }
        if (preg_match(self::AGENT_NAME, $agent) !== 1) {
            return "standup.agent '{$agent}' is not an agent name — it must match the <agent>.yml filename convention (letters, digits, '.', '_', '-')";
        }
        if ($interval < 1) {
            return "standup.interval must be a positive number of seconds, got {$interval}";
        }

        return null;
    }

    /** One-line operator summary of what this install will actually do. */
    public function summary(): string
    {
        return sprintf('push to %s, every %ds (on the first delivery after)', (string) $this->agent, $this->interval);
    }
}
