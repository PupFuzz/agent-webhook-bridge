<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsBoardStateCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The per-agent board-STATE legs (DL-217/DL-220), migrated in DL-242 stage 7b.
 *
 * ONE ARM OF ONE PREDICATE HERE IS ALREADY PINNED BYTE-FOR-BYTE ELSEWHERE: the three ssh
 * golden installs all read a board with 0 cards, so the ambiguity warn is in the corpus.
 * Its OTHER arm — the `writeback token can see board N` line — is in no fixture, and
 * neither is any other leg below, so a predicate whose two arms are split across a strong
 * and an absent measurement is asserted here in FULL rather than half.
 *
 * THE CATCH IS ASSERTED WITH THE FINDINGS THAT PRECEDED IT, never alone. A test asserting
 * only the degraded line passes under a caller-side catch too — and a caller-side catch is
 * exactly the wrong placement here, because `CheckRunner` materializes findings before the
 * caller renders any of them, so a throw escaping this generator would DISCARD the
 * visibility line this check had already yielded. The pre-throw finding is the only
 * observable that tells the two placements apart.
 */
class BoardToolsBoardStateCheckTest extends TestCase
{
    public function test_zero_cards_warns_with_both_readings(): void
    {
        $this->fakeBoard(total: 0);

        $findings = $this->findings($this->agent());

        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertSame(
            'board_tools: agent prod-agent: the writeback token sees 0 cards on board 10 — EITHER the board is empty (fine) OR the service user is not a member / board_id is wrong (then board_my_cards returns an empty window and board_create_card\'s correlation reads blind). Verify membership + board_id if you expect cards.',
            $findings[0]['message'],
        );
    }

    public function test_a_visible_board_reports_ok(): void
    {
        $this->fakeBoard(total: 3);

        $findings = $this->findings($this->agent());

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertSame('board_tools: agent prod-agent: writeback token can see board 10', $findings[0]['message']);
    }

    public function test_a_swimlane_absent_from_the_board_warns(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [99]);

        $joined = $this->joined($this->findings($this->agent()));

