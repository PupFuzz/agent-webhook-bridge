<?php

namespace Tests\Feature\Console;

use App\Models\WritebackBoardDivergence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * bridge:reconcile — board-vs-GitHub drift reconciler (DL-183). Fakes BOTH APIs
 * (kanban board read + move; GitHub PR state) to exercise the report-only default,
 * --fix, and the guards: backward, pinned, dl-only, truncated read, per-card 404,
 * --max-moves cap, --repo filter, and the belongs-to-mapped-board row re-check
 * (DL-301, its own section below). ⚑ That list is a coverage claim about THIS file,
 * not a restatement of the command's safety posture — `docs/writeback.md`
 * § Reconciliation owns that, and it has been out of step with a fourth copy once
 * already, so add a guard here without checking there at your peril.
 */
class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string|false $origGhToken;

    /** Stage-order positions (workflow_stage_id => position) for board 8. */
    private const ORDER = [46 => 1.0, 49 => 3.0, 50 => 4.0, 52 => 5.0, 53 => 6.0];

    private const ALERT_URL = 'http://127.0.0.1:9938/';

    /** A board this install is NOT mapped to — another tenant's, on the shared instance. */
    private const FOREIGN_BOARD = 12;

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
     * @param  array<string, mixed>  $top  extra TOP-LEVEL keys (e.g. `alert_channel`)
     */
    private function writeWriteback(array $mappings = [], array $top = []): void
    {
        $default = ['owner/repo' => [
            'board_id' => 8,
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
        ]];
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => $mappings === [] ? $default : $mappings,
        ] + $top));
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
            self::ALERT_URL.'*' => Http::response('', 204),
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

    /**
     * A merged PR whose TITLE CLOSES the card it is fixtured against (card#7348 / DL-305).
     *
     * The closing form is a REQUIRED part of the fixture, not decoration: since DL-305 the
     * reconciler derives no expected stage from a merged PR that merely mentions a card,
     * so a title-less merged PR here would make every drift test below assert its subject
     * against a card that is skipped for an unrelated reason. `$closes` names the card so a
     * multi-card fixture gives each PR its own closing form — a title closing card 5 must
     * not authorize card 6.
     *
     * @return array<string, mixed>
     */
    private function mergedToDevPr(int $closes = 5): array
    {
        return ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x', 'title' => "work, Closes card#{$closes}"];
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
        // ⚑ The BOARD COLUMN is asserted, not just the DRIFT keyword (DL-301 review): the
        // report names the board this run reconciled the card under, and a bare 'DRIFT' match
        // certified whatever that column happened to hold — including the value it holds when
        // nothing computes it. `expectsOutputToContain` matches once per chain, so this is the
        // existing matcher made specific rather than a second one beside it.
        $this->artisan('bridge:reconcile')
            ->expectsOutputToContain('DRIFT     card 5 board 8: stage 50 → 52 (merged)')
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
            ->expectsOutputToContain('SKIP-DRIFT card 5 board 8: stage 52 ↛ 50 (opened; backward')
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

    /**
     * DL-309, on the real surface: a `pr_number` that names no single integer must not
     * reach GitHub at all. Before the fix the float `1.5` truncated to PR 1 — a real,
     * unrelated pull request — and this card drifted forward on its state, while the
     * kanban server derives NO `github_pr` ref from the same stored value (its DL-251).
     */
    public function test_a_non_integer_pr_number_is_not_a_tracked_card_and_reaches_no_pull_request(): void
    {
        $this->writeWriteback();
        // PR 1 is fixtured merged-and-closing card 5: if the value truncates, the card
        // moves 50 → 52. The assertions below are what "it named no PR" looks like.
        $this->fake([$this->card(5, 50, ['pr_number' => 1.5])], [1 => $this->mergedToDevPr()]);

        // The COUNTS are asserted, not just the absence of traffic: pre-fix this run
        // reported `1 forward drift (1 moved)`, so a matcher on the summary reds on its
        // own rather than leaning entirely on the two request assertions below.
        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('Summary: 0 forward drift (0 moved), 0 backward/unorderable, 0 in sync, 0 skipped, 0 terminal.')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
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
        ], [5 => $this->mergedToDevPr(), 6 => $this->mergedToDevPr(6)]);

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

        // ⛔ DECODE, AND PAIR IT. Two independent defects, both of which left "limits to ONE
        // mapping" unguarded (card#7471):
        //  1. the board scope travels as the QUERY TERM `q=board_id=<b>`, which the client
        //     percent-encodes to `q=board_id%3D9` — so `str_contains($r->url(), 'board_id=9')`
        //     was false for a request that DID read board 9, and the absence leg could not
        //     fail. Every other board-scope assertion in this suite already decodes
        //     ({@see \Tests\Feature\Writeback\KanbanClientTest}); this one did not.
        //  2. an absence alone certifies whatever replaces the filter — including a `--repo`
        //     that matches nothing and reads no board at all. The presence leg is what makes
        //     the silence about board 9 mean "filtered", not "nothing ran".
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'board_id=8'));
        Http::assertNotSent(fn (Request $r) => str_contains(urldecode($r->url()), 'board_id=9'));
    }

    public function test_repo_filter_matches_a_differently_cased_spelling(): void
    {
        // DL-293: `--repo` names the same repo the writeback does, so it matches the way
        // the writeback matches — case-insensitively. The operator types the spelling
        // every GitHub URL accepts; the mapping may be keyed the other way.
        $this->writeWriteback([
            'Owner/Repo' => ['board_id' => 8, 'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]],
            'owner/other' => ['board_id' => 9, 'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]],
        ]);
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->openPr()]);

        $this->artisan('bridge:reconcile', ['--repo' => 'owner/repo'])
            ->doesntExpectOutputToContain('is not a writeback.json mapping')
            ->assertExitCode(0);

        // The MATCH is a measurement, not the absence of a complaint: board 8 was really read
        // under the differently-cased key. And the board-9 leg decodes, for the reason the
        // sibling above states — `board_id=9` never appears in the raw url (card#7471).
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'board_id=8'));
        Http::assertNotSent(fn (Request $r) => str_contains(urldecode($r->url()), 'board_id=9'));
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

    // ------------------------------------- the resolved-row board re-check (card#7211, DL-301)

    public function test_fix_moves_the_mapped_card_and_refuses_a_row_naming_another_board(): void
    {
        // ⛔ THE SEVENTH ARM of the DL-009 belongs-to-mapped-board guard, and the fourth
        // search-resolved write site of card#7211 — the one DL-298 left open. `readBoardCards`
        // is a `q=board_id=<b>` search whose rows drive `moveCard` directly, and NOTHING in a
        // 200 response distinguishes a dropped filter from an honoured one, so a `q=`→top-level
        // hoist would hand this command another tenant's cards on the happy path.
        //
        // ⚠ MIXED SET, one measurement, because the newly-refused set is expected to be EMPTY
        // in production (`q=board_id=` IS enforced server-side, measured with a control on
        // rt#327). A method asserting only that the foreign row is not moved could not tell
        // "refuses foreign rows" from "stopped moving anything"; one asserting only the mapped
        // move could not tell the guard from its absence. Every row here drifts forward
        // identically — the ONLY thing separating them is the board the row names.
        $this->writeWriteback([], ['alert_channel' => ['url' => self::ALERT_URL]]);
        $this->fake([
            $this->card(5, 50, ['pr_url' => $this->prUrl(5)]),
            $this->card(6, 50, ['pr_url' => $this->prUrl(6)], ['board_id' => self::FOREIGN_BOARD]),
            // Fail-closed on an absent board, stated rather than assumed: a row kanban answered
            // without a `board_id` cannot be SHOWN to be on the mapped board, so it is refused
            // like a foreign one — which is what a hoist against a trimmed projection looks like.
            $this->card(7, 50, ['pr_url' => $this->prUrl(7)], ['board_id' => null]),
        ], [5 => $this->mergedToDevPr(), 6 => $this->mergedToDevPr(6), 7 => $this->mergedToDevPr(7)]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('REFUSED')
            // Loud, not a silent skip: a cron reading exit 0 over a cross-board row in its
            // board read would be reporting a clean reconcile over an unfiltered result set.
            ->assertExitCode(1);

        // PRESENCE WITNESS — the ordinary same-board reconcile still applies its move.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]);
        // CONTROL — neither refused row is written to...
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/6.json'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json'));
        // ...nor does either consume this repo's GitHub token: the guard sits BEFORE the PR
        // read, so a foreign card's PR reference is never dereferenced with our credential.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/6'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/7'));
        // The refusal reaches the operator LIVE, through the same primitive, reason code and
        // dedup tuple the six event-path arms share — kept apart from them by its `outcome`.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), self::ALERT_URL)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['outcome'] === 'reconcile'
            && $r['card_id'] === 6);
        // ⚠ STATED BOUND, the promote-arm bound again: dedup is `(repo, outcome, reason)`, so
        // card 7's refusal is log-only — ONE push per run carrying the FIRST refused card. The
        // per-card `Log::warning` inside the guard is where the rest are enumerated.
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::ALERT_URL) && $r['card_id'] === 7);
        // ⭐ AND THE DURABLE HALF, which is where the alert's dedup bound stops mattering: the
        // alert carries the first refused card and the console lines scroll past, but BOTH
        // refusals outlive the run in the ledger (card#7212/DL-300) — which is the only surface
        // that still answers "did a cross-board row reach this cron?" once the log has rolled.
        // This arm had no ledger assertion at all until the growth vector was measured on it.
        $this->assertSame(
            [[6, '12', 'refused'], [7, null, 'refused']],
            WritebackBoardDivergence::query()
                ->orderBy('card_id')
                ->get()
                ->map(fn (WritebackBoardDivergence $r) => [$r->card_id, $r->card_board, $r->disposition])
                ->all(),
        );
    }

    public function test_an_applied_move_records_the_cards_own_board_durably(): void
    {
        // card#7212 on this leg. The console `MOVED` line is not a durable record — the
        // documented cron redirects stdout to an operator-chosen file, while the REFUSAL above
        // lands in the log through the alert primitive. Recording only the refusal answers
        // "did we ever stop it?" and never "did this ever happen?", which is exactly the
        // asymmetry that makes a landed cross-board write unmeasurable after the fact.
        //
        // ⭐ The pair is read off the ROW, and the value pinned here proves it: a row cannot
        // name a genuinely different board any more (the guard refuses it), so the divergence
        // is forced through the ACCEPTED INTERVAL (DL-292) — `is_numeric` + `(int)` admits the
        // numeric STRING '8' onto a mapped board of 8. A record echoing the mapping gives
        // int 8 here; only one read off the card gives '8'.
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)], ['board_id' => '8'])], [5 => $this->mergedToDevPr()]);
        Log::spy();

        $this->artisan('bridge:reconcile', ['--fix' => true])->assertExitCode(0);

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'bridge_reconcile: moved'
            && $ctx['card_id'] === 5 && $ctx['stage'] === 52
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    // --- card#7348 / DL-305: correlation is not completion, on the backstop ---

    /**
     * A merged PR whose title MENTIONS the card without closing it — the historical shape.
     *
     * @return array<string, mixed>
     */
    private function mentioningMergedPr(int $card = 5): array
    {
        return ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x', 'title' => "work, follows card#{$card}"];
    }

    public function test_witness_3_a_historical_bare_mention_card_is_not_demoted_on_a_later_pass(): void
    {
        // ⛔ WITNESS 3 — THE MASS-DEMOTION REGRESSION, and it needs its own witness rather
        // than being implied by the classifier's no-op. This card is ALREADY in the merged
        // stage (52): it is one of the already-correct cards an install is full of on the
        // day DL-305 ships, and its PR is a bare mention under the new grammar — as every
        // historical PR is. `bridge:reconcile --fix` re-derives an expected stage for every
        // in-window card on EVERY pass, so a gate that returned an earlier stage here (the
        // naive "demote a mention" reading) would walk the whole board backwards on the
        // first run. It must derive NO expectation at all.
        //
        // (Make the gate return the `opened` stage instead of skipping ⇒ a backward
        // SKIP-DRIFT row appears ⇒ RED; make it fall through to the merged stage ⇒ the
        // in-sync count is 1 instead of the skip ⇒ RED.)
        $this->writeWriteback();
        $this->fake([$this->card(5, 52, ['pr_url' => $this->prUrl(5)])], [5 => $this->mentioningMergedPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('a MENTION, not a closure claim')
            // ⛔ THE DEMOTION ASSERTION ITSELF, and it is the one that makes this witness
            // about mass-demotion rather than about a message: the naive rule derives the
            // pre-merge stage here, and stage 52 → 50 is BACKWARD, which this command
            // prints as SKIP-DRIFT. Its absence is what says nothing walked backwards.
            // (Chained matchers were CONTROLLED before this was trusted: replacing the
            // second one with a string that cannot print reds the test.)
            ->doesntExpectOutputToContain('SKIP-DRIFT')
            ->expectsOutputToContain('1 skipped')
            ->assertExitCode(0);

        // Nothing was written, and nothing was even PLANNED — a backward row would have
        // printed SKIP-DRIFT and an in-sync read would have counted it as reconciled.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_bare_mention_merge_plans_no_forward_move_either(): void
    {
        // The same rule in the forward direction: this card sits at `opened` (50) and its
        // PR is merged, which is exactly the drift the backstop exists to repair — but the
        // PR never claimed the card was done, so there is nothing to repair TO. The
        // failure direction is an UNDER-promoted card, recoverable by hand.
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->mentioningMergedPr()]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->doesntExpectOutputToContain('DRIFT     card 5')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_closing_form_naming_the_cards_own_dl_closes_it_across_spellings(): void
    {
        // The DL half of the gate, plus the one place the closure path needs the reference
        // NORMALIZER: the card is stamped `DL-0305` (the board's own zero-padded spelling)
        // and the title says `DL-305`. Those are one DL — the kanban server derives the same
        // `dl:305` ref from both — so an exact string compare here would silently withhold
        // every move on an install whose stamps are padded.
        $this->writeWriteback();
        $this->fake(
            [$this->card(5, 50, ['pr_url' => $this->prUrl(5), 'dl_number' => 'DL-0305'])],
            [5 => ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x', 'title' => 'work, Closes DL-305']],
        );

        $this->artisan('bridge:reconcile', ['--fix' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_a_closing_form_naming_another_cards_dl_does_not_close_this_one(): void
    {
        // The mirror, and the reason the DL is read off the CARD and never off the title: a
        // release-shaped PR that closes someone else's DL must not reconcile this card
        // forward. That is the DL-218 foreign-mention door, kept shut on the backstop too.
        $this->writeWriteback();
        $this->fake(
            [$this->card(5, 50, ['pr_url' => $this->prUrl(5), 'dl_number' => 'DL-0305'])],
            [5 => ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x', 'title' => 'work, Closes DL-999']],
        );

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->expectsOutputToContain('a MENTION, not a closure claim')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- card#7348 / DL-308: the structural route, in lockstep on the backstop ---

    /**
     * A merged PR whose title only MENTIONS the card, on a head branch that names `$card`.
     *
     * @return array<string, mixed>
     */
    private function mergedPrOnBranch(string $head, int $card = 5): array
    {
        return ['state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x',
            'title' => "work, follows card#{$card}", 'head' => ['ref' => $head]];
    }

    public function test_the_backstop_reconciles_a_merge_whose_branch_names_the_card(): void
    {
        // THE LOCKSTEP POSITIVE. This is byte-for-byte the situation
        // `test_a_bare_mention_merge_plans_no_forward_move_either` above refuses — same
        // card, same stage, same mention-only title — with ONE field added: a head branch
        // ref naming card 5. The classifier moves this card (witness 4); if the backstop
        // did not, the two paths would disagree about which merges close a card, and
        // `--fix` on a schedule would keep declining a move the event path had made. That
        // is the drift `PrOutcome` owns the term to prevent, and this is the assertion
        // that the term is actually WIRED here rather than only there.
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->mergedPrOnBranch('card-5-widget')]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->doesntExpectOutputToContain('a MENTION, not a closure claim')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_the_backstop_refuses_a_branch_that_names_another_card(): void
    {
        // ⛔ THE NEGATIVE, on the leg that runs unattended on a schedule — which is where a
        // wrong widening does the most damage, because nobody is watching a cron the way
        // they watch a merge. One variable changed from the test above: the branch names
        // card 9999, so nothing about this merge claims card 5 is done. (Key the term on
        // "the ref names any card" ⇒ card 5 is PATCHed to the merged stage by a cron ⇒ RED.)
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => $this->mergedPrOnBranch('card-9999-other')]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            // ONE matcher, spanning BOTH claims — the skip line names the ref it read (so
            // an operator debugging a card that will not move sees the surface that decided
            // it) AND states the ruling. Two chained `expectsOutputToContain` calls against
            // one line do not both match: the first consumes it.
            ->expectsOutputToContain("neither its head branch ref ('card-9999-other') nor a closing form in its title names this card — a MENTION, not a closure claim")
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_the_backstop_does_not_demote_a_shipped_card_whose_branch_named_it(): void
    {
        // The DL-305 no-demotion property, re-asserted through the new route: a card
        // already at the merged stage whose PR closes it structurally must reconcile to
        // IN-SYNC, not to a move. Widening what closes a card widens the population this
        // command re-derives an expectation for on every pass, so the property that made
        // DL-305 safe to ship has to be re-witnessed against the wider population rather
        // than inherited from it.
        $this->writeWriteback();
        $this->fake([$this->card(5, 52, ['pr_url' => $this->prUrl(5)])], [5 => $this->mergedPrOnBranch('card-5-widget')]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->doesntExpectOutputToContain('SKIP-DRIFT')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- card#8306: the revert refusal, in lockstep on the backstop ---

    public function test_the_backstop_refuses_a_github_revert_on_both_routes(): void
    {
        // ⛔ THE LOCKSTEP THAT MATTERS MOST HERE, because this leg runs on a schedule with
        // nobody watching: the classifier declines the revert at merge time, and without
        // the same term the backstop would PATCH the card forward an hour later with a
        // CLI's name on it — the DL-305 §6 failure, re-minted through the revert door. Both
        // routes are live in this one fixture (the title quotes `Closes card#5`, the ref
        // wraps `card-5`), and the term lives on the two shared authorities so neither path
        // spells it. (Delete either conjunct ⇒ card 5 is PATCHed to stage 52 by a cron.)
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => [
            'state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x',
            'title' => 'Revert "work (Closes card#5)"', 'head' => ['ref' => 'revert-611-card-5-widget'],
        ]]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            // The skip line must NAME the revert rather than assert the default sentence,
            // which is false here on both of its clauses — the ref does name card 5 and the
            // title does carry a closing form. One matcher, because the first consumes the
            // line (the constraint the negative above already records).
            ->expectsOutputToContain('takes NEITHER closure route')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_the_backstop_still_reconciles_the_reverted_original(): void
    {
        // ⛔ THE CONTROL, one variable away: the SAME title and the SAME branch without
        // GitHub's two wrappers. It reconciles exactly as it did before, so the test above
        // is evidence about the revert and not about a backstop that stopped closing
        // anything — the DL-305 failure mode this whole area exists to avoid.
        $this->writeWriteback();
        $this->fake([$this->card(5, 50, ['pr_url' => $this->prUrl(5)])], [5 => [
            'state' => 'closed', 'merged' => true, 'base' => ['ref' => 'dev'], 'html_url' => 'x',
            'title' => 'work (Closes card#5)', 'head' => ['ref' => 'card-5-widget'],
        ]]);

        $this->artisan('bridge:reconcile', ['--fix' => true])
            ->doesntExpectOutputToContain('a MENTION, not a closure claim')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]);
    }
}
