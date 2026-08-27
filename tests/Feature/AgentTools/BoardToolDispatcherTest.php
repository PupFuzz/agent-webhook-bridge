<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\CallProvenance;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesUnmigratedDatabase;
use Tests\TestCase;

/**
 * The shared BoardToolDispatcher (card 4952, Finding A) — the transport-neutral
 * machinery both front doors single-source. Asserts the DispatchOutcome status-class
 * for each branch (ok 200 / refusal 422 / upstream 502 / writeback-unavailable 503),
 * the exit-code mapping (0/1/2), and the body-shape both doors serialize.
 */
class BoardToolDispatcherTest extends TestCase
{
    // card#7756: a SUCCESSFUL dispatch now writes one durable row (ClientHalfLedger), so
    // this class touches the database transitively where it did not before. Without the
    // trait those rows COMMIT on the MariaDB legs and outlive the test — invisible on
    // SQLite `:memory:`, where the insert finds no table and the ledger swallows it. The
    // isolation guard in Tests\TestCase names both halves.
    use RefreshDatabase;
    use UsesUnmigratedDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dispatcher-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function dispatcher(): BoardToolDispatcher
    {
        return new BoardToolDispatcher(new BoardToolsRegistry);
    }

    private function cfg(): BoardToolsConfig
    {
        return new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );
    }

    public function test_ok_outcome_is_200_exit_0_with_parity_body(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me', CallProvenance::NotSshd);

        $this->assertTrue($outcome->ok);
        $this->assertSame(200, $outcome->status);
        $this->assertSame(0, $outcome->exitCode());
        $body = $outcome->body();
        $this->assertTrue($body['ok']);
        $this->assertSame('board_my_cards', $body['tool']);
        $this->assertArrayHasKey('result', $body);
    }

    public function test_refusal_is_422_exit_1(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $outcome = $this->dispatcher()->dispatch('board_create_card', ['title' => 't', 'tags' => ['triaged']], $this->cfg(), 'me', CallProvenance::NotSshd);

        $this->assertFalse($outcome->ok);
        $this->assertSame(422, $outcome->status);
        $this->assertSame(1, $outcome->exitCode());
        $this->assertFalse($outcome->body()['ok']);
        $this->assertArrayHasKey('error', $outcome->body());
        Http::assertNothingSent();
    }

    public function test_upstream_error_is_502_exit_2(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response('boom', 500),
        ]);

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me', CallProvenance::NotSshd);

        $this->assertSame(502, $outcome->status);
        $this->assertSame(2, $outcome->exitCode());
    }

    public function test_writeback_unavailable_is_503_exit_2(): void
    {
        // No writeback token → WritebackClientFactory throws ConfigException → 503.
        File::delete($this->dir.'/kanban/writeback-token');

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me', CallProvenance::NotSshd);

        $this->assertSame(503, $outcome->status);
        $this->assertSame(2, $outcome->exitCode());
    }

    public function test_unknown_tool_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('board_delete_everything', [], $this->cfg(), 'me', CallProvenance::NotSshd);
        $this->assertSame(422, $outcome->status);
    }

    public function test_non_array_args_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('board_my_cards', 'not-an-object', $this->cfg(), 'me', CallProvenance::NotSshd);
        $this->assertSame(422, $outcome->status);
    }

    public function test_empty_tool_name_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('', [], $this->cfg(), 'me', CallProvenance::NotSshd);
        $this->assertSame(422, $outcome->status);
    }

    /**
     * ⭐ THE SEAT'S REPORT-BY-CALLING (card#7756 / DL-313). A SEAT reaching this point has
     * already exercised its whole client chain, so the success point is the only place the
     * bridge can honestly learn anything about that half — it may not read the seat's own
     * files to find out. The row records that the door OPENED for the agent and cannot name
     * the caller (`--probe-tools` and a hand-run `bridge:tools-call` stamp it too), which is
     * why the reading check bounds its `ok` line rather than claiming a wired seat.
     */
    public function test_a_successful_call_records_the_client_half_row(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'prod-agent', CallProvenance::NotSshd);

        $row = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();
        $this->assertSame('ssh', $row->transport);
        $this->assertTrue($row->last_success_at->greaterThan(now()->subMinute()));
    }

    /**
     * The discriminating half. Without it, a leg that stamped on EVERY dispatch would pass
     * the test above — and `bridge:check` would then certify a seat's client half off a
     * refusal the bridge generated on its own, before any board work happened.
     */
    public function test_a_refused_call_records_nothing(): void
    {
        $outcome = $this->dispatcher()->dispatch('board_delete_everything', [], $this->cfg(), 'prod-agent', CallProvenance::NotSshd);

        $this->assertFalse($outcome->ok);
        $this->assertSame(0, BoardToolsClientCall::query()->count());
    }

    public function test_an_upstream_error_records_nothing(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response('boom', 500),
        ]);

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'prod-agent', CallProvenance::NotSshd);

        $this->assertSame(502, $outcome->status);
        $this->assertSame(0, BoardToolsClientCall::query()->count());
    }

    /**
     * ⛔ RECORDING IS AN OBSERVATION ABOUT THE CALL, NEVER A PRECONDITION OF IT. The tool has
     * already read or written the board by the time the ledger runs, so a failure here must
     * cost the caller nothing — surfacing it would turn "the audit row could not be written"
     * into a retry that re-does the board work, or into a 5xx on a call that SUCCEEDED.
     *
     * ⚑ The failure is REAL, not synthetic: the insert runs against a genuinely unmigrated
     * SQLite connection and comes back with the driver's own `no such table`, which is the
     * live cause (an install that pulled the code and has not run `php artisan migrate`).
     */
    public function test_a_recording_failure_does_not_break_the_tool_call(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $outcome = $this->withUnmigratedDatabase(
            fn () => $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'prod-agent', CallProvenance::NotSshd),
        );

        // The call is byte-for-byte the healthy one: same ok, same status, same exit code,
        // same body shape. Asserted rather than "no exception was thrown", because a
        // swallowed throw that degraded the RESULT would pass that weaker test.
        $this->assertTrue($outcome->ok);
        $this->assertSame(200, $outcome->status);
        $this->assertSame(0, $outcome->exitCode());
        $this->assertSame('board_my_cards', $outcome->body()['tool']);
        $this->assertArrayHasKey('result', $outcome->body());
        // …and nothing was recorded, which is what makes the sabotage the cause rather
        // than something the harness merely hoped for.
        $this->assertSame(0, BoardToolsClientCall::query()->count());
    }
}
