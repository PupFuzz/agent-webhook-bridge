<?php

namespace App\Bridge\Tools;

/**
 * A tool that acts only on the CALLING SEAT's own state and never on a board (card#11283) —
 * today `ci_await` and `ci_await_cancel`.
 *
 * ⛔ IT IS A MARKER WITH A SECOND ENTRY POINT, NOT A CHANGE TO {@see Tool}. `Tool::call()` keeps its
 * `App\Bridge\Writeback\KanbanClient` parameter, because an operator's custom tool registered against
 * {@see BoardToolsRegistry} implements that signature and would fatal if it moved.
 * {@see BoardToolDispatcher} calls {@see callAsSeat()} instead, without building a writeback
 * client: a seat with no board scope, on an install with no writeback token, must still be able
 * to wait for its CI.
 *
 * {@see ServedTools} reads this marker to decide who is served the tool: every enabled agent
 * that has not opted out of the CI tools, scoped or not.
 */
interface SelfScopedTool extends Tool
{
    /**
     * Run the tool for `$agentName`, the seat the front door authenticated. Same contract as
     * {@see Tool::call()} minus the board scope and the kanban client, which a self-scoped tool
     * never reads.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function callAsSeat(array $args, string $agentName): array;
}