        $this->assertStringContainsString('board_tools: agent prod-agent: swimlane_id 4 is not on board 10 — board_create_card will 422 (create) or board_my_cards will read empty until fixed.', $joined);
    }

    public function test_the_shared_swimlane_is_checked_too(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4]);

        $joined = $this->joined($this->findings($this->agent(['shared_swimlane_id' => 7])));

        $this->assertStringNotContainsString('swimlane_id 4 is not on board', $joined);
        $this->assertStringContainsString('swimlane_id 7 is not on board 10', $joined);
    }

    public function test_a_present_swimlane_is_silent(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4]);

        $this->assertStringNotContainsString('swimlane_id', $this->joined($this->findings($this->agent())));
    }

    public function test_a_create_stage_absent_from_the_board_warns(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: [['id' => 50, 'name' => 'Backlog', 'position' => 1.0]]);

        $this->assertStringContainsString(
            'board_tools: agent prod-agent: create_stage_id 55 is not a stage on board 10 — every board_create_card will 422 until fixed.',
            $this->joined($this->findings($this->agent())),
        );
    }

    public function test_a_present_create_stage_is_silent(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]]);

        $this->assertStringNotContainsString('create_stage_id', $this->joined($this->findings($this->agent())));
    }

    /**
     * An EMPTY stage list means the stages were not read, not that 55 is missing —
     * warning there would report a config error on the strength of a failed read.
     */
    public function test_an_unreadable_stage_list_does_not_warn_about_the_create_stage(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: []);

        $this->assertStringNotContainsString('create_stage_id', $this->joined($this->findings($this->agent())));
    }

    public function test_a_blind_coord_board_warns(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]], coordTotal: 0);

        $joined = $this->joined($this->findings($this->agent(['coord_board_id' => 12])));

        // The product board's own line proves the fake really told the two reads apart —
        // a fake answering 0 to both would satisfy the coord assertion for the wrong reason.
        $this->assertStringContainsString('board_tools: agent prod-agent: writeback token can see board 10', $joined);
        $this->assertStringContainsString(
            'board_tools: agent prod-agent: coord_board_id 12 reads 0 cards — the coordination leg returns empty if the service user is not a member or the id is wrong.',
            $joined,
        );
    }

    public function test_a_visible_coord_board_is_silent(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]], coordTotal: 5);

        $this->assertStringNotContainsString('coord_board_id', $this->joined($this->findings($this->agent(['coord_board_id' => 12]))));
    }

    public function test_no_coord_board_makes_no_second_read(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]]);

        $this->findings($this->agent());

        // The coord leg's read is a second visibility call; its absence is the observable
        // that the `coord_board_id === null` guard held.
        // Two preloads, not one: boardSwimlaneIds and boardStageOrder each read
        // `preload.json` (no per-run cache), which is the pre-existing behavior this
        // migration preserves rather than the count the leg names.
        Http::assertSentCount(3);   // visibility + swimlane preload + stage preload
    }

    /**
     * The degradation AND the line that preceded it — see the class docblock. The board
     * read succeeds, the swimlane read throws, and both must survive.
     */
    public function test_a_read_failure_degrades_to_a_warn_without_discarding_earlier_findings(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'preload.json')) {
                return Http::response(['message' => 'kaboom'], 500);
            }

            return Http::response(['data' => [], 'meta' => ['total' => 3]]);
        });

        $findings = $this->findings($this->agent());

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertSame('board_tools: agent prod-agent: writeback token can see board 10', $findings[0]['message']);
        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringStartsWith('board_tools: agent prod-agent: could not read board 10 with the writeback token — ', $findings[1]['message']);
    }

    public function test_no_client_reports_nothing(): void
    {
        $ctx = new CheckContext;

        $this->assertNull($ctx->boardToolsClient);
        $this->assertSame([], iterator_to_array((new BoardToolsBoardStateCheck)->runFor($this->agent(), $ctx), false));
    }

    public function test_an_agent_without_a_board_tools_block_reports_nothing(): void
    {
        $ctx = new CheckContext;
        $ctx->boardToolsClient = new KanbanClient('https://kanban.test', 'wb-token');
        $config = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);

        $this->assertSame([], iterator_to_array((new BoardToolsBoardStateCheck)->runFor($config, $ctx), false));
    }

    /** @param array<string, mixed> $extra */
    private function agent(array $extra = []): AgentConfig
    {
        return AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'board_tools' => array_merge([
                'enabled' => true,
                'transport' => 'ssh',
                'board_id' => 10,
                'swimlane_id' => 4,
                'create_stage_id' => 55,
            ], $extra),
        ]);
    }

    /**
     * @param  list<int>  $swimlaneIds
     * @param  list<array<string, mixed>>|null  $stages
     */
    private function fakeBoard(int $total = 1, array $swimlaneIds = [], ?array $stages = null, ?int $coordTotal = null): void
    {
        $stages ??= [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]];
        Http::fake(function (Request $request) use ($total, $swimlaneIds, $stages, $coordTotal) {
            if (str_contains($request->url(), 'preload.json')) {
                return Http::response(['data' => [
                    'workflows' => [['stages' => $stages]],
                    'swimlanes' => array_map(fn (int $id) => ['id' => $id], $swimlaneIds),
                ]]);
            }
            // The coord board is a SECOND visibility read, distinguished by its board id.
            // The id rides INSIDE the `q` param, so the url arrives percent-encoded and a
            // naive `board_id=12` match never fires (it did not, first run); decode, and
            // anchor on the separator so board 1 cannot match board 12.
            $forCoord = $coordTotal !== null && str_contains(urldecode($request->url()), 'q=board_id=12&');

            return Http::response(['data' => [], 'meta' => ['total' => $forCoord ? $coordTotal : $total]]);
        });
    }

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(AgentConfig $config): array
    {
        $ctx = new CheckContext;
        $ctx->boardToolsClient = new KanbanClient('https://kanban.test', 'wb-token');

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array((new BoardToolsBoardStateCheck)->runFor($config, $ctx), false),
        );
    }

    /** @param  list<array{severity: Severity, message: string}>  $findings */
    private function joined(array $findings): string
    {
        return implode("\n", array_column($findings, 'message'));
    }
}
