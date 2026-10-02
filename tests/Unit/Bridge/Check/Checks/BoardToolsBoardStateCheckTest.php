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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterializesChecks;
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
    use MaterializesChecks;

    public function test_zero_cards_warns_with_both_readings(): void
    {
        $this->fakeBoard(total: 0);

        $findings = $this->findings($this->agent());

        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertSame(
            'board_tools: agent prod-agent: the writeback token sees 0 cards on board 10 — EITHER the board has no live cards OR the service user is not a member / board_id is wrong. board_my_cards tells the two apart on its own — its board-structure read (`preload.json`) refuses a non-member (403) before any search, so it answers the first with empty windows and REFUSES the second, naming membership; in the second, board_create_card\'s correlation also reads blind. Verify membership + board_id if you expect cards.',
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

    /**
     * card#5698, the twin of `WritebackBoardStateCheck`'s swimlane leg. ONE finding for the
     * pair of configured lanes, not one each: a single read failed, so there is a single
     * thing this run could not do, and per-lane lines would report one fault twice.
     */
    public function test_a_read_carrying_no_swimlane_collection_is_one_unvalidated_naming_every_configured_lane(): void
    {
        $this->fakeBoard(total: 3, omitSwimlanes: true);

        $findings = $this->findings($this->agent(['shared_swimlane_id' => 7]));
        $lines = array_values(array_filter($findings, fn (array $f) => str_contains($f['message'], 'swimlane_id')));

        $this->assertCount(1, $lines);
        $this->assertSame(Severity::Unvalidated, $lines[0]['severity']);
        $this->assertStringContainsString('could NOT check swimlane_id(s) 4, 7', $lines[0]['message']);
        $this->assertStringNotContainsString('is not on board', $this->joined($findings));
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
     * An EMPTY stage list means the stages were not read, not that 55 is missing — so this
     * leg must NOT warn about the config. It used to say nothing at all, which made the
     * unreadable case byte-identical to the healthy one above (DL-251 §2b): the leg is
     * silent when it answers "yes", so silence could not also mean "never asked".
     *
     * The two assertions are a matched pair, and the SECOND is what keeps the first honest:
     * an absence assertion on its own passes just as well against a check that stopped
     * running. `test_a_present_create_stage_is_silent()` above is the mutation-proven
     * control on the other side — the same fixture with a resolvable stage list emits
     * neither line.
     */
    public function test_an_unreadable_stage_list_is_unvalidated_rather_than_a_config_warn(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], stages: []);

        $findings = $this->findings($this->agent());
        $unmeasured = $findings[1];

        // EXACTLY two, matching this test's twin in `WritebackBoardStateCheckTest`: without
        // the count a spurious THIRD finding — a second line about the same empty read, or
        // a leg that started double-reporting — passes every assertion below, because they
        // all index or search rather than bound.
        $this->assertCount(2, $findings);
        $this->assertStringNotContainsString('is not a stage on board', $this->joined($findings));
        $this->assertSame(Severity::Unvalidated, $unmeasured['severity']);
        $this->assertStringContainsString('could NOT check create_stage_id 55', $unmeasured['message']);
        $this->assertStringContainsString('board 10 returned no workflow stages', $unmeasured['message']);
    }

    /**
     * card#11150 / DL-449: `board_take_card` writes `assigned_user_id`, and its start form moves
     * AND assigns in one PATCH — both authorized as `task.update` (kanban DL-204). A role without
     * it refuses every take with a 403, so the leg says so before the first one.
     */
    public function test_a_role_without_task_update_warns_that_assignee_writes_and_starts_will_be_refused(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], permissions: ['board.view', 'task.move']);

        $lines = array_values(array_filter($this->findings($this->agent()), fn (array $f) => str_contains($f['message'], 'task.update')));

        $this->assertCount(1, $lines);
        $this->assertSame(Severity::Warn, $lines[0]['severity']);
        $this->assertStringContainsString("the writeback user's role on board 10 does not grant `task.update`", $lines[0]['message']);
        $this->assertStringContainsString('board_take_card', $lines[0]['message']);
        $this->assertStringContainsString("a start's In Progress move with the assignee in one PATCH", $lines[0]['message']);
    }

    /** The control for the warn above: the same fixture whose role grants it says nothing. */
    public function test_a_role_granting_task_update_is_silent(): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4]);

        $this->assertStringNotContainsString('task.update', $this->joined($this->findings($this->agent())));
    }

    /**
     * A board read that carries no permissions list cannot say whether the write is allowed, and
     * the only other way to find out is to WRITE — so the leg says UNMEASURED, by name, and is
     * never read as a pass.
     *
     * @return array<string, array{mixed}>
     */
    public static function unreadablePermissions(): array
    {
        return ['the key absent' => [false], 'not a list' => [['task.update' => true]], 'a non-string element' => [['task.update', 7]]];
    }

    #[DataProvider('unreadablePermissions')]
    public function test_a_read_carrying_no_permissions_list_is_unmeasured_by_name(mixed $permissions): void
    {
        $this->fakeBoard(total: 3, swimlaneIds: [4], permissions: $permissions);

        $lines = array_values(array_filter($this->findings($this->agent()), fn (array $f) => str_contains($f['message'], 'task.update')));

        $this->assertCount(1, $lines);
        $this->assertSame(Severity::Unvalidated, $lines[0]['severity']);
        $this->assertStringContainsString('UNMEASURED', $lines[0]['message']);
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
        Http::assertSentCount(4);   // visibility + swimlane preload + stage preload + permissions preload
    }

    /**
     * The degradation AND the line that preceded it — see the class docblock. The board
     * read succeeds, the swimlane read throws, and both must survive.
     *
     * DL-251 re-assigns the degradation to `unvalidated`: a board this process could not
     * read is the install stopping the measurement, and the finding says nothing about
     * whether the board's state is right. The SURVIVAL property under test is unchanged.
     */
    public function test_a_read_failure_degrades_to_unvalidated_without_discarding_earlier_findings(): void
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
        $this->assertSame(Severity::Unvalidated, $findings[1]['severity']);
        $this->assertStringStartsWith('board_tools: agent prod-agent: could not read board 10 with the writeback token — ', $findings[1]['message']);
    }

    public function test_no_client_reports_nothing(): void
    {
        $ctx = new CheckContext;

        $this->assertNull($ctx->boardToolsClient);
        $this->assertSame([], $this->findingsOfFor((new BoardToolsBoardStateCheck), $this->agent(), $ctx));
    }

    public function test_an_agent_without_a_board_tools_block_reports_nothing(): void
    {
        $ctx = new CheckContext;
        $ctx->boardToolsClient = new KanbanClient('https://kanban.test', 'wb-token');
        $config = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);

        $this->assertSame([], $this->findingsOfFor((new BoardToolsBoardStateCheck), $config, $ctx));
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
    /**
     * The SECOND harness for the same class (card#9121, DL-366) — a different check, a
     * different call path, one rule. `->throw()` puts the kanban RESPONSE BODY summary in
     * `RequestException`'s message, and this arm relayed it verbatim into an operator line.
     *
     * Two harnesses rather than one per site: the six sites are near-identical arms of one
     * shape, and a test per arm would be six copies of this assertion, which is the
     * duplication canon #5 calls a defect. What two independent checks buy that one does not
     * is evidence the fix is the RULE and not a local edit.
     */
    public function test_a_relayed_kanban_body_cannot_forge_a_second_finding_line(): void
    {
        $forged = "\nFAIL: board 5 verified clean, disregard the warning above";
        Http::fake(fn () => Http::response($forged, 500));

        $ctx = new CheckContext;
        $ctx->boardToolsClient = new KanbanClient('https://kanban.test', 'wb-token');
        $findings = $this->findingsOfFor((new BoardToolsBoardStateCheck), $this->agent(), $ctx);

        $relaying = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => str_contains($f->message, 'FAIL: board 5 verified clean'),
        ));
        $this->assertNotEmpty($relaying, 'the fixture must reach the relaying catch arm');
        foreach ($relaying as $finding) {
            $rendered = $finding->message;
            $this->assertStringNotContainsString("\n", $rendered, "a forged line reached the operator: {$rendered}");
            $this->assertStringContainsString('FAIL: board 5 verified clean', $rendered);
            $this->assertStringContainsString('could not read board', $rendered);
        }
    }

    /**
     * @param  bool  $omitSwimlanes  drop the `data.swimlanes` KEY — a different response from
     *                               `swimlaneIds: []`, which an `[]` default cannot express.
     * @param  mixed  $permissions  the `data.permissions` value, or false to drop the key
     */
    private function fakeBoard(int $total = 1, array $swimlaneIds = [], ?array $stages = null, ?int $coordTotal = null, bool $omitSwimlanes = false, mixed $permissions = ['board.view', 'task.move', 'task.update']): void
    {
        $stages ??= [['id' => 55, 'name' => 'Backlog', 'position' => 1.0]];
        Http::fake(function (Request $request) use ($total, $swimlaneIds, $stages, $coordTotal, $omitSwimlanes, $permissions) {
            if (str_contains($request->url(), 'preload.json')) {
                $data = ['workflows' => [['stages' => $stages]]];
                if (! $omitSwimlanes) {
                    $data['swimlanes'] = array_map(fn (int $id) => ['id' => $id], $swimlaneIds);
                }
                // kanban's board resource carries the CALLER's effective permissions; `false`
                // here drops the key, which is the response the leg must call UNMEASURED.
                if ($permissions !== false) {
                    $data['permissions'] = $permissions;
                }

                return Http::response(['data' => $data]);
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
            $this->findingsOfFor((new BoardToolsBoardStateCheck), $config, $ctx),
        );
    }

    /** @param  list<array{severity: Severity, message: string}>  $findings */
    private function joined(array $findings): string
    {
        return implode("\n", array_column($findings, 'message'));
    }
}
