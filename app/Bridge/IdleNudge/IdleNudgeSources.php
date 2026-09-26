<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Support\AgentConfig;

/**
 * Where each declared agent's idle evidence comes from — derived once from the agent YAMLs, so
 * the job that reads it and the `bridge:check` leg that reports it cannot disagree about which
 * agents need Mezzanine.
 *
 * ⭐ AN AGENT DECLARING `idle_nudge.seat_record` IS SEAT-RECORD-SOURCED, and nothing about
 * Mezzanine or `route_intents` applies to it (rt#562). Every other agent is Mezzanine-sourced.
 *
 * ⭐ MEZZANINE IS NEEDED ONLY FOR A MEZZANINE-SOURCED AGENT THAT IS PUSH-ROUTED. Any other one
 * is `not_push_routed` whatever the snapshot says (DL-380 Decision 1), so an install with none
 * reads no snapshot and owes none of the `BRIDGE_IDLE_NUDGE_*` Mezzanine keys.
 */
final class IdleNudgeSources
{
    /**
     * @param  array<string, string>  $seatRecords  agent => its seat record path
     * @param  array<string, bool>  $mezzanine  agent => its `channel.route_intents`
     */
    private function __construct(
        public readonly array $seatRecords,
        public readonly array $mezzanine,
    ) {}

    /** @param  list<AgentConfig>  $configs */
    public static function of(array $configs): self
    {
        $seatRecords = [];
        $mezzanine = [];
        foreach ($configs as $config) {
            if ($config->idleNudgeSeatRecord !== null) {
                $seatRecords[$config->agentName] = $config->idleNudgeSeatRecord;
            } else {
                $mezzanine[$config->agentName] = $config->channel->routeIntents;
            }
        }

        return new self($seatRecords, $mezzanine);
    }

    public function mezzanineNeeded(): bool
    {
        return in_array(true, $this->mezzanine, true);
    }

    /** @return list<string> every declared agent */
    public function declared(): array
    {
        return array_map('strval', [...array_keys($this->seatRecords), ...array_keys($this->mezzanine)]);
    }
}
