<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;

/**
 * THE RULE BEHIND {@see ServedTools}, with no registry in it (card#11283).
 *
 * Two kinds of tool, two predicates over one block:
 *  - a BOARD-SCOPED tool (every registered tool that is not a {@see SelfScopedTool}) is served to
 *    an enabled agent with a board scope;
 *  - a SELF-SCOPED tool (the CI tools) is served to an enabled agent that has not opted out
 *    (`board_tools.ci_tools`), scoped or not.
 *
 * ⚑ WHY IT IS ITS OWN CLASS. The card-take path (`SeatKanbanUser`) asks "who takes cards as this
 * seat", and `BoardTakeCardRefusalReasonCoverageTest` follows every class that path names; a path
 * that named the registry would pull every other tool into that census. The answer to "is
 * `board_take_card` served" is {@see servesBoardTools()} — the take is a board-scoped tool — so
 * the take path asks this rule directly, and {@see ServedTools} applies the same rule to each
 * registered tool. One rule, two shapes of question.
 */
final class ServedToolsRule
{
    public static function servesBoardTools(?BoardToolsConfig $bt): bool
    {
        return $bt !== null && $bt->enabled && ! $bt->isScopeless();
    }

    public static function servesCiTools(?BoardToolsConfig $bt): bool
    {
        return $bt !== null && $bt->enabled && $bt->ciTools;
    }

    /**
     * Whether `$agent` is served `board_take_card` — it writes a kanban assignee as its seat, so
     * its seat's kanban user must identify it. A scope-less agent is not, and is never counted
     * as a second taker on a seat it shares with a scoped one.
     */
    public static function takesCards(AgentConfig $agent): bool
    {
        return self::servesBoardTools($agent->boardTools);
    }

    /**
     * Seat name → the agents among `$configs` that take cards as that seat, in config order. The
     * seat is `identity.coord_seat`, else the agent name — the derivation
     * `App\Bridge\Support\AgentKanbanUsers::of()` uses.
     *
     * @param  list<AgentConfig>  $configs
     * @return array<string, list<string>>
     */
    public static function takersBySeat(array $configs): array
    {
        $bySeat = [];
        foreach ($configs as $config) {
            if (self::takesCards($config)) {
                $bySeat[$config->identity->seatName($config->agentName)][] = $config->agentName;
            }
        }

        return $bySeat;
    }
}
