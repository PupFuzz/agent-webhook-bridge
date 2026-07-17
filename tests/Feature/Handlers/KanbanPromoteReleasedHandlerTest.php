<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * KanbanPromoteReleasedHandler (DL-207) — the board-wide Shipped→Released scan on a
 * release merge to main. shipped=52, released=53, board=8.
 */
class KanbanPromoteReleasedHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/promo-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        $this->writeWriteback(['promote_on_release' => true]);
        $this->writeTokens();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string,mixed> $extra */
    private function writeWriteback(array $extra): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => array_merge([
                'board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53],
            ], $extra)],
        ]));
    }

    private function writeTokens(): void
    {
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/github/token', 'ghp_read');
        chmod($this->dir.'/github/token', 0o600);
    }

    /** @param list<array<string,mixed>> $cards */
    private function fakeBoard(array $cards): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => $cards, 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/pulls/101' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA6', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/pulls/102' => Http::response(['merged' => false, 'merge_commit_sha' => 'TESTMERGE', 'state' => 'open', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            'https://api.github.com/repos/owner/repo/compare/SHA6...main' => Http::response(['status' => 'diverged']),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 0]]),   // PATCH move (last: least specific)
        ]);
    }

    private function handle(string $repo = 'owner/repo'): void
    {
        (new KanbanPromoteReleasedHandler)->handle(
            ReactionTarget::make('kanban_promote_released', $repo, payload: ['repo' => $repo]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    private function assertMoved(int $cardId, int $stage): void
    {
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), "/tasks/{$cardId}.json")
            && $r['task'] === ['workflow_stage_id' => $stage]);
    }

    private function assertNotMoved(int $cardId): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), "/tasks/{$cardId}.json"));
    }

    public function test_promotes_only_shipped_cards_whose_merge_is_on_main(): void
    {
        $this->fakeBoard([
            ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],   // on main → promote
            ['id' => 6, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 101]],   // diverged → leave
            ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 100]],   // not shipped → skip
            ['id' => 8, 'workflow_stage_id' => 52, 'payload' => ['dl_number' => 'DL-1']],   // no PR → skip
            ['id' => 9, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 102]],   // open PR → skip
        ]);

        $this->handle();

        $this->assertMoved(5, 53);
        $this->assertNotMoved(6);
        $this->assertNotMoved(7);
        $this->assertNotMoved(8);
        $this->assertNotMoved(9);
    }

    public function test_skips_pinned_shipped_card(): void
    {
        $this->fakeBoard([
            ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100], 'block_reason' => 'human hold'],
        ]);

        $this->handle();

        $this->assertNotMoved(5);
    }

    public function test_no_github_token_is_a_noop_no_move(): void
    {
        // Authoritative token_path override to a missing file → resolve fails loud with NO
        // store/env fallback (GitHubTokenResolver leg 1), deterministically unresolvable.
        File::delete($this->dir.'/github/token');
        config(['bridge.providers.github.token_path' => $this->dir.'/github/absent-token']);
        $this->fakeBoard([
            ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
        ]);

        $this->handle();

        $this->assertNotMoved(5);
        // No board read either — the token gate is before the scan.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json'));
    }

    public function test_flag_off_is_a_noop(): void
    {
        $this->writeWriteback([]);   // promote_on_release absent
        $this->fakeBoard([
            ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
        ]);

        $this->handle();

        $this->assertNotMoved(5);
    }

    public function test_unconfigured_repo_is_a_noop(): void
    {
        $this->fakeBoard([]);

        $this->handle('other/unmapped');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_truncated_board_read_is_loud_but_still_promotes_the_visible_cards(): void
    {
        // A non-null links.next on every page drives readBoard past MAX_PAGES → truncated=true.
        // The scan must proceed on the partial view AND warn (no reconcile backstop for this leg).
        Log::spy();
        Http::fake([
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]]],
                'links' => ['next' => 'https://kanban.example.com/api/v3/tasks/search.json?page=99'],
            ]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 0]]),
        ]);

        $this->handle();

        $this->assertMoved(5, 53);   // partial view is still scanned + promoted
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'hit the page ceiling'))->once();
    }

    public function test_transient_getpull_5xx_propagates_for_redelivery(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['message' => 'boom'], 503),
        ]);

        $this->expectException(RequestException::class);
        $this->handle();
    }

    public function test_permanent_getpull_4xx_skips_the_card(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
                ['id' => 6, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 101]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/owner/repo/pulls/101' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA6', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA6...main' => Http::response(['status' => 'identical']),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 0]]),
        ]);

        $this->handle();   // 404 on card 5 must not abort; card 6 still promotes

        $this->assertMoved(6, 53);
        $this->assertNotMoved(5);
    }
}
