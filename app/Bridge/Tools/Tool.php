<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;

/**
 * A channel-identity-scoped board tool (DL-217) invoked over the two-way agent
 * channel. It is reached through MORE THAN ONE front door and this deliberately
 * describes none of them: {@see BoardToolDispatcher} is what every door funnels
 * into, and it owns how each resolves the calling agent. What is common to all —
 * and all a tool may rely on — is that the agent is resolved BEFORE dispatch and
 * its config arrives as $cfg; a tool never sees the transport or the credential.
 *
 * The write scope is NOT the caller's to choose — it is the resolved agent's
 * {@see BoardToolsConfig} (swimlane_id / board_id / create_stage_id), so a tool
 * only ever reads/writes the lane the operator minted the token for. Args carry
 * only the caller-supplied content (a title, a description); anything that names
 * scope is ignored or refused.
 */
interface Tool
{
    /** The MCP tool name (also the `tool` key POST /agent-tools/call dispatches on). */
    public function name(): string;

    /**
     * Run the tool. `$args` is the caller-supplied argument object (already
     * decoded); `$cfg` is the resolved agent's board_tools scope; `$client` is
     * the shared least-privilege writeback client (the kanban token never leaves
     * the bridge). Returns the JSON-serializable result the caller receives
     * verbatim. Throws {@see ToolRefusalException} on a
     * caller-fixable bad request (422-class).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array;
}
