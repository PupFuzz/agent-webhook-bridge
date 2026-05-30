<?php

namespace App\Bridge\Support;

/**
 * A provider account shared by more than one agent, declared once in
 * agents.json under `shared_identities` rather than denormalized across every
 * agent entry. Events from a shared account cannot be attributed to a single
 * agent by identity alone, so recognition intentionally yields Actor.name =
 * null and defers to a custom classifier (e.g. a FROM:-line / repo-scope
 * re-attribution layer). See DL-002.
 *
 * Keyed on the immutable numeric githubUserId; githubLogin is a display-only
 * label for the stale-login drift warning.
 */
final class SharedIdentity
{
    /**
     * @param  list<string>  $agentNames  the agents that share this account (declared intent; documents which entries the shared login maps to)
     */
    public function __construct(
        public readonly int $githubUserId,
        public readonly ?string $githubLogin,
        public readonly array $agentNames,
    ) {}
}
