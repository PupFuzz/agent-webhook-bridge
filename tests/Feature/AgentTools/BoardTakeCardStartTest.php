<?php

namespace Tests\Feature\AgentTools;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\CoordRosterFixture;
use Tests\TestCase;

/**
 * `board_take_card` with `start: true` (card#11150, DL-449): ONE write moves the card into the
 * board's In Progress column AND assigns it to the calling seat, then both are read back.
 *
 * The board below is the reference shape: Prioritized (47) is the only column writeback.json
 * lists in `started_from_stages`, so Backlog (48) is NOT start-eligible — the writeback's own
 * `started` move refuses it too. In Progress (49) is the mapping's `started` stage.
 *
 * Every PATCH the fake receives is APPLIED to the card it answers searches with, so a read-back
 * sees what the board stored — and `$storesNothing` makes the board answer 2xx while storing
 * nothing, which is the one failure only a read-back can see.
 */
class BoardTakeCardStartTest extends TestCase
{
    use RefreshDatabase;

    private const STAGES = [
        ['id' => 47, 'name' => 'Prioritized', 'position' => 1, 'lane_type' => 'backlog_inventory'],
        ['id' => 48, 'name' => 'Backlog', 'position' => 0.5, 'lane_type' => 'backlog_inventory'],
        ['id' => 49, 'name' => 'In Progress', 'position' => 2, 'lane_type' => 'in_progress'],
        ['id' => 50, 'name' => 'In Review', 'position' => 3, 'lane_type' => 'in_progress'],
        ['id' => 52, 'name' => 'Shipped to dev', 'position' => 4, 'lane_type' => 'waiting'],
        ['id' => 53, 'name' => 'Released to main', 'position' => 5, 'lane_type' => 'done'],
        ['id' => 113, 'name' => 'Done', 'position' => 6, 'lane_type' => 'done'],
    ];

    private string $dir;

    private string $token = 'tools-bearer-start';   // gitleaks:allow — test fixture

    /** @var list<array<string, mixed>> */
    private array $patches = [];

    /** @var list<array<string, mixed>> */
    private array $comments = [];

