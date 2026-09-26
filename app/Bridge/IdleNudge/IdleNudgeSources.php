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
 *
 * ⛔ ONE SEAT'S RECORD WAKES AT MOST ONE CHANNEL (DL-424 Decision 9). Two seat-record agents
 * whose {@see recordAgentOf()} is the same name both claim that seat — a copied `idle_nudge:`
 * block, or a YAML named for the seat beside one adopting it through `seat_agent` — and each
 * would deliver its prompt. Every agent in such a claim is found here ({@see $seatClaims}), and
 * the pass refuses each by name without reading its record. Keyed on the compared NAME, not the
 * path: a record carries one `agent`, so two agents reading one file under different names can
 * never both match it, and the path is resolved only at use ({@see SeatRecordPath}).
 *
 * ⚠ SEAT-RECORD AGENTS ONLY. A push-routed Mezzanine-sourced agent is not a claimant, so a
 * Mezzanine agent named for a seat beside a seat-record agent adopting it could wake that seat
 * on two channels — once Mezzanine publishes seat names (card#9375); until then it cannot occur.
 * Out of scope until card#9375 lands; the reopen condition is DL-424 Decision 9's.
 */
final class IdleNudgeSources
{
    /**
     * seat-record agent => every seat-record agent (itself included, sorted) claiming the same
     * seat; present only for an agent in a claim of two or more.
     *
     * @var array<string, list<string>>
     */
    public readonly array $seatClaims;

    /**
     * @param  array<string, string>  $seatRecords  agent => its seat record path AS DECLARED ({@see SeatRecordPath::resolve()} at use)
     * @param  array<string, bool>  $mezzanine  agent => its `channel.route_intents`
     * @param  array<string, string>  $seatAgents  seat-record agent => its `idle_nudge.seat_agent`, where declared
     */
    private function __construct(
        public readonly array $seatRecords,
        public readonly array $mezzanine,
        public readonly array $seatAgents,
    ) {
        $claimants = [];
        foreach (array_keys($seatRecords) as $agent) {
            $claimants[$this->recordAgentOf((string) $agent)][] = (string) $agent;
        }
        $claims = [];
        foreach ($claimants as $agents) {
            if (count($agents) > 1) {
                sort($agents);
                foreach ($agents as $agent) {
                    $claims[$agent] = $agents;
                }
            }
        }
        $this->seatClaims = $claims;
    }

    /** @param  list<AgentConfig>  $configs */
    public static function of(array $configs): self
    {
        $seatRecords = [];
        $mezzanine = [];
        $seatAgents = [];
        foreach ($configs as $config) {
            if ($config->idleNudgeSeatRecord !== null) {
                $seatRecords[$config->agentName] = $config->idleNudgeSeatRecord;
                if ($config->idleNudgeSeatAgent !== null) {
                    $seatAgents[$config->agentName] = $config->idleNudgeSeatAgent;
                }
            } else {
                $mezzanine[$config->agentName] = $config->channel->routeIntents;
            }
        }

        return new self($seatRecords, $mezzanine, $seatAgents);
    }

    /**
     * The `agent` a seat-record agent's record must carry: its `idle_nudge.seat_agent` where
     * declared, else the bridge agent name (DL-424 Decision 9).
     */
    public function recordAgentOf(string $agent): string
    {
        return $this->seatAgents[$agent] ?? $agent;
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
