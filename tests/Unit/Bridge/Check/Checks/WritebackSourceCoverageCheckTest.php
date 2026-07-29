<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackSourceCoverageCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `writeback.source_coverage` in full, because the golden suite reaches NONE of it.
 *
 * Same reason as `writeback.by_ref`: every golden fixture holding a writeback client runs
 * in `scan` correlation, and this check is a no-op outside `ref`.
 *
 * WHAT MAKES THIS LEG WORTH ITS OWN TEST rather than an operator's eye: the failure it
 * names (#3399) is the only writeback failure with NO trace anywhere — the by-ref lookup
 * quietly excludes the card, and the dispatch ledger records a legitimate-looking no-match.
 * So the shared-vs-not distinction is the whole content of the check, and getting it
 * backwards would either nag every 1:1 board or stay silent on the boards that break.
 * Both directions are asserted below, each against the same card.
 */
class WritebackSourceCoverageCheckTest extends TestCase
{
    private const BOARD = 8;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bridge.writeback.correlation' => 'ref']);
    }

    /**
     * DL-174: on a 1:1 board the by-ref lookup omits the repo qualifier, so a source-less
     * DL card correlates fine. The ok line is the witness that the check read the board and
     * examined the card — an absence alone would also be satisfied by a check that ran
     * against nothing.
     */
    public function test_a_source_less_dl_card_on_a_non_shared_board_is_not_flagged(): void
    {
        $this->fakeBoard(self::BOARD, [$this->dlCard(1, [])]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('dl_number cards on board 8 all have a mapped source', $findings[0]['message']);
    }

    /** The same card on a SHARED board is excluded by the qualifier and never self-moves. */
    public function test_the_same_card_on_a_shared_board_is_flagged_as_never_self_moving(): void
    {
        $this->fakeBoard(self::BOARD, [$this->dlCard(1, [])]);

        $findings = $this->findings($this->sharedBoardMappings());

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('card 1 (DL 42) on SHARED board 8 has dl_number but source=null', $findings[0]['message']);
        $this->assertStringContainsString('will NEVER self-move', $findings[0]['message']);
        $this->assertStringNotContainsString('all have a mapped source', $this->joined($findings));
    }

    /**
     * A source that derives but names an unmapped repo is operator error and warns
     * EVERYWHERE — shared or not — which is the one asymmetry with the case above.
     */
    public function test_a_source_naming_an_unmapped_repo_is_flagged_on_a_non_shared_board_too(): void
    {
        $this->fakeBoard(self::BOARD, [
            $this->dlCard(1, ['pr_url' => 'https://github.com/someone/elsewhere/pull/7']),
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('card 1 (DL 42) on board 8 has source=someone/elsewhere', $findings[0]['message']);
        $this->assertStringContainsString('matches no repo mapped to that board (owner/repo)', $findings[0]['message']);
    }

    /** A pr_url on a mapped repo is the healthy shape, and it is what the ok line means. */
    public function test_a_card_whose_source_matches_a_mapped_repo_is_eligible(): void
    {
        $this->fakeBoard(self::BOARD, [
            $this->dlCard(1, ['pr_url' => 'https://github.com/Owner/Repo/pull/7']),
        ]);

        $findings = $this->findings($this->sharedBoardMappings());

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
    }

    /**
     * Only DL cards are in scope. The ok line is again the witness — it proves the board
     * WAS read and this card examined and skipped, not that the read never happened.
     */
    public function test_a_card_with_no_dl_number_is_not_examined(): void
    {
        $this->fakeBoard(self::BOARD, [['id' => 9, 'payload' => ['pr_number' => 3], 'external_link' => null]]);

        $findings = $this->findings($this->sharedBoardMappings());

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
    }

    /**
     * THE STAGE-3b THROW CONSTRAINT, on this check's own loop. `CheckRunner` materializes
     * findings before rendering, so a board read that threw past the per-board catch would
     * discard every finding already yielded for earlier boards — where the inline code had
     * already printed them.
     *
     * The failure sits BETWEEN two readable boards deliberately: a trailing failure cannot
     * tell the catch's `continue` apart from a `return`, so only a board read AFTER it
     * witnesses that the loop resumes rather than concluding early.
     */
    public function test_one_unreadable_board_does_not_erase_the_findings_of_the_boards_around_it(): void
    {
        Http::fake(function (Request $request) {
            return str_contains(urldecode($request->url()), 'board_id=9')
                ? Http::response(['message' => 'nope'], 500)
                : Http::response(['data' => [$this->dlCard(1, [])], 'links' => ['next' => null]]);
        });

        $findings = $this->findings([
            'owner/repo' => new WritebackMapping(boardId: self::BOARD, stages: []),
            'owner/other' => new WritebackMapping(boardId: 9, stages: []),
            'owner/third' => new WritebackMapping(boardId: 10, stages: []),
        ]);

        $this->assertCount(3, $findings);
        $this->assertStringContainsString('dl_number cards on board 8 all have a mapped source', $findings[0]['message']);
        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('could not read board 9 to check dl source coverage', $findings[1]['message']);
        $this->assertStringContainsString('dl_number cards on board 10 all have a mapped source', $findings[2]['message']);
    }

    /**
     * A read that hit the page ceiling saw only part of the board, so the all-clear must
     * NOT be printed — an incomplete measurement reported as a pass is the exact shape this
     * program exists to remove.
     */
    public function test_a_truncated_board_read_reports_incomplete_and_withholds_the_all_clear(): void
    {
        Http::fake(fn () => Http::response([
            'data' => [$this->dlCard(1, ['pr_url' => 'https://github.com/owner/repo/pull/7'])],
            'links' => ['next' => 'https://kanban.test/next'],
        ]));

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('source-coverage check on board 8 is INCOMPLETE', $findings[0]['message']);
        $this->assertStringNotContainsString('all have a mapped source', $this->joined($findings));
    }

    /**
     * Scan mode does not repo-qualify, so there is no coverage gap to find. Asserted with
     * its positive control — the identical setup in `ref` mode speaks.
     */
    public function test_scan_mode_reads_no_board_at_all(): void
    {
        $this->fakeBoard(self::BOARD, [$this->dlCard(1, [])]);

        config(['bridge.writeback.correlation' => 'scan']);
        $this->assertSame([], $this->findings($this->sharedBoardMappings()));
        Http::assertNothingSent();

        config(['bridge.writeback.correlation' => 'ref']);
        $this->assertCount(1, $this->findings($this->sharedBoardMappings()));
    }

    // ---- helpers ----

    /** Two repos on ONE board — the shape `WritebackConfig::boardIsShared` reports true for. */
    private function sharedBoardMappings(): array
    {
        return [
            'owner/repo' => new WritebackMapping(boardId: self::BOARD, stages: []),
            'owner/second' => new WritebackMapping(boardId: self::BOARD, stages: []),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function dlCard(int $id, array $payload): array
    {
        return ['id' => $id, 'payload' => ['dl_number' => '42'] + $payload, 'external_link' => null];
    }

    /** @param  list<array<string, mixed>>  $cards */
    private function fakeBoard(int $boardId, array $cards): void
    {
        Http::fake(function (Request $request) use ($boardId, $cards) {
            return str_contains(urldecode($request->url()), "board_id={$boardId}")
                ? Http::response(['data' => $cards, 'links' => ['next' => null]])
                : Http::response(['data' => [], 'links' => ['next' => null]]);
        });
    }

    /**
     * @param  array<string, WritebackMapping>|null  $mappings
     * @return list<array{severity: Severity, message: string}>
     */
    private function findings(?array $mappings = null): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, $mappings ?? [
            'owner/repo' => new WritebackMapping(boardId: self::BOARD, stages: []),
        ]);
        $ctx->client = new KanbanClient('https://kanban.test', 'wb-token');

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array((new WritebackSourceCoverageCheck)->run($ctx), false),
        );
    }

    /** @param  list<array{severity: Severity, message: string}>  $findings */
    private function joined(array $findings): string
    {
        return implode("\n", array_column($findings, 'message'));
    }
}
