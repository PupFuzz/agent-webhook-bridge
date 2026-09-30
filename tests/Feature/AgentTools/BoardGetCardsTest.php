<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardCardProjection;
use App\Bridge\Tools\BoardGetCardsTool;
use App\Bridge\Tools\ToolsCallStdio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\Support\KanbanBoardStatus;
use Tests\TestCase;

/**
 * `board_get_cards` (card#10832, DL-435), on both front doors.
 *
 * ⛔ THE INCIDENT (rt#572). A seat asked after ~10 known cards, read its lane and then every column,
 * and several cards were in NO read it could make: the tool omitted them without saying whether each
 * was archived, in another lane, on another board or gone, so the answer fell back to "UNREAD". Every
 * test here that asserts a status also asserts the answer holds EVERY requested id exactly once.
 */
class BoardGetCardsTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 10;

    private const FOREIGN_BOARD = 77;

    /** A string only a foreign card's row carries — it must never reach a response. */
    private const FOREIGN_MARKER = 'FOREIGN-TENANT-CONTENT';

    private string $dir;

    private string $token = 'tools-bearer-getcards';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-getcards-'.uniqid();
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
            .'  board_id: '.self::BOARD."\n  swimlane_id: 4\n  create_stage_id: 50\n  description_max_bytes: 8\n");
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
        ], (string) json_encode(['tool' => 'board_get_cards', 'args' => $args]));

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

        $fake = new FakeToolsCallStdio((string) json_encode(['tool' => 'board_get_cards', 'args' => $args]));
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
     * A card row on THIS board, as kanban's search answers it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function row(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id, 'board_id' => self::BOARD, 'swimlane_id' => 99, 'workflow_stage_id' => 51, 'position' => 1024,
            'name' => "card {$id}", 'description' => 'a body longer than eight bytes', 'tags' => ['lane:A'],
            'assigned_user_id' => null, 'payload' => ['pr_number' => 7], 'updated_at' => '2026-09-28T00:00:00+00:00',
        ], $overrides);
    }

    /**
     * The kanban surface this tool reads: the board-scoped search (both archive sides), the unscoped
     * by-id read, the board's status read (the membership control: 200 to a `$member`, else 403) and
     * the board's stages. The search also answers kanban's `limit=1` whole-board count, as kanban
     * would — the board's live cards to a member, zero to anyone else — though the tool never sends it.
     *
     * @param  array<int, array<string, mixed>>  $live  id => row on this board, live side
     * @param  array<int, array<string, mixed>>  $archived  id => row on this board, archived side
     * @param  array<int, int|array{status: int}>  $byId  id => the board the by-id read answers, or a status it answers with
     */
    private function fakeBoard(array $live = [], array $archived = [], array $byId = [], bool $member = true, ?int $searchStatus = null): void
    {
        Http::fake([
            '*/boards/'.self::BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1],
                ['id' => 51, 'name' => 'In Review', 'position' => 2],
            ]]]]]),
            '*/boards/'.self::BOARD.'/status.json' => $member ? KanbanBoardStatus::readable(self::BOARD) : KanbanBoardStatus::forbidden(),
            '*/tasks/search.json*' => function (Request $request) use ($live, $archived, $member, $searchStatus) {
                if ($searchStatus !== null) {
                    return Http::response('refused', $searchStatus);
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $q = (string) ($query['q'] ?? '');
                if ($q === 'board_id='.self::BOARD) {
                    return Http::response(['data' => [], 'meta' => ['total' => $member ? count($live) : 0]]);
                }
                if (preg_match('/^board_id='.self::BOARD.' id=(\d+)$/', $q, $m) !== 1) {
                    return Http::response('unexpected search '.$q, 500);
                }
                $side = ($query['archived'] ?? null) === '1' ? $archived : $live;

                return Http::response(['data' => isset($side[(int) $m[1]]) ? [$side[(int) $m[1]]] : []]);
            },
            '*/tasks/*/preload.json' => function (Request $request) use ($byId) {
                preg_match('#/tasks/(\d+)/preload\.json#', $request->url(), $m);
                $answer = $byId[(int) $m[1]] ?? ['status' => 404];
                if (is_array($answer)) {
                    return Http::response(['message' => $answer['status'] === 404 ? 'Not Found' : 'This action is unauthorized.'], $answer['status']);
                }

                return Http::response(['data' => ['id' => (int) $m[1], 'board_id' => $answer, 'name' => self::FOREIGN_MARKER, 'description' => self::FOREIGN_MARKER]]);
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{id: int, status: string}>
     */
    private static function idStatus(array $body): array
    {
        return array_map(static fn (array $e): array => ['id' => $e['id'], 'status' => $e['status']], $body['result']['cards']);
    }

    // ─── N in, N out ─────────────────────────────────────────────────────────

    /**
     * ⛔ THE CONTRACT: every requested id, once, in request order, each with its status — including
     * the ids no read on the seat's own board can see. Every status is exercised, and `other_board`
     * through BOTH of its routes (a readable foreign board, and a 403).
     */
    #[DataProvider('doors')]
    public function test_every_requested_id_comes_back_exactly_once_with_an_explicit_status(string $door): void
    {
        $this->fakeBoard(
            live: [101 => self::row(101), 104 => self::row(104, ['swimlane_id' => null])],
            archived: [102 => self::row(102)],
            byId: [103 => self::FOREIGN_BOARD, 105 => ['status' => 403], 106 => ['status' => 404]],
        );

        $ids = [106, 101, 105, 102, 103, 104];
        $res = $this->through($door, ['ids' => $ids]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([
            ['id' => 106, 'status' => 'not_found'],
            ['id' => 101, 'status' => 'found'],
            ['id' => 105, 'status' => 'other_board'],
            ['id' => 102, 'status' => 'archived'],
            ['id' => 103, 'status' => 'other_board'],
            ['id' => 104, 'status' => 'found'],
        ], self::idStatus($res['body']));
        $this->assertCount(count($ids), $res['body']['result']['cards'], 'N ids in must be N entries out');
        $this->assertSame(self::BOARD, $res['body']['result']['configured_board_id']);
    }

    public function test_only_a_card_on_this_board_carries_content_and_a_foreign_card_s_never_leaves_the_bridge(): void
    {
        $this->fakeBoard(live: [101 => self::row(101)], archived: [102 => self::row(102)], byId: [103 => self::FOREIGN_BOARD]);

        $res = $this->http(['ids' => [101, 102, 103, 104]]);

        $this->assertTrue($res['ok']);
        [$found, $archived, $other, $missing] = $res['body']['result']['cards'];
        $this->assertSame('card 101', $found['card']['name']);
        $this->assertSame('card 102', $archived['card']['name']);
        $this->assertArrayNotHasKey('card', $other, 'an other_board entry carries a status and nothing of the card');
        $this->assertArrayNotHasKey('card', $missing);
        $this->assertStringNotContainsString(self::FOREIGN_MARKER, (string) json_encode($res['body']));
        $this->assertStringNotContainsString((string) self::FOREIGN_BOARD, (string) json_encode($res['body']['result']['cards'][2]), 'the foreign board id is not disclosed either');
    }

    public function test_the_by_id_read_is_made_only_for_an_id_the_board_scoped_search_missed_on_both_sides(): void
    {
        $this->fakeBoard(live: [101 => self::row(101)], archived: [102 => self::row(102)]);

        $this->assertTrue($this->http(['ids' => [101, 102]])['ok']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/10') && str_contains($r->url(), '/preload.json'));
        // Live side for both; the archived side only for the live miss.
        Http::assertSentCount(3 + 1);   // three searches + the stage read
    }

    // ─── Projection ──────────────────────────────────────────────────────────

    public function test_the_default_projection_is_every_field_but_the_body_and_names_the_card_s_lane(): void
    {
        $this->fakeBoard(live: [101 => self::row(101), 104 => self::row(104, ['swimlane_id' => null, 'position' => 1.5])]);

        $res = $this->http(['ids' => [101, 104]]);

        $this->assertSame(BoardCardProjection::defaultFields(), $res['body']['result']['fields']);
        $card = $res['body']['result']['cards'][0]['card'];
        $this->assertSame(['id', 'name', 'stage', 'tags', 'assigned_user_id', 'dl_number', 'pr_number', 'pr_url', 'source', 'updated_at', 'swimlane_id', 'position'], array_keys($card));
        // JSON has one number type: a whole-number position reads back as 1024 on both hops.
        $this->assertSame(1024, $card['position']);
        $this->assertSame(1.5, $res['body']['result']['cards'][1]['card']['position']);
        $this->assertSame('In Review', $card['stage']);
        $this->assertSame(99, $card['swimlane_id']);
        $this->assertNull($res['body']['result']['cards'][1]['card']['swimlane_id'], 'a laneless card says so by name');
        $this->assertArrayNotHasKey('description', $card);
    }

    public function test_description_is_opt_in_per_call_and_arrives_with_its_truncation_flag(): void
    {
        $this->fakeBoard(live: [101 => self::row(101)]);

        $res = $this->http(['ids' => [101], 'fields' => ['name', 'description']]);

        $this->assertSame(['name' => 'card 101', 'description' => 'a body l', 'description_truncated' => true], $res['body']['result']['cards'][0]['card']);
    }

    /** A padded field name means the same on both doors — the HTTP door's middleware trims it before the tool sees it. */
    #[DataProvider('doors')]
    public function test_a_padded_field_name_is_the_field_on_both_doors(string $door): void
    {
        $this->fakeBoard(live: [101 => self::row(101)]);

        $res = $this->through($door, ['ids' => [101], 'fields' => [" name\u{00A0}"]]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame(['name'], $res['body']['result']['fields']);
        $this->assertSame(['name' => 'card 101'], $res['body']['result']['cards'][0]['card']);
    }

    public function test_a_projection_without_stage_does_not_read_the_board_s_stages(): void
    {
        $this->fakeBoard(live: [101 => self::row(101)]);

        $res = $this->http(['ids' => [101], 'fields' => ['id', 'tags']]);

        $this->assertSame(['id' => 101, 'tags' => ['lane:A']], $res['body']['result']['cards'][0]['card']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/boards/'));
    }

    public function test_an_empty_projection_answers_status_only(): void
    {
        $this->fakeBoard(live: [101 => self::row(101)]);

        $res = $this->http(['ids' => [101], 'fields' => []]);

        // `card` must be ABSENT, not an empty value: `[]` on the wire is a JSON array, while every
        // other call's `card` is an object, so an empty one would be a type change a strict
        // consumer (e.g. jq's `.card.name`) could not walk the same way as a populated one.
        $this->assertSame([['id' => 101, 'status' => 'found']], $res['body']['result']['cards']);
        $this->assertArrayNotHasKey('card', $res['body']['result']['cards'][0]);
    }

    // ─── Refused before any read ─────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedArguments(): array
    {
        return [
            'no ids' => [[], '`ids` is required'],
            'empty ids' => [['ids' => []], '`ids` is required'],
            'a decorated id' => [['ids' => [101, '102']], 'never coerced'],
            'a zero id' => [['ids' => [0]], 'never coerced'],
            'an object, not a list' => [['ids' => ['a' => 1]], '`ids` is required'],
            'a repeated id' => [['ids' => [101, 101]], 'more than once'],
            'over the cap' => [['ids' => range(1, BoardGetCardsTool::MAX_IDS + 1)], 'Split it across calls'],
            'an unknown field' => [['ids' => [101], 'fields' => ['title']], 'not a card field'],
            'fields not a list' => [['ids' => [101], 'fields' => 'name'], 'must be a list'],
            'include_description' => [['ids' => [101], 'include_description' => true], 'naming `description` in `fields`'],
        ];
    }

    /** @param  array<string, mixed>  $args */
    #[DataProvider('refusedArguments')]
    public function test_a_malformed_call_is_refused_before_any_board_read(array $args, string $needle): void
    {
        $this->fakeBoard();

        $res = $this->http($args);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString($needle, (string) $res['body']['error']);
        Http::assertNothingSent();
    }

    public function test_the_cap_itself_is_accepted(): void
    {
        $this->fakeBoard();

        $res = $this->http(['ids' => range(1, BoardGetCardsTool::MAX_IDS), 'fields' => []]);

        $this->assertTrue($res['ok']);
        $this->assertCount(BoardGetCardsTool::MAX_IDS, $res['body']['result']['cards']);
    }

    // ─── Where no status can be established, the whole call refuses ─────────

    /**
     * ⛔ A writeback user that is not a MEMBER of this board searches it and gets zero rows, then
     * 403s on every one of its own cards by id. Without the control, every card on the seat's own
     * board would come back `other_board` — the confident-wrong answer this tool exists to replace.
     */
    public function test_a_403_is_not_other_board_while_the_token_may_not_read_the_seat_s_own_board(): void
    {
        $this->fakeBoard(byId: [101 => ['status' => 403]], member: false);

        $res = $this->http(['ids' => [101]]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('NO cards were returned', (string) $res['body']['error']);
        $this->assertStringContainsString('may not read your board', (string) $res['body']['error']);
        $this->assertStringContainsString('MEMBER', (string) $res['body']['error']);
    }

    /**
     * ⛔ THE OWNER OF A TRASHED BOARD gets 200 from `status.json`, never 403 — the control fails
     * closed on `data.status: "trashed"` (the shared membership control), so this refusal must
     * NOT claim kanban answered 403 when it answered 200 (card#10856 review).
     */
    public function test_a_403_while_the_board_reads_as_trashed_does_not_claim_kanban_said_403(): void
    {
        Http::fake([
            '*/boards/'.self::BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1],
            ]]]]]),
            '*/boards/'.self::BOARD.'/status.json' => Http::response(['data' => ['id' => self::BOARD, 'status' => 'trashed']]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks/*/preload.json' => Http::response(['message' => 'This action is unauthorized.'], 403),
        ]);

        $res = $this->http(['ids' => [101]]);

        $this->assertSame(422, $res['status']);
        $error = (string) $res['body']['error'];
        $this->assertStringNotContainsString('refuses (403)', $error, $error);
        $this->assertStringContainsString('TRASHED', $error);
    }

    /**
     * An EMPTY board the token may read — a new board, or one whose every card is archived — answers
     * the membership control 200, so a 403 id on it is `other_board` rather than a refusal (card#10856:
     * the `limit=1` search the control used to be read zero there, the same as a non-member's).
     */
    public function test_a_403_on_an_empty_readable_board_is_other_board(): void
    {
        $this->fakeBoard(byId: [101 => ['status' => 403]]);

        $res = $this->http(['ids' => [101], 'fields' => []]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $this->assertSame([['id' => 101, 'status' => 'other_board']], self::idStatus($res['body']));
        $this->assertSame([self::BOARD], KanbanBoardStatus::asked());
    }

    /**
     * A 404 on the membership control's OWN read (`status.json`) must not tell the operator to
     * look for a trashed board: unlike `preload.json` / `by-ref.json`, `status.json` RESOLVES a
     * trashed board (kanban's `->withTrashed()`), so its 404 means no board carries the id at
     * all — trash is not a candidate cause on this route ({@see BoardReadRoute::MembershipStatus}).
     */
    public function test_the_control_s_own_404_does_not_blame_the_trash(): void
    {
        Http::fake([
            '*/boards/'.self::BOARD.'/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1],
            ]]]]]),
            '*/boards/'.self::BOARD.'/status.json' => Http::response(['message' => 'Not Found'], 404),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks/*/preload.json' => Http::response(['message' => 'This action is unauthorized.'], 403),
        ]);

        $res = $this->http(['ids' => [101], 'fields' => []]);

        $this->assertSame(422, $res['status'], json_encode($res['body']) ?: '');
        $error = (string) $res['body']['error'];
        $this->assertStringNotContainsString('trash', $error, $error);
        $this->assertStringContainsString('does not resolve to any board this route can see', $error);
    }

    /**
     * ⛔ A ROW THE SAME CALL ALREADY RESOLVED IS THE MEMBERSHIP PROOF (PR #822 r3). kanban's search
     * floors to membership, so a `found` or `archived` row proves the token can read this board, and
     * the control is not asked — whichever order the ids came in, because the 403 id is placed only
     * after every scoped lookup. (As first built, a board with NO live cards read 0 to the control's
     * `limit=1` search, and the call was refused over a membership it had just proven.)
     *
     * @return array<string, array{list<int>}>
     */
    public static function orderOfAResolvedAndAForbiddenId(): array
    {
        return ['resolved first' => [[102, 105]], 'forbidden first' => [[105, 102]]];
    }

    /** @param  list<int>  $ids */
    #[DataProvider('orderOfAResolvedAndAForbiddenId')]
    public function test_a_row_resolved_in_the_same_call_proves_membership_and_the_control_is_not_asked(array $ids): void
    {
        $this->fakeBoard(archived: [102 => self::row(102)], byId: [105 => ['status' => 403]]);

        $res = $this->http(['ids' => $ids, 'fields' => []]);

        $this->assertTrue($res['ok'], json_encode($res['body']) ?: '');
        $statuses = array_column($res['body']['result']['cards'], 'status', 'id');
        $this->assertSame(['archived', 'other_board'], [$statuses[102], $statuses[105]]);
        $this->assertSame([], KanbanBoardStatus::asked());
    }

    public function test_the_membership_control_is_asked_once_per_call_however_many_ids_403(): void
    {
        $this->fakeBoard(byId: [101 => ['status' => 403], 102 => ['status' => 403]]);

        $res = $this->http(['ids' => [101, 102], 'fields' => []]);

        $this->assertSame(['other_board', 'other_board'], array_column($res['body']['result']['cards'], 'status'));
        $this->assertSame([self::BOARD], KanbanBoardStatus::asked());
    }

    /**
     * The by-id read names this board while the board-scoped search missed the same id — the shape
     * a kanban whose VIEW authorization and its SEARCH scope disagree about membership would
     * produce (see `BoardGetCardsTool::placedVerdict`; not a live case on current kanban, where the
     * two agree for an API token). Refused as a BROKEN READ, never guessed into a status.
     */
    public function test_a_by_id_answer_naming_this_board_after_a_scoped_miss_is_a_broken_read(): void
    {
        $this->fakeBoard(byId: [101 => self::BOARD]);

        $res = $this->http(['ids' => [101]]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('BROKEN READ', (string) $res['body']['error']);
    }

    public function test_a_by_id_5xx_keeps_the_retryable_502(): void
    {
        $this->fakeBoard(byId: [101 => ['status' => 503]]);

        $res = $this->http(['ids' => [101]]);

        $this->assertSame(502, $res['status']);
    }

    public function test_a_refused_board_search_is_a_named_install_fault_not_an_empty_answer(): void
    {
        $this->fakeBoard(searchStatus: 401);

        $res = $this->http(['ids' => [101]]);

        $this->assertSame(422, $res['status']);
        $this->assertStringStartsWith('board_get_cards: the bridge could not read', (string) $res['body']['error']);
        $this->assertStringContainsString('NO cards were returned', (string) $res['body']['error']);
    }
}
