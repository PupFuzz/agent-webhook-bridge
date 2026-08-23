<?php

namespace Tests\Feature\Standup;

use App\Bridge\Standup\StandupService;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Writeback\KanbanClient;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins WHAT THE DIGEST IS ALLOWED TO SAY (DL-306).
 *
 * The subject is not "does it produce output" — a report always produces output. It is
 * whether every value in it is one the bridge measured, and whether the ones it did not
 * measure are ABSENT rather than defaulted. A digest that prints a plausible-looking
 * number nothing stands behind is worse than one missing the field, because its reader
 * has no way to tell.
 */
class StandupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/standup-svc-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/kanban');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function service(): StandupService
    {
        return new StandupService(new HandlerRegistry);
    }

    private function seatYaml(string $agent): void
    {
        File::put($this->dir."/{$agent}.yml", "identity:\n  kanban_user_id: 5\nsubscriptions: []\n");
    }

    private function dispatch(string $agent, ?string $outcome, ?string $processedAt): void
    {
        $event = WebhookEvent::create([
            'provider' => 'kanban',
            'scope_id' => '5',
            'delivery_id' => 'd-'.uniqid(),
            'event_type' => 'task.moved',
            'payload' => ['a' => 1],
        ]);
        AgentDispatch::create([
            'webhook_event_id' => $event->id,
            'agent_name' => $agent,
            'outcome' => $outcome,
            'processed_at' => $processedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function seatRow(string $agent): array
    {
        foreach ($this->service()->build()->toArray()['seats'] as $row) {
            if ($row['agent'] === $agent) {
                return $row;
            }
        }
        $this->fail("no seat row for {$agent}");
    }

    private function writeWriteback(array $mapping): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => $mapping],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    /** One board-read page of $n cards, each at $stageId. */
    private function page(int $n, int $stageId, ?string $next): array
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = ['id' => $i + 1, 'workflow_stage_id' => $stageId];
        }

        return ['data' => $cards, 'links' => ['next' => $next]];
    }

    // ---------------------------------------------------------------- seats

    public function test_a_seat_with_no_delivered_dispatch_carries_no_delivery_key_at_all(): void
    {
        // THE load-bearing assertion of this card. The bridge knows DELIVERY, and when it
        // has none for a seat it must say nothing — not `null`, not an epoch, not "never".
        // The paired presence assertion is the witness: without it, "the key is absent"
        // would also pass if the seat row were never built.
        $this->seatYaml('quiet-seat');

        $row = $this->seatRow('quiet-seat');

        $this->assertArrayNotHasKey('last_delivery_at', $row);
        $this->assertSame(0, $row['unseen_inbox_intents'], 'the row must exist and carry its measured field');
    }

    public function test_a_gate_dropped_dispatch_is_not_a_delivery(): void
    {
        // A gate-drop sets processed_at exactly like a delivery does (DL-036 is the only
        // thing that tells them apart). Reading it as a delivery would show a seat nobody
        // is routing anything to as freshly served — the mislabelling this card exists to
        // route around, in the one place the ledger makes it easy.
        $this->seatYaml('dropped-seat');
        $this->dispatch('dropped-seat', AgentDispatch::OUTCOME_DROPPED, '2026-08-20 10:00:00');

        $this->assertArrayNotHasKey('last_delivery_at', $this->seatRow('dropped-seat'));
    }

    public function test_a_legacy_dispatch_with_no_outcome_is_not_a_delivery(): void
    {
        // Pre-DL-036 rows carry NULL outcome, and their outcome is unknowable
        // retroactively. Unknowable must render as absent, never as the timestamp.
        $this->seatYaml('legacy-seat');
        $this->dispatch('legacy-seat', null, '2026-08-20 10:00:00');

        $this->assertArrayNotHasKey('last_delivery_at', $this->seatRow('legacy-seat'));
    }

    public function test_a_delivered_dispatch_yields_the_newest_delivery_time(): void
    {
        $this->seatYaml('busy-seat');
        $this->dispatch('busy-seat', AgentDispatch::OUTCOME_DELIVERED, '2026-08-19 09:00:00');
        $this->dispatch('busy-seat', AgentDispatch::OUTCOME_DELIVERED, '2026-08-21 11:30:00');

        $row = $this->seatRow('busy-seat');

        $this->assertArrayHasKey('last_delivery_at', $row);
        $this->assertStringStartsWith('2026-08-21T11:30:00', $row['last_delivery_at']);
    }

    public function test_a_seat_row_carries_only_the_keys_the_bridge_can_source(): void
    {
        // An EXACT key set, deliberately: the failure this guards is additive. Someone
        // adding `last_activity`, a context-%, or a zero-filled `last_delivery_at` would
        // pass every other test in this file — each of those fields is one the bridge has
        // no producer for, and a new key here is a claim that it acquired one.
        $this->seatYaml('busy-seat');
        $this->dispatch('busy-seat', AgentDispatch::OUTCOME_DELIVERED, '2026-08-21 11:30:00');

        $this->assertSame(
            ['agent', 'last_delivery_at', 'unseen_inbox_intents'],
            array_keys($this->seatRow('busy-seat')),
        );
    }

    public function test_unseen_inbox_intents_counts_the_lines_the_cursor_has_not_consumed(): void
    {
        $this->seatYaml('pm');
        File::put($this->dir.'/state/inbox.jsonl', implode("\n", [
            (string) json_encode(['id' => 'a:pm:0', 'agent' => 'pm', 'kind' => 'coord_message']),
            (string) json_encode(['id' => 'b:pm:0', 'agent' => 'pm', 'kind' => 'coord_message']),
            // A partial-staging redelivery can leave two lines carrying one id; they are
            // one intent, and both readers of this inbox must agree they are.
            (string) json_encode(['id' => 'b:pm:0', 'agent' => 'pm', 'kind' => 'coord_message']),
            (string) json_encode(['id' => 'c:pm:0', 'agent' => 'other', 'kind' => 'coord_message']),
        ])."\n");
        File::put($this->dir.'/state/inbox-seen-pm.json', (string) json_encode(['a:pm:0']));

        $this->assertSame(1, $this->seatRow('pm')['unseen_inbox_intents']);
    }

    // --------------------------------------------------------------- boards

    public function test_an_empty_install_reports_empty_lists_without_inventing_rows(): void
    {
        // A digest over a fleet of nothing is the case a report is most likely to fabricate
        // its way through. Empty lists, both sections present — "nothing is configured"
        // must stay distinguishable from "the section was not built".
        $digest = $this->service()->build()->toArray();

        $this->assertSame([], $digest['seats']);
        $this->assertSame([], $digest['boards']);
        $this->assertArrayHasKey('generated_at', $digest);
    }

    public function test_a_board_with_no_now_lane_mapping_produces_no_row(): void
    {
        // NOT a `now_depth: 0` row. The bridge cannot identify that board's Now column at
        // all, and a zero there would read as "nothing queued" — the most consequential
        // wrong number this digest could print.
        $this->writeWriteback(['board_id' => 8, 'stages' => ['merged' => 52]]);

        $this->assertSame([], $this->service()->build()->toArray()['boards']);
    }

    public function test_now_depth_counts_rows_by_their_own_stage_id(): void
    {
        Http::fake(['kanban.example.com/*' => Http::response([
            'data' => [
                ['id' => 1, 'workflow_stage_id' => 13],
                ['id' => 2, 'workflow_stage_id' => 14],
                ['id' => 3, 'workflow_stage_id' => 13],
            ],
            'links' => ['next' => null],
        ], 200)]);
        $this->writeWriteback([
            'board_id' => 8,
            'stages' => ['merged' => 52],
            'move_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 13, 'next' => 14, 'later' => 15],
        ]);

        $this->assertSame(
            [['board_id' => 8, 'now_depth' => 2]],
            $this->service()->build()->toArray()['boards'],
        );
    }

    public function test_a_failed_board_read_omits_the_depth_and_names_the_cause(): void
    {
        Http::fake(['kanban.example.com/*' => Http::response('nope', 500)]);
        $this->writeWriteback([
            'board_id' => 8,
            'stages' => ['merged' => 52],
            'move_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 13, 'later' => 15],
        ]);

        $board = $this->service()->build()->toArray()['boards'][0];

        $this->assertArrayNotHasKey('now_depth', $board);
        $this->assertStringContainsString('board read failed', $board['now_depth_unavailable']);
    }

    public function test_a_truncated_board_read_omits_the_depth_rather_than_reporting_a_lower_bound(): void
    {
        // The page walk stops at the ceiling, so what it counted is a floor, not a depth.
        // A floor printed under the key `now_depth` is a wrong number, not a missing one —
        // and it is wrong in the reassuring direction.
        $page = $this->page(KanbanClient::SEARCH_LIMIT, 13, 'https://kanban.example.com/api/v3/tasks/search.json?page=2');
        Http::fake(['kanban.example.com/*' => Http::response($page, 200)]);
        $this->writeWriteback([
            'board_id' => 8,
            'stages' => ['merged' => 52],
            'move_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 13, 'later' => 15],
        ]);

        $board = $this->service()->build()->toArray()['boards'][0];

        $this->assertArrayNotHasKey('now_depth', $board);
        $this->assertStringContainsString('ceiling', $board['now_depth_unavailable']);
    }

    public function test_an_unresolvable_writeback_token_leaves_every_board_saying_why(): void
    {
        // The seat half of the digest does not depend on kanban, so the pass still reports
        // — but no board may go silent: a missing row would read as "this board has no Now
        // lane", which is a different fact from "the bridge could not look".
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['merged' => 52],
                'move_coord_cards' => true,
                'coord_card_stage_id' => 21,
                'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['now' => 13, 'later' => 15],
            ]],
        ]));   // no token file written

        $board = $this->service()->build()->toArray()['boards'][0];

        $this->assertArrayNotHasKey('now_depth', $board);
        $this->assertStringContainsString('no usable kanban writeback client', $board['now_depth_unavailable']);
    }
}