    /** @var list<string> */
    private array $sequence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-start-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);
        // The seat's kanban user id is its coord roster entry's (DL-450), never a YAML key.
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => $this->me()]);
        File::put($this->dir.'/me.yml', "subscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/me-tools-token\n"
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 48\n");
        $this->writeWriteback(['o/mine' => [
            'board_id' => 10,
            'stages' => ['started' => 49, 'merged' => 52, 'merged_to_main' => 53],
            'started_from_stages' => [47],
        ]]);

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

    private function me(): int
    {
        return crc32('me');
    }

    /** @param array<string, mixed> $mappings */
    private function writeWriteback(array $mappings): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode(['mappings' => $mappings]));
    }

    /**
     * A stateful board holding card 42 in this seat's own lane.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function board(array $overrides = [], int $patchStatus = 200, bool $storesNothing = false, bool $brokenReadBack = false, ?int $readBackStatus = null, ?int $preloadStatus = null, bool $broken = false): void
    {
        $card = array_merge([
            'id' => 42, 'board_id' => 10, 'swimlane_id' => 4, 'name' => 'queued for me',
            'tags' => ['type:feature'], 'assigned_user_id' => null, 'workflow_stage_id' => 47,
        ], $overrides);

        Http::fake(function ($request) use (&$card, $patchStatus, $storesNothing, $brokenReadBack, $readBackStatus, $preloadStatus, $broken) {
            $url = urldecode($request->url());
            if (str_contains($url, '/tasks/search.json')) {
                if ($readBackStatus !== null && $this->patches !== []) {
                    return Http::response('nope', $readBackStatus);
                }
                $row = $broken || ($brokenReadBack && $this->patches !== []) ? array_merge($card, ['id' => 777]) : $card;

                return Http::response(['data' => str_contains($url, 'archived=1') ? [] : [$row]]);
            }
            if (str_contains($url, '/boards/10/preload.json')) {
                if ($preloadStatus !== null) {
                    return Http::response('nope', $preloadStatus);
                }

                return Http::response(['data' => ['workflows' => [['stages' => self::STAGES]]]]);
            }
            if (str_contains($url, '/comments.json')) {
                $this->comments[] = $request->data();

                return Http::response(['data' => ['id' => 1]], 201);
            }
            if ($request->method() === 'PATCH') {
                $this->sequence[] = 'PATCH';
                $this->patches[] = $request->data();
                if ($patchStatus !== 200) {
                    return Http::response('nope', $patchStatus);
                }
                if (! $storesNothing) {
                    $card = array_merge($card, $request->data());
                }

                return Http::response(['data' => ['id' => 42]]);
            }

            return Http::response('unexpected '.$request->method().' '.$url, 500);
        });
    }

    /** @param array<string, mixed> $args */
    private function take(array $args): TestResponse
    {
        CallingSeatSeal::forANewServingProcess();

        return $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => 'board_take_card', 'args' => $args]));
    }

    private function start(): TestResponse
    {
        return $this->take(['card_id' => 42, 'start' => true]);
    }

    public function test_start_from_a_start_eligible_column_moves_and_assigns_in_one_write(): void
    {
        $this->board();

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.taken', true)
            ->assertJsonPath('result.moved', true)
            ->assertJsonPath('result.assigned', true)
            ->assertJsonPath('result.replaced', null)
            ->assertJsonPath('result.from_stage_id', 47)
            ->assertJsonPath('result.stage_id', 49)
            ->assertJsonPath('result.assigned_user_id', $this->me());
        $this->assertSame([['workflow_stage_id' => 49, 'assigned_user_id' => $this->me()]], $this->patches, 'ONE write carries the move and the assignee');
    }

    public function test_start_on_a_card_already_in_progress_assigns_without_moving(): void
    {
        $this->board(['workflow_stage_id' => 49]);

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.moved', false)
            ->assertJsonPath('result.assigned', true)
            ->assertJsonPath('result.stage_id', 49);
        $this->assertSame([['assigned_user_id' => $this->me()]], $this->patches);
    }

    public function test_start_on_a_card_already_in_progress_and_held_by_this_seat_writes_nothing(): void
    {
        $this->board(['workflow_stage_id' => 49, 'assigned_user_id' => $this->me()]);

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.already_held', true)
            ->assertJsonPath('result.moved', false)
            ->assertJsonPath('result.assigned', false)
            ->assertJsonPath('result.stage_id', 49);
        $this->assertSame([], $this->patches);
    }

    /** A seat that already holds a Prioritized card: the start is a column-only move. */
    public function test_start_on_a_card_this_seat_already_holds_moves_it_without_rewriting_the_assignee(): void
    {
        $this->board(['assigned_user_id' => $this->me()]);

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.moved', true)
            ->assertJsonPath('result.assigned', false)
            ->assertJsonPath('result.already_held', true);
        $this->assertSame([['workflow_stage_id' => 49]], $this->patches);
    }

    /**
     * card#11267: a card routed to this seat by its assignee, in a lane the seat does not work, starts
     * like any card in its own lane — the case a topic lane put out of reach.
     */
    public function test_start_moves_a_card_assigned_to_this_seat_in_a_lane_it_does_not_work(): void
    {
        $this->board(['swimlane_id' => 9, 'assigned_user_id' => $this->me()]);

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.in_scope_by', 'assigned')
            ->assertJsonPath('result.swimlane_id', 9)
            ->assertJsonPath('result.moved', true)
            ->assertJsonPath('result.already_held', true);
        $this->assertSame([['workflow_stage_id' => 49]], $this->patches);
    }

    /** @return array<string, array{int, string}> */
    public static function finishedColumns(): array
    {
        return [
            'Shipped to dev (mapping merged)' => [52, 'Shipped to dev'],
            'Done (board done)' => [113, 'Done'],
        ];
    }

    #[DataProvider('finishedColumns')]
    public function test_start_refuses_a_card_in_a_finished_column_by_name_and_writes_nothing(int $stage, string $column): void
    {
        $this->board(['workflow_stage_id' => $stage]);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'finished_column');
        $this->assertStringContainsString("FINISHED column ({$column})", (string) $res->json('error'));
        $this->assertSame([], $this->patches);
    }

    /**
     * Backlog is not in `started_from_stages`, and In Review is past In Progress: the writeback's
     * own `started` move refuses both (DL-160 — it promotes only from the declared columns), so
     * the start form refuses too rather than move a card the push would not.
     *
     * @return array<string, array{int, string}>
     */
    public static function notStartEligible(): array
    {
        return ['Backlog' => [48, 'Backlog'], 'In Review' => [50, 'In Review']];
    }

    #[DataProvider('notStartEligible')]
    public function test_start_refuses_a_card_outside_the_start_eligible_columns_and_writes_nothing(int $stage, string $column): void
    {
        $this->board(['workflow_stage_id' => $stage]);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'not_start_eligible');
        $error = (string) $res->json('error');
        $this->assertStringContainsString($column, $error);
        $this->assertStringContainsString('Prioritized', $error, 'the refusal names the columns a start IS made from');
        $this->assertSame([], $this->patches);
    }

    public function test_start_warns_then_takes_a_card_another_user_holds_and_moves_it(): void
    {
        Log::spy();
        $this->board(['assigned_user_id' => 4242]);

        $res = $this->start()->assertStatus(200)
            ->assertJsonPath('result.moved', true)
            ->assertJsonPath('result.assigned', true)
            ->assertJsonPath('result.replaced.assigned_user_id', 4242)
            ->assertJsonPath('result.takeover_comment', 'posted');
        $this->assertStringContainsString('kanban user 4242', (string) $res->json('result.warning'));
        $this->assertSame([['workflow_stage_id' => 49, 'assigned_user_id' => $this->me()]], $this->patches);
        $this->assertCount(1, $this->comments);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx = []) => str_contains($m, 'TAKING a card another holder has')
            && $ctx['replaced_assignee'] === 4242)->once();
    }

    public function test_a_2xx_that_stored_nothing_is_a_failure(): void
    {
        $this->board(storesNothing: true);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'not_stored');
        $error = (string) $res->json('error');
        $this->assertStringContainsString('column 47', $error);
        $this->assertStringContainsString('no assignee', $error);
        $this->assertCount(1, $this->patches);
    }

    /**
     * A read-back that answers somebody else's row cannot say whether the start landed, and the
     * board-scoped lookup's own refusal says "nothing was written" — false after a write — so the
     * start re-words it as UNKNOWN.
     */
    public function test_a_broken_read_back_after_the_write_is_reported_unconfirmed_not_unwritten(): void
    {
        $this->board(brokenReadBack: true);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'not_confirmed');
        $error = (string) $res->json('error');
        $this->assertStringContainsString('UNKNOWN', $error);
        $this->assertStringNotContainsString('nothing was written', $error);
        $this->assertCount(1, $this->patches);
    }

    public function test_a_token_whose_role_lacks_task_update_is_a_named_install_fault(): void
    {
        $this->board(patchStatus: 403);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'install_fault.write_forbidden');
        $error = (string) $res->json('error');
        $this->assertStringContainsString('INSTALL fault', $error);
        $this->assertStringContainsString('`task.update`', $error);
        $this->assertStringContainsString('bridge:check', $error);
    }

    /** @return array<string, array{\Closure(self): void, string}> */
    public static function unmappedBoards(): array
    {
        return [
            'no writeback.json' => [static function (self $t): void {
                File::delete($t->dir.'/writeback.json');
            }, 'install_fault.start_unmapped'],
            'a mapping that maps no started stage' => [static function (self $t): void {
                $t->writeWriteback(['o/mine' => ['board_id' => 10, 'stages' => ['merged' => 52]]]);
            }, 'install_fault.start_unmapped'],
            'a mapping on another board only' => [static function (self $t): void {
                $t->writeWriteback(['o/x' => ['board_id' => 999, 'stages' => ['started' => 49], 'started_from_stages' => [47]]]);
            }, 'install_fault.start_unmapped'],
            'two mappings naming different In Progress columns' => [static function (self $t): void {
                $t->writeWriteback([
                    'o/a' => ['board_id' => 10, 'stages' => ['started' => 49], 'started_from_stages' => [47]],
                    'o/b' => ['board_id' => 10, 'stages' => ['started' => 50], 'started_from_stages' => [47]],
                ]);
            }, 'install_fault.start_ambiguous'],
            'writeback.json will not parse' => [static function (self $t): void {
                File::put($t->dir.'/writeback.json', '{not json');
            }, 'install_fault.writeback_config_unreadable'],
        ];
    }

    #[DataProvider('unmappedBoards')]
    public function test_start_on_a_board_without_one_in_progress_mapping_is_refused_with_no_write(\Closure $setUp, string $reason): void
    {
        $setUp($this);
        $this->board();

        $this->start()->assertStatus(422)->assertJsonPath('reason', $reason);
        $this->assertSame([], $this->patches);
    }

    public function test_start_on_a_card_whose_column_cannot_be_read_is_refused_with_no_write(): void
    {
        $this->board(['workflow_stage_id' => null]);

        $this->start()->assertStatus(422)->assertJsonPath('reason', 'column_unknown');
        $this->assertSame([], $this->patches);
    }

    /**
     * The writeback refuses EVERY write to a `program` parent (DL-403, `KanbanMoveCardHandler`'s
     * consult), its `started` move included — so the start refuses to move one too.
     */
    public function test_start_refuses_to_move_a_program_parent_and_writes_nothing(): void
    {
        $this->board(['tags' => ['program']]);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'program_parent');
        $this->assertStringContainsString('without `start`', (string) $res->json('error'));
        $this->assertSame([], $this->patches);
    }

    /**
     * A start whose read-back the board does not answer is NOT reported as a start: `ok:false`,
     * `not_confirmed`, and on a takeover the message names the holder the write was sent over —
     * never `ok:true` with an unverified `moved`/`assigned`.
     *
     * @return array<string, array{array<string, mixed>, ?string}>
     */
    public static function unansweredReadBacks(): array
    {
        return [
            'a takeover' => [['assigned_user_id' => 4242], 'kanban user 4242'],
            'no other holder' => [[], null],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unansweredReadBacks')]
    public function test_a_start_whose_read_back_fails_is_refused_not_confirmed(array $overrides, ?string $holder): void
    {
        $this->board($overrides, readBackStatus: 500);

        $res = $this->start()->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('reason', 'not_confirmed');
        $error = (string) $res->json('error');
        $this->assertStringContainsString('UNKNOWN', $error);
        if ($holder !== null) {
            $this->assertStringContainsString($holder, $error);
            $this->assertStringContainsString('Check with that holder', $error, 'the holder was never told on the card if the write landed');
        } else {
            $this->assertStringNotContainsString('Check with that holder', $error);
        }
        $this->assertCount(1, $this->patches);
        $this->assertSame([], $this->comments);
    }

    /** The holder is named in the durable log BEFORE the PATCH, so a call cut off after it still leaves the record. */
    public function test_a_takeover_start_logs_the_holder_before_the_write(): void
    {
        Log::listen(function (MessageLogged $e): void {
            if (str_contains($e->message, 'TAKING a card another holder has')) {
                $this->sequence[] = 'HOLDER_LOGGED';
            }
        });
        $this->board(['assigned_user_id' => 4242]);

        $this->start()->assertStatus(200);
        $this->assertSame(['HOLDER_LOGGED', 'PATCH'], $this->sequence);
    }

    /**
     * A sample of the shared refusal codes on the start path; what is covered is
     * `BoardTakeCardRefusalReasonCoverageTest`'s class docblock.
     *
     * @return array<string, array{\Closure(self): void, array<string, mixed>, string}>
     */
    public static function sharedRefusals(): array
    {
        return [
            'not in your lane' => [static fn (self $t) => $t->board(['swimlane_id' => 99]), ['card_id' => 42, 'start' => true], 'out_of_scope'],
            'holder unreadable' => [static function (self $t): void {
                $t->board(['assigned_user_id' => 'x']);
            }, ['card_id' => 42, 'start' => true], 'holder_unreadable'],
            'a broken lookup' => [static fn (self $t) => $t->board(broken: true), ['card_id' => 42, 'start' => true], 'broken_read'],
            'a column read the board refuses' => [static fn (self $t) => $t->board(['workflow_stage_id' => 48], preloadStatus: 403), ['card_id' => 42, 'start' => true], 'board_read_failed'],
            'a non-integer card_id' => [static fn (self $t) => $t->board(), ['card_id' => '42', 'start' => true], 'bad_arguments'],
            'an undeclared argument' => [static fn (self $t) => $t->board(), ['card_id' => 42, 'start' => true, 'stage' => 49], 'bad_arguments'],
            'the card removed before the write (404)' => [static fn (self $t) => $t->board(patchStatus: 404), ['card_id' => 42, 'start' => true], 'card_gone'],
            'the board rejects the write (422)' => [static fn (self $t) => $t->board(patchStatus: 422), ['card_id' => 42, 'start' => true], 'board_rejected'],
            'the token is not accepted on the write (401)' => [static fn (self $t) => $t->board(patchStatus: 401), ['card_id' => 42, 'start' => true], 'install_fault.token_rejected'],
            'the roster gives this seat\'s kanban user id to another seat' => [static function (self $t): void {
                $t->board();
                File::put($t->dir.'/other.yml', "subscriptions: []\n");
                CoordRosterFixture::configure($t->dir.'/coord', ['me' => $t->me(), 'other' => $t->me()]);
            }, ['card_id' => 42, 'start' => true], 'install_fault.shared_kanban_user'],
        ];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('sharedRefusals')]
    public function test_a_shared_refusal_on_the_start_path_carries_its_reason(\Closure $setUp, array $args, string $reason): void
    {
        $setUp($this);

        $this->take($args)->assertStatus(422)->assertJsonPath('reason', $reason);
        // The write-status refusals are refusals OF the write: it was sent once and refused.
        $this->assertLessThanOrEqual(1, count($this->patches));
    }

    public function test_an_unknown_tool_is_refused_with_its_reason(): void
    {
        $this->board();
        CallingSeatSeal::forANewServingProcess();

        $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => 'board_start_card', 'args' => ['card_id' => 42]]))
            ->assertStatus(422)->assertJsonPath('reason', 'unknown_tool');
        Http::assertNothingSent();
    }

    /**
     * The plain take's finished-column refusal used to send four causes under one code; each is
     * now its own (round-1 review of #850).
     *
     * @return array<string, array{\Closure(self): void, int|null, string}>
     */
    public static function takeoverColumnRefusals(): array
    {
        return [
            'truly finished' => [static function (self $t): void {}, 52, 'finished_column'],
            'a column the order does not place' => [static function (self $t): void {}, 777, 'column_unknown'],
            'no readable column on the row' => [static function (self $t): void {}, null, 'column_unknown'],
            'writeback.json will not parse' => [static function (self $t): void {
                File::put($t->dir.'/writeback.json', '{not json');
            }, 49, 'install_fault.writeback_config_unreadable'],
        ];
    }

    #[DataProvider('takeoverColumnRefusals')]
    public function test_the_plain_takeover_codes_each_finished_column_cause_separately(\Closure $setUp, ?int $stage, string $reason): void
    {
        $setUp($this);
        $this->board(['assigned_user_id' => 4242, 'workflow_stage_id' => $stage]);

        $this->take(['card_id' => 42])->assertStatus(422)->assertJsonPath('reason', $reason);
        $this->assertSame([], $this->patches);
    }

    /**
     * The program-parent consult runs BEFORE the pin, as `KanbanMoveCardHandler` orders them, so a
     * pinned parent is refused as a parent.
     */
    public function test_a_pinned_program_parent_is_refused_as_a_program_parent(): void
    {
        $this->board(['tags' => ['program', 'no-automove']]);

        $this->start()->assertStatus(422)->assertJsonPath('reason', 'program_parent');
        $this->assertSame([], $this->patches);
    }

    /**
     * The writeback's `started` move refuses a pinned card (DL-178), and a start is the same move.
     * The control is the first test: the same card unpinned is moved.
     */
    public function test_start_refuses_to_move_a_pinned_card_and_writes_nothing(): void
    {
        $this->board(['tags' => ['no-automove']]);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'pinned');
        $this->assertStringContainsString('without `start`', (string) $res->json('error'));
        $this->assertSame([], $this->patches);
    }

    /**
     * A seat the coord roster gives no kanban user id has no one to record as the owner, so a
     * start is refused as a named install fault before ANY board request — never an unassigned move.
     */
    public function test_a_seat_with_no_kanban_user_id_is_refused_as_an_install_fault_before_any_request(): void
    {
        $this->board();
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => null]);

        $res = $this->start()->assertStatus(422)->assertJsonPath('reason', 'install_fault.no_kanban_user');
        $this->assertStringContainsString('INSTALL fault', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    /** The pin holds the COLUMN, not the claim: a pinned card already In Progress is still assigned. */
    public function test_a_start_on_a_pinned_card_already_in_progress_still_assigns_it(): void
    {
        $this->board(['workflow_stage_id' => 49, 'tags' => ['no-automove'], 'block_reason' => 'held by an operator']);

        $this->start()->assertStatus(200)
            ->assertJsonPath('result.moved', false)
            ->assertJsonPath('result.assigned', true);
        $this->assertSame([['assigned_user_id' => $this->me()]], $this->patches);
    }

    /** @return array<string, array{mixed}> */
    public static function nonBooleanStarts(): array
    {
        return ['the string "true"' => ['true'], 'the integer 1' => [1], 'null' => [null]];
    }

    #[DataProvider('nonBooleanStarts')]
    public function test_start_must_be_a_boolean_and_anything_else_is_refused_before_any_request(mixed $value): void
    {
        $this->board();

        $res = $this->take(['card_id' => 42, 'start' => $value])->assertStatus(422);
        $this->assertStringContainsString('`start` must be a boolean', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    /** `start: false` is the plain take: the assignee alone, no column read, no move. */
    public function test_start_false_is_the_plain_take(): void
    {
        $this->board();

        $this->take(['card_id' => 42, 'start' => false])->assertStatus(200)
            ->assertJsonMissingPath('result.moved');
        $this->assertSame([['assigned_user_id' => $this->me()]], $this->patches);
    }
}
