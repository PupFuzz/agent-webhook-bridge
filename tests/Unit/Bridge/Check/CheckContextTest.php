<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\CheckContext;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Support\AgentConfig;
use Tests\TestCase;

/**
 * The coord-card family scope-map derivation, at the primitive that now owns it (card#8305).
 *
 * WHY IT NEEDED A TEST WHEN THE HOIST CHANGED NO BEHAVIOR. The three blocks this replaced
 * lived inside `CheckCommand::handle()`, so the only instrument that could see them was the
 * golden corpus — which enables exactly ONE of the three families in exactly one fixture
 * (`coord-card-move`). The create and relane halves of the derivation were reachable only
 * through a full `bridge:check` run that no fixture performs, i.e. measured nowhere. Moving
 * the shape to a primitive is what makes the properties below assertable at all, and the
 * golden corpus is the separate, byte-level evidence that the move changed no output.
 *
 * ⛔ WHAT THIS DOES NOT COVER: whether `handle()` CALLS this at the right point in its
 * per-agent loop. It is called after both aborts on purpose — an agent that never parsed,
 * or whose classifier did not resolve, must contribute nothing to these maps, which is the
 * whole premise of `AgentScopeCoverage`. That position is a property of `handle()` and is
 * measured by the `writeback-move-leg-agent-unread` golden fixture, not here.
 */
class CheckContextTest extends TestCase
{
    public function test_a_family_the_agent_enables_records_every_github_scope_it_subscribes_to(): void
    {
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-message', 'coord-card-create'],
            [['provider' => 'github', 'scopes' => ['owner/one', 'owner/two']]],
        ));

        $this->assertSame(['owner/one' => true, 'owner/two' => true], $ctx->coordCardCreateScopes);
    }

    public function test_each_family_fills_only_its_own_map(): void
    {
        // The reason the three maps are separate fields rather than one keyed by family: a
        // consumer of any one of them must never read another family's enablement as its
        // own. Asserted for all three at once, so a binding wired to the wrong field cannot
        // hide behind a test that only looks at the field it was wired to.
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-move'],
            [['provider' => 'github', 'scopes' => ['owner/repo']]],
        ));

        $this->assertSame(['owner/repo' => true], $ctx->coordCardMoveScopes);
        $this->assertSame([], $ctx->coordCardCreateScopes);
        $this->assertSame([], $ctx->coordCardRelaneScopes);
    }

    public function test_all_three_families_at_once_fill_all_three_maps(): void
    {
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-create', 'coord-card-move', 'coord-card-relane'],
            [['provider' => 'github', 'scopes' => ['owner/repo']]],
        ));

        $this->assertSame(['owner/repo' => true], $ctx->coordCardCreateScopes);
        $this->assertSame(['owner/repo' => true], $ctx->coordCardMoveScopes);
        $this->assertSame(['owner/repo' => true], $ctx->coordCardRelaneScopes);
    }

    public function test_an_agent_enabling_no_coord_card_family_records_nothing(): void
    {
        // An unset `families` resolves to [coord-message], and none of the three is in
        // `DEFAULT_FAMILIES` — which is what makes the raw-config membership test the
        // RESOLVED answer rather than an approximation of it.
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies(AgentConfig::fromArray('prod-agent', [
            'identity' => ['github_user_id' => 555],
            'subscriptions' => [['provider' => 'github', 'scopes' => ['owner/repo']]],
        ]));

        $this->assertSame([], $ctx->coordCardCreateScopes);
        $this->assertSame([], $ctx->coordCardMoveScopes);
        $this->assertSame([], $ctx->coordCardRelaneScopes);
    }

    public function test_a_non_github_subscription_is_not_recorded(): void
    {
        // These maps are compared against `writeback.json` mapping keys, which name github
        // repos. A kanban scope id landing in one would be an orphan accusation about a
        // repo nobody named.
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-move'],
            [
                ['provider' => 'kanban', 'scopes' => ['8']],
                ['provider' => 'github', 'scopes' => ['owner/repo']],
            ],
        ));

        $this->assertSame(['owner/repo' => true], $ctx->coordCardMoveScopes);
    }

    public function test_scopes_are_keyed_by_repo_identity_not_by_the_spelling_the_agent_used(): void
    {
        // DL-293: every consumer looks these up with a `writeback.json` mapping key, and
        // GitHub owner/repo is case-insensitive. A raw key here does not report a mismatch —
        // it reports a working mapping as ORPHANED.
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-relane'],
            [['provider' => 'github', 'scopes' => ['PupFuzz/Kanban-Board']]],
        ));

        $this->assertSame(
            [CheckContext::canonicalScope('PupFuzz/Kanban-Board') => true],
            $ctx->coordCardRelaneScopes,
        );
        $this->assertArrayNotHasKey('PupFuzz/Kanban-Board', $ctx->coordCardRelaneScopes);
    }

    public function test_a_second_agent_adds_to_the_map_rather_than_replacing_it(): void
    {
        // The maps ACCUMULATE across the per-agent loop — the property the inline blocks got
        // from assigning one key at a time, and the one a whole-map assignment would have
        // silently broken: the second agent would have erased the first agent's scopes and
        // every leg reading the map would then accuse a repo that IS served.
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-create'],
            [['provider' => 'github', 'scopes' => ['owner/one']]],
        ));
        $ctx->recordCoordCardFamilies($this->agent(
            ['coord-card-create'],
            [['provider' => 'github', 'scopes' => ['owner/two']]],
        ));

        $this->assertSame(['owner/one' => true, 'owner/two' => true], $ctx->coordCardCreateScopes);
    }

    public function test_an_agent_with_no_github_subscription_at_all_records_nothing(): void
    {
        $ctx = new CheckContext;
        $ctx->recordCoordCardFamilies($this->agent(['coord-card-create', 'coord-card-move'], []));

        $this->assertSame([], $ctx->coordCardCreateScopes);
        $this->assertSame([], $ctx->coordCardMoveScopes);
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $subscriptions
     */
    private function agent(array $families, array $subscriptions): AgentConfig
    {
        return AgentConfig::fromArray('prod-agent', [
            'identity' => ['github_user_id' => 555],
            'subscriptions' => $subscriptions,
            'classifier' => [
                'class' => CoordinationClassifier::class,
                'config' => ['families' => $families],
            ],
        ]);
    }
}
