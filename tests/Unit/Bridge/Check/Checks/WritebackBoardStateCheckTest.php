<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackBoardStateCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The legs of `writeback.board_state` the golden suite CANNOT see, and only those.
 *
 * `CheckGoldenTest`'s move-leg install (named, not `{@see}`-linked, because pint's docblock
 * fixer turns a fully-qualified `{@see}` into a real import) reaches exactly four of this
 * check's legs byte-for-byte: the inexact-visibility line, the all-stages-known line, the
 * per-mapping unreadable-board catch, and three arms of the coord-terminal compare (unset /
 * unreadable / agrees). Those are absent here on purpose — duplicating a stronger
 * measurement does not strengthen it.
 *
 * Everything else is residue no fixture reaches, and `docs/check-golden-coverage.md`
 * discloses most of it by name (the scan-ceiling warn, the swimlane mismatch, the
 * dependabot missing-CF branch, the issue_number branch, the `started_from_stages` /
 * `unpark_from_stages` loops, the `coord_card_stage_id` / `coord_card_terminal_stage_id`
 * appends). The rest is invisible to that instrument by construction:
 * `bin/check-golden-mutate.php` walks `if`/`elseif`/`foreach` and enumerates neither
 * `catch` arms nor `switch` arms, so absence from the disclosed-gap list is not protection
 * for the two `catch` arms below.
 *
 * TWO PROPERTIES ARE PINNED HERE THAT NO SINGLE LEG OWNS:
 *  - **the exit contract** — the #4553 legs are the only `fail` this check can yield, and
 *    `CheckCommandSeverityContractTest` pins that `fail` (and only `fail`) flips the
 *    command's exit code, so asserting the SEVERITY here completes that chain;
 *  - **the stage-3b throw constraint** — `CheckRunner` materializes findings before the
 *    caller renders them, so a throw escaping this generator would discard findings it had
 *    already yielded, where the inline code had already PRINTED them. The per-mapping catch
 *    is what prevents that, and it is asserted directly rather than assumed.
 */
class WritebackBoardStateCheckTest extends TestCase
{
    private const REPO = 'owner/repo';

    private const BOARD = 8;

    private string $dir;

