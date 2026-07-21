<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * bridge:reconcile — board-vs-GitHub drift reconciler (DL-183). Fakes BOTH APIs
 * (kanban board read + move; GitHub PR state) to exercise the report-only default,
 * --fix, and every guard (backward/pinned/dl-only/truncated/404/cap/--repo filter).
 */
class ReconcileCommandTest extends TestCase
{
    private string $dir;

    private string|false $origGhToken;

    /** Stage-order positions (workflow_stage_id => position) for board 8. */
    private const ORDER = [46 => 1.0, 49 => 3.0, 50 => 4.0, 52 => 5.0, 53 => 6.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/reconcile-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'ref',
            // Neutralize the store-native leg (this host has a real
            // git-credential-coord on PATH) so these tests exercise the file /
            // GH_TOKEN legs deterministically; per-repo store resolution is covered
            // by GitHubTokenResolverTest and the dedicated store test below.
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
        ]);
        $this->writeToken($this->dir.'/kanban/writeback-token');
        $this->writeToken($this->dir.'/github/token');
        // Hermetic: the host/CI may export GH_TOKEN (~/.bashrc), which is now a
        // reconcile token source (DL-184). Clear it so the file-token path is
        // exercised deterministically and the "no token" case really has none.
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        if ($this->origGhToken === false) {
            putenv('GH_TOKEN');
        } else {
            putenv('GH_TOKEN='.$this->origGhToken);
        }
        parent::tearDown();
    }

    private function writeToken(string $path): void
    {
        File::put($path, 'tok');
        chmod($path, 0o600);
    }

    /**
     * @param  array<string, mixed>  $mappings  repo mappings keyed by repo (defaults to one owner/repo → board 8)
     */
    private function writeWriteback(array $mappings = []): void
    {
        $default = ['owner/repo' => [
            'board_id' => 8,
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
        ]];
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => $mappings === [] ? $default : $mappings,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function card(int $id, int $stage, array $payload, array $over = []): array
    {
        return array_merge(['id' => $id, 'board_id' => 8, 'workflow_stage_id' => $stage, 'payload' => $payload], $over);
    }

    /**
     * @param  list<array<string, mixed>>  $cards  board-8 cards
     * @param  array<int, array<string, mixed>>  $pulls  pr_number => github pr response (or ['__status'=>404])
     * @param  array<int, float>|null  $order  stage order for board 8 (defaults to ORDER)
     */
    private function fake(array $cards, array $pulls, ?array $order = null): void
    {
        $order ??= self::ORDER;
        $stages = [];
        foreach ($order as $id => $pos) {
            $stages[] = ['id' => $id, 'position' => $pos];
        }

        Http::fake([
            '*tasks/search.json*' => Http::response(['data' => $cards, 'links' => ['next' => null]]),
            '*preload.json' => Http::response(['data' => ['workflows' => [['stages' => $stages]]]]),
            'https://api.github.com/*' => function (Request $request) use ($pulls) {
                if (preg_match('#/pulls/(\d+)#', $request->url(), $m) === 1) {
                    $pr = $pulls[(int) $m[1]] ?? null;
                    if ($pr === null || ($pr['__status'] ?? null) === 404) {
                        return Http::response(['message' => 'Not Found'], 404);
                    }

                    return Http::response($pr);
                }

                // Startup repo auth/scope probe (GET /repos/{owner}/{repo}) → OK.
                return Http::response(['full_name' => 'owner/repo'], 200);
            },
            '*tasks/*.json' => Http::response(['data' => ['id' => 1]]),   // PATCH move
        ]);
    }

    private function prUrl(int $n): string
    {
        return "https://github.com/owner/repo/pull/{$n}";
    }

    /** @return array<string, mixed> */
    private function openPr(): array
    {
        return ['state' => 'open', 'merged' => false, 'base' => ['ref' => 'dev'], 'html_url' => 'x'];
    }

    /** @return array<string, mixed> */
    private function mergedToDevPr(): array
    {
        return ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x'];
    }

    public function test_in_sync_card_is_noop(): void
    {
        $this->writeWriteback();
        // card in the `opened` stage (50); PR open → expected 50 → in sync.
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->openPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('1 in sync')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_forward_drift_reports_and_fix_moves(): void
    {
        $this->writeWriteback();
        // card at `opened` (50, pos 4); PR merged to dev → expected `merged` (52, pos 5) — forward.
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->mergedToDevPr()]);

        // report-only: reports drift, does NOT move.
        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('DRIFT')
            ->assertExitCode(0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');

        // --fix: applies the move.
        $this->artisan('bridge:reconcile', ['--fix' => true])->assertExitCode(0);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_backward_drift_is_reported_but_never_moved(): void
    {
        $this->writeWriteback();
        // card at `merged` (52, pos 5); PR open → expected `opened` (50, pos 4) — backward.
        $this->fake([$this->card(5, 52, ['pr_url' => $this->prUrl(5)])], [5 => $this->openPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('SKIP-DRIFT')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_pinned_card_is_skipped_without_a_github_call(): void
    {
        $this->writeWriteback();
        // pinned card that would otherwise drift forward.
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)], ['block_reason' => 'parked'])], [5 => $this->mergedToDevPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('pinned')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_dl_only_card_is_skipped_with_info(): void
    {
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['dl_number' => 'DL-42'])], []);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('out of v1 scope')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/'));
    }

    public function test_truncated_board_read_aborts_that_board(): void
    {
        $this->writeWriteback();
        $fullPage = array_fill(0, 200, ['id' => 1, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => []]);
        // No `links` key + a full 200-row page every time → the page walk hits the
        // ceiling → readBoardCards reports truncated=true → the board is aborted.
        Http::fake([
            '*tasks/search.json*' => Http::response(['data' => $fullPage]),
            'https://api.github.com/*' => Http::response([], 200),
        ]);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('ABORTING this board')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/'));
    }

    public function test_github_404_warns_and_continues(): void
    {
        $this->writeWriteback();
        // card 5 → 404 (deleted PR); card 6 → in sync. The run must not abort.
        $this->fake([
            $this->card(5, 50, ['pr_url' => $this->prUrl(5)]),
            $this->card(6, 50, ['pr_url' => $this->prUrl(6)]),
        ], [5 => ['__status' => 404], 6 => $this->openPr()]);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('GitHub 404')
            ->expectsOutputToContain('1 in sync')
            ->assertExitCode(0);
    }

    public function test_max_moves_cap_aborts_before_applying_any(): void
    {
        $this->writeWriteback();
        // two forward-drift cards, cap = 1 → abort before any PATCH.
        $this->fake([
            $this->card(5, 50, ['pr_url' => $this->prUrl(5)]),
            $this->card(6, 50, ['pr_url' => $this->prUrl(6)]),
        ], [5 => $this->mergedToDevPr(), 6 => $this->mergedToDevPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true, '--max-moves' => 1])
            ->expectsOutputToContain('ABORTING before applying')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_repo_filter_limits_to_one_mapping(): void
    {
        $this->writeWriteback([
            'owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]],
            'owner/other' => ['board_id' => 9, 'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]],
        ]);
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->openPr()]);

        $this->artisan('bridge:reconcile', ['--repo' => 'owner/repo'])->assertExitCode(0);

        // board 9 (owner/other) must never be read.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'board_id=9'));
    }

    public function test_unknown_repo_filter_fails(): void
    {
        $this->writeWriteback();
        $this->fake([], []);

        $this->artisan('bridge:reconcile', ['--repo' => 'nope/nope'])
            ->expectsOutputToContain('is not a writeback.json mapping')
            ->assertExitCode(1);
    }

    public function test_missing_github_token_fails_clearly(): void
    {
        $this->writeWriteback();
        File::delete($this->dir.'/github/token');
        $this->fake([], []);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('no github token')
            ->assertExitCode(1);
    }

    public function test_gh_token_env_is_used_when_file_absent(): void
    {
        // DL-184: no file token, but GH_TOKEN exported → reconcile proceeds past
        // the token check instead of failing "no github token".
        $this->writeWriteback();
        File::delete($this->dir.'/github/token');
        putenv('GH_TOKEN=ghp_env');
        $this->fake([], []);

        $this->artisan('bridge:reconcile')
            ->doesntExpectOutputToContain('no github token')
            ->assertExitCode(0);
    }

    public function test_configured_token_path_is_authoritative_over_gh_token(): void
    {
        // DL-184: an explicit (missing) token_path override fails loud and does
        // NOT silently fall through to an ambient GH_TOKEN.
        $this->writeWriteback();
        File::delete($this->dir.'/github/token');
        config(['bridge.providers.github.token_path' => $this->dir.'/github/missing-override']);
        putenv('GH_TOKEN=ghp_env');
        $this->fake([], []);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('configured token_path')
            ->assertExitCode(1);
    }

    public function test_store_native_token_is_used_per_repo(): void
    {
        // DL-185: no file token; credential_helper resolves a per-repo token from
        // the store (the stub echoes a token derived from the requested path). The
        // GitHub calls must carry that store-derived token, not a file/env one.
        $this->writeWriteback();
        File::delete($this->dir.'/github/token');
        $stub = $this->dir.'/gcc-stub';
        File::put($stub, "#!/bin/sh\npath=\$(sed -n 's/^path=//p')\nprintf 'password=tok:%s\\n' \"\$path\"\n");
        chmod($stub, 0o755);
        config(['bridge.providers.github.credential_helper' => $stub]);
        // in-sync card (stage 50, open PR → 50): no move, just proves auth wiring.
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->openPr()]);

        $this->artisan('bridge:reconcile')->assertExitCode(0);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.github.com/')
            && $r->hasHeader('Authorization', 'Bearer tok:owner/repo'));
    }

    public function test_auth_probe_failure_names_the_resolved_leg(): void
    {
        // DL-186: a 401 on the startup auth probe must name WHICH leg resolved the
        // token (here the conventional file, from setUp), so a stale shadowing file
        // is obvious instead of an un-actionable bare "401".
        $this->writeWriteback();
        Http::fake([
            '*preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*tasks/search.json*' => Http::response(['data' => [], 'links' => ['next' => null]]),
            'https://api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('token from token file')
            ->assertExitCode(1);
    }

    public function test_no_writeback_config_fails(): void
    {
        // no writeback.json written
        $this->fake([], []);

        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('writeback is not configured')
            ->assertExitCode(1);
    }

    public function test_repo_probe_failure_skips_repo_and_exits_nonzero(): void
    {
        $this->writeWriteback();
        // Every github call 404s → the startup repo probe fails (token can't see the
        // private repo) → the repo's cards are skipped, no PR is fetched, exit 1.
        Http::fake([
            '*tasks/search.json*' => Http::response(['data' => [$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], 'links' => ['next' => null]]),
            '*preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 50, 'position' => 4.0]]]]]]),
            'https://api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('cannot read repo owner/repo')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/'));
    }

    public function test_unorderable_board_exits_nonzero(): void
    {
        $this->writeWriteback();
        // Empty stage order (preload carries no stages) → a drifted card can't be
        // direction-checked → reported unorderable, never moved, and exit 1.
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->mergedToDevPr()], []);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('unorderable')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }
}
