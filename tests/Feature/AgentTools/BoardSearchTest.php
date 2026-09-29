<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardSearchTool;
use App\Bridge\Tools\ToolsCallStdio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\TestCase;

/**
 * `board_search` (card#10832 Stage 2, DL-437), on both front doors.
 *
 * The kanban fake below EVALUATES the search terms it is sent over a fixture board, the way kanban's
 * `QueryParser` does (source-read at kanban `origin/dev` 54a63399): a known term filters, an unknown
 * one is disclosed as free text in `meta.free_text_terms` (kanban DL-282), rows come newest-first and
 * `meta.total` counts the whole match. So a test here asserts what a seat would get back, not only
 * which request was sent.
 */
class BoardSearchTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 10;

    private const MY_LANE = 4;

    private string $dir;

    private string $token = 'tools-bearer-search';   // gitleaks:allow — test fixture

    /** @var array<int, array<string, mixed>> id => row, live side */
    private array $live = [];

    /** @var array<int, array<string, mixed>> id => row, archived side */
    private array $archived = [];

    /** @var list<string> terms this fake kanban does NOT know, so it free-texts them */
    private array $unknownTerms = [];

    /** @var array<string, mixed>|null a whole response body served for every search instead */
    private ?array $searchBody = null;

    /** The writeback user is not a member of the board: kanban's search floors it to zero rows, at 200. */
    private bool $nonMember = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-search-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);

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

    private function writeAgent(string $transport): void
    {
        $auth = $transport === 'http' ? "  auth:\n    token_path: {$this->dir}/me-tools-token\n" : '';

        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: ".crc32('me')."\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: {$transport}\n".$auth
            .'  board_id: '.self::BOARD."\n  swimlane_id: ".self::MY_LANE."\n  create_stage_id: 50\n  description_max_bytes: 8\n");
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function http(array $args): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('http');

        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => 'board_search', 'args' => $args]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['ok' => $response->status() === 200, 'status' => $response->status(), 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function ssh(array $args): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('ssh');

        $fake = new FakeToolsCallStdio((string) json_encode(['tool' => 'board_search', 'args' => $args]));
        $this->app->instance(ToolsCallStdio::class, $fake);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => 'me'])->run();

        /** @var array<string, mixed> $body */
        $body = json_decode($fake->capturedOut(), true);

        return ['ok' => $exit === 0, 'status' => $exit, 'body' => $body];
    }

    /** @return array<string, array{string}> */
    public static function doors(): array
    {
        return ['http door' => ['http'], 'ssh door' => ['ssh']];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function through(string $door, array $args): array
    {
        return $door === 'http' ? $this->http($args) : $this->ssh($args);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function row(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id, 'board_id' => self::BOARD, 'swimlane_id' => self::MY_LANE, 'workflow_stage_id' => 50, 'position' => 1024,
            'name' => "card {$id}", 'description' => 'a body longer than eight bytes', 'tags' => [],
            'assigned_user_id' => null, 'payload' => [], 'updated_at' => '2026-09-20T10:00:00+00:00',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function card(int $id, array $overrides = [], bool $archived = false): void
    {
        if ($archived) {
            $this->archived[$id] = self::row($id, $overrides);
        } else {
            $this->live[$id] = self::row($id, $overrides);
        }
    }

    /**
     * Does `$row` satisfy one kanban `q` token? null when kanban would not recognise the token (it
     * is then free text). Mirrors the QueryParser arms this tool sends.
     *
     * @param  array<string, mixed>  $row
     */
    private function honours(string $token, array $row): ?bool
    {
        if (in_array($token, $this->unknownTerms, true)) {
            return null;
        }

        return match (true) {
            preg_match('/^board_id=(\d+)$/', $token, $m) === 1 => $row['board_id'] === (int) $m[1],
            preg_match('/^id=(\d+)$/', $token, $m) === 1 => $row['id'] === (int) $m[1],
            preg_match('/^tags:"([^"]+)"$/', $token, $m) === 1 => in_array($m[1], $row['tags'], true),
            preg_match('/^workflow_stage_id=([\d,]+)$/', $token, $m) === 1 => in_array($row['workflow_stage_id'], array_map('intval', explode(',', $m[1])), true),
            preg_match('/^name:"(.+)"$/', $token, $m) === 1 => str_contains(mb_strtolower((string) $row['name']), mb_strtolower($m[1])),
            preg_match('/^updated_at>=:(\d{4}-\d{2}-\d{2})$/', $token, $m) === 1 => substr((string) $row['updated_at'], 0, 10) >= $m[1],
            $token === 'swimlane_id=none' => $row['swimlane_id'] === null,
            preg_match('/^swimlane_id=(\d+)$/', $token, $m) === 1 => $row['swimlane_id'] === (int) $m[1],
            default => null,
        };
    }

    private function fakeKanban(int $byRefStatus = 200, ?int $searchStatus = null): void
    {
        Http::fake([
            '*/boards/'.self::BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1],
                ['id' => 51, 'name' => 'In Review', 'position' => 2],
                ['id' => 52, 'name' => 'Done', 'position' => 3],
            ]]]]]),
            '*/boards/'.self::BOARD.'/tasks/by-ref.json*' => function (Request $request) use ($byRefStatus) {
                if ($byRefStatus !== 200) {
                    return Http::response(['message' => 'This action is unauthorized.'], $byRefStatus);
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $rows = array_values(array_filter($this->live, fn (array $r): bool => ($r['payload']['pr_number'] ?? null) == ($query['ref'] ?? '')));

                return Http::response(['data' => $rows]);
            },
            '*/tasks/search.json*' => function (Request $request) use ($searchStatus) {
                if ($searchStatus !== null) {
                    return Http::response('refused', $searchStatus);
                }
                if ($this->searchBody !== null) {
                    return Http::response($this->searchBody);
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                preg_match_all('/[^\s"]+(?:"[^"]*"[^\s"]*)*|"[^"]*"/', (string) $query['q'], $tokens);
                $side = ($query['archived'] ?? null) === '1' ? $this->archived : $this->live;
                $applied = [];
                $free = [];
                $rows = [];
                foreach ($tokens[0] as $token) {
                    if ($this->honours($token, self::row(0)) === null) {
                        $free[] = $token;
                    } else {
                        $applied[] = $token;
                    }
                }
                foreach ($this->nonMember ? [] : $side as $row) {
                    foreach ($applied as $token) {
                        if (! $this->honours($token, $row)) {
                            continue 2;
                        }
                    }
                    // A free-texted term matches no card's text in this fixture.
                    if ($free === []) {
                        $rows[] = $row;
                    }
                }
                usort($rows, fn (array $a, array $b): int => $b['id'] <=> $a['id']);

                return Http::response([
                    'data' => array_slice($rows, 0, min(200, (int) ($query['limit'] ?? 50))),
                    'meta' => ['total' => count($rows), 'applied_filters' => $applied, 'free_text_terms' => $free],
                ]);
            },
        ]);
    }

    /** @return list<string> every `q` this call sent to the search, in order */
    private static function searches(): array
    {
        $qs = [];
        Http::recorded(function (Request $r) use (&$qs) {
            if (str_contains($r->url(), '/tasks/search.json')) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
                $qs[] = (string) $query['q'].(($query['archived'] ?? null) === '1' ? ' [archived]' : '');
            }

            return false;
        });

        return $qs;
    }

    /** Every request this call sent to kanban, of any route. */
    private static function sent(): int
    {
        return count(Http::recorded());
    }

    /** @return list<string> the tags `t1` … `t<n>` */
    private static function tags(int $n): array
    {
        return array_map(fn (int $i): string => "t{$i}", range(1, $n));
    }

    /** @param  array<string, mixed>  $body @return list<int> */
    private static function ids(array $body): array
    {
        return array_column($body['result']['cards'], 'id');
    }

    // ─── Matches only, each filter kanban's ──────────────────────────────────

    #[DataProvider('doors')]
    public function test_it_returns_the_matches_only_newest_first_with_an_exact_window(string $door): void
    {
        $this->card(101, ['tags' => ['lane:A']]);
        $this->card(102, ['tags' => ['lane:A', 'bug'], 'workflow_stage_id' => 51, 'swimlane_id' => 9]);
        $this->card(103, ['tags' => ['bug']]);
        $this->card(104, ['tags' => ['lane:A'], 'swimlane_id' => null]);
        $this->fakeKanban();

        $res = $this->through($door, ['tags_all' => ['lane:A']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([104, 102, 101], self::ids($res['body']));
        $this->assertSame(['configured_board_id', 'filters', 'fields', 'cards', 'window'], array_keys($res['body']['result']), 'matches only: no lane list, no column list, no grouping');
        $this->assertSame(['total' => 3, 'returned' => 3, 'limit' => BoardSearchTool::DEFAULT_LIMIT, 'truncated' => false, 'total_is_lower_bound' => false], $res['body']['result']['window']);
        $this->assertSame(['tags_all' => ['lane:A'], 'include_archived' => false, 'lane' => 'any'], $res['body']['result']['filters']);
        $this->assertSame(9, $res['body']['result']['cards'][1]['swimlane_id'], 'lane any crosses lanes and says which');
        $this->assertNull($res['body']['result']['cards'][0]['swimlane_id']);
        $this->assertArrayNotHasKey('description', $res['body']['result']['cards'][0]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function serverSideFilters(): array
    {
        return [
            'tags_all' => [['tags_all' => ['a', 'b']], 'board_id=10 tags:"a" tags:"b"'],
            'stage, by id and by name' => [['stage' => [51, 'backlog']], 'board_id=10 workflow_stage_id=51,50'],
            'name_contains' => [['name_contains' => ' fix login '], 'board_id=10 name:"fix login"'],
            'updated_since' => [['updated_since' => '2026-09-01'], 'board_id=10 updated_at>=:2026-09-01'],
            'lane mine' => [['lane' => 'mine'], 'board_id=10 swimlane_id='.self::MY_LANE],
            'lane none' => [['lane' => 'none'], 'board_id=10 swimlane_id=none'],
            'lane any' => [['lane' => 'any'], 'board_id=10'],
            'no filter' => [[], 'board_id=10'],
        ];
    }

    /**
     * Each filter kanban has a term for is PASSED THROUGH as that term, in one search — never
     * answered by reading more of the board and filtering here.
     *
     * @param  array<string, mixed>  $args
     */
    #[DataProvider('serverSideFilters')]
    public function test_each_filter_kanban_supports_is_sent_as_its_search_term(array $args, string $q): void
    {
        // A card every filter matches, in each lane shape — so no answer is empty and the membership
        // control is never asked.
        foreach ([101 => self::MY_LANE, 102 => null] as $id => $lane) {
            $this->card($id, ['tags' => ['a', 'b'], 'workflow_stage_id' => 51, 'name' => 'fix login', 'swimlane_id' => $lane]);
        }
        $this->fakeKanban();

        $res = $this->http($args);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([$q], self::searches());
    }

    public function test_the_filters_narrow_as_kanban_evaluates_them(): void
    {
        $this->card(101, ['name' => 'Fix LOGIN page', 'updated_at' => '2026-09-02T00:00:00+00:00', 'workflow_stage_id' => 51]);
        $this->card(102, ['name' => 'fix login', 'updated_at' => '2026-08-31T23:59:59+00:00', 'workflow_stage_id' => 51]);
        $this->card(103, ['name' => 'fix login', 'updated_at' => '2026-09-05T00:00:00+00:00', 'workflow_stage_id' => 50]);
        $this->card(104, ['name' => 'fix login', 'updated_at' => '2026-09-05T00:00:00+00:00', 'workflow_stage_id' => 51, 'swimlane_id' => 9]);
        $this->fakeKanban();

        $res = $this->http(['name_contains' => 'fix login', 'updated_since' => '2026-09-01', 'stage' => ['In Review'], 'lane' => 'mine']);

        $this->assertSame([101], self::ids($res['body']));
    }

    // ─── The window ──────────────────────────────────────────────────────────

    public function test_a_cut_window_keeps_the_newest_and_says_how_many_matched(): void
    {
        foreach (range(101, 110) as $id) {
            $this->card($id);
        }
        $this->fakeKanban();

        $res = $this->http(['limit' => 3, 'fields' => ['id']]);

        $this->assertSame([110, 109, 108], self::ids($res['body']));
        $this->assertSame(['total' => 10, 'returned' => 3, 'limit' => 3, 'truncated' => true, 'total_is_lower_bound' => false], $res['body']['result']['window']);
        $this->assertCount(1, self::searches(), 'kanban counts the whole match; nothing is walked');
    }

    public function test_tags_any_is_one_search_per_tag_merged_with_an_exact_total_when_every_search_was_complete(): void
    {
        $this->card(101, ['tags' => ['a']]);
        $this->card(102, ['tags' => ['a', 'b']]);
        $this->card(103, ['tags' => ['b']]);
        $this->card(104, ['tags' => ['c']]);
        $this->fakeKanban();

        $res = $this->http(['tags_any' => ['a', 'b'], 'fields' => ['id']]);

        $this->assertSame([103, 102, 101], self::ids($res['body']), 'a card carrying both tags appears once');
        $this->assertSame(['total' => 3, 'returned' => 3, 'limit' => BoardSearchTool::DEFAULT_LIMIT, 'truncated' => false, 'total_is_lower_bound' => false], $res['body']['result']['window']);
        $this->assertSame(['board_id=10 tags:"a"', 'board_id=10 tags:"b"'], self::searches());
    }

    /**
     * ⛔ A union kanban was not asked to size cannot be sized exactly from per-tag counts: two cards
     * carrying both tags would be counted twice. So a cut union says its total is a LOWER bound —
     * and that the window is cut — while the window itself is still exactly the newest.
     */
    public function test_a_cut_tags_any_union_reports_a_lower_bound_and_still_keeps_the_newest(): void
    {
        foreach ([101 => ['a'], 102 => ['a', 'b'], 103 => ['b'], 104 => ['a', 'b'], 105 => ['a']] as $id => $tags) {
            $this->card($id, ['tags' => $tags]);
        }
        $this->fakeKanban();

        $res = $this->http(['tags_any' => ['a', 'b'], 'limit' => 2, 'fields' => ['id']]);

        $this->assertSame([105, 104], self::ids($res['body']));
        $window = $res['body']['result']['window'];
        $this->assertTrue($window['total_is_lower_bound']);
        $this->assertTrue($window['truncated']);
        $this->assertSame(4, $window['total'], 'the larger per-tag count (a: 4) is the floor the union cannot be under');
    }

    public function test_include_archived_reads_both_archive_sides_and_marks_each_card(): void
    {
        $this->card(101, ['tags' => ['x']]);
        $this->card(102, ['tags' => ['x']], archived: true);
        $this->card(103, ['tags' => ['y']], archived: true);
        $this->fakeKanban();

        $res = $this->http(['tags_all' => ['x'], 'include_archived' => true, 'fields' => ['id']]);

        $this->assertSame([['id' => 102, 'archived' => true], ['id' => 101, 'archived' => false]], $res['body']['result']['cards']);
        $this->assertSame(2, $res['body']['result']['window']['total']);
        $this->assertSame(['board_id=10 tags:"x"', 'board_id=10 tags:"x" [archived]'], self::searches());
    }

    public function test_without_include_archived_a_card_carries_no_archived_key_and_the_archive_is_not_read(): void
    {
        $this->card(101);
        $this->card(102, [], archived: true);
        $this->fakeKanban();

        $res = $this->http(['fields' => ['id']]);

        $this->assertSame([['id' => 101]], $res['body']['result']['cards']);
        $this->assertSame(['board_id=10'], self::searches());
    }

    // ─── pr_number ───────────────────────────────────────────────────────────

    public function test_pr_number_alone_is_kanban_s_by_ref_index_and_no_search(): void
    {
        $this->card(101, ['payload' => ['pr_number' => 7]]);
        $this->card(102, ['payload' => ['pr_number' => 8]]);
        $this->card(103, ['payload' => ['pr_number' => 7]]);
        $this->fakeKanban();

        $res = $this->http(['pr_number' => 7, 'fields' => ['id', 'pr_number']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([103, 101], self::ids($res['body']));
        $this->assertSame(2, $res['body']['result']['window']['total']);
        $this->assertSame([], self::searches());
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '/boards/10/tasks/by-ref.json?system=github_pr&ref=7'));
    }

    /** With another filter, each PR card is re-asked with that filter, so kanban still decides it. */
    public function test_pr_number_with_other_filters_re_asks_each_candidate_through_the_search(): void
    {
        $this->card(101, ['payload' => ['pr_number' => 7], 'tags' => ['lane:A']]);
        $this->card(103, ['payload' => ['pr_number' => 7]]);
        $this->fakeKanban();

        $res = $this->http(['pr_number' => 7, 'tags_all' => ['lane:A'], 'fields' => ['id']]);

        $this->assertSame([101], self::ids($res['body']));
        $this->assertSame(1, $res['body']['result']['window']['total']);
        $this->assertSame(['board_id=10 id=101 tags:"lane:A"', 'board_id=10 id=103 tags:"lane:A"'], self::searches());
    }

    public function test_pr_number_with_include_archived_is_refused_naming_the_gap(): void
    {
        $this->fakeKanban();

        $res = $this->http(['pr_number' => 7, 'include_archived' => true]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('by-ref index', (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    public function test_a_refused_by_ref_read_is_a_named_install_fault(): void
    {
        $this->fakeKanban(byRefStatus: 403);

        $res = $this->http(['pr_number' => 7]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('NO cards were returned', (string) $res['body']['error']);
    }

    // ─── summary ─────────────────────────────────────────────────────────────

    public function test_summary_is_kanban_s_own_counts_per_stage_and_per_tag_and_no_cards(): void
    {
        $this->card(101, ['tags' => ['lane:A', 'bug']]);
        $this->card(102, ['tags' => ['lane:A'], 'workflow_stage_id' => 51]);
        $this->card(103, ['tags' => ['lane:A', 'bug'], 'workflow_stage_id' => 51]);
        $this->card(104, ['tags' => ['other'], 'workflow_stage_id' => 51]);
        $this->fakeKanban();

        $res = $this->http(['tags_all' => ['lane:A'], 'summary' => true, 'summary_tags' => ['bug', 'nope']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertArrayNotHasKey('cards', $res['body']['result']);
        $this->assertSame([
            'total' => 3,
            'total_is_lower_bound' => false,
            'truncated' => false,
            'by_stage' => [
                ['id' => 50, 'name' => 'Backlog', 'count' => 1],
                ['id' => 51, 'name' => 'In Review', 'count' => 2],
                ['id' => 52, 'name' => 'Done', 'count' => 0],
            ],
            'stage_counts_sum_to_total' => true,
            'by_tag' => [['tag' => 'bug', 'count' => 2], ['tag' => 'nope', 'count' => 0]],
        ], $res['body']['result']['summary']);
        foreach (self::searches() as $q) {
            $this->assertStringStartsWith('board_id=10 tags:"lane:A"', $q, 'every count carries the filters');
        }
    }

    public function test_a_summary_narrowed_by_stage_counts_only_those_stages(): void
    {
        $this->card(101);
        $this->card(102, ['workflow_stage_id' => 51]);
        $this->fakeKanban();

        $res = $this->http(['stage' => [51], 'summary' => true, 'include_archived' => true]);

        $this->assertSame(1, $res['body']['result']['summary']['total']);
        $this->assertSame([['id' => 51, 'name' => 'In Review', 'count' => 1]], $res['body']['result']['summary']['by_stage']);
    }

    public function test_a_pr_number_summary_tallies_the_complete_by_ref_population(): void
    {
        $this->card(101, ['payload' => ['pr_number' => 7], 'tags' => ['bug']]);
        $this->card(103, ['payload' => ['pr_number' => 7], 'workflow_stage_id' => 52]);
        $this->fakeKanban();

        $res = $this->http(['pr_number' => 7, 'summary' => true, 'summary_tags' => ['bug']]);

        $summary = $res['body']['result']['summary'];
        $this->assertSame(2, $summary['total']);
        $this->assertSame([1, 0, 1], array_column($summary['by_stage'], 'count'));
        $this->assertSame([['tag' => 'bug', 'count' => 1]], $summary['by_tag']);
    }

    // ─── An answer kanban cannot vouch for is refused ────────────────────────

    /**
     * ⛔ THE HAZARD: a kanban that does not know a term searches it as TEXT at 200. `swimlane_id=none`
     * on a kanban before v0.45.0 is the live instance — a count of cards whose text matches.
     */
    public function test_a_filter_kanban_searched_as_text_refuses_the_call(): void
    {
        $this->card(101, ['swimlane_id' => null]);
        $this->unknownTerms = ['swimlane_id=none'];
        $this->fakeKanban();

        $res = $this->http(['lane' => 'none']);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('`swimlane_id=none` as TEXT', (string) $res['body']['error']);
        $this->assertStringContainsString('NO cards were returned', (string) $res['body']['error']);
    }

    /** @return array<string, array{array<string, mixed>, int, string}> */
    public static function unvouchedAnswers(): array
    {
        $row = self::row(101);

        return [
            'no parse disclosure (kanban before v0.47.0)' => [['data' => [$row], 'meta' => ['total' => 1]], 422, 'meta.free_text_terms'],
            'no match count' => [['data' => [$row], 'meta' => ['applied_filters' => ['board_id=10'], 'free_text_terms' => []]], 422, 'meta.total'],
            'a row of another board' => [['data' => [self::row(101, ['board_id' => 77, 'name' => 'FOREIGN'])], 'meta' => ['total' => 1, 'applied_filters' => ['board_id=10'], 'free_text_terms' => []]], 422, 'BROKEN READ'],
            'no card collection' => [['meta' => ['total' => 1, 'applied_filters' => ['board_id=10'], 'free_text_terms' => []]], 502, 'upstream board error'],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('unvouchedAnswers')]
    public function test_an_answer_kanban_does_not_vouch_for_is_never_returned(array $body, int $status, string $needle): void
    {
        $this->fakeKanban();
        $this->searchBody = $body;

        $res = $this->http([]);

        $this->assertSame($status, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString($needle, (string) $res['body']['error']);
        $this->assertStringNotContainsString('FOREIGN', (string) json_encode($res['body']));
    }

    public function test_a_refused_search_is_a_named_install_fault_not_an_empty_answer(): void
    {
        $this->fakeKanban(searchStatus: 401);

        $res = $this->http([]);

        $this->assertSame(422, $res['status']);
        $this->assertStringStartsWith('board_search: the bridge could not read', (string) $res['body']['error']);
    }

    // ─── The per-call request ceiling: ONE planned total, every read counted ────

    /** The fan-out is bounded before it is sent, with its own count. */
    public function test_a_call_whose_fan_out_exceeds_the_ceiling_is_refused_before_any_read(): void
    {
        $this->fakeKanban();

        $res = $this->http(['tags_any' => self::tags(intdiv(BoardSearchTool::REQUEST_CEILING, 2) + 1), 'include_archived' => true]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('per-call ceiling of '.BoardSearchTool::REQUEST_CEILING, (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    public function test_the_ceiling_itself_is_accepted(): void
    {
        $this->card(101, ['tags' => ['other']]);
        $this->fakeKanban();

        $res = $this->http(['tags_any' => self::tags(intdiv(BoardSearchTool::REQUEST_CEILING, 2)), 'include_archived' => true, 'fields' => ['id']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertLessThanOrEqual(BoardSearchTool::REQUEST_CEILING, self::sent());
    }

    /**
     * The default `fields` selects `stage`, so the call pays the structure read too: `tags_any` of
     * CEILING − 1 tags is CEILING − 1 searches + 1 structure read + 1 membership control, one over.
     */
    public function test_the_structure_read_is_counted_so_a_default_fields_tags_any_one_over_is_refused_before_any_read(): void
    {
        $this->fakeKanban();

        $res = $this->http(['tags_any' => self::tags(BoardSearchTool::REQUEST_CEILING - 1)]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString('would send '.(BoardSearchTool::REQUEST_CEILING + 1).' kanban requests in all (1 the board structure read', (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    /** …and the same call one tag smaller sends EXACTLY the ceiling, the control included — never more. */
    public function test_a_default_fields_tags_any_at_the_ceiling_sends_no_more_than_it(): void
    {
        $this->card(101, ['tags' => ['other']]);
        $this->fakeKanban();

        $res = $this->http(['tags_any' => self::tags(BoardSearchTool::REQUEST_CEILING - 2)]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame(BoardSearchTool::REQUEST_CEILING, self::sent(), 'structure read + every search + the membership control');
    }

    /** A rows call with no structure read: CEILING searches + the membership control, one over. */
    public function test_the_membership_control_is_counted_so_a_rows_call_of_ceiling_searches_is_refused_before_any_read(): void
    {
        $this->fakeKanban();

        $res = $this->http(['tags_any' => self::tags(BoardSearchTool::REQUEST_CEILING), 'fields' => ['id']]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString('would send '.(BoardSearchTool::REQUEST_CEILING + 1).' kanban requests', (string) $res['body']['error']);
        $this->assertStringContainsString('1 membership control', (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    /** `summary_tags` alone decide this summary is over, so it is refused before the structure read. */
    public function test_a_summary_whose_arguments_alone_exceed_the_ceiling_is_refused_before_any_read(): void
    {
        $this->fakeKanban();

        $res = $this->http(['summary' => true, 'summary_tags' => self::tags(BoardSearchTool::REQUEST_CEILING)]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        Http::assertNothingSent();
    }

    /**
     * A summary over every column is sized by the structure read (the fixture board has 3): 1
     * structure + (1 total + 3 columns + N tags) + 1 control, with N the smallest over the ceiling.
     * The arguments alone fit, so this is refused after that one read, before any count.
     */
    public function test_a_summary_sized_by_the_board_s_columns_is_refused_before_any_count(): void
    {
        $this->fakeKanban();

        $res = $this->http(['summary' => true, 'summary_tags' => self::tags(BoardSearchTool::REQUEST_CEILING - 5)]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString('would send '.(BoardSearchTool::REQUEST_CEILING + 1).' kanban requests', (string) $res['body']['error']);
        $this->assertSame([], self::searches());
        $this->assertSame(1, self::sent(), 'only the structure read that sized it');
    }

    /** Three cards carry PR 7 in the seat's lane; `lane: mine` makes each a re-ask. */
    private function threePrCardsInMyLane(): void
    {
        foreach ([101, 102, 103] as $id) {
            $this->card($id, ['payload' => ['pr_number' => 7], 'tags' => ['t1']]);
        }
        $this->fakeKanban();
    }

    /**
     * ⛔ The re-asks and the `summary_tags` tally are ONE total, sized by the by-ref read and refused
     * before any search: 1 structure + 1 by-ref + 3 re-asks + 3 × N tallies + 1 control. N is the
     * smallest over the ceiling, so each phase alone (≤ CEILING) would pass a per-phase check.
     */
    public function test_a_pr_number_summary_is_refused_on_its_whole_total_before_any_search(): void
    {
        $this->threePrCardsInMyLane();
        $n = intdiv(BoardSearchTool::REQUEST_CEILING - 6, 3) + 1;

        $res = $this->http(['pr_number' => 7, 'lane' => 'mine', 'summary' => true, 'summary_tags' => self::tags($n)]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString('would send '.(6 + 3 * $n).' kanban requests in all', (string) $res['body']['error']);
        $this->assertSame([], self::searches(), 'refused before the first re-ask');
        $this->assertSame(2, self::sent(), 'only the two reads that size the call: the structure read and the by-ref read');
    }

    public function test_a_pr_number_summary_at_the_ceiling_sends_no_more_than_it(): void
    {
        $this->threePrCardsInMyLane();
        $n = intdiv(BoardSearchTool::REQUEST_CEILING - 6, 3);

        $res = $this->http(['pr_number' => 7, 'lane' => 'mine', 'summary' => true, 'summary_tags' => self::tags($n)]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame(3, $res['body']['result']['summary']['total']);
        $this->assertLessThanOrEqual(BoardSearchTool::REQUEST_CEILING, self::sent());
    }

    // ─── Membership: "no matches" is said only of a board the token can read ─

    /** kanban's search answers a non-member ZERO rows at 200 — the same body as "nothing matched". */
    #[DataProvider('doors')]
    public function test_a_board_the_token_cannot_read_is_refused_not_answered_as_no_matches(string $door): void
    {
        $this->card(101, ['tags' => ['lane:A']]);
        $this->nonMember = true;
        $this->fakeKanban();

        $res = $this->through($door, ['tags_all' => ['lane:A']]);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('MEMBER', (string) json_encode($res['body']));
        $this->assertStringNotContainsString('"cards"', (string) json_encode($res['body']));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function searchedShapes(): array
    {
        return [
            'a summary' => [['summary' => true]],
            'a pr_number re-ask' => [['pr_number' => 7, 'lane' => 'mine']],
            'a pr_number summary tally' => [['pr_number' => 7, 'summary' => true, 'summary_tags' => ['lane:A']]],
        ];
    }

    /**
     * Every shape that searches is held to the control — the `pr_number` tally included, whose
     * columns come from the by-ref read (kanban's `view` policy, NOT floored to membership) and would
     * otherwise sit beside silently-zero tag counts.
     *
     * @param  array<string, mixed>  $args
     */
    #[DataProvider('searchedShapes')]
    public function test_every_shape_that_searches_refuses_on_a_board_the_token_cannot_read(array $args): void
    {
        $this->card(101, ['tags' => ['lane:A'], 'payload' => ['pr_number' => 7]]);
        $this->nonMember = true;
        $this->fakeKanban();

        $res = $this->http($args);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString('MEMBER', (string) $res['body']['error']);
    }

    /** `pr_number` alone sends no search: the by-ref read's answer stands, and the control is not asked. */
    public function test_pr_number_alone_asks_no_control(): void
    {
        $this->card(101, ['payload' => ['pr_number' => 7]]);
        $this->nonMember = true;
        $this->fakeKanban();

        $res = $this->http(['pr_number' => 7, 'fields' => ['id']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([101], self::ids($res['body']));
        $this->assertSame([], self::searches());
    }

    public function test_no_matches_on_a_readable_board_is_answered_after_one_control(): void
    {
        $this->card(101, ['tags' => ['other']]);
        $this->fakeKanban();

        $res = $this->http(['tags_all' => ['lane:A'], 'fields' => ['id']]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([], $res['body']['result']['cards']);
        $this->assertSame(['board_id=10 tags:"lane:A"', 'board_id=10'], self::searches(), 'the search, then the one membership control');
    }

    public function test_a_match_is_the_membership_proof_and_no_control_is_asked(): void
    {
        $this->card(101, ['tags' => ['lane:A']]);
        $this->fakeKanban();

        $this->http(['tags_all' => ['lane:A'], 'fields' => ['id']]);

        $this->assertSame(['board_id=10 tags:"lane:A"'], self::searches());
    }

    // ─── Projection ──────────────────────────────────────────────────────────

    public function test_description_is_opt_in_and_arrives_with_its_truncation_flag(): void
    {
        $this->card(101);
        $this->fakeKanban();

        $res = $this->http(['fields' => ['name', 'description']]);

        $this->assertSame([['name' => 'card 101', 'description' => 'a body l', 'description_truncated' => true]], $res['body']['result']['cards']);
    }

    // ─── Refused before any read ─────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedArguments(): array
    {
        return [
            'tags_all empty' => [['tags_all' => []], 'non-empty LIST'],
            'tags_all a string' => [['tags_all' => 'a'], 'non-empty LIST'],
            'a blank tag' => [['tags_any' => ['a', ' ']], 'non-empty tag'],
            'a glob tag' => [['tags_all' => ['a*']], 'may not contain'],
            'stage not a list' => [['stage' => 51], 'non-empty LIST'],
            'stage entry a float' => [['stage' => [51.5]], 'never coerced'],
            'pr_number decorated' => [['pr_number' => '#7'], 'positive integer'],
            'name_contains with a quote' => [['name_contains' => 'a"b'], 'may not contain'],
            'name_contains blank' => [['name_contains' => "\u{00A0}"], 'non-empty string'],
            'updated_since a timestamp' => [['updated_since' => '2026-09-01T10:00:00Z'], 'calendar DATE'],
            'updated_since not a date' => [['updated_since' => '2026-02-30'], 'calendar DATE'],
            'include_archived a string' => [['include_archived' => 'yes'], 'must be a boolean'],
            'lane unknown' => [['lane' => 'theirs'], '`lane` must be one of'],
            'limit over the page cap' => [['limit' => BoardSearchTool::MAX_LIMIT + 1], 'from 1 to'],
            'limit zero' => [['limit' => 0], 'from 1 to'],
            'fields empty' => [['fields' => []], 'empty card'],
            'an unknown field' => [['fields' => ['title']], 'not a card field'],
            'summary_tags without summary' => [['summary_tags' => ['a']], 'would change nothing'],
            'fields with summary' => [['summary' => true, 'fields' => ['id']], 'would change nothing'],
            'limit with summary' => [['summary' => true, 'limit' => 5], 'would change nothing'],
            'summary over a tags_any union' => [['summary' => true, 'tags_any' => ['a', 'b']], 'counted twice'],
            'include_description' => [['include_description' => true], 'naming `description` in `fields`'],
        ];
    }

    /** @param  array<string, mixed>  $args */
    #[DataProvider('refusedArguments')]
    public function test_a_malformed_call_is_refused_before_any_board_read(array $args, string $needle): void
    {
        $this->fakeKanban();

        $res = $this->http($args);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $this->assertStringContainsString($needle, (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    public function test_a_stage_name_that_names_nothing_is_refused_before_any_search(): void
    {
        $this->fakeKanban();

        $res = $this->http(['stage' => ['Nowhere']]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('does not name any stage', (string) $res['body']['error']);
        $this->assertSame([], self::searches());
    }
}
