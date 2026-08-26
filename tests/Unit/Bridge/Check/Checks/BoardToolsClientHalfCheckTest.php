<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\ClientHalfLedger;
use App\Models\BoardToolsClientCall;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The per-agent CLIENT-half report (card#7756 / DL-313).
 *
 * ⭐ WHAT THIS CLASS HAS TO PROVE, and why a green run without it would prove nothing: the
 * leg has exactly TWO verdicts over THREE inputs — no record and a STALE record must both
 * land on `unvalidated`, and only a FRESH record may go green. A test that only asserted
 * the happy path would pass just as well against a leg that always reported `ok`, and one
 * that only asserted the absence arm would pass against a leg that never reported anything
 * else. So the three inputs are driven through the same code path and their verdicts
 * compared, and the stale case is the one that separates "reads the row" from "reads the
 * row's AGE".
 *
 * ⛔ THE SEVERITY BOUND IS ASSERTED FROM THE SOURCE, not from the fixtures below. A
 * behavioural union over the inputs some test happens to construct is blind to an arm no
 * input reaches — which is the failure mode the whole `bridge:check` program exists to
 * remove — so `test_the_leg_can_construct_only_ok_and_unvalidated()` slices the class's own
 * code the way `CheckCommandSeverityContractTest` slices `probePinnedLine()`. That is what
 * makes "never `fail`, never `warn`" a property of the CLASS rather than of this corpus.
 */
class BoardToolsClientHalfCheckTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    public function test_a_fresh_call_is_the_positive_proof_and_names_its_age(): void
    {
        $this->recordCall(ageSeconds: 3 * 3600 + 1800);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('board_tools: agent prod-agent: client half WIRED', $findings[0]['message']);
        // The AGE is on the green line by design: an operator judging "3h" beats a boolean
        // they have to trust, and it is what makes a seat drifting toward silence visible
        // BEFORE it crosses the window.
        $this->assertStringContainsString('last successful board-tools call was 3h ago, over ssh', $findings[0]['message']);
    }

    public function test_no_record_is_unvalidated_and_says_it_is_not_evidence_of_unwired(): void
    {
        $this->assertSame(0, BoardToolsClientCall::query()->count());

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('client half UNREPORTED', $findings[0]['message']);
        // THE BOUND IS IN THE TEXT, NOT LEFT TO THE SEVERITY. This is the whole card: an
        // operator read a bridge-side absence as a client-side fault and burned a
        // privileged remediation window on it.
        $this->assertStringContainsString('NOT EVIDENCE THE SEAT IS UNWIRED', $findings[0]['message']);
        $this->assertStringContainsString('ASK THE SEAT', $findings[0]['message']);
        $this->assertStringContainsString('do NOT re-provision', $findings[0]['message']);
    }

    /**
     * THE DISCRIMINATING THIRD INPUT. A stale row is present, readable and parseable — so a
     * leg that merely checked for a row's EXISTENCE would go green here, and every other
     * assertion in this class would still pass.
     */
    public function test_a_stale_call_is_unvalidated_again_never_ok(): void
    {
        $this->recordCall(ageSeconds: 22 * 86400);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('client half UNREPORTED', $findings[0]['message']);
        $this->assertStringContainsString('was 22d ago (over ssh), older than the 7d freshness window', $findings[0]['message']);
        $this->assertStringContainsString('NOT EVIDENCE THE SEAT IS UNWIRED', $findings[0]['message']);
    }

    /**
     * The same three inputs, compared as a set. Written as one test on purpose: the three
     * assertions above each pass against a leg stuck on their own verdict, and only the
     * comparison shows the leg DISCRIMINATES.
     */
    public function test_the_three_inputs_produce_two_distinct_verdicts_from_one_code_path(): void
    {
        $none = $this->findings()[0]['severity'];

        $this->recordCall(ageSeconds: 22 * 86400);
        $stale = $this->findings()[0]['severity'];

        $this->recordCall(ageSeconds: 60);
        $fresh = $this->findings()[0]['severity'];

        $this->assertSame(Severity::Unvalidated, $none);
        $this->assertSame(Severity::Unvalidated, $stale);
        $this->assertSame(Severity::Ok, $fresh);
        // The point of the set: the leg is not constant in either direction.
        $this->assertNotSame($fresh, $stale);
    }

    public function test_the_ttl_is_the_boundary_and_is_overridable(): void
    {
        // One hour old, with a one-minute window: the row is fine, the WINDOW decides — so
        // this pins that the config key is read rather than the 7-day default hard-coded.
        config(['bridge.board_tools.client_half_ttl' => 60]);
        $this->recordCall(ageSeconds: 3600);

        $this->assertSame(Severity::Unvalidated, $this->findings()[0]['severity']);

        // The control: the same row, the default window, opposite verdict.
        config(['bridge.board_tools.client_half_ttl' => 7 * 86400]);
        $this->assertSame(Severity::Ok, $this->findings()[0]['severity']);
    }

    public function test_a_non_positive_ttl_falls_back_to_the_shipped_default_rather_than_staling_everything(): void
    {
        // A `0` would otherwise make every stamp stale the instant it was written — a
        // typo turning a healthy fleet plain, with the leg's own text blaming the seat.
        config(['bridge.board_tools.client_half_ttl' => 0]);
        $this->recordCall(ageSeconds: 3600);

        $this->assertSame(Severity::Ok, $this->findings()[0]['severity']);
    }

    /**
     * The read itself failing is limb (a), not an absence: an unmigrated install must not
     * be reported as a silent seat, and must not ABORT `bridge:check` either
     * ({@see CheckRunner} deliberately does not catch).
     *
     * ⚑ THE FAILURE IS REAL, NOT SYNTHETIC. The query runs against a genuinely unmigrated
     * SQLite connection and comes back with the driver's own `no such table`, so this
     * asserts the arm a real install reaches rather than a throw the test invented.
     */
    public function test_an_unreadable_record_is_unvalidated_and_does_not_abort_the_run(): void
    {
        $findings = $this->withUnmigratedDatabase(fn () => $this->findings());

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('could NOT read the client-half record', $findings[0]['message']);
        $this->assertStringContainsString('board_tools_client_calls', $findings[0]['message']);
        $this->assertStringContainsString('says nothing about the seat', $findings[0]['message']);
    }

    public function test_an_agent_with_no_enabled_block_reports_nothing(): void
    {
        $config = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);

        $this->assertSame([], $this->findingsOfFor(new BoardToolsClientHalfCheck, $config));
    }

    public function test_the_report_is_per_agent_and_never_borrows_another_seats_call(): void
    {
        // The failure this forbids is the one an install with two board-tools agents would
        // hit first: one seat calling would certify the other.
        $this->recordCall(agent: 'other-agent', ageSeconds: 60);

        $findings = $this->findings();

        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('agent prod-agent: client half UNREPORTED', $findings[0]['message']);
    }

    /**
     * ⭐ THE SEVERITY BOUND, DERIVED FROM THE SOURCE. Every `Finding::` construction in this
     * check is inline in the class, so the slice is the complete set — which is the whole
     * reason it is read here rather than unioned from the fixtures above.
     *
     * WHY `fail` AND `warn` ARE BOTH FORBIDDEN: a `fail` flips `bridge:check`'s exit over a
     * seat that is idle by choice, and a `warn` asserts something is wrong when the honest
     * statement is that this run learned nothing. The rule that decides is prose in
     * {@see Severity}; what this pins is that the set has not moved without someone
     * revisiting it.
     */
    public function test_the_leg_can_construct_only_ok_and_unvalidated(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionMethod(BoardToolsClientHalfCheck::class, 'runFor'))->getFileName());

        preg_match_all('/Finding::(ok|warn|unvalidated|fail)\(/', $this->codeOf($source), $m);
        $emitted = array_values(array_unique($m[1]));
        sort($emitted);

        // Non-vacuous: an empty match satisfies any assertSame against an empty list.
        $this->assertNotEmpty($emitted, 'the source scan found no Finding factory calls — it has broken');
        $this->assertSame(
            ['ok', 'unvalidated'],
            $emitted,
            'the severity set BoardToolsClientHalfCheck can emit has MOVED. DL-313 fixed it at two: '
            .'`fail` would exit non-zero over an idle seat and `warn` would assert a fault this leg '
            .'cannot establish. Re-read the DL before changing this pin.',
        );
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

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(): array
    {
        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOfFor(new BoardToolsClientHalfCheck, $this->agent()),
        );
    }

    /**
     * Stamp a row `$ageSeconds` in the past.
     *
     * Written through the model rather than through {@see ClientHalfLedger}
     * on purpose: the ledger stamps `now()`, and this class is about how the CHECK reads an
     * age. The ledger's own write is asserted where it happens, in
     * `tests/Feature/AgentTools/BoardToolDispatcherTest.php`.
     */
    private function recordCall(string $agent = 'prod-agent', string $transport = 'ssh', int $ageSeconds = 0): void
    {
        BoardToolsClientCall::query()->updateOrCreate(
            ['agent' => $agent],
            ['transport' => $transport, 'last_success_at' => now()->subSeconds($ageSeconds)],
        );
    }

    /**
     * Run `$body` with every model resolving to a REAL SQLite connection that has never been
     * migrated, so a query against it fails the way it fails on an install that pulled the
     * code and did not run `php artisan migrate`.
     *
     * ⚑ `RefreshDatabase`'s own transaction is unaffected: it resolves the connection through
     * the CONTAINER (`$this->app->make('db')`), while Eloquent resolves through the static
     * resolver swapped here — and the swap is undone in a `finally` regardless.
     */
    private function withUnmigratedDatabase(callable $body): mixed
    {
        config(['database.connections.unmigrated' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        /** @var DatabaseManager $manager */
        $manager = $this->app->make('db');
        $original = BoardToolsClientCall::getConnectionResolver();

        BoardToolsClientCall::setConnectionResolver(new class($manager) implements ConnectionResolverInterface
        {
            public function __construct(private DatabaseManager $manager) {}

            public function connection($name = null): ConnectionInterface
            {
                return $this->manager->connection('unmigrated');
            }

            public function getDefaultConnection(): string
            {
                return 'unmigrated';
            }

            public function setDefaultConnection($name): void {}
        });

        try {
            return $body();
        } finally {
            BoardToolsClientCall::setConnectionResolver($original);
        }
    }

    /**
     * One PHP file with its comments removed — the same reason
     * `UnvalidatedCallSiteTest::codeOf()` does it: a docblock MENTIONING a factory is not a
     * construction site, and this class's own docblocks name every severity in the enum.
     */
    private function codeOf(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
