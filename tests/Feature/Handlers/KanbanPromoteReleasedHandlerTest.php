<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
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

    private const ALERT_URL = 'http://127.0.0.1:9932/';

    /**
     * The same mapping WITH an alert channel — this handler's fixture had none, so its
     * three pre-existing notify() calls were never exercised (card#5312).
     *
     * @param  array<string,mixed>  $extra
     */
    private function writeWritebackWithAlert(array $extra): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => array_merge([
                'board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53],
            ], $extra)],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
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
            && ! isset($r['task'])   // DL-219: flat move body, no task wrapper
            && $r['workflow_stage_id'] === $stage);
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

    // --- card#5312 / DL-274: this leg's three permanent-refusal arms became live signals ---

    public function test_getpull_4xx_alerts_and_skips_the_card(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->handle();

        // FLAT reason: GitHub answers 404 for a private repo this token cannot see, so a
        // named 403/404 split here would be wrong-but-specific.
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'promote_getpull_4xx'
            && $r['outcome'] === 'promote_on_release'
            && $r['card_id'] === 5);
        $this->assertNotMoved(5);
    }

    public function test_compare_status_4xx_alerts_and_skips_the_card(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['message' => 'No common ancestor'], 404),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'promote_compare_4xx');
        $this->assertNotMoved(5);
    }

    public function test_promote_move_422_alerts_the_only_arm_of_this_class_observed_in_production(): void
    {
        // The 2026-07-21 incident: a 422 ("this endpoint does not use a resource wrapper")
        // no-op'd the promote leg silently for 15 days. This leg has NO reconcile backstop,
        // so its failure paths are the ones that most need to be loud.
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response(['message' => 'This endpoint does not use a resource wrapper'], 422),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'promote_movecard_4xx'
            && $r['card_id'] === 5);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && $ctx['status'] === 422
            && str_contains($ctx['body'], 'resource wrapper'));
    }

    public function test_promote_move_403_alerts_the_read_but_not_write_reason(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'promote_movecard_403_not_writable_by_this_token');
    }

    public function test_no_github_token_alerts_now_that_the_fixture_has_a_channel(): void
    {
        // A pre-existing notify() call that no test could see: this handler's fixture
        // carried no alert_channel, so all three of its alerts were unasserted.
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        File::delete($this->dir.'/github/token');
        config(['bridge.providers.github.token_path' => $this->dir.'/github/absent-token']);
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'promote_no_github_token'
            && $r['card_id'] === null);
    }

    public function test_truncated_board_read_alerts(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]]],
                'links' => ['next' => 'https://kanban.example.com/api/v3/tasks/search.json?page=99'],
            ]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 0]]),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'promote_board_truncated');
        $this->assertMoved(5, 53);   // the partial view is still promoted
    }

    // --- card#5968 / DL-285: the three PRE-SCAN gaps join the signalling set ---

    public function test_missing_payload_repo_alerts_on_the_empty_repo_key(): void
    {
        // The repo IS the dedup tuple's first element and it is what is missing, so the
        // arm degrades to '' rather than staying silent — the shape the move handler's
        // payload arms already use. The alert_channel loads from writeback.json, which
        // exists here (the mapping is simply never consulted).
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        (new KanbanPromoteReleasedHandler)->handle(
            ReactionTarget::make('kanban_promote_released', 'owner/repo', payload: ['repo' => null]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'promote_repo_invalid'
            && $r['repo'] === ''
            && $r['outcome'] === 'promote_on_release'
            && $r['card_id'] === null
            && $r['issue_number'] === null);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'payload.repo is missing'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json'));
    }

    public function test_a_promote_mapping_missing_a_stage_is_refused_at_load_not_at_the_handler(): void
    {
        // The disposition of the third pre-scan gap, asserted rather than asserted-about:
        // its `Log::warning` stays log-only because the branch is TYPE-NARROWING, and the
        // reason it is unreachable is this fail-closed load. If that guard is ever relaxed,
        // this test reds and the handler branch becomes a real refusal needing a signal.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52], 'promote_on_release' => true]],
        ]));

        $this->expectException(ConfigException::class);
        $this->handle();
    }

    public function test_absent_writeback_json_still_logs_and_cannot_push(): void
    {
        // The Branch-#3 degradation, asserted rather than assumed: the arm routes through
        // the paired primitive, but the notifier loads its channel from the very file that
        // is absent, so no push is structurally possible. The durable log is the record.
        File::delete($this->dir.'/writeback.json');
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'writeback is not configured'));
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // --- card#5968: promote_candidate_cap, the one DL-274 arm no test could reach ---

    public function test_candidate_overflow_alerts_and_processes_exactly_the_cap(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        $over = self::cap() + 1;
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => $this->shippedCards($over), 'links' => ['next' => null]]),
            // Unmerged ⇒ each candidate costs exactly one getPull and promotes nothing, so
            // the getPull count IS the number of candidates the cap let through.
            'https://api.github.com/repos/owner/repo/pulls/*' => Http::response(['merged' => false, 'merge_commit_sha' => 'TESTMERGE', 'state' => 'open', 'base' => ['ref' => 'dev']]),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'promote_candidate_cap'
            && $r['outcome'] === 'promote_on_release'
            && $r['card_id'] === null);
        $this->assertSame(self::cap(), $this->getPullCount(), 'the cap must truncate the candidate list, not merely warn about it');
    }

    public function test_exactly_the_cap_is_not_an_overflow(): void
    {
        // The negative control for the leg above: `>` not `>=`. Without it, "an alert
        // fired" could be true for any board at or near the cap, and the truncation
        // assertion would hold trivially.
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => $this->shippedCards(self::cap()), 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/*' => Http::response(['merged' => false, 'merge_commit_sha' => 'TESTMERGE', 'state' => 'open', 'base' => ['ref' => 'dev']]),
        ]);

        $this->handle();

        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
        $this->assertSame(self::cap(), $this->getPullCount());
    }

    private static function cap(): int
    {
        return KanbanPromoteReleasedHandler::MAX_CANDIDATES;
    }

    /**
     * $n Shipped, unpinned, PR-referenced cards — the candidate shape the scan collects.
     *
     * @return list<array<string, mixed>>
     */
    private function shippedCards(int $n): array
    {
        return array_map(
            fn (int $i) => ['id' => 1000 + $i, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 200 + $i]],
            range(1, $n),
        );
    }

    private function getPullCount(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.github.com') && str_contains($r->url(), '/pulls/'))->count();
    }

    public function test_transient_5xx_on_the_promote_move_throws_and_never_alerts(): void
    {
        $this->writeWritebackWithAlert(['promote_on_release' => true]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response('upstream error', 503),
        ]);

        try {
            $this->handle();
            $this->fail('a 5xx on the promote move must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }
}
