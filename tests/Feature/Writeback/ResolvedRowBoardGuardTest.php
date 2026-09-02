<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Support\AgentConfig;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * card#7211 fix #2 (DL-298) — THE RESOLVED-ROW BOARD RE-CHECK, on the three writeback
 * HANDLER paths that resolve card ids from a BOARD-SCOPED SEARCH and then write to them. The
 * fourth such path is the `bridge:reconcile --fix` CLI; it is guarded too (DL-301) and covered
 * beside the command — see the bound at the end of this docblock.
 *
 * The companion to {@see BoardScopedReadConstructionTest} (fix #1), and deliberately not
 * the same guard: that one pins the CALL — every board term stays inside `q=`, because an
 * unrecognised TOP-LEVEL parameter is dropped silently and answers 200 with an UNFILTERED
 * result set (measured on the live instance, rt#327: a bogus `?<key>=xyz` returned 21 cards
 * spanning every board the token could reach). This one pins the RESULT — a row that came
 * back naming another board is never written to, whatever produced it.
 *
 * ⛔ THE NEWLY-REFUSED SET IS EXPECTED TO BE EMPTY IN PRODUCTION, and that is the point,
 * not an objection to the guard. `q=board_id=<id>` IS enforced server-side today, measured
 * with the control that makes the zero mean something (a token planted only on board B
 * returned `total:1` scoped to B and `total:0` scoped to A, holding past page 1). So these
 * sites are correct BY LUCK — `board_id` also works as a bare top-level parameter and
 * filters correctly THERE, so hoisting it out of `q` filters in a manual test, reviews as
 * equivalent, and takes the next filter hoisted beside it silently out of the query.
 *
 * ⚠ WHICH MEANS THE HAPPY PATH REFUSES NOTHING, so every method here feeds a MIXED set —
 * one row on the mapped board, one row on another — and asserts BOTH directions in one
 * measurement. A method that only asserted the foreign row is not written could not tell
 * "refuses foreign rows" from "stopped writing at all"; a method that only asserted the
 * mapped row is written could not tell the guard from its absence.
 *
 * The compare and the refusal report are `MappedBoardGuard`'s, unchanged (DL-292) — these
 * three arms are new CALLERS of the token path's primitive, never a second copy of it, so
 * they share its predicate, its `card_not_on_mapped_board` reason code and its
 * warn+notify channel, and are kept apart in the dedup tuple by their `$outcome`.
 *
 * ⚠ THE ARM COUNT IS NOT THE LEG COUNT, and the difference is why this class grows without
 * gaining a fourth `$outcome`. ONE arm feeds SEVERAL writes off ONE correlation — the
 * dependabot arm's stage MOVE, its closed-unmerged ARCHIVE and the DL-328 name RESTAMP are
 * three of them, all under the one `dependabot_card` outcome — and the gate they share sits
 * upstream of all of them, in `cardsForRepo`. So each write earns its own method here: a leg
 * covering one write reports on the gate over a population of one write, which is the shape
 * canon #7 exists to refuse.
 *
 * ⭐ THE FOURTH SITE IS NOW GUARDED TOO (DL-301) — and it is deliberately NOT covered here.
 * `bridge:reconcile --fix` (`ReconcileCommand`, board read → `moveCard`) calls the same
 * `MappedBoardGuard::refuses()` on every reconcilable row, under the synthetic `reconcile`
 * outcome. Its mixed-set leg lives beside the command, in
 * `ReconcileCommandTest::test_fix_moves_the_mapped_card_and_refuses_a_row_naming_another_board`,
 * because exercising it needs the whole console harness (per-repo GitHub token probe, stage-order
 * preload, `--fix`) that this class's three handler arms have no use for — the pattern is
 * carried, not the fixture. A method here that re-derived that harness would be a second copy of
 * it, which is the shape this class exists to argue against.
 */
class ResolvedRowBoardGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MAPPED_BOARD = 8;

    private const FOREIGN_BOARD = 12;

    private const ALERT_URL = 'http://127.0.0.1:9937/';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/rowboard-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/github/token', 'ghp_read');
        chmod($this->dir.'/github/token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $mapping */
    private function writeWriteback(string $repo, array $mapping): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => [$repo => ['board_id' => self::MAPPED_BOARD] + $mapping],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    /** The refusal every arm shares: MappedBoardGuard's reason code, on the named card. */
    private function assertRefused(int $cardId, string $outcome): void
    {
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['outcome'] === $outcome
            && $r['card_id'] === $cardId);
    }

    private function assertNoWriteTo(int $cardId): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), "/tasks/{$cardId}.json"));
    }

    // ---------------------------------------------------------------- promote-on-release

    public function test_promote_on_release_promotes_the_mapped_row_and_refuses_the_foreign_one(): void
    {
        // `readBoardCards` is a `q=board_id=<b>` search paged to completion; its rows drive
        // `moveCard` directly. Both rows here are Shipped with a merged, on-main PR — the
        // ONLY thing separating them is the board the row names, so the assertion pair
        // isolates the guard and nothing else.
        $this->writeWriteback('owner/repo', [
            'stages' => ['merged' => 52, 'merged_to_main' => 53],
            'promote_on_release' => true,
        ]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 5, 'board_id' => self::MAPPED_BOARD, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
                ['id' => 6, 'board_id' => self::FOREIGN_BOARD, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 100]],
            ], 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/100' => Http::response(['merged' => true, 'merge_commit_sha' => 'SHA5', 'state' => 'closed', 'base' => ['ref' => 'dev']]),
            'https://api.github.com/repos/owner/repo/compare/SHA5...main' => Http::response(['status' => 'ahead']),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 0]]),
        ]);

        $this->promote();

        // PRESENCE WITNESS — the ordinary same-board promote still happens.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && ($r['workflow_stage_id'] ?? null) === 53);
        // CONTROL — the foreign row is neither promoted nor silently dropped.
        $this->assertNoWriteTo(6);
        $this->assertRefused(6, 'promote_on_release');
    }

    // ------------------------------------------------------------------- dependabot card

    public function test_dependabot_moves_the_mapped_card_and_refuses_the_foreign_one(): void
    {
        // `correlatePr` in `scan` mode walks the same `q=board_id=<b>` board read; each id
        // is then re-read with `getCard`, so the row carrying the board is already in hand
        // and the re-check costs no request. Two cards for one repo+PR is the create-race
        // shape the handler collapses — without the guard card 6 is the LOWEST id, so it
        // would win the collapse and be the one MOVED, and card 5 would be ARCHIVED.
        config(['bridge.writeback.correlation' => 'scan']);
        $this->writeWriteback('owner/repo', [
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
            'create_dependabot_cards' => true,
        ]);
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 6, 'payload' => ['pr_number' => 42]],
                ['id' => 7, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => self::FOREIGN_BOARD, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => self::MAPPED_BOARD, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->dependabot('merged');

        // PRESENCE WITNESS — the mapped card still moves to the merged stage.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/7.json')
            && ($r['workflow_stage_id'] ?? null) === 52);
        // CONTROL — the foreign card is neither moved nor archived by the collapse.
        $this->assertNoWriteTo(6);
        $this->assertRefused(6, 'dependabot_card');
    }

    public function test_dependabot_archive_on_closed_unmerged_refuses_the_foreign_card(): void
    {
        // Another write this correlation feeds: `closed_unmerged` ARCHIVES every
        // correlated card. Archival is the least reversible write on this path, so it gets
        // its own leg rather than inheriting the move leg's result.
        config(['bridge.writeback.correlation' => 'scan']);
        $this->writeWriteback('owner/repo', [
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
            'create_dependabot_cards' => true,
        ]);
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 6, 'payload' => ['pr_number' => 42]],
                ['id' => 7, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => self::FOREIGN_BOARD, 'archived_at' => null, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => self::MAPPED_BOARD, 'archived_at' => '2026-08-22T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->dependabot('closed_unmerged');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/7.json')
            && ($r['_action'] ?? null) === 'archive');
        $this->assertNoWriteTo(6);
        $this->assertRefused(6, 'dependabot_card');
    }

    public function test_dependabot_retitle_restamps_the_mapped_card_and_refuses_the_foreign_one(): void
    {
        // A third write this correlation feeds (DL-328): an upstream retitle RESTAMPS the
        // card `name`. Its own leg, and not an inheritance of the move leg's result, because
        // its write is gated a second time INSIDE `restampNames` on byte-equality with
        // `changes.title.from` — so both rows are named byte-equal to that string here, and
        // the ownership test therefore says WRITE about both. The only thing left that can
        // separate them is the board gate, which is what makes this leg discriminate: without
        // it card 6 is renamed on a board this install does not own, and the ownership test
        // would have vouched for the write.
        config(['bridge.writeback.correlation' => 'scan']);
        $this->writeWriteback('owner/repo', [
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
            'create_dependabot_cards' => true,
        ]);
        $prUrl = 'https://github.com/owner/repo/pull/42';
        $from = 'chore(deps): Bump typescript from 5.9.0 to 7.0.2';
        $to = 'chore(deps): Bump typescript from 5.9.0 to 6.0.3';
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 6, 'payload' => ['pr_number' => 42]],
                ['id' => 7, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => self::FOREIGN_BOARD, 'name' => $from, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => self::MAPPED_BOARD, 'name' => $from, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->dependabot(KanbanDependabotCardHandler::RENAMED_OUTCOME, extra: ['name_from' => $from, 'pr_title' => $to]);

        // PRESENCE WITNESS — the mapped card is still restamped, name-only.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/7.json')
            && $r->data() === ['name' => $to]);
        // CONTROL — the foreign card is not renamed, and the refusal is reported.
        $this->assertNoWriteTo(6);
        $this->assertRefused(6, 'dependabot_card');
    }

    public function test_dependabot_refuses_a_row_whose_board_is_unreadable(): void
    {
        // FAIL-CLOSED on an absent board, stated rather than assumed: a row kanban answered
        // without a `board_id` cannot be shown to be on the mapped board, so it is refused
        // like a foreign one. Not a vector for the predicate's `is_numeric` disjunct (null
        // casts to 0 and the compare refuses it alone) — this pins the ARM's posture on an
        // unreadable board, which is what a `q=`→top-level hoist against a trimmed
        // projection would actually look like.
        config(['bridge.writeback.correlation' => 'scan']);
        $this->writeWriteback('owner/repo', [
            'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
            'create_dependabot_cards' => true,
        ]);
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 6, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->dependabot('merged');

        $this->assertNoWriteTo(6);
        $this->assertRefused(6, 'dependabot_card');
    }

    // ------------------------------------------------------------ coord-card post-create

    public function test_coord_post_create_collapse_archives_only_the_mapped_duplicate(): void
    {
        // The check-then-create race: the pre-create tag read is empty → create → the
        // post-create re-read surfaces a racer. WITHOUT the guard the foreign row (id 60)
        // is the lowest id, so it would SURVIVE and the bridge's own freshly-created card
        // (99) would be archived — a cross-board read costing us the card we just minted.
        $this->writeWriteback('org/coord', [
            'stages' => ['opened' => 50],
            'create_coord_cards' => true,
            'coord_card_stage_id' => 21,
        ]);
        Http::fake($this->coordSearchFake(
            live: [
                [],   // pre-create: nothing carries the tag
                [     // post-create re-read: our card, plus a row naming another board
                    ['id' => 60, 'board_id' => self::FOREIGN_BOARD],
                    ['id' => 99, 'board_id' => self::MAPPED_BOARD],
                    ['id' => 101, 'board_id' => self::MAPPED_BOARD],
                ],
            ],
            archived: [],
            rest: [
                self::ALERT_URL.'*' => Http::response('', 204),
                '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
                '*/tasks/101.json' => Http::response(['data' => ['id' => 101, 'archived_at' => '2026-08-22T00:00:00+00:00']]),
                // Stubbed so the UNGUARDED behaviour (foreign id 60 wins the tie-break and
                // OUR freshly-created card is retired) reds as a failed assertion below,
                // not as an unstubbed request escaping the fake.
                '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'archived_at' => '2026-08-22T00:00:00+00:00']]),
                '*/tasks/60.json' => Http::response(['data' => ['id' => 60, 'archived_at' => '2026-08-22T00:00:00+00:00']]),
            ],
        ));

        $this->coord();

        // PRESENCE WITNESS — the collapse still runs over the mapped rows: lowest mapped id
        // (99) survives, its mapped duplicate (101) is retired.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/101.json')
            && ($r['_action'] ?? null) === 'archive');
        $this->assertNoWriteTo(99);
        // CONTROL — the foreign row neither survives nor is archived.
        $this->assertNoWriteTo(60);
        $this->assertRefused(60, 'coord_card_create');
    }

    public function test_coord_by_ref_collapse_reads_each_card_and_refuses_the_foreign_one(): void
    {
        // The ONE arm that pays a request per card, and the only place this change adds a
        // read: by-ref projects to ids and carries its board in the URL PATH, so there is no
        // row to re-check without fetching one. It is paid ONLY inside `count(...) > 1` — a
        // create race — so the ordinary single-card delivery issues nothing extra, which
        // this method also pins by asserting the pre-check path issues no `getCard` at all.
        $this->writeWriteback('org/coord', [
            'stages' => ['opened' => 50],
            'create_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'issue_population' => 'all',
        ]);
        $byRef = [[], [['id' => 60], ['id' => 99], ['id' => 101]]];   // pre-check empty → create → race surfaced
        Http::fake(function (Request $request) use (&$byRef) {
            $url = $request->url();
            if (str_contains($url, '/tasks/by-ref.json')) {
                $this->assertNotSame([], $byRef, "unexpected extra by-ref read: {$url}");

                return Http::response(['data' => array_shift($byRef)]);
            }
            if (str_starts_with($url, self::ALERT_URL)) {
                return Http::response('', 204);
            }
            if (str_contains($url, '/tasks/60.json')) {
                return Http::response(['data' => ['id' => 60, 'board_id' => self::FOREIGN_BOARD, 'archived_at' => '2026-08-22T00:00:00+00:00']]);
            }
            if (str_contains($url, '/tasks/99.json')) {
                return Http::response(['data' => ['id' => 99, 'board_id' => self::MAPPED_BOARD, 'archived_at' => '2026-08-22T00:00:00+00:00']]);
            }
            if (str_contains($url, '/tasks/101.json')) {
                return Http::response(['data' => ['id' => 101, 'board_id' => self::MAPPED_BOARD, 'archived_at' => '2026-08-22T00:00:00+00:00']]);
            }
            if (str_contains($url, '/tasks.json')) {
                return Http::response(['data' => ['id' => 99]], 201);
            }
            $this->fail("unstubbed request: {$url}");
        });

        $this->coord(['sid' => null, 'itype' => 'task', 'title' => 'a plain non-prefixed title']);

        // PRESENCE WITNESS — the collapse still runs across the mapped rows.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/101.json')
            && ($r['_action'] ?? null) === 'archive');
        $this->assertNoWriteTo(99);
        // CONTROL — the foreign row is read, refused and reported; never archived, never
        // allowed to be the survivor the two mapped cards collapse onto.
        $this->assertNoWriteTo(60);
        $this->assertRefused(60, 'coord_card_create');
    }

    // ------------------------------------------------------------------------- harnesses

    private function promote(string $repo = 'owner/repo'): void
    {
        (new KanbanPromoteReleasedHandler)->handle(
            ReactionTarget::make('kanban_promote_released', $repo, payload: ['repo' => $repo]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /** @param array<string, mixed> $extra the retitle leg's `name_from` / its own `pr_title`; empty on every move-or-archive arm */
    private function dependabot(string $outcome, int $pr = 42, array $extra = []): void
    {
        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', "pr-{$pr}", payload: $extra + [
                'repo' => 'owner/repo', 'outcome' => $outcome, 'pr_number' => $pr,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/owner/repo/pull/'.$pr,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function coord(array $overrides = []): void
    {
        $payload = array_merge(['repo' => 'org/coord', 'issue_number' => 4, 'sid' => 'QUERY-4', 'itype' => 'query', 'title' => '[QUERY-4] a thread'], $overrides);
        (new KanbanCoordCardHandler)->handle(
            ReactionTarget::make('kanban_coord_card', 'issue-4', payload: $payload),
            AgentConfig::fromArray('me', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * ⛔ ONE CALLBACK, never a URL map with an `Http::sequence()` in it. Laravel invokes
     * EVERY stub for EVERY request and keeps the first non-null answer, so a
     * `search.json*` sequence is popped by the DL-296 `archived=1` read as well and the
     * post-create re-read is handed the wrong element (or an exhausted sequence). This is
     * the coord handler test's own harness, carried here rather than re-derived.
     *
     * `$live` is one `data` payload per LIVE tag search in call order (an extra call fails
     * rather than silently repeating the last answer); `$archived` answers every
     * `archived=1` search; `$rest` is an ordinary pattern => response map.
     *
     * @param  list<list<array<string, mixed>>>  $live
     * @param  list<array<string, mixed>>  $archived
     * @param  array<string, mixed>  $rest
     */
    private function coordSearchFake(array $live, array $archived, array $rest = []): Closure
    {
        return function (Request $request) use (&$live, $archived, $rest) {
            $url = $request->url();
            if (str_contains($url, '/tasks/search.json')) {
                if (str_contains($url, 'archived=1')) {
                    return Http::response(['data' => $archived]);
                }
                $this->assertNotSame([], $live, "unexpected extra LIVE tag search: {$url}");

                return Http::response(['data' => array_shift($live)]);
            }
            foreach ($rest as $pattern => $response) {
                if (Str::is(Str::start($pattern, '*'), $url)) {
                    return $response;
                }
            }
            $this->fail("unstubbed request: {$url}");
        };
    }
}
