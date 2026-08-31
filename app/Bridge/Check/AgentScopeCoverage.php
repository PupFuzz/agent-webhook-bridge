<?php

namespace App\Bridge\Check;

/**
 * Which agents this run never finished reading — the fact that decides whether a
 * scope-keyed NEGATIVE taken from {@see CheckContext}'s five scope maps is evidence
 * (card#5698).
 *
 * THE DEFECT THIS EXISTS TO REMOVE. `CheckCommand`'s per-agent loop aborts an agent in two
 * places — its YAML did not parse, or its classifier did not resolve — and BOTH `continue`
 * before the agent's subscriptions reach {@see CheckContext::$writebackEmittingScopes},
 * {@see CheckContext::$coordCardCreateScopes}, {@see CheckContext::$coordCardMoveScopes},
 * {@see CheckContext::$coordCardRelaneScopes} or {@see CheckContext::$githubScopeConsumers}.
 * Every consumer of those maps then reads "this scope is absent" as "the operator did not
 * enable it" and prints a confident config accusation — telling the operator to add an
 * agent when the agent exists and a line further up already named the real fault. The maps
 * cannot express the difference, which is why the fact lives beside them rather than as a
 * guard at each call site: N call-site patches leave the N+1th consumer to re-mint it.
 *
 * IT IS NOT A COUNT OF ABORTS. What a consumer needs is not "did anything go wrong" but
 * "could the agent I never read have covered THIS scope", and the two aborts answer that
 * differently. A classifier abort happens with `$cfg` already parsed, so the agent's github
 * subscriptions are KNOWN and an unrelated scope's negative stays trustworthy; a config-load
 * abort knows only a filename, so every scope is in doubt. Recording the known set where
 * there is one is what keeps a single broken YAML from erasing a healthy install's findings.
 *
 * THE SEVERITY THIS FEEDS NEVER MOVES THE EXIT CODE, and that is true by construction, not
 * by convention: both abort sites already set `$ok = false` before recording here, so a run
 * with an incomplete roster is a failing run whatever the legs downstream say about it.
 */
final class AgentScopeCoverage
{
    /**
     * @var list<array{agent: string, scopes: ?list<string>}> `scopes` is null when the
     *                                                        agent's config never parsed, so which scopes it subscribes to is itself unknown
     */
    private array $unread = [];

    /**
     * Record an agent whose config was not read to the point where its subscriptions
     * reach the scope maps.
     *
     * @param  ?list<string>  $githubScopes  the github scope ids the agent subscribes to,
     *                                       or null when the config did not parse at all. An EMPTY list is a real
     *                                       answer — an agent with no github subscription cannot be the missing
     *                                       driver of any github scope — and is deliberately not conflated with null.
     */
    public function recordUnread(string $agent, ?array $githubScopes): void
    {
        $this->unread[] = ['agent' => $agent, 'scopes' => $githubScopes];
    }

    /**
     * Did every agent config reach the scope-map derivations?
     *
     * True on a healthy install AND on a hand-built {@see CheckContext} — an empty ledger
     * is a complete one, which is why the field it lives on is not nullable.
     */
    public function isComplete(): bool
    {
        return $this->unread === [];
    }

    /**
     * The unread agents that could have contributed $scope, in the order they aborted.
     *
     * @return list<string>
     */
    public function unreadCovering(string $scope): array
    {
        // Compared by repo IDENTITY (DL-293): the recorded scopes are agent-YAML spellings
        // and the caller asks with a writeback.json spelling, which for one repo may
        // differ. A raw compare answers "no unread agent covers this scope" for an agent
        // that subscribes to exactly it — and that answer is what promotes a CANNOT-VERIFY
        // back into a confident config accusation, the one direction card#5698 exists to
        // stop. The RECORDED spelling stays raw: it is rendered verbatim in the
        // `--format=json` document's `unread_agents[].scopes`.
        $canonical = CheckContext::canonicalScope($scope);
        $names = [];
        foreach ($this->unread as $entry) {
            if ($entry['scopes'] === null || in_array($canonical, array_map(CheckContext::canonicalScope(...), $entry['scopes']), true)) {
                $names[] = $entry['agent'];
            }
        }

        return $names;
    }

    /**
     * Is a NEGATIVE about $scope taken from one of the scope maps unproven?
     *
     * The question every consumer asks, and the reason none of them index `unread`
     * themselves: an absent scope is evidence only when nothing unread could have supplied
     * it.
     */
    public function mayCover(string $scope): bool
    {
        return $this->unreadCovering($scope) !== [];
    }

    /**
     * The shared WHY clause, for a message whose leg could not answer because of $scope's
     * unread agents.
     *
     * ONE PHRASING FOR EVERY LEG, and it deliberately does NOT restate the cause: the
     * config error or the classifier error is its own finding, printed above this one, and
     * a second copy here would be a diagnosis that can disagree with the one that owns it.
     */
    public function gapClause(string $scope): string
    {
        $names = $this->unreadCovering($scope);
        $subject = count($names) === 1
            ? "agent {$names[0]} was"
            : 'agents '.implode(', ', $names).' were';

        return "{$subject} not read to completion this run (see the error(s) above)";
    }

    /**
     * The ledger, for the `--format=json` document.
     *
     * @return list<array{agent: string, scopes: ?list<string>}>
     */
    public function unreadAgents(): array
    {
        return $this->unread;
    }
}
