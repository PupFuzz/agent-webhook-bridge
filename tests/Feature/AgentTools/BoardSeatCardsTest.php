<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardCardRank;
use App\Bridge\Tools\SeatCardScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\CoordRosterFixture;
use Tests\TestCase;

/**
 * A seat's cards are the cards ASSIGNED to it, in any lane or in none, plus the UNASSIGNED cards in
 * its home lane, listed across lanes by (stage rank, position, id) — card#11267 / rt#595, asks 1-3:
 * `board_my_cards` selects and orders by that rule, `board_take_card` takes a card assigned to the
 * seat wherever it sits, and `board_create_card` assigns the card it creates to the seat.
 * {@see SeatCardScope} owns the rule and {@see BoardCardRank}
 * the order.
 */
class BoardSeatCardsTest extends TestCase
{
    use RefreshDatabase;

    private const ME = 7001;

    private const OTHER = 7002;

    private const HOME = 4;

    private const TOPIC = 9;

    private string $dir;

    private string $token = 'tools-bearer-seatcards';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-seatcards-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);
        File::put($this->dir.'/me.yml', "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n"
            ."  auth:\n    token_path: {$this->dir}/me-tools-token\n"
            ."  board_id: 10\n  swimlane_id: ".self::HOME."\n  create_stage_id: 50\n");

        // The board's In Progress column and its pull column, as the take's start form reads them
        // (DL-449): what ranks a seat's cards, never a column's name or kanban `lane_type`.
        File::put($this->dir.'/writeback.json', (string) json_encode(['mappings' => ['o/mine' => [
            'board_id' => 10,
            'stages' => ['started' => 49, 'merged' => 53],
            'started_from_stages' => [54],
        ]]]));

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => self::ME, 'other' => self::OTHER]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tool(string $tool, array $args = []): array
    {
        CallingSeatSeal::forANewServingProcess();
        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => $tool] + ($args === [] ? [] : ['args' => $args])));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['status' => $response->status(), 'body' => $body];
    }

    /**
     * Stages 48 Backlog, 54 Prioritized, 49 In Progress, 53 Done, in that COLUMN order, which is not
     * the rank order. Every one but Done carries kanban's DEFAULT `lane_type`, `in_progress`, so a
     * rank read off the lane type could not tell them apart.
     *
     * @return array<string, mixed>
     */
    private static function preload(): array
    {
        return ['data' => [
            'swimlanes' => [['id' => self::HOME], ['id' => self::TOPIC]],
            'workflows' => [['stages' => [
                ['id' => 48, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'in_progress'],
                ['id' => 54, 'name' => 'Prioritized', 'position' => 2, 'lane_type' => 'in_progress'],
                ['id' => 49, 'name' => 'In Progress', 'position' => 3, 'lane_type' => 'in_progress'],
                ['id' => 53, 'name' => 'Done', 'position' => 4, 'lane_type' => 'done'],
            ]]],
        ]];
    }

    /** @return array<string, mixed> */
    private static function row(int $id, int $stage, ?int $lane, ?int $assignee, float $position, int $priority = 0): array
    {
        return [
            'id' => $id, 'board_id' => 10, 'swimlane_id' => $lane, 'workflow_stage_id' => $stage,
            'name' => "card {$id}", 'tags' => [], 'assigned_user_id' => $assignee, 'position' => $position,
            'priority' => $priority,
        ];
    }

    /**
     * A board that answers the lane search with its home-lane rows and the bare board search with
     * every row. Rows are returned in descending id, as kanban's search answers.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $preload  the structure read's answer; {@see preload} when null
     */
    private function board(array $rows, ?array $preload = null): void
    {
        usort($rows, fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        Http::fake(function (Request $request) use ($rows, $preload) {
            $url = urldecode($request->url());
            if (str_contains($url, '/preload.json')) {
                return Http::response($preload ?? self::preload());
            }
            if (str_contains($url, '/tasks/search.json')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $q = (string) ($query['q'] ?? '');
                $lane = preg_match('/swimlane_id=(\d+)/', $q, $m) === 1 ? (int) $m[1] : null;
                $data = array_values(array_filter($rows, fn (array $r): bool => $lane === null || $r['swimlane_id'] === $lane));

                return Http::response(['data' => $data, 'meta' => ['total' => count($data)]]);
            }

            return Http::response(['data' => ['id' => 1]], 200);
        });
    }

    /** @return list<int> the ids in `cards_by_stage`, in emitted order */
    private static function listed(array $body): array
    {
        $ids = [];
        foreach ($body['result']['cards_by_stage'] as $cards) {
            foreach ($cards as $card) {
                $ids[] = $card['id'];
            }
        }

        return $ids;
    }

    private static function boardWalkSent(): bool
    {
        $sent = false;
        Http::assertSent(function (Request $r) use (&$sent): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            if (str_contains($r->url(), '/tasks/search.json') && ($query['q'] ?? null) === 'board_id=10') {
                $sent = true;
            }

            return true;
        });

        return $sent;
    }

    public function test_my_cards_are_the_cards_assigned_to_me_anywhere_plus_the_unassigned_ones_in_my_lane(): void
    {
        $this->board([
            self::row(101, 48, self::HOME, null, 1024),          // mine: unassigned, home lane
            self::row(102, 48, self::HOME, self::ME, 2048),      // mine: assigned to me, home lane
            self::row(103, 48, self::HOME, self::OTHER, 3072),   // NOT mine: home lane, someone else's
            self::row(104, 48, self::TOPIC, self::ME, 1536),     // mine: assigned to me, topic lane
            self::row(105, 48, self::TOPIC, null, 512),          // NOT mine: unassigned, another lane
            self::row(106, 48, null, self::ME, 4096),            // mine: assigned to me, in no lane
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $ids = self::listed($res['body']);
        sort($ids);
        $this->assertSame([101, 102, 104, 106], $ids);
        $this->assertSame(4, $res['body']['result']['cards_window']['total']);
        $this->assertSame(
            ['kanban_user_id' => self::ME, 'assignee_arm' => 'applied', 'unavailable_reason' => null, 'no_kanban_user_reason' => null, 'assigned_read_truncated' => false],
            $res['body']['result']['selection'],
        );
    }

    public function test_my_cards_are_ordered_by_stage_rank_then_position_then_id_across_lanes(): void
    {
        $this->board([
            self::row(201, 48, self::HOME, null, 100),     // Backlog
            self::row(202, 49, self::TOPIC, self::ME, 50),  // In Progress, topic lane
            self::row(203, 54, self::HOME, null, 300),     // Prioritized
            self::row(204, 54, self::TOPIC, self::ME, 200), // Prioritized, topic lane, above 203
            self::row(205, 54, self::HOME, null, 200),     // Prioritized, same position as 204: id breaks the tie
            self::row(206, 53, self::HOME, null, 1),       // Done
            self::row(207, 49, self::HOME, null, 60),      // In Progress
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(['In Progress', 'Prioritized', 'Backlog', 'Done'], array_keys($res['body']['result']['cards_by_stage']));
        $this->assertSame([202, 207, 204, 205, 203, 201, 206], self::listed($res['body']));
        $this->assertSame(['in_progress_stage_id' => 49, 'pull_stage_ids' => [54], 'unmapped_reason' => null], $res['body']['result']['stage_rank']);
        $first = $res['body']['result']['cards_by_stage']['In Progress'][0];
        $this->assertSame(self::TOPIC, $first['swimlane_id']);
        $this->assertEquals(50, $first['position']);   // a whole float encodes as 50 on the wire
    }

    public function test_a_board_no_mapping_declares_is_ranked_by_column_order_with_finished_columns_last_and_says_why(): void
    {
        File::delete($this->dir.'/writeback.json');
        $this->board([
            self::row(211, 53, self::HOME, null, 1),       // Done
            self::row(212, 49, self::HOME, null, 1),       // In Progress
            self::row(213, 54, self::HOME, null, 1),       // Prioritized
            self::row(214, 48, self::HOME, null, 1),       // Backlog
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(['Backlog', 'Prioritized', 'In Progress', 'Done'], array_keys($res['body']['result']['cards_by_stage']));
        $this->assertSame(['in_progress_stage_id' => null, 'pull_stage_ids' => [], 'unmapped_reason' => 'no_mapping_on_board'], $res['body']['result']['stage_rank']);
    }

    /**
     * A seat the roster gives no kanban user ON THIS HOST may still hold cards under an id the roster
     * has not recorded here, so a held card in its lane cannot be shown not to be its own: the lane
     * is kept WHOLE, as under `unavailable` — never narrowed to the unassigned cards (card#11267 r1).
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function noKanbanUserRosters(): array
    {
        return [
            'seat absent from the roster' => [['other' => self::OTHER], SeatCardScope::ROSTER_SEAT_ABSENT],
            'seat with no kanban_user_id at all' => [['me' => null, 'other' => self::OTHER], SeatCardScope::NO_KANBAN_ID_FOR_HOST],
            'seat with an id for another host only' => [['me' => ['other-kanban.example.com' => self::ME], 'other' => self::OTHER], SeatCardScope::NO_KANBAN_ID_FOR_HOST],
        ];
    }

    /** @param  array<string, int|array<string, mixed>|null>  $roster */
    #[DataProvider('noKanbanUserRosters')]
    public function test_a_seat_the_roster_gives_no_kanban_user_keeps_its_whole_lane_and_says_which_roster_case(array $roster, string $reason): void
    {
        CoordRosterFixture::configure($this->dir.'/coord', $roster);
        $this->board([
            self::row(301, 48, self::HOME, null, 1),
            self::row(302, 48, self::HOME, self::OTHER, 2),   // held, in the seat's own lane: kept
            self::row(303, 48, self::TOPIC, null, 3),
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $ids = self::listed($res['body']);
        sort($ids);
        $this->assertSame([301, 302], $ids);
        $this->assertSame('no_kanban_user', $res['body']['result']['selection']['assignee_arm']);
        $this->assertSame($reason, $res['body']['result']['selection']['no_kanban_user_reason']);
        $this->assertNull($res['body']['result']['selection']['assigned_read_truncated']);
        $this->assertFalse(self::boardWalkSent(), 'a seat with no kanban user has no id to walk the board for');
    }

    public function test_a_roster_that_cannot_identify_the_seat_keeps_the_whole_lane_and_says_why(): void
    {
        config(['bridge.coord_config_path' => null]);
        $this->board([
            self::row(401, 48, self::HOME, null, 1),
            self::row(402, 48, self::HOME, self::OTHER, 2),
            self::row(403, 48, self::TOPIC, self::ME, 3),
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $ids = self::listed($res['body']);
        sort($ids);
        $this->assertSame([401, 402], $ids);
        $this->assertSame('unavailable', $res['body']['result']['selection']['assignee_arm']);
        $this->assertSame('install_fault.coord_config_unset', $res['body']['result']['selection']['unavailable_reason']);
        $this->assertNull($res['body']['result']['selection']['no_kanban_user_reason']);
        $this->assertFalse(self::boardWalkSent());
    }

    public function test_take_claims_a_card_assigned_to_me_in_a_lane_i_do_not_work(): void
    {
        $this->board([self::row(501, 54, self::TOPIC, self::ME, 1)]);

        $res = $this->tool('board_take_card', ['card_id' => 501]);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertTrue($res['body']['result']['already_held']);
        $this->assertSame(self::TOPIC, $res['body']['result']['swimlane_id']);
        $this->assertSame('assigned', $res['body']['result']['in_scope_by']);
    }

    /**
     * "Assigned to" is a PERMISSION test on the take's assigned arm, so it is strict: a numeric
     * STRING names nobody (card#11267 r1 — the primitive used to cast it).
     */
    public function test_a_numeric_string_assignee_is_not_assigned_to_that_user(): void
    {
        $this->assertFalse(SeatCardScope::isAssignedTo(['assigned_user_id' => '42'], 42));
        $this->assertFalse(SeatCardScope::isAssignedTo(['assigned_user_id' => 42.0], 42));
        $this->assertFalse(SeatCardScope::isAssignedTo([], 42));
        $this->assertTrue(SeatCardScope::isAssignedTo(['assigned_user_id' => 42], 42), 'the control: an integer id does match');
    }

    public function test_take_refuses_a_card_in_another_lane_whose_assignee_is_my_id_as_a_string(): void
    {
        $row = self::row(503, 54, self::TOPIC, null, 1);
        $row['assigned_user_id'] = (string) self::ME;
        $this->board([$row]);

        $res = $this->tool('board_take_card', ['card_id' => 503]);

        $this->assertSame(422, $res['status'], json_encode($res['body']));
        $this->assertSame('out_of_scope', $res['body']['reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    public function test_take_still_refuses_a_card_in_another_lane_that_is_not_assigned_to_me(): void
    {
        $this->board([self::row(502, 54, self::TOPIC, self::OTHER, 1)]);

        $res = $this->tool('board_take_card', ['card_id' => 502]);

        $this->assertSame(422, $res['status']);
        $this->assertSame('out_of_scope', $res['body']['reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    public function test_create_assigns_the_new_card_to_the_creating_seat(): void
    {
        $this->board([]);

        $res = $this->tool('board_create_card', ['title' => 'born owned']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(self::ME, $res['body']['result']['assigned_user_id']);
        $this->assertNull($res['body']['result']['assignee_unset_reason']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/tasks/1.json')
            && $r->data() === ['assigned_user_id' => self::ME]);
    }

    /**
     * The pin governs `name` and not the claim (DL-372 Decision 6): a card a seat creates already
     * pinned (`no-automove`, a hold the seat may set on its own card) is still assigned to it.
     */
    public function test_create_assigns_a_card_born_pinned_because_the_pin_governs_the_name_and_not_the_claim(): void
    {
        $this->board([]);

        $res = $this->tool('board_create_card', ['title' => 'born held', 'tags' => ['no-automove']]);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(self::ME, $res['body']['result']['assigned_user_id']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && in_array('no-automove', $r->data()['tags'] ?? [], true));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r->data() === ['assigned_user_id' => self::ME]);
    }

    public function test_create_leaves_the_card_unassigned_and_says_why_when_the_roster_gives_the_seat_no_user(): void
    {
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => null]);
        $this->board([]);

        $res = $this->tool('board_create_card', ['title' => 'born unowned']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertTrue($res['body']['result']['created']);
        $this->assertNull($res['body']['result']['assigned_user_id']);
        $this->assertSame('no_kanban_user', $res['body']['result']['assignee_unset_reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    public function test_create_still_creates_when_the_assignee_write_is_refused(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH') {
                return Http::response(['message' => 'The assignee must be a member of this board.'], 422);
            }
            if (str_contains($request->url(), '/tasks.json')) {
                return Http::response(['data' => ['id' => 1]], 201);
            }

            return Http::response(['data' => ['id' => 1, 'board_id' => 10, 'swimlane_id' => self::HOME]]);
        });

        $res = $this->tool('board_create_card', ['title' => 'born, then refused an owner']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertTrue($res['body']['result']['created']);
        $this->assertSame(1, $res['body']['result']['card_id']);
        $this->assertNull($res['body']['result']['assigned_user_id']);
        $this->assertSame('assign_failed', $res['body']['result']['assignee_unset_reason']);
    }

    public function test_create_reports_an_unanswered_assignee_write_as_unconfirmed_not_unassigned(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH') {
                return Http::failedConnection('cURL error 28: Operation timed out')($request);
            }
            if (str_contains($request->url(), '/tasks.json')) {
                return Http::response(['data' => ['id' => 1]], 201);
            }

            return Http::response(['data' => ['id' => 1, 'board_id' => 10, 'swimlane_id' => self::HOME]]);
        });

        $res = $this->tool('board_create_card', ['title' => 'born, owner unknown']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertTrue($res['body']['result']['created']);
        $this->assertNull($res['body']['result']['assigned_user_id']);
        $this->assertSame('assign_unconfirmed', $res['body']['result']['assignee_unset_reason']);
    }

    /**
     * A board whose tag search answers `$hit` for the idempotency key, and whose read-back of that
     * card answers `$readBack` (null: the read-back fails).
     *
     * @param  array<string, mixed>|null  $readBack
     */
    private function idempotentHit(int $hit, ?array $readBack): void
    {
        Http::fake(function (Request $request) use ($hit, $readBack) {
            $url = urldecode($request->url());
            if (str_contains($url, '/tasks/search.json')) {
                return Http::response(['data' => [['id' => $hit, 'board_id' => 10]], 'meta' => ['total' => 1]]);
            }
            if ($request->method() === 'GET' && str_contains($url, "/tasks/{$hit}.json")) {
                return $readBack === null ? Http::response(['message' => 'boom'], 500) : Http::response(['data' => $readBack]);
            }

            return Http::response(['data' => ['id' => $hit]], 200);
        });
    }

    public function test_a_retried_create_assigns_the_hit_card_when_nobody_holds_it(): void
    {
        $this->idempotentHit(88, ['id' => 88, 'board_id' => 10, 'swimlane_id' => self::HOME, 'assigned_user_id' => null]);

        $res = $this->tool('board_create_card', ['title' => 'retried', 'idempotency_key' => 'k-1']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertTrue($res['body']['result']['idempotent_hit']);
        $this->assertSame(self::ME, $res['body']['result']['assigned_user_id']);
        $this->assertNull($res['body']['result']['assignee_unset_reason']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/tasks/88.json')
            && $r->data() === ['assigned_user_id' => self::ME]);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_a_retried_create_reports_the_holder_of_a_hit_card_and_writes_nothing(): void
    {
        $this->idempotentHit(89, ['id' => 89, 'board_id' => 10, 'swimlane_id' => self::HOME, 'assigned_user_id' => self::OTHER]);

        $res = $this->tool('board_create_card', ['title' => 'retried', 'idempotency_key' => 'k-2']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(self::OTHER, $res['body']['result']['assigned_user_id']);
        $this->assertNull($res['body']['result']['assignee_unset_reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unreadableHitHolders(): array
    {
        return [
            'the read-back failed' => [null],
            'the read-back carries no assigned_user_id' => [['id' => 90, 'board_id' => 10, 'swimlane_id' => self::HOME]],
            'the read-back carries a non-integer assigned_user_id' => [['id' => 90, 'board_id' => 10, 'swimlane_id' => self::HOME, 'assigned_user_id' => '7002']],
        ];
    }

    /** @param  array<string, mixed>|null  $readBack */
    #[DataProvider('unreadableHitHolders')]
    public function test_a_retried_create_whose_hit_holder_cannot_be_read_writes_nothing_and_says_so(?array $readBack): void
    {
        $this->idempotentHit(90, $readBack);

        $res = $this->tool('board_create_card', ['title' => 'retried', 'idempotency_key' => 'k-3']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertNull($res['body']['result']['assigned_user_id']);
        $this->assertSame('assignee_unreadable', $res['body']['result']['assignee_unset_reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    public function test_a_retried_create_by_a_seat_with_no_kanban_user_says_why_the_hit_is_unassigned(): void
    {
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => null]);
        $this->idempotentHit(91, ['id' => 91, 'board_id' => 10, 'swimlane_id' => self::HOME, 'assigned_user_id' => null]);

        $res = $this->tool('board_create_card', ['title' => 'retried', 'idempotency_key' => 'k-4']);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertNull($res['body']['result']['assigned_user_id']);
        $this->assertSame('no_kanban_user', $res['body']['result']['assignee_unset_reason']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    // ─── the triage view (card#11268 / DL-464): one order, top tier first, a per-column cut ──

    public function test_a_top_tier_card_leads_the_triage_order_ahead_of_in_progress(): void
    {
        $this->board([
            self::row(301, 49, self::HOME, null, 10),           // In Progress
            self::row(302, 48, self::HOME, null, 500, 1),       // Backlog, High: top tier
            self::row(303, 54, self::HOME, null, 1),            // Prioritized (a pull column)
            self::row(306, 48, self::HOME, null, 2),            // Backlog
            self::row(307, 53, self::HOME, null, 1),            // Done
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(['order' => [302, 301, 303, 306, 307], 'top_tier' => [302], 'priority_unread' => 0], $res['body']['result']['triage']);
        // cards_by_stage keeps its shape: the top-tier card stays in its own column, in rank order.
        $this->assertSame([301, 303, 306, 302, 307], self::listed($res['body']));
    }

    /**
     * kanban's `priority` is -1 Low, 0 Normal, 1 High — so a truthiness test puts every LOW card on
     * top, which is the defect framework card#11293 carries. And a High card already in a finished
     * column is not something to do next.
     */
    public function test_only_priority_one_in_an_unfinished_column_is_top_tier(): void
    {
        $this->board([
            self::row(311, 48, self::HOME, null, 1, -1),        // Low
            self::row(312, 48, self::HOME, null, 2, 0),         // Normal
            self::row(313, 48, self::HOME, null, 3, 1),         // High
            self::row(314, 53, self::HOME, null, 1, 1),         // High, but Done
            self::row(315, 49, self::HOME, null, 1, -1),        // Low, In Progress
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame([313], $res['body']['result']['triage']['top_tier']);
        $this->assertSame([313, 315, 311, 312, 314], $res['body']['result']['triage']['order']);
    }

    /**
     * limit 10 over In Progress 2, Prioritized 10, Backlog 30 + one High card at the BOTTOM of
     * Backlog, Done 5. The High card is the oldest in Backlog and the last by position, so neither
     * the newest-id cut nor Backlog's own share would keep it — it survives as top tier. The other
     * nine slots: one per column in column order (4), then the unfinished columns in rounds
     * (In Progress takes its 2nd, Prioritized and Backlog their 2nd and 3rd). Done's one slot is its
     * LAST card by position — the one most recently moved in.
     */
    public function test_the_cut_shares_the_limit_by_column_and_never_cuts_a_top_tier_card(): void
    {
        $rows = [self::row(401, 49, self::HOME, null, 1), self::row(402, 49, self::HOME, null, 2)];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = self::row(411 + $i, 54, self::HOME, null, 1 + $i);
        }
        $rows[] = self::row(430, 48, self::HOME, null, 1000, 1);
        for ($i = 0; $i < 30; $i++) {
            $rows[] = self::row(431 + $i, 48, self::HOME, null, 1 + $i);
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = self::row(501 + $i, 53, self::HOME, null, 1 + $i);
        }
        $this->board($rows);

        $res = $this->tool('board_my_cards', ['limit' => 10]);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $result = $res['body']['result'];
        $this->assertSame([401, 402, 411, 412, 413, 431, 432, 433, 430, 505], self::listed($res['body']));
        $this->assertSame([430, 401, 402, 411, 412, 413, 431, 432, 433, 505], $result['triage']['order']);
        $this->assertSame([430], $result['triage']['top_tier']);
        $window = $result['cards_window'];
        $this->assertSame([48, 10, 10, true], [$window['total'], $window['returned'], $window['limit'], $window['truncated']]);
        $this->assertSame([
            ['stage_id' => 49, 'stage' => 'In Progress', 'total' => 2, 'returned' => 2],
            ['stage_id' => 54, 'stage' => 'Prioritized', 'total' => 10, 'returned' => 3],
            ['stage_id' => 48, 'stage' => 'Backlog', 'total' => 31, 'returned' => 4],
            ['stage_id' => 53, 'stage' => 'Done', 'total' => 5, 'returned' => 1],
        ], $window['per_stage']);
    }

    public function test_a_finished_column_gets_one_card_even_with_budget_left_over(): void
    {
        $rows = [self::row(601, 49, self::HOME, null, 1), self::row(602, 49, self::HOME, null, 2)];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = self::row(610 + $i, 53, self::HOME, null, 1 + $i);
        }
        $this->board($rows);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $result = $res['body']['result'];
        $this->assertSame([601, 602, 669], self::listed($res['body']), 'the finished column shows its last card by position: the one most recently moved in');
        $this->assertSame([62, 3, true], [$result['cards_window']['total'], $result['cards_window']['returned'], $result['cards_window']['truncated']]);
        $this->assertSame([
            ['stage_id' => 49, 'stage' => 'In Progress', 'total' => 2, 'returned' => 2],
            ['stage_id' => 53, 'stage' => 'Done', 'total' => 60, 'returned' => 1],
        ], $result['cards_window']['per_stage']);
    }

    public function test_top_tier_cards_are_kept_past_the_limit_and_the_rest_is_hidden(): void
    {
        $this->board([
            self::row(701, 48, self::HOME, null, 1, 1),
            self::row(702, 48, self::HOME, null, 2, 1),
            self::row(703, 49, self::HOME, null, 1),
        ]);

        $hidden = $this->tool('board_my_cards', ['limit' => 1])['body']['result'];
        $this->assertSame([3, 2, 1, true], [$hidden['cards_window']['total'], $hidden['cards_window']['returned'], $hidden['cards_window']['limit'], $hidden['cards_window']['truncated']]);
        $this->assertSame([701, 702], $hidden['triage']['order']);
        $this->assertSame(['stage_id' => 49, 'stage' => 'In Progress', 'total' => 1, 'returned' => 0], $hidden['cards_window']['per_stage'][0]);
    }

    public function test_an_all_top_tier_set_past_the_limit_is_returned_whole_and_not_truncated(): void
    {
        // More than `limit` come back and nothing is hidden, so nothing was cut.
        $this->board([self::row(701, 48, self::HOME, null, 1, 1), self::row(702, 48, self::HOME, null, 2, 1)]);
        $whole = $this->tool('board_my_cards', ['limit' => 1])['body']['result'];
        $this->assertSame([2, 2, false], [$whole['cards_window']['total'], $whole['cards_window']['returned'], $whole['cards_window']['truncated']]);
        $this->assertArrayNotHasKey('remedy', $whole['cards_window']);
    }

    /**
     * `stage` names one column, and the caller asked for it: it gets the whole `limit`, finished or
     * not — which is how a finished column is read past its one slot. A finished column's cut keeps
     * its TAIL, the cards moved in most recently, so the read advances as work ships.
     */
    public function test_a_stage_narrowed_finished_column_gets_the_whole_limit(): void
    {
        $rows = [self::row(801, 49, self::HOME, null, 1)];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = self::row(810 + $i, 53, self::HOME, null, 1 + $i);
        }
        $this->board($rows);

        $res = $this->tool('board_my_cards', ['stage' => 53, 'limit' => 20]);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame(range(850, 869), self::listed($res['body']));
        $this->assertSame([['stage_id' => 53, 'stage' => 'Done', 'total' => 60, 'returned' => 20]], $res['body']['result']['cards_window']['per_stage']);
    }

    public function test_a_set_that_fits_is_returned_whole_finished_columns_included(): void
    {
        $this->board([
            self::row(901, 49, self::HOME, null, 1),
            self::row(902, 53, self::HOME, null, 1),
            self::row(903, 53, self::HOME, null, 2),
            self::row(904, 53, self::HOME, null, 3),
        ]);

        $window = $this->tool('board_my_cards')['body']['result']['cards_window'];

        $this->assertSame([4, 4, false], [$window['total'], $window['returned'], $window['truncated']]);
        $this->assertSame(['stage_id' => 53, 'stage' => 'Done', 'total' => 3, 'returned' => 3], $window['per_stage'][1]);
    }

    /**
     * A row with no integer `priority` is read as not top tier — and COUNTED, so "no High cards" and
     * "the board sent no priority" do not read alike.
     */
    public function test_a_card_whose_row_carries_no_priority_is_counted_as_unread(): void
    {
        $noPriority = self::row(951, 48, self::HOME, null, 1);
        unset($noPriority['priority']);
        $stringPriority = self::row(952, 48, self::HOME, null, 2);
        $stringPriority['priority'] = '1';
        $this->board([$noPriority, $stringPriority, self::row(953, 48, self::HOME, null, 3, 1)]);

        $triage = $this->tool('board_my_cards')['body']['result']['triage'];

        $this->assertSame(['order' => [953, 951, 952], 'top_tier' => [953], 'priority_unread' => 2], $triage);
    }

    /**
     * A structure read that carried no columns leaves every stage uncarried. The rank tier needs only
     * the stage id and the mappings, so In Progress still leads and the pull column follows.
     */
    public function test_a_degraded_structure_read_still_lists_in_progress_first(): void
    {
        $this->board([
            self::row(1001, 48, self::HOME, null, 1),   // Backlog
            self::row(1002, 53, self::HOME, null, 1),   // Done (the mapping's merged stage)
            self::row(1003, 49, self::HOME, null, 1),   // In Progress
            self::row(1004, 54, self::HOME, null, 1),   // Prioritized
        ], ['data' => ['swimlanes' => [['id' => self::HOME]], 'workflows' => null]]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame([1003, 1004, 1001, 1002], self::listed($res['body']));
        $this->assertSame([1003, 1004, 1001, 1002], $res['body']['result']['triage']['order']);
    }

    /**
     * Top-tier cards take the budget first, so `B` can be smaller than the column count: the
     * earliest columns get their one slot, later ones and the finished column get none, and
     * `per_stage` says so.
     */
    public function test_a_budget_smaller_than_the_column_count_reaches_the_earliest_columns_only(): void
    {
        $this->board([
            self::row(1101, 49, self::HOME, null, 1),
            self::row(1102, 49, self::HOME, null, 2),
            self::row(1111, 54, self::HOME, null, 1),
            self::row(1112, 54, self::HOME, null, 2),
            self::row(1121, 48, self::HOME, null, 1),
            self::row(1122, 48, self::HOME, null, 2),
            self::row(1123, 48, self::HOME, null, 3, 1),    // High: top tier
            self::row(1131, 53, self::HOME, null, 1),
        ]);

        $res = $this->tool('board_my_cards', ['limit' => 3]);

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame([1123, 1101, 1111], $res['body']['result']['triage']['order']);
        $this->assertSame([
            ['stage_id' => 49, 'stage' => 'In Progress', 'total' => 2, 'returned' => 1],
            ['stage_id' => 54, 'stage' => 'Prioritized', 'total' => 2, 'returned' => 1],
            ['stage_id' => 48, 'stage' => 'Backlog', 'total' => 3, 'returned' => 1],
            ['stage_id' => 53, 'stage' => 'Done', 'total' => 1, 'returned' => 0],
        ], $res['body']['result']['cards_window']['per_stage']);
    }
}
