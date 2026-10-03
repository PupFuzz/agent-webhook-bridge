<?php

namespace App\Bridge\Support;

/**
 * One agent in the identity registry, derived from a per-agent YAML's `identity`
 * block (the registry is built by scanning the YAMLs — there is no agents.json).
 *
 * Recognition keys on IMMUTABLE numeric ids: githubUserId matches GitHub events'
 * numeric sender.id. githubLogin is a display-only label (GitHub usernames are
 * renameable, so they must never be a matching key — see DL-002); it drives the
 * stale-login drift warning. The agent's kanban user id is NOT carried here: it
 * comes from the coord roster and reaches {@see AgentRegistry} as its own input
 * (DL-450).
 */
final class RegisteredAgent
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $githubUserId = null,
        public readonly ?string $githubLogin = null,
    ) {}
}