    private string|false $origCoordConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wb-board-state-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config([
            'bridge.writeback.correlation' => 'ref',
            'bridge.writeback.coord_config_path' => null,
        ]);
        $this->origCoordConfig = getenv('COORD_CONFIG');
        putenv('COORD_CONFIG');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->origCoordConfig === false ? putenv('COORD_CONFIG') : putenv('COORD_CONFIG='.$this->origCoordConfig);
        parent::tearDown();
    }

    // ---- DL-029: the visibility probe ----

    /**
     * 0 cards on a 200 is genuinely ambiguous — an empty new board, or a token whose user
     * is not a member and whose every move will silently no-op. The check must present
     * BOTH rather than assert membership on evidence that cannot carry it.
     */
    public function test_a_board_with_zero_visible_cards_names_both_readings(): void
    {
        $this->fakeBoard(total: 0);

        $findings = $this->findings($this->mapping());

        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('token sees 0 cards on board 8 (owner/repo)', $findings[0]['message']);
        $this->assertStringContainsString('EITHER the board is empty', $findings[0]['message']);
        $this->assertStringContainsString("OR the token's user isn't a member", $findings[0]['message']);
    }

    public function test_an_exact_card_count_is_reported_as_such(): void
    {
        $this->fakeBoard(total: 3);

        $findings = $this->findings($this->mapping());

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertSame('writeback: token sees 3 card(s) on board 8 (owner/repo)', $findings[0]['message']);
    }

    /**
     * DL-028: scan-mode correlation walks at most MAX_PAGES × SEARCH_LIMIT cards, so a
     * board past that ceiling silently drops correlations beyond it. Asserted WITH its
     * control: the identical oversized board in `ref` mode (which does not scan) must NOT
     * warn — a check that warned in both modes would be telling every large-board operator
     * to switch to a mode they are already on.
     */
    public function test_a_board_beyond_the_scan_ceiling_warns_only_in_scan_mode(): void
    {
        $this->fakeBoard(total: KanbanClient::SEARCH_LIMIT * KanbanClient::MAX_PAGES + 1);

        config(['bridge.writeback.correlation' => 'scan']);
        $scan = $this->findings($this->mapping());
        $this->assertSame(Severity::Warn, $scan[1]['severity']);
        $this->assertStringContainsString('has 10001 cards, beyond the scan ceiling', $scan[1]['message']);
        $this->assertStringContainsString('switch BRIDGE_WRITEBACK_CORRELATION=ref', $scan[1]['message']);

        config(['bridge.writeback.correlation' => 'ref']);
        $ref = $this->findings($this->mapping());
        $this->assertStringContainsString('token sees 10001 card(s)', $ref[0]['message']);
        $this->assertStringNotContainsString('scan ceiling', $this->joined($ref));
    }

    // ---- DL-027: the created-card swimlane ----

    public function test_a_swimlane_id_absent_from_the_board_is_reported_as_a_silent_422(): void
    {
        $this->fakeBoard(swimlaneIds: [4]);

        $findings = $this->findings($this->mapping(swimlaneId: 99));

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('swimlane_id 99 not found on board 8 (owner/repo)', $findings[1]['message']);
        $this->assertStringContainsString('created cards will 422 and SILENTLY no-op', $findings[1]['message']);
    }

    public function test_a_swimlane_id_present_on_the_board_is_reported_ok(): void
    {
        $this->fakeBoard(swimlaneIds: [4]);

        $findings = $this->findings($this->mapping(swimlaneId: 4));

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertSame('writeback: swimlane_id 4 ok on board 8 (owner/repo)', $findings[1]['message']);
    }

    /**
     * The mapping without a swimlane must not emit the leg at all — the ok line above is
     * not a stand-in for "no lane configured". The visibility line is the witness that the
     * check ran and reached the loop.
     */
    public function test_a_mapping_with_no_swimlane_emits_no_swimlane_line(): void
    {
        $this->fakeBoard();

        $findings = $this->findings($this->mapping());

        $this->assertStringNotContainsString('swimlane_id', $this->joined($findings));
        $this->assertStringContainsString('token sees', $findings[0]['message']);
    }

    // ---- #2949: the dependabot create payload's custom fields ----

    public function test_a_board_missing_the_dependabot_custom_fields_names_every_missing_key(): void
    {
        $this->fakeBoard(customFieldKeys: ['pr_number']);

        $findings = $this->findings($this->mapping(createDependabotCards: true));

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('create_dependabot_cards is on for owner/repo', $findings[1]['message']);
        $this->assertStringContainsString('MISSING the custom field(s) pr_url, origin', $findings[1]['message']);
        $this->assertStringContainsString('SILENTLY no-op until they are registered', $findings[1]['message']);
    }

    public function test_a_board_registering_every_dependabot_custom_field_is_reported_ok(): void
    {
        $this->fakeBoard(customFieldKeys: ['pr_number', 'pr_url', 'origin']);

        $findings = $this->findings($this->mapping(createDependabotCards: true));

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString('create_dependabot_cards custom fields ok on board 8', $findings[1]['message']);
    }

    // ---- #4553: the fail-closed issue_number leg (the only `fail` this check yields) ----

    /**
     * THE EXIT CONTRACT. This is the one leg that can make `bridge:check` exit non-zero, so
     * the assertion is on the SEVERITY, not merely on the text — a warn-level version of
     * this message would certify an install that silently double-cards.
     */
    public function test_a_board_without_issue_number_registered_fails_rather_than_warns(): void
    {
        $this->fakeBoard(customFieldKeys: ['pr_number']);

        $findings = $this->findings($this->coordMapping());

        $this->assertSame(Severity::Fail, $findings[1]['severity']);
        $this->assertStringContainsString("does not register the 'issue_number' custom field", $findings[1]['message']);
        $this->assertStringContainsString('the bridge would silently double-card', $findings[1]['message']);
    }

    public function test_a_board_registering_issue_number_is_reported_ready(): void
    {
        $this->fakeBoard(customFieldKeys: ['issue_number']);

        $findings = $this->findings($this->coordMapping());

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString('github_issue by-ref ready (issue_population=all)', $findings[1]['message']);
    }

    /**
     * The `catch` arm no fixture can reach and `bin/check-golden-mutate.php` does not even
     * enumerate. A fail-closed invariant we could not VERIFY is a failure, not a warn — an
     * unverifiable board could silently double-card, so it must not be swallowed by the
     * per-mapping warn-catch that wraps it (canon #9: an unrun measurement is not a pass).
     */
    public function test_an_unreadable_custom_field_list_fails_closed_rather_than_warning(): void
    {
        $this->fakeBoard(customFieldsStatus: 500);

        $findings = $this->findings($this->coordMapping());

        $this->assertSame(Severity::Fail, $findings[1]['severity']);
        $this->assertStringContainsString('could NOT read board 8', $findings[1]['message']);
        $this->assertStringContainsString('This fail-closed check must not be skipped', $findings[1]['message']);
        // The discriminating control: the per-mapping catch would have produced THIS text
        // instead, at warn severity, had the inner try been removed.
        $this->assertStringNotContainsString('could not read board 8 (owner/repo) with the writeback token', $this->joined($findings));
    }

    /** The population gate: `prefixed` correlates by prefix/tag, so the leg does not apply. */
    public function test_the_prefixed_population_reads_no_custom_fields_at_all(): void
    {
        $this->fakeBoard(customFieldKeys: ['pr_number']);

        $findings = $this->findings($this->coordMapping(population: WritebackMapping::POPULATION_PREFIXED));

        $this->assertStringNotContainsString('issue_number', $this->joined($findings));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'custom_fields.json'));
        $this->assertStringContainsString('token sees', $findings[0]['message']);
    }

    // ---- #2652 / DL-194 / DL-198 / DL-200: every targeted stage id must exist ----

    /**
     * The four id SOURCES the golden suite never exercises together. Each contributes an
     * unknown id, so a dropped source would shrink this list rather than fail loudly.
     */
    public function test_every_source_of_a_targeted_stage_id_is_checked_against_the_board(): void
    {
        $this->fakeBoard(stages: [['id' => 50, 'name' => 'In Progress', 'position' => 1.0]]);

        $findings = $this->findings(new WritebackMapping(
            boardId: self::BOARD,
            stages: ['opened' => 50, 'merged' => 61],
            startedFromStages: [62],
            unparkFromStages: [63],
            coordCardStageId: 64,
            coordCardTerminalStageId: 65,
        ));

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('references workflow stage id(s) 61, 62, 63, 64, 65 not on board 8', $findings[1]['message']);
    }

    /**
     * A board whose stages could not be read yields NO verdict either way — warning on an
     * empty read would false-alarm every operator whose kanban hiccuped. The visibility
     * line witnesses that the check ran.
     */
    public function test_a_board_with_no_readable_stages_emits_no_stage_verdict(): void
    {
        $this->fakeBoard(stages: []);

        $findings = $this->findings($this->mapping());

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('token sees', $findings[0]['message']);
        $this->assertStringNotContainsString('stage id', $this->joined($findings));
    }

    // ---- DL-200: the coord-terminal cross-config compare (the residue arms) ----

    public function test_the_compare_is_silent_when_the_move_leg_is_off(): void
    {
        $this->fakeBoard();

        // Family enabled (gate 1) but no terminal ⇒ move_coord_cards resolves false.
        $findings = $this->findings($this->mapping(), moveScope: true);

        $this->assertStringNotContainsString('move_coord_cards', $this->joined($findings));
        $this->assertStringContainsString('token sees', $findings[0]['message']);
    }

    /**
     * Gate 2's OTHER half, which the terminal-absent arm above cannot witness: an explicit
     * `move_coord_cards: false` is honored even with a terminal configured (`WritebackConfig`
     * reads the key when present and falls back to terminal-presence only when it is absent),
     * so an install that deliberately opted out must get no compare. Paired with its positive
     * control — the same mapping with the flag on does compare — so the flag is the only
     * difference between the two readings.
     */
    public function test_the_compare_is_silent_when_the_move_leg_is_explicitly_opted_out(): void
    {
        $this->fakeBoard(stages: [['id' => 53, 'name' => 'Done', 'position' => 1.0]]);
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Done']],
        ])]);
        $optedOut = new WritebackMapping(
            boardId: self::BOARD,
            stages: [],
            moveCoordCards: false,
            coordCardTerminalStageId: 53,
        );

        $this->assertStringNotContainsString('move_coord_cards', $this->joined($this->findings($optedOut, moveScope: true)));
        $this->assertStringContainsString('coord config agrees', $this->joined($this->findings($this->movingMapping(), moveScope: true)));
    }

    /**
     * Gate 1 — the classifier's coord-card-move family, scoped per repo on `CheckContext`. A
     * fully-configured move leg still gets no compare where the family is off, because the
     * leg cannot fire there and verifying a terminal for a dead leg reads as though it were
     * live. Nothing else asserts this gate (no golden fixture enables the family), so it is
     * the one that would flip silently.
     */
    public function test_the_compare_is_silent_where_the_coord_card_move_family_is_off(): void
    {
        $this->fakeBoard(stages: [['id' => 53, 'name' => 'Done', 'position' => 1.0]]);
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Done']],
        ])]);

        $this->assertStringNotContainsString('move_coord_cards', $this->joined($this->findings($this->movingMapping(), moveScope: false)));
        $this->assertStringContainsString('coord config agrees', $this->joined($this->findings($this->movingMapping(), moveScope: true)));
    }

    public function test_a_coord_config_declaring_no_terminal_for_this_board_is_cannot_verify(): void
    {
        $this->fakeBoard();
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => 999, 'terminal_columns' => ['Done']],
        ])]);

        $findings = $this->findings($this->movingMapping(), moveScope: true);

        $this->assertSame(Severity::Warn, $findings[2]['severity']);
        $this->assertStringContainsString('CANNOT VERIFY the terminal', $findings[2]['message']);
        $this->assertStringContainsString('it declares no terminal for board 8', $findings[2]['message']);
    }

    /**
     * Two terminals is legal framework-wide but leaves the bridge — which concludes into
     * exactly one stage — with no defensible pick. Ambiguous must read as cannot-verify;
     * choosing one and calling it agreement is the failure this arm exists to prevent.
     */
    public function test_two_declared_terminals_is_cannot_verify_rather_than_a_coin_flip(): void
    {
        $this->fakeBoard();
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Done', "Won't Do"]],
        ])]);

        $findings = $this->findings($this->movingMapping(), moveScope: true);

        $this->assertStringContainsString('it resolves 2 terminals for board 8', $findings[2]['message']);
        $this->assertStringContainsString("(Done, Won't Do)", $findings[2]['message']);
    }

    public function test_a_terminal_column_that_is_not_a_stage_on_the_board_is_cannot_verify(): void
    {
        $this->fakeBoard(stages: [['id' => 53, 'name' => 'Done', 'position' => 1.0]]);
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Archived']],
        ])]);

        $findings = $this->findings($this->movingMapping(), moveScope: true);

        $this->assertStringContainsString('its terminal column "Archived" for board 8 is not a stage on that board', $findings[2]['message']);
    }

    /**
     * THE MOTIVATING FAILURE (DL-200): both movers individually "work" while dragging every
     * concluded card back and forth forever. Only comparing the two CONFIGS can see it.
     */
    public function test_two_movers_with_different_terminals_are_reported_as_a_disagreement(): void
    {
        $this->fakeBoard(stages: [
            ['id' => 53, 'name' => 'Done', 'position' => 1.0],
            ['id' => 54, 'name' => 'Released', 'position' => 2.0],
        ]);
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Released']],
        ])]);

        $findings = $this->findings($this->movingMapping(), moveScope: true);

        $this->assertSame(Severity::Warn, $findings[2]['severity']);
        $this->assertStringContainsString('the two movers DISAGREE on the terminal', $findings[2]['message']);
        $this->assertStringContainsString('this bridge concludes coord cards into stage 53', $findings[2]['message']);
        $this->assertStringContainsString('is "Released" (stage 54)', $findings[2]['message']);
        $this->assertStringContainsString('Set coord_card_terminal_stage_id=54', $findings[2]['message']);
    }

    /**
     * The compare's own read can fail independently of the stage-existence read above it,
     * and that must report as cannot-verify rather than as agreement. Driven by failing the
     * SECOND preload read only — the first one feeds the stage-existence leg.
     */
    public function test_a_read_failure_inside_the_compare_is_cannot_verify_not_agreement(): void
    {
        $preloads = 0;
        Http::fake(function (Request $request) use (&$preloads) {
            if (str_contains($request->url(), 'preload.json')) {
                return ++$preloads === 1
                    ? Http::response(['data' => ['workflows' => [['stages' => [['id' => 53, 'name' => 'Done', 'position' => 1.0]]]], 'swimlanes' => []]])
                    : Http::response(['message' => 'nope'], 500);
            }

            return Http::response(['data' => [], 'meta' => ['total' => 1]]);
        });
        config(['bridge.writeback.coord_config_path' => $this->coordConfig([
            ['board_id' => self::BOARD, 'terminal_columns' => ['Done']],
        ])]);

        $findings = $this->findings($this->movingMapping(), moveScope: true);

        $this->assertSame(Severity::Warn, $findings[2]['severity']);
        $this->assertStringContainsString('could not read board 8 to resolve its terminal column "Done"', $findings[2]['message']);
        $this->assertStringNotContainsString('coord config agrees', $this->joined($findings));
    }

    // ---- the stage-3b throw constraint ----

    /**
     * `CheckRunner` materializes a check's findings before the caller renders any of them,
     * whereas the inline code this check replaces had already PRINTED the earlier mappings'
     * lines. So a throw escaping this generator would silently discard findings it had
     * already yielded — the one difference stage 3a could not reach, because every leg it
     * migrated was total.
     *
     * The per-mapping catch is what closes it, and this asserts the property directly: the
     * mapping BEFORE the failure keeps its findings, the failure itself becomes one warn,
     * and the mapping AFTER it still reports.
     */
    public function test_a_mapping_that_throws_loses_neither_the_mappings_before_nor_after_it(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains(urldecode($request->url()), 'board_id=9')) {
                return Http::response(['message' => 'nope'], 500);
            }

            return str_contains($request->url(), 'preload.json')
                ? Http::response(['data' => ['workflows' => [['stages' => [['id' => 50, 'name' => 'In Progress', 'position' => 1.0]]]], 'swimlanes' => []]])
                : Http::response(['data' => [], 'meta' => ['total' => 1]]);
        });

        $findings = $this->findingsFor([
            'owner/first' => new WritebackMapping(boardId: self::BOARD, stages: ['opened' => 50]),
            'owner/broken' => new WritebackMapping(boardId: 9, stages: ['opened' => 50]),
            'owner/last' => new WritebackMapping(boardId: self::BOARD, stages: ['opened' => 50]),
        ]);

        $messages = $this->joined($findings);
        $this->assertStringContainsString('token sees 1 card(s) on board 8 (owner/first)', $messages);
        $this->assertStringContainsString('all mapped stage ids exist on board 8 (owner/first)', $messages);
        $this->assertStringContainsString('could not read board 9 (owner/broken) with the writeback token', $messages);
        $this->assertStringContainsString('token sees 1 card(s) on board 8 (owner/last)', $messages);
        $this->assertCount(5, $findings);
    }

    /**
     * The same property WITHIN one mapping: a leg that throws after earlier legs already
     * yielded must not erase them. The visibility line lands, then the stage read fails.
     */
    public function test_a_throw_partway_through_one_mapping_keeps_what_it_already_yielded(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'preload.json')
                ? Http::response(['message' => 'nope'], 500)
                : Http::response(['data' => [], 'meta' => ['total' => 2]]);
        });

        $findings = $this->findings($this->mapping());

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('token sees 2 card(s)', $findings[0]['message']);
        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('could not read board 8 (owner/repo) with the writeback token', $findings[1]['message']);
    }

    // ---- helpers ----

    private function mapping(?int $swimlaneId = null, bool $createDependabotCards = false): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: [],
            swimlaneId: $swimlaneId,
            createDependabotCards: $createDependabotCards,
        );
    }

    private function coordMapping(string $population = WritebackMapping::POPULATION_ALL): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: [],
            createCoordCards: true,
            coordCardStageId: null,
            issuePopulation: $population,
        );
    }

    /** A mapping whose coord-card MOVE leg is on — gate 2 of the DL-204 pair. */
    private function movingMapping(): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: [],
            moveCoordCards: true,
            coordCardTerminalStageId: 53,
        );
    }

    /** @param  list<array<string, mixed>>  $boards */
    private function coordConfig(array $boards): string
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, (string) json_encode(['kanban' => ['boards' => $boards]]));

        return $path;
    }

    /**
     * @param  list<int>  $swimlaneIds
     * @param  list<string>  $customFieldKeys
     * @param  list<array<string, mixed>>|null  $stages
     */
    private function fakeBoard(
        int $total = 1,
        array $swimlaneIds = [],
        array $customFieldKeys = [],
        ?array $stages = null,
        int $customFieldsStatus = 200,
    ): void {
        $stages ??= [['id' => 50, 'name' => 'In Progress', 'position' => 1.0]];
        Http::fake(function (Request $request) use ($total, $swimlaneIds, $customFieldKeys, $stages, $customFieldsStatus) {
            if (str_contains($request->url(), 'custom_fields.json')) {
                return $customFieldsStatus === 200
                    ? Http::response(['data' => array_map(fn (string $k) => ['key' => $k], $customFieldKeys)])
                    : Http::response(['message' => 'nope'], $customFieldsStatus);
            }
            if (str_contains($request->url(), 'preload.json')) {
                return Http::response(['data' => [
                    'workflows' => [['stages' => $stages]],
                    'swimlanes' => array_map(fn (int $id) => ['id' => $id], $swimlaneIds),
                ]]);
            }

            return Http::response(['data' => [], 'meta' => ['total' => $total]]);
        });
    }

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(WritebackMapping $mapping, bool $moveScope = false): array
    {
        return $this->findingsFor([self::REPO => $mapping], $moveScope ? [self::REPO => true] : []);
    }

    /**
     * @param  array<string, WritebackMapping>  $mappings
     * @param  array<string, true>  $moveScopes
     * @return list<array{severity: Severity, message: string}>
     */
    private function findingsFor(array $mappings, array $moveScopes = []): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, $mappings);
        $ctx->client = new KanbanClient('https://kanban.test', 'wb-token');
        $ctx->coordCardMoveScopes = $moveScopes;

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array((new WritebackBoardStateCheck)->run($ctx), false),
        );
    }

    /** @param  list<array{severity: Severity, message: string}>  $findings */
    private function joined(array $findings): string
    {
        return implode("\n", array_column($findings, 'message'));
    }
}
