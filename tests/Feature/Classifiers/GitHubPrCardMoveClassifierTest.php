<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubPrCardMoveClassifierTest extends TestCase
{
    private string $dir;

    private AgentConfig $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prcls-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        $this->agent = AgentConfig::fromArray('test-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<mixed> $pr */
    private function classify(string $eventType, array $pr, string $repo = 'owner/repo'): ClassifyResult
    {
        return (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            $eventType,
            ['pull_request' => $pr, 'repository' => ['full_name' => $repo]],
            new Actor('999'),
            'github',
            $repo,
            $this->agent,
        ));
    }

    private function fakeBoardCards(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
        ]])]);
    }

    public function test_opened_pr_correlates_and_emits_move_to_opened_stage(): void
    {
        $this->fakeBoardCards();

        $result = $this->classify('pull_request.opened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $result->targets);
        $t = $result->targets[0];
        $this->assertSame('kanban_move_card', $t->handler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'opened'], $t->payload);
        $this->assertSame([], $result->intents);   // machine-only, no inbox intent
    }

    public function test_merged_to_main_vs_merged_keys_on_base_ref(): void
    {
        $this->fakeBoardCards();

        $main = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);
        $this->assertSame('merged_to_main', $main->targets[0]->payload['outcome']);

        $dev = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'dev']]);
        $this->assertSame('merged', $dev->targets[0]->payload['outcome']);
    }

    public function test_closed_unmerged_outcome(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => false]);
        $this->assertSame('closed_unmerged', $r->targets[0]->payload['outcome']);
    }

    public function test_dl_token_from_head_branch_when_title_has_none(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.opened', ['title' => 'no ref here', 'head' => ['ref' => 'fix/DL-42-thing']]);
        $this->assertSame(5, $r->targets[0]->payload['card_id']);
    }

    public function test_unmapped_repo_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42'], repo: 'other/repo');
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // didn't even search
    }

    public function test_no_dl_token_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'no card reference']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_no_matching_card_is_noop(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
    }

    public function test_bundled_dl_emits_one_move_target_per_matching_card(): void
    {
        // DL-148: a DL can track multiple cards (bundled PR) — move them ALL,
        // one target each with the card id as a distinct target_id (no coalesce).
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],   // same canonical 42
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],  // different DL, not matched
        ]])]);

        $r = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);

        $this->assertCount(2, $r->targets);
        $ids = array_map(fn ($t) => $t->payload['card_id'], $r->targets);
        $this->assertEqualsCanonicalizing([5, 6], $ids);
        $this->assertEqualsCanonicalizing(['5', '6'], array_map(fn ($t) => $t->targetId, $r->targets));   // distinct target ids
        foreach ($r->targets as $t) {
            $this->assertSame('kanban_move_card', $t->handler);
            $this->assertSame('merged_to_main', $t->payload['outcome']);
        }
    }

    public function test_non_pull_request_event_is_noop(): void
    {
        Http::fake();
        $r = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext('push', [], new Actor('1'), 'github', 'owner/repo', $this->agent));
        $this->assertSame([], $r->targets);
    }

    public function test_unhandled_pr_action_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.synchronize', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    private function enableDependabot(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ], 'create_dependabot_cards' => true]],
        ]));
    }

    public function test_dependabot_pr_emits_create_or_move_target_when_opted_in(): void
    {
        $this->enableDependabot();
        Http::fake();   // no correlation read on this path

        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x from 1 to 2',
            'number' => 77,
            'head' => ['ref' => 'dependabot/composer/x-2.0'],
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_dependabot_card', $t->handler);
        $this->assertSame('owner/repo', $t->payload['repo']);
        $this->assertSame('opened', $t->payload['outcome']);
        $this->assertSame(77, $t->payload['pr_number']);
        Http::assertNothingSent();   // create/move decided by the durable handler, not here
    }

    public function test_dependabot_pr_falls_through_when_not_opted_in(): void
    {
        // setUp's config has no create_dependabot_cards → no dependabot branch;
        // a dependabot PR has no DL, so the normal path is a no-op.
        Http::fake();
        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x', 'number' => 77, 'head' => ['ref' => 'dependabot/composer/x-2.0'],
        ]);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }
}
