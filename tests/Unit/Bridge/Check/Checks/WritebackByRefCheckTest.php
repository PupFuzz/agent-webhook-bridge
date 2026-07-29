<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackByRefCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `writeback.by_ref` in full, because the golden suite reaches NONE of it.
 *
 * Every golden fixture that constructs a writeback client runs in `scan` correlation
 * (`CheckGoldenTest`'s move-leg install pins it — named, not `{@see}`-linked, because
 * pint's docblock fixer turns a fully-qualified `{@see}` into a real import), and this
 * check is a no-op outside `ref`. (`docs/check-golden-coverage.md` does not speak
 * for the `! $client->byRefAvailable(...)` predicate in either direction — that file
 * enumerates `CheckCommand::handle()`'s predicates, and this one migrated out of it.) So the
 * measurement of this leg is here, not there.
 *
 * THE NOT-APPLICABLE TEST CARRIES A POSITIVE CONTROL rather than a bare absence: a check
 * that yielded nothing for any reason — a wrong fake, an unbuilt context — would satisfy
 * "silent in scan mode" just as well. Each absence is paired with the same context in `ref`
 * mode producing a finding.
 */
class WritebackByRefCheckTest extends TestCase
{
    private const REPO = 'owner/repo';

    private const BOARD = 8;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bridge.writeback.correlation' => 'ref']);
    }

    public function test_a_reachable_by_ref_route_is_reported_ok(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertSame('writeback: by-ref reachable (correlation=ref)', $findings[0]['message']);
    }

    /**
     * The motivating failure (DL-031): a kanban predating the by-ref route 404s EVERY
     * correlation with no error anywhere — the check exists to make that state loud.
     */
    public function test_a_404_by_ref_route_warns_that_no_card_will_ever_move(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['message' => 'not found'], 404)]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('correlation=ref but by-ref returned 404 on board 8', $findings[0]['message']);
        $this->assertStringContainsString('EVERY correlation will 404 and no card will move', $findings[0]['message']);
    }

    /**
     * A transient kanban is NOT evidence that by-ref is missing, so the probe's own
     * failure reports as a distinct could-not-ask rather than borrowing the 404 message.
     */
    public function test_an_erroring_probe_reports_could_not_verify_not_a_404_verdict(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['message' => 'nope'], 500)]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('could not probe by-ref reachability', $findings[0]['message']);
        $this->assertStringNotContainsString('returned 404', $findings[0]['message']);
    }

    /**
     * The probe is instance-wide by design (DL-031) — one call against the FIRST mapped
     * board, not one per mapping. A per-mapping probe would multiply the cost of a check
     * whose answer is a property of the kanban build, not of any board.
     */
    public function test_the_probe_runs_once_against_the_first_mapped_board(): void
    {
        Http::fake(['*/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $findings = $this->findings([
            self::REPO => new WritebackMapping(boardId: self::BOARD, stages: []),
            'owner/other' => new WritebackMapping(boardId: 99, stages: []),
        ]);

        $this->assertCount(1, $findings);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/boards/8/tasks/by-ref.json'));
    }

    /**
     * Scan mode does not use by-ref at all. Asserted WITH its control: the identical
     * context in `ref` mode yields a finding, so the silence below is the mode's doing and
     * not a check that could never speak.
     */
    public function test_scan_mode_does_not_probe_by_ref_at_all(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);

        config(['bridge.writeback.correlation' => 'scan']);
        $this->assertSame([], $this->findings());
        Http::assertNothingSent();

        // Positive control: same fake, same context, only the mode differs.
        config(['bridge.writeback.correlation' => 'ref']);
        $this->assertCount(1, $this->findings());
        Http::assertSentCount(1);
    }

    /**
     * A writeback with no mappings has no board to probe against. Same control discipline:
     * adding one mapping back makes the identical setup speak.
     */
    public function test_a_writeback_with_no_mappings_probes_nothing(): void
    {
        Http::fake(['*/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $this->assertSame([], $this->findings([]));
        Http::assertNothingSent();

        $this->assertCount(1, $this->findings());
    }

    // ---- helpers ----

    /**
     * @param  array<string, WritebackMapping>|null  $mappings
     * @return list<array{severity: Severity, message: string}>
     */
    private function findings(?array $mappings = null): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, $mappings ?? [
            self::REPO => new WritebackMapping(boardId: self::BOARD, stages: []),
        ]);
        $ctx->client = new KanbanClient('https://kanban.test', 'wb-token');

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array((new WritebackByRefCheck)->run($ctx), false),
        );
    }
}
