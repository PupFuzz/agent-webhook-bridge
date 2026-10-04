<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardCardRank;
use App\Bridge\Tools\SeatCardScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
    private static function row(int $id, int $stage, ?int $lane, ?int $assignee, float $position): array
    {
        return [
            'id' => $id, 'board_id' => 10, 'swimlane_id' => $lane, 'workflow_stage_id' => $stage,
            'name' => "card {$id}", 'tags' => [], 'assigned_user_id' => $assignee, 'position' => $position,
        ];
    }

    /**
     * A board that answers the lane search with its home-lane rows and the bare board search with
     * every row. Rows are returned in descending id, as kanban's search answers.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function board(array $rows): void
    {
        usort($rows, fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        Http::fake(function (Request $request) use ($rows) {
            $url = urldecode($request->url());
            if (str_contains($url, '/preload.json')) {
                return Http::response(self::preload());
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
            ['kanban_user_id' => self::ME, 'assignee_arm' => 'applied', 'unavailable_reason' => null, 'assigned_read_truncated' => false],
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

    public function test_a_seat_the_roster_gives_no_kanban_user_reads_only_the_unassigned_cards_in_its_lane(): void
    {
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => null, 'other' => self::OTHER]);
        $this->board([
            self::row(301, 48, self::HOME, null, 1),
            self::row(302, 48, self::HOME, self::OTHER, 2),
            self::row(303, 48, self::TOPIC, null, 3),
        ]);

        $res = $this->tool('board_my_cards');

        $this->assertSame(200, $res['status'], json_encode($res['body']));
        $this->assertSame([301], self::listed($res['body']));
        $this->assertSame('no_kanban_user', $res['body']['result']['selection']['assignee_arm']);
        $this->assertNull($res['body']['result']['selection']['assigned_read_truncated']);
        $this->assertFalse(self::boardWalkSent(), 'a seat with no kanban user has no assigned cards to walk the board for');
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
}
