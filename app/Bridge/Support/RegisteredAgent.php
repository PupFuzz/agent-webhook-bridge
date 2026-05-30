<?php

namespace App\Bridge\Support;

/**
 * One entry in agents.json — the cross-agent identity registry.
 *
 * Recognition keys on IMMUTABLE numeric ids: kanbanUserId matches kanban
 * events' integer user_id; githubUserId matches GitHub events' numeric
 * sender.id. githubLogin is a display-only label (GitHub usernames are
 * renameable, so they must never be a matching key — see DL-002); it drives
 * the "edited by <login>" surface text and the stale-login drift warning.
 */
final class RegisteredAgent
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $kanbanUserId,
        public readonly string $scope = '',
        public readonly ?int $githubUserId = null,
        public readonly ?string $githubLogin = null,
    ) {}
}
