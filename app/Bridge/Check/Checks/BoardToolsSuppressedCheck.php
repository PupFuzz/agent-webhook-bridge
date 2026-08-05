<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;

/**
 * A DEFAULT-on `board_tools` block that could not satisfy itself (DL-217 default-ON per
 * v7), migrated out of `CheckCommand::checkBoardTools()` (DL-242 stage 7b).
 *
 * WHY IT FAILS RATHER THAN WARNS. A default-class block that cannot be satisfied
 * SUPPRESSES instead of throwing at load — one under-configured agent must never 5xx the
 * whole fleet through `SubscriptionRegistry` (see `BoardToolsConfig`'s classification).
 * The cost of that choice is that the agent's board tools are silently OFF, so the loud
 * half has to happen here: `bridge:check` FAILs, which is the contract the suppress path
 * was designed against.
 *
 * IT READS EVERY CONFIG, NOT THE ENABLED SUBSET, AND THAT IS THE WHOLE POINT. A suppressed
 * block resolves to `enabled === false`, so it is absent from
 * {@see CheckContext::$boardToolsEnabled} and every other check in this plane skips it in
 * silence. On a fleet whose ONLY board-tools agent is suppressed that subset is EMPTY —
 * the inline code ran this scan before its own `$enabled === []` early return for exactly
 * that reason, and the slot ordering ({@see CheckSlot::BoardToolsSuppression} before
 * {@see CheckSlot::BoardToolsBearer}) is what preserves it.
 */
final class BoardToolsSuppressedCheck implements Check
{
    public function id(): string
    {
        return 'board_tools.suppressed';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        foreach ($ctx->configs as $config) {
            $bt = $config->boardTools;
            if ($bt === null || $bt->suppressedReason === null) {
                continue;
            }

            yield Finding::fail("board_tools: agent {$config->agentName}: {$bt->suppressedReason} — board tools are OFF for this agent (a default-on block could not be satisfied). Fix the config, or set enabled: false to stage it silently.");
        }

        yield Silence::because('no config carries a default-on board_tools block that could not satisfy itself — the scan covers every config, including a fleet with no board_tools at all');
    }
}
