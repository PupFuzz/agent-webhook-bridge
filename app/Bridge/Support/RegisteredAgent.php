<?php

namespace App\Bridge\Support;

/**
 * One entry in agents.json — the cross-agent identity registry.
 * kanbanUserId matches kanban events' integer user_id; githubLogin matches
 * GitHub events' string sender.login. Either may be null.
 */
final class RegisteredAgent
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $kanbanUserId,
        public readonly string $scope = '',
        public readonly ?string $githubLogin = null,
    ) {}
}
