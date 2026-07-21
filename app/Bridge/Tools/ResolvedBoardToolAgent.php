<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\BoardToolsConfig;

/**
 * The agent a board-tools bearer resolved to (DL-217): the canonical agent name
 * (DERIVED from the token, never read from the request body) plus its resolved
 * board_tools scope. Produced only by {@see BoardToolAgentResolver::resolve} on a
 * hash_equals match against a non-colliding, readable roster entry.
 */
final class ResolvedBoardToolAgent
{
    public function __construct(
        public readonly string $agentName,
        public readonly BoardToolsConfig $config,
    ) {}
}
