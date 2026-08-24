<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\AgentScopeCoverage;
use App\Bridge\Check\CheckContext;
use Tests\TestCase;

/**
 * The ledger behind card#5698's scope-map disclosures.
 *
 * WHAT IS DELIBERATELY NOT HERE: the four consuming legs' sentences, and the two abort
 * sites that fill this. Both are measured at the real command surface by
 * `CheckGoldenTest`'s `writeback-move-leg-agent-unread` (all three disclosures fire) and
 * `writeback-orphan-survives-unrelated-unread-agent` (the accusation survives an unrelated
 * abort). Re-asserting them against a hand-built ledger would prove the sentences while
 * saying nothing about whether `CheckCommand` still records what they describe.
 *
 * WHAT IS ONLY HERE is the discrimination those fixtures cannot vary cheaply: null-vs-empty
 * scopes, and the multi-agent phrasing. A golden fixture per shape would be a full command
 * run each.
 */
class AgentScopeCoverageTest extends TestCase
{
    public function test_an_empty_ledger_is_a_complete_one(): void
    {
        $coverage = new AgentScopeCoverage;

        $this->assertTrue($coverage->isComplete());
        $this->assertFalse($coverage->mayCover('owner/repo'));
        $this->assertSame([], $coverage->unreadAgents());
    }

    public function test_a_fresh_check_context_starts_complete(): void
    {
        // The reason the field is non-nullable and constructor-promoted: every check that
        // consults it does so unconditionally, so a hand-built context — which is what
        // every unit test in this suite passes — must answer "nothing is missing" rather
        // than force each consumer to decide what a null would have meant.
        $this->assertTrue((new CheckContext)->agentScopeCoverage->isComplete());
    }

    public function test_an_agent_whose_config_did_not_parse_casts_doubt_on_every_scope(): void
    {
        // The config-load abort: the load is what WOULD have said which scopes it covers,
        // so there is no scope this agent can be ruled out of.
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('prod-agent', null);

        $this->assertFalse($coverage->isComplete());
        $this->assertTrue($coverage->mayCover('owner/repo'));
        $this->assertTrue($coverage->mayCover('any/other-repo'));
    }

    public function test_an_agent_that_parsed_casts_doubt_only_on_the_scopes_it_subscribes_to(): void
    {
        // The classifier-gate abort: `$cfg` exists, so the subscription list is a real
        // answer. This is the whole reason the ledger stores a set instead of a count — one
        // broken agent must not soften an unrelated mapping's finding.
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('prod-agent', ['owner/repo']);

        $this->assertTrue($coverage->mayCover('owner/repo'));
        $this->assertFalse($coverage->mayCover('other/repo'));
    }

    public function test_an_empty_scope_list_is_an_answer_and_not_a_shrug(): void
    {
        // An agent subscribed to no github scope at all (a kanban-only agent) cannot be the
        // missing driver of ANY github scope. Conflating this with the null above — the
        // easy implementation — would make every kanban-only agent's abort invalidate every
        // writeback finding on the install.
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('kanban-only', []);

        $this->assertFalse($coverage->isComplete(), 'the run is still short an agent');
        $this->assertFalse($coverage->mayCover('owner/repo'), 'but not short one that could cover this scope');
    }

    public function test_the_gap_clause_names_every_unread_agent_that_could_cover_the_scope(): void
    {
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('alpha', ['owner/repo']);
        $coverage->recordUnread('beta', ['other/repo']);
        $coverage->recordUnread('gamma', null);

        $this->assertSame(['alpha', 'gamma'], $coverage->unreadCovering('owner/repo'));
        $this->assertSame(
            'agents alpha, gamma were not read to completion this run (see the error(s) above)',
            $coverage->gapClause('owner/repo'),
        );
        // `gamma`'s null reaches every scope, so it joins `beta` here too — which is the
        // point of asserting a second scope rather than trusting the first.
        $this->assertSame(['beta', 'gamma'], $coverage->unreadCovering('other/repo'));
    }

    public function test_the_gap_clause_is_singular_for_one_agent(): void
    {
        // Not a formatting nicety — it is the shape every one of the golden captures
        // renders, so a regression here rewrites two committed fixtures.
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('prod-agent', null);

        $this->assertSame(
            'agent prod-agent was not read to completion this run (see the error(s) above)',
            $coverage->gapClause('owner/repo'),
        );
    }

    public function test_the_json_ledger_keeps_null_and_empty_apart(): void
    {
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('alpha', null);
        $coverage->recordUnread('beta', []);

        $this->assertSame(
            [
                ['agent' => 'alpha', 'scopes' => null],
                ['agent' => 'beta', 'scopes' => []],
            ],
            $coverage->unreadAgents(),
        );
    }

    public function test_a_recorded_scope_covers_a_differently_cased_spelling_of_the_same_repo(): void
    {
        // DL-293: the recorded scope is the agent YAML's spelling and the caller asks with
        // writeback.json's, which for one repo may differ. A raw compare would answer
        // "nothing unread covers this scope" for an agent that subscribes to exactly it —
        // turning a CANNOT-VERIFY back into the confident accusation card#5698 removed.
        $coverage = new AgentScopeCoverage;
        $coverage->recordUnread('alpha', ['Owner/Repo']);

        $this->assertSame(['alpha'], $coverage->unreadCovering('owner/repo'));
        $this->assertTrue($coverage->mayCover('OWNER/REPO'));
        $this->assertFalse($coverage->mayCover('owner/other'));
        // The RECORDED spelling is untouched — it is rendered verbatim in the JSON ledger.
        $this->assertSame([['agent' => 'alpha', 'scopes' => ['Owner/Repo']]], $coverage->unreadAgents());
    }
}
