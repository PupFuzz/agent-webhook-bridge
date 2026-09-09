<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ClientHalfLedger;
use App\Bridge\Tools\ClientVersion;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\MaterializesChecks;
use Tests\Support\UsesUnmigratedDatabase;
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
 * ⭐ SINCE card#7836 / DL-316 THE `ok` SEVERITY CARRIES TWO DIFFERENT CLAIMS, and the same
 * argument applies one level down: the STRONGER line is reported only for a row whose
 * `call_provenance` is `sshd`, and a leg that printed it for every green row would satisfy
 * every assertion the class had before. So the THREE provenance inputs — `sshd`, `not_sshd`
 * and NULL — are driven through the same path and compared as a set, with NULL (a row
 * written before the column existed) pinned NOT to read as proven. The over-claim arm is
 * pinned as text, not only as a severity: the whole point of the card is that a green line
 * means what it says, and `Severity::Ok` is identical on both.
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
    use UsesUnmigratedDatabase;

    /**
     * The version the stand-in bundled snapshot declares — the same one the incident was
     * measured against (a 0.4.4 seat calling a bridge that bundled this).
     */
    private const BUNDLED_VERSION = '0.9.12';

    private string $bundledDir;

    /**
     * ⚑ AFTER `parent::tearDown()`, never before. A throw in a tearDown that runs first
     * cascades into every later test in the class and hides the real failure; nothing here
     * is load-bearing enough to risk that, so the cleanup is last and best-effort.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->bundledDir)) {
            @unlink($this->bundledDir.'/package.json');
            @rmdir($this->bundledDir);
        }
    }

    public function test_a_fresh_call_reports_the_recorded_call_and_names_its_age(): void
    {
        $this->recordCall(ageSeconds: 3 * 3600 + 1800);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('board_tools: agent prod-agent: client half REPORTED', $findings[0]['message']);
        // The AGE is on the green line by design: an operator judging "3h" beats a boolean
        // they have to trust, and it is what makes a seat drifting toward silence visible
        // BEFORE it crosses the window.
        $this->assertStringContainsString('a successful board-tools call for this agent was recorded 3h ago, over ssh', $findings[0]['message']);
    }

    /**
     * ⭐ THE GREEN LINE MAY NOT CLAIM A SEAT. Three things stamp this row without the seat's
     * client chain existing at all: `bridge:check --probe-tools` (a real POST to
     * `/agent-tools/call` with the agent's own bearer), `provision-board-tools.py
     * --self-cert`, and an operator running `bridge:tools-call --agent=X` on the bridge
     * host. The enablement runbook makes that REACHABLE rather than theoretical — its
     * verify step is `--probe-tools` and its NEXT step restarts the channel server, so the
     * ledger is stamped before the seat is live and the line would read green for a seat
     * with no `.mcp.json` entry at all. That is the card's own incident inverted: an
     * operator reads green and does NOT re-provision the seat that needs it.
     *
     * Presence AND absence are pinned together: the absence alone would be satisfied by any
     * replacement wording, including a differently-worded overclaim.
     */
    public function test_the_green_line_names_what_else_stamps_the_row_and_claims_no_more(): void
    {
        $this->recordCall(ageSeconds: 60);

        $message = $this->findings()[0]['message'];

        $this->assertStringContainsString('THAT IS THE CALL, NOT THE CALLER', $message);
        $this->assertStringContainsString('--probe-tools', $message);
        $this->assertStringContainsString('--self-cert', $message);
        $this->assertStringContainsString('bridge:tools-call --agent=prod-agent', $message);
        $this->assertStringContainsString('the door OPENED', $message);
        // The retired claim, by both of its load-bearing words: the row is not proof of a
        // wired seat, and enumerating the client chain here presented an inference as a
        // measurement.
        $this->assertStringNotContainsString('proof', $message);
        $this->assertStringNotContainsString('client chain', $message);
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

        $this->assertSame([], $this->findingsOfFor(new BoardToolsClientHalfCheck($this->bundledDir()), $config));
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
     * WHY `fail` IS FORBIDDEN: it flips `bridge:check`'s exit over a seat that is idle by
     * choice, or over a snapshot an operator has not re-deployed yet. ⭐ WHY `warn` IS NOW
     * IN THE SET, having been forbidden by DL-313: card#8974 gave the leg its first MEASURED
     * fault — a seat whose reported channel-server version is older than the one this bridge
     * bundles. That is not "this run learned nothing" (the reason `warn` was excluded); it
     * is a conclusion, reached from a comparison, with a remedy the operator can act on.
     *
     * ⛔ IT HAS EXACTLY ONE PRODUCER, and that is load-bearing rather than incidental:
     * `NextSteps::seatReported()` reads a `Warn` here as "the seat DID report", so a second
     * arm minting `warn` for some other reason would silently start clearing an agent's
     * NEXT STEPS entry. This pin is what stops that arriving unnoticed; the consequence
     * itself is pinned in `CheckNextStepsTest`. The rule that decides is prose in
     * {@see Severity}.
     */
    public function test_the_leg_can_construct_only_ok_unvalidated_and_warn(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionMethod(BoardToolsClientHalfCheck::class, 'runFor'))->getFileName());

        preg_match_all('/Finding::(ok|warn|unvalidated|fail)\(/', $this->codeOf($source), $m);
        $emitted = array_values(array_unique($m[1]));
        sort($emitted);

        // Non-vacuous: an empty match satisfies any assertSame against an empty list.
        $this->assertNotEmpty($emitted, 'the source scan found no Finding factory calls — it has broken');
        $this->assertSame(
            ['ok', 'unvalidated', 'warn'],
            $emitted,
            'the severity set BoardToolsClientHalfCheck can emit has MOVED. DL-313 fixed it at two and '
            .'DL-364 added the third: `fail` would exit non-zero over an idle seat or an un-redeployed '
            .'snapshot, and `warn` belongs to the ONE measured fault this leg can reach (a client older '
            .'than the bundled one). NextSteps::seatReported() reads that warn as a positive report, so '
            .'a second warn producer would clear an agent\'s NEXT STEPS entry silently. Re-read both DLs '
            .'before changing this pin.',
        );
    }

    // ─── the provenance split (card#7836 / DL-316) ────────────────────────────

    /**
     * ⭐ THE EARNED CLAIM. `sshd` provenance says the serving process carried sshd's session
     * environment with no pty — the shape of the pinned forced command — so the line may
     * name what that EXCLUDES, which a bare record could not.
     */
    public function test_an_sshd_provenance_call_reports_the_stronger_claim(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('client half REPORTED THROUGH THE SSH DOOR', $findings[0]['message']);
        $this->assertStringContainsString("carried sshd's session environment, had NO CONTROLLING TERMINAL, and carried no SSH_TTY", $findings[0]['message']);
        $this->assertStringContainsString('THAT RULES OUT', $findings[0]['message']);
        // ⭐ THE ENUMERATION IS RE-DERIVED FROM THE PREDICATE, and the tmux regression is why
        // it had to be. The line it replaced claimed an interactive ssh shell "exports
        // SSH_TTY and passes it to everything it spawns" and that a local console, cron or
        // systemd unit "exports neither marker" — both measurably FALSE, and both were the
        // most confident sentence on the most confident line. What the predicate actually
        // establishes is the controlling terminal, so that is what the line may name.
        $this->assertStringContainsString('EVERY hand-run FROM A TERMINAL', $findings[0]['message']);
        $this->assertStringContainsString('tmux pane', $findings[0]['message']);
        $this->assertStringContainsString('keeps its controlling terminal even when stdin is a pipe', $findings[0]['message']);
        // The two retired false clauses, pinned as ABSENCES so neither can come back.
        $this->assertStringNotContainsString('passes it to everything it spawns', $findings[0]['message']);
        $this->assertStringNotContainsString('which exports neither marker', $findings[0]['message']);
    }

    /**
     * ⭐ THE RESIDUAL AMBIGUITY IS IN THE TEXT, AND THE CARD IS ABOUT NOTHING ELSE. The
     * stronger line NARROWS the caller set; it does not close it. `bridge:check
     * --probe-tools-ssh` drives a real, pty-less ssh round-trip from this very host and is
     * INDISTINGUISHABLE here from the seat, and `provision-board-tools.py --self-cert` does
     * the same from the seat's host. A line implying the seat called would be false on any
     * host where either had just run — which the enablement runbook makes routine.
     *
     * Presence and absence are pinned together: an absence-only assertion is satisfied by
     * any rewording, including a differently-worded overclaim.
     */
    public function test_the_stronger_line_still_names_what_it_cannot_exclude(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd);

        $message = $this->findings()[0]['message'];

        $this->assertStringContainsString('DOES NOT NAME THE CALLER', $message);
        $this->assertStringContainsString('--probe-tools-ssh', $message);
        $this->assertStringContainsString('--self-cert', $message);
        $this->assertStringContainsString('INDISTINGUISHABLE from the seat', $message);
        // ⭐ THE SECOND REMAINDER, which the controlling-terminal predicate MINTS and the
        // `SSH_TTY` one did not have: a terminal-less hand-run that carries SSH_CONNECTION.
        // A systemd USER unit inherits it outright after `systemctl --user
        // import-environment`, so "cron and systemd export neither marker" was never true
        // and this line names the case instead of denying it.
        $this->assertStringContainsString('TWO THINGS IT DOES NOT RULE OUT', $message);
        $this->assertStringContainsString('TERMINAL-LESS context', $message);
        $this->assertStringContainsString('systemctl --user import-environment', $message);
        // The claims this line is NOT entitled to make. `proof` and `client chain` are the
        // two DL-313 retired as inference-presented-as-measurement; `the seat called` is the
        // one card#7836 could have minted and did not.
        $this->assertStringNotContainsString('proof', $message);
        $this->assertStringNotContainsString('client chain', $message);
        $this->assertStringNotContainsString('the seat called', $message);
    }

    /**
     * ⚑ THE STORED VALUE IS A DECISION INPUT, NOT OUTPUT — the line describes the shape in
     * words and never echoes the column it branched on.
     *
     * ⛔ WHAT THIS IS NOT, stated because the version it replaces claimed to be it. It is NOT
     * a leak control: `recordCall()` writes the ENUM or null, exactly as `ClientHalfLedger`
     * does, so no environment value is ever in the column and an
     * `assertStringNotContainsString('203.0.113', …)` here COULD NOT FAIL — a decorative
     * assertion under a docblock asserting a control is worse than no assertion, because the
     * next reader stops looking. The real leak controls drive a value that IS present at the
     * moment of the write: `ClientHalfLedgerTest` for the row and `ToolsCallCommandTest` for
     * the row, stdout and stderr together.
     */
    public function test_the_printed_line_never_echoes_the_provenance_column(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd);

        $message = $this->findings()[0]['message'];

        $this->assertStringContainsString('REPORTED THROUGH THE SSH DOOR', $message);
        $this->assertStringNotContainsString('not_sshd', $message);
        $this->assertStringNotContainsString('call_provenance', $message);
    }

    /**
     * ⭐ A BACKING VALUE THIS BUILD CANNOT INTERPRET MUST NOT ABORT `bridge:check`, and the
     * reason this test exists is that the code once believed it could not happen HERE. The
     * Eloquent enum cast is applied LAZILY, on attribute access — NOT on hydration — so the
     * `ValueError` lands wherever the attribute is first READ. Read at the branch, that is
     * OUTSIDE the leg's fail-soft envelope and the whole command dies with an uncaught
     * exception, taking every other check's output with it; DL-316 recorded the opposite as
     * a bound and was measurably wrong.
     *
     * ⛔ THIS IS NOT A GUARD OVER AN UNREACHABLE STATE (canon #6): nothing is added to defend
     * against the value, and the leg still has exactly two severities. What is asserted is
     * that the read happens inside the envelope the leg ALREADY has, so the existing limb (a)
     * arm answers for it — which is what makes the refusal to guard correct rather than lucky.
     */
    public function test_an_uninterpretable_provenance_value_is_reported_and_does_not_abort_the_run(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd);
        // ⛔ MUST FIT varchar(16) — SQLite ignores the width, MariaDB enforces it (SQLSTATE 22001),
        // so a longer literal here is green locally and red on both CI database legs. It is also
        // the more faithful input: a value a FUTURE build wrote had to fit this column too, so an
        // over-long one tests a state that cannot occur.
        DB::table('board_tools_client_calls')->where('agent', 'prod-agent')->update(['call_provenance' => 'future-case']);

        // Non-vacuous: the hydration really does succeed, so the throw really is at the READ
        // and this test is about the placement rather than about the query.
        $row = BoardToolsClientCall::query()->where('agent', 'prod-agent')->first();
        $this->assertNotNull($row, 'the row did not hydrate at all, so this says nothing about where the cast throws');

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('could NOT read the client-half record', $findings[0]['message']);
        $this->assertStringNotContainsString('THROUGH THE SSH DOOR', $findings[0]['message']);
    }

    /**
     * A MEASURED non-sshd call keeps DL-313's line, to the byte. This is the hand-run
     * `bridge:tools-call` in an operator's shell and every http call — including
     * `--probe-tools`, which is why the HTTP door states this provenance as a constant.
     */
    public function test_a_measured_non_sshd_call_keeps_the_weaker_claim(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::NotSshd);

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('client half REPORTED — a successful board-tools call', $findings[0]['message']);
        $this->assertStringContainsString('THAT IS THE CALL, NOT THE CALLER', $findings[0]['message']);
        $this->assertStringNotContainsString('THROUGH THE SSH DOOR', $findings[0]['message']);
    }

    /**
     * ⭐ AN UNMEASURED ROW IS NOT A PROVEN ONE. Every row written before DL-316 has a NULL
     * `call_provenance` — the column is additive with no backfill, because both available
     * backfills are lies. This pins the direction that matters: an install upgrading with a
     * live ledger must not have its existing rows silently promoted to the stronger claim.
     */
    public function test_a_row_written_before_the_column_existed_does_not_read_as_proven(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: null);

        $findings = $this->findings();

        $this->assertNull(
            BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole()->call_provenance,
            'the fixture did not actually produce a NULL-provenance row, so this test says nothing about a pre-upgrade install',
        );
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringNotContainsString('THROUGH THE SSH DOOR', $findings[0]['message']);
        $this->assertStringContainsString('THAT IS THE CALL, NOT THE CALLER', $findings[0]['message']);
    }

    /**
     * The three provenance inputs compared as a SET, for the same reason the three age
     * inputs are: each assertion above passes against a leg stuck on its own arm, and only
     * the comparison shows the leg DISCRIMINATES. The severity is deliberately asserted to
     * be IDENTICAL across all three — that is what makes the message the whole signal here,
     * and what a severity-only test would have missed entirely.
     */
    public function test_the_three_provenance_inputs_produce_two_distinct_lines_from_one_code_path(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd);
        [$sshdSeverity, $sshd] = array_values($this->findings()[0]);

        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::NotSshd);
        [$notSshdSeverity, $notSshd] = array_values($this->findings()[0]);

        $this->recordCall(ageSeconds: 60, provenance: null);
        [$nullSeverity, $null] = array_values($this->findings()[0]);

        $this->assertSame(Severity::Ok, $sshdSeverity);
        $this->assertSame(Severity::Ok, $notSshdSeverity);
        $this->assertSame(Severity::Ok, $nullSeverity);
        $this->assertNotSame($sshd, $notSshd, 'the leg is constant across provenance — the stronger claim is being made, or withheld, for every green row alike');
        $this->assertSame($notSshd, $null, 'a NULL row rendered differently from a measured non-sshd one: the two say the same thing and DL-313\'s wording is meant to be byte-identical on both');
    }

    /**
     * ⚑ A STALE ROW IS STALE WHATEVER ITS PROVENANCE. The freshness window decides FIRST, so
     * the stronger claim cannot be reached by an old sshd stamp — otherwise the one arm that
     * carries the most confident wording would be the one arm exempt from the TTL.
     */
    public function test_an_sshd_row_past_the_freshness_window_is_unvalidated_like_any_other(): void
    {
        $this->recordCall(ageSeconds: 22 * 86400, provenance: CallProvenance::Sshd);

        $findings = $this->findings();

        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('client half UNREPORTED', $findings[0]['message']);
        $this->assertStringNotContainsString('THROUGH THE SSH DOOR', $findings[0]['message']);
    }

    // ─── the seat's snapshot version (card#8974 / DL-364) ─────────────────────

    /**
     * ⭐ THE INCIDENT, REPRODUCED. A seat on 0.4.4 called a bridge bundling 0.9.12;
     * `board_correct_card` — a tool the 0.4.4 snapshot never advertised — was reported
     * "absent from my surface" and blamed on the BRIDGE, because nothing on either side
     * compared the two numbers. The line now carries BOTH, so the reader can see which side
     * is behind, and it WARNS: this is a measured conclusion with an actionable remedy, not
     * a leg that could not look.
     */
    public function test_a_client_older_than_the_bundled_snapshot_warns_and_names_both_versions(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('CLIENT VERSION 0.4.4 IS OLDER THAN THE 0.9.12 THIS BRIDGE BUNDLES', $findings[0]['message']);
        // The misattribution the card exists to remove, named on the line itself: the
        // absent tool may be missing from the SEAT's copy, not from this bridge.
        $this->assertStringContainsString('missing from ITS copy rather than from this bridge', $findings[0]['message']);
        // The remedy is BOTH halves. A re-deploy with no restart leaves the running server
        // on the old files, so this line would still report the old version and an operator
        // would conclude the re-deploy failed.
        $this->assertStringContainsString('npm ci', $findings[0]['message']);
        $this->assertStringContainsString('RESTART that session', $findings[0]['message']);
        // It is still the same finding, so the seat's own report is not displaced by it.
        $this->assertStringContainsString('client half REPORTED', $findings[0]['message']);
    }

    /**
     * THE CONTROL FOR THE WARN. Same install, same row, one field different — a version
     * EQUAL to the bundled one — and the severity goes green. Without this, a leg that
     * warned on every recorded version would pass the test above.
     */
    public function test_a_client_at_the_bundled_snapshot_does_not_warn(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.9.12');

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('Client version 0.9.12 is at or ahead of the 0.9.12 this bridge bundles', $findings[0]['message']);
        $this->assertStringNotContainsString('OLDER THAN', $findings[0]['message']);
    }

    /**
     * A seat AHEAD of the bridge is not a fault to warn about — it is what an operator sees
     * mid-rollout, and the remedy the warn hands out (re-copy the bridge's snapshot over the
     * seat) would be a DOWNGRADE. The comparison is `<`, not `!==`, and this is the arm that
     * separates the two.
     */
    public function test_a_client_ahead_of_the_bundled_snapshot_does_not_warn(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.9.20');

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('Client version 0.9.20 is at or ahead of the 0.9.12', $findings[0]['message']);
    }

    /**
     * ⛔ AN ABSENT REPORT IS NOT A STALE SEAT. It is what every client older than
     * `ClientVersion::FIRST_REPORTING_SNAPSHOT` sends, what `--probe-tools` / `--self-cert`
     * / a hand-run `bridge:tools-call` send (none of them is a channel server), and what a
     * value the bridge would not take is reduced to. Warning on it would put a re-deploy
     * instruction in front of every operator on the fleet the day this shipped.
     */
    public function test_a_call_reporting_no_version_says_so_and_does_not_warn(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: null);

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('CLIENT VERSION NOT REPORTED (client < '.ClientVersion::FIRST_REPORTING_SNAPSHOT.')', $findings[0]['message']);
        // The bound in the text, not left to the severity — the same discipline the
        // UNREPORTED arm follows one level up.
        $this->assertStringContainsString('NOT EVIDENCE THE SEAT IS STALE', $findings[0]['message']);
        $this->assertStringNotContainsString('OLDER THAN', $findings[0]['message']);
    }

    /**
     * The three version inputs compared as a SET, for the reason the age and provenance sets
     * exist: each assertion above passes against a leg stuck on its own arm, and only the
     * comparison shows the leg DISCRIMINATES on the version rather than on the row.
     */
    public function test_the_three_version_inputs_produce_two_severities_and_three_distinct_lines(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');
        [$staleSeverity, $stale] = array_values($this->findings()[0]);

        $this->recordCall(ageSeconds: 60, clientVersion: '0.9.12');
        [$currentSeverity, $current] = array_values($this->findings()[0]);

        $this->recordCall(ageSeconds: 60, clientVersion: null);
        [$absentSeverity, $absent] = array_values($this->findings()[0]);

        $this->assertSame(Severity::Warn, $staleSeverity);
        $this->assertSame(Severity::Ok, $currentSeverity);
        $this->assertSame(Severity::Ok, $absentSeverity);
        $this->assertNotSame($stale, $current);
        $this->assertNotSame($current, $absent, 'a reported CURRENT version and no report at all rendered identically — the line says nothing about which happened');
    }

    /**
     * ⛔ THE FRESHNESS WINDOW DECIDES FIRST, exactly as it does for the provenance claim. A
     * stale ROW carries a version that may be months out of date and says nothing about what
     * the seat runs today, so the version clause must not be reached at all — otherwise the
     * one arm reporting "this run learned nothing" would also be handing out a re-deploy
     * instruction derived from a stamp it just declared too old to trust.
     */
    public function test_a_row_past_the_freshness_window_reports_no_version_verdict(): void
    {
        $this->recordCall(ageSeconds: 22 * 86400, clientVersion: '0.4.4');

        $findings = $this->findings();

        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('client half UNREPORTED', $findings[0]['message']);
        $this->assertStringNotContainsString('0.4.4', $findings[0]['message']);
    }

    /**
     * ⭐ THE CLAUSE IS ON BOTH GREEN LINES, and the ssh-proven arm is the one that could
     * silently lose it: it is a separate `yield` with its own long enumeration, so a version
     * clause appended to the weaker line alone would leave every ssh-door seat — the seats
     * this whole feature is for — with no version reported at all, and nothing else in this
     * class would notice.
     */
    public function test_the_stronger_ssh_line_carries_the_version_clause_and_warns_with_it(): void
    {
        $this->recordCall(ageSeconds: 60, provenance: CallProvenance::Sshd, clientVersion: '0.4.4');

        $findings = $this->findings();

        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('client half REPORTED THROUGH THE SSH DOOR', $findings[0]['message']);
        $this->assertStringContainsString('CLIENT VERSION 0.4.4 IS OLDER THAN THE 0.9.12', $findings[0]['message']);
    }

    /**
     * ⭐ A BLIND READ OF THE BRIDGE'S OWN FILE IS NOT A SILENT SEAT. With no bundled manifest
     * the comparison has no operand — but the SEAT's report was measured and is what this
     * finding is about, so the line stays green, prints the reported version, and says the
     * comparison was NOT MADE. Turning the whole finding `unvalidated` here would tell the
     * operator their seat had gone quiet because a file in the BRIDGE's checkout is missing:
     * the card's own misattribution, inverted.
     */
    public function test_an_unreadable_bundled_manifest_reports_the_version_and_says_it_was_not_compared(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');
        $this->bundling(null);

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('client half REPORTED', $findings[0]['message']);
        $this->assertStringContainsString('The seat reports client version 0.4.4, NOT COMPARED', $findings[0]['message']);
        $this->assertStringContainsString('is not present', $findings[0]['message']);
        // ⛔ The verdict it must NOT reach: the same input WITH a manifest warns, so a leg
        // that fell back to comparing against nothing would warn here too.
        $this->assertStringNotContainsString('OLDER THAN', $findings[0]['message']);
    }

    /**
     * ⚑ THE FOURTH MANIFEST STATE, which the absent-file case above does not reach: a
     * manifest that PARSES and declares no `version`. It renders a DIFFERENT sentence,
     * because it is a different operator situation — nothing to restore, the file is there
     * and wrong — and a leg that collapsed the four causes into "unreadable" would send this
     * reader after a file they can see perfectly well.
     */
    public function test_a_bundled_manifest_declaring_no_version_says_so_rather_than_reading_as_absent(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');
        $this->bundling('');

        $findings = $this->findings();

        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('The seat reports client version 0.4.4, NOT COMPARED', $findings[0]['message']);
        $this->assertStringContainsString('declares no version', $findings[0]['message']);
        $this->assertStringNotContainsString('is not present', $findings[0]['message']);
    }

    /**
     * The control for the test above: it asserts an ABSENCE of the stale verdict, which any
     * broken clause satisfies. This pins that the very same row, with the manifest restored,
     * DOES reach it — so the not-compared arm is the manifest's doing and not the row's.
     */
    public function test_the_not_compared_arm_is_the_manifests_doing_not_the_rows(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');
        $this->bundling(null);
        $withoutManifest = $this->findings()[0]['severity'];

        $this->bundling(self::BUNDLED_VERSION);
        $withManifest = $this->findings()[0]['severity'];

        $this->assertSame(Severity::Ok, $withoutManifest);
        $this->assertSame(Severity::Warn, $withManifest);
    }

    /**
     * ⭐ AN UNORDERABLE REPORTED VERSION MUST NOT BECOME A STALE VERDICT. The comparator
     * COERCES rather than refusing — `versionTuple()` takes each chunk's leading digits and
     * yields 0 where there are none — so `v1.0.0` ranks as [0,0,0] and compares OLDER than
     * every real snapshot. Unfixed, the leg printed "v1.0.0 IS OLDER THAN 0.9.12" and told
     * the operator to re-copy the bridge's snapshot over that seat: a DOWNGRADE instruction
     * for a NEWER client, derived from a fabricated zero, on the most actionable line this
     * leg prints.
     *
     * The absence of the stale verdict is asserted WITH the presence of the not-compared
     * one: an absence alone is satisfied by any breakage, including a leg that says nothing.
     *
     * @param  string  $reported  a value `ClientVersion` ADMITS (it is a storage whitelist,
     *                            not a semver parser) and the comparator cannot order
     */
    #[DataProvider('unorderableReportedVersions')]
    public function test_a_reported_version_the_comparator_cannot_order_is_not_compared(string $reported): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: $reported);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString("The seat reports client version {$reported}, NOT COMPARED", $findings[0]['message']);
        $this->assertStringContainsString('does not begin with a digit', $findings[0]['message']);
        $this->assertStringNotContainsString('IS OLDER THAN', $findings[0]['message']);
        // The remedy must not be the re-deploy one either — that is the downgrade.
        $this->assertStringNotContainsString('over the seat\'s deployed directory', $findings[0]['message']);
        $this->assertStringContainsString('Ask that seat what it is running', $findings[0]['message']);
    }

    /** @return array<string, array{0: string}> */
    public static function unorderableReportedVersions(): array
    {
        return [
            'a v-prefixed semver (NEWER than the bundle by any human reading)' => ['v1.0.0'],
            'not a version at all' => ['abc'],
            'a bare pre-release word' => ['beta2'],
            'a channel name' => ['release-3'],
        ];
    }

    /**
     * ⚠ THE DISCLOSED REMAINDER, pinned so that narrowing the predicate later is a decision
     * rather than an accident. `0.beta.1` has a NUMERIC first chunk, so it is ordered — as
     * [0,0,1] — and warns. That is deliberately NOT treated as the defect above: the
     * declared authority (`bin/provision-board-tools.py`, the tool that actually replaces a
     * deployed snapshot) reads it identically and would itself replace that copy, so the
     * warn agrees with the tool that acts on it and CLEARS when the operator follows it. The
     * `v1.0.0` case is different in kind — there the WHOLE tuple is a fabrication.
     */
    public function test_a_numeric_first_chunk_is_still_ordered_even_with_a_word_after_it(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: '0.beta.1');

        $findings = $this->findings();

        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('CLIENT VERSION 0.beta.1 IS OLDER THAN THE 0.9.12', $findings[0]['message']);
    }

    /**
     * The control for the routing above: the SAME install and the SAME bundled manifest, with
     * only the reported string changed, DOES reach the stale warn. Without it, the
     * not-compared assertions would pass against a leg that had stopped comparing anything.
     */
    public function test_the_not_compared_routing_is_the_reported_versions_doing_not_the_legs(): void
    {
        $this->recordCall(ageSeconds: 60, clientVersion: 'v1.0.0');
        $unorderable = $this->findings()[0]['severity'];

        $this->recordCall(ageSeconds: 60, clientVersion: '0.4.4');
        $orderable = $this->findings()[0]['severity'];

        $this->assertSame(Severity::Ok, $unorderable);
        $this->assertSame(Severity::Warn, $orderable);
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
            $this->findingsOfFor(new BoardToolsClientHalfCheck($this->bundledDir()), $this->agent()),
        );
    }

    /**
     * A stand-in `examples/channel-servers` whose bundled version this class CHOOSES.
     *
     * ⛔ THE REAL CHECKOUT'S SNAPSHOT IS DELIBERATELY NOT USED. Every version assertion here
     * would then be a function of whatever `examples/channel-servers/package.json` happens
     * to say on the day the suite runs — so the STALE case would stop being reachable the
     * moment the repo's own snapshot moved past the fixture, and the EQUAL case would red on
     * every routine bump. The comparison is what is under test; the operands are fixtures.
     */
    private function bundledDir(): string
    {
        if (! isset($this->bundledDir)) {
            $this->bundledDir = (string) tempnam(sys_get_temp_dir(), 'bundled-snap-');
            unlink($this->bundledDir);
            mkdir($this->bundledDir, 0o700);
            $this->bundling(self::BUNDLED_VERSION);
        }

        return $this->bundledDir;
    }

    /**
     * Write the stand-in manifest. `null` writes no manifest at all; `''` writes one that
     * PARSES and declares no `version` — the fourth state `ChannelSnapshotProbe`'s reader
     * keeps apart from the other three, and a different operator situation from an absent
     * file (nothing to restore; the file is there and wrong).
     */
    private function bundling(?string $version): void
    {
        $path = $this->bundledDir().'/package.json';
        if ($version === null) {
            @unlink($path);

            return;
        }
        $manifest = $version === '' ? ['name' => 'stand-in'] : ['name' => 'stand-in', 'version' => $version];
        file_put_contents($path, (string) json_encode($manifest));
    }

    /**
     * Stamp a row `$ageSeconds` in the past.
     *
     * Written through the model rather than through {@see ClientHalfLedger}
     * on purpose: the ledger stamps `now()`, and this class is about how the CHECK reads an
     * age. The ledger's own write is asserted where it happens, in
     * `tests/Feature/AgentTools/BoardToolDispatcherTest.php`.
     */
    private function recordCall(string $agent = 'prod-agent', string $transport = 'ssh', int $ageSeconds = 0, ?CallProvenance $provenance = null, ?string $clientVersion = null): void
    {
        BoardToolsClientCall::query()->updateOrCreate(
            ['agent' => $agent],
            ['transport' => $transport, 'call_provenance' => $provenance, 'client_version' => $clientVersion, 'last_success_at' => now()->subSeconds($ageSeconds)],
        );
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
