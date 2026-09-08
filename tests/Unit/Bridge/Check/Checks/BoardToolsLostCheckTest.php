<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsLostCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\CallProvenance;
use App\Models\BoardToolsClientCall;
use App\Models\BoardToolsConfigSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsDocPointers;
use Tests\Support\MaterializesChecks;
use Tests\Support\UsesUnmigratedDatabase;
use Tests\TestCase;

/**
 * The LOST-block leg (card#8973 / DL-360).
 *
 * ⭐ WHAT THIS CLASS HAS TO PROVE, and why a green run without it would prove nothing: the
 * leg's whole value is that it DISCRIMINATES over inputs that all look like "this agent has
 * no enabled board_tools block". Five install shapes land on that description —
 * never-provisioned, block deleted, YAML deleted, explicitly retired, and a config that
 * failed to parse — and today's `bridge:check` renders all five identically as silence. A
 * test asserting only the FAIL would pass against a leg that failed on all five, and one
 * asserting only a silence would pass against the leg this card exists to replace. So the
 * shapes are driven through one code path and compared.
 *
 * ⛔ THE OPERATOR RULING IS PINNED AS AN ABSENCE WITH A NON-VACUOUS CONTROL. A
 * `board_tools_client_calls` row ALONE must never produce a FAIL — that row is stamped by
 * `--probe-tools`, `--self-cert` and a hand-run `bridge:tools-call`, so any agent ever probed
 * and later removed carries one forever and would flip the exit code on an install nobody
 * touched. The absence is asserted beside a case where the SAME agent name DOES produce a
 * FAIL off a config-seen row, so "nothing happened" cannot be satisfied by a leg that is
 * simply broken.
 */
class BoardToolsLostCheckTest extends TestCase
{
    use AssertsDocPointers;
    use MaterializesChecks;
    use RefreshDatabase;
    use UsesUnmigratedDatabase;

    // ─── the LOST verdict ─────────────────────────────────────────────────────

    public function test_a_recorded_seat_whose_block_is_gone_fails_and_names_itself_to_the_context(): void
    {
        $this->recordSeen('impl');
        $ctx = $this->ctx([$this->agent('impl', null)], ['impl']);

        $findings = $this->findingsOf(new BoardToolsLostCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board_tools: agent impl: block LOST', $findings[0]->message);
        // BOTH remedies, because only the operator knows which happened.
        $this->assertStringContainsString('Re-add the block from the deploy\'s source of truth', $findings[0]->message);
        $this->assertStringContainsString('retire the seat explicitly', $findings[0]->message);
        $this->assertStringContainsString('docs/board-tools.md § Retiring a seat', $findings[0]->message);
        // The YAML is still there, so the recreate-run-delete cure does NOT apply.
        $this->assertStringNotContainsString('recreate impl.yml', $findings[0]->message);
        // The seat is named in PROSE; this field is the only structured carrier, and the
        // NEXT STEPS suppression is downstream of it.
        $this->assertSame(['impl'], $ctx->boardToolsLost);
    }

    public function test_a_recorded_seat_with_no_yaml_at_all_gains_the_recreate_run_delete_cure(): void
    {
        $this->recordSeen('impl');
        // No config, and the name is NOT in agentNames: the file is gone from the config dir.
        $ctx = $this->ctx([], []);

        $findings = $this->findingsOf(new BoardToolsLostCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('block LOST', $findings[0]->message);
        // The operator cannot write a `retired:` key into a file that does not exist, so the
        // line has to say how to get one written.
        $this->assertStringContainsString('recreate impl.yml holding only that block, run bridge:check once (it prints RETIRED), then delete it', $findings[0]->message);
    }

    /**
     * ⚑ THE CLIENT-CALL CLAUSE IS EVIDENCE, PRINTED ON A LINE THE ROW DID NOT TRIGGER. It
     * names the TRANSPORT, never the provenance: the sentence is about which door served the
     * call, and printing an internal measurement there would put a word the operator cannot
     * act on where a door name belongs.
     */
    public function test_the_lost_line_quotes_a_client_call_as_evidence_when_one_exists(): void
    {
        $this->recordSeen('impl');
        $this->recordCall('impl');

        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], []));

        $this->assertStringContainsString('last successful tools call ', $findings[0]->message);
        $this->assertStringContainsString(' over ssh', $findings[0]->message);
        $this->assertStringNotContainsString('sshd', $findings[0]->message);
        $this->assertStringNotContainsString('call_provenance', $findings[0]->message);
    }

    // ─── the four shapes that must NOT fail ───────────────────────────────────

    /**
     * ⭐ THE DISCRIMINATION TEST. Every one of these renders as "no enabled board_tools block"
     * to every other leg in this plane, and each assertion alone would pass against a leg
     * stuck on its own verdict — only the set shows the leg tells them apart.
     */
    public function test_a_present_block_in_any_form_is_not_lost(): void
    {
        $this->recordSeen('impl');

        $verdicts = [];
        foreach ([
            'enabled' => ['transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55],
            'disabled' => ['enabled' => false],
            'suppressed' => ['board_id' => 10],   // default-on, unsatisfiable
        ] as $shape => $block) {
            $verdicts[$shape] = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([$this->agent('impl', $block)], ['impl']));
        }
        // The control: the SAME recorded seat, with the block gone, DOES fail — so the three
        // empty results above are the leg answering, not the leg being broken.
        $verdicts['gone'] = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([$this->agent('impl', null)], ['impl']));

        $this->assertSame([], $verdicts['enabled']);
        $this->assertSame([], $verdicts['disabled']);
        $this->assertSame([], $verdicts['suppressed']);
        $this->assertCount(1, $verdicts['gone']);
        $this->assertSame(Severity::Fail, $verdicts['gone'][0]->severity);
    }

    /**
     * A YAML that is on disk and did not parse is already a `fail` on its own leg, and this
     * run knows NOTHING about what its board_tools block says. Claiming the block is gone
     * would be a guess about a file this run could not read.
     */
    public function test_a_yaml_that_failed_to_parse_is_not_reported_as_lost(): void
    {
        $this->recordSeen('impl');
        // On disk (agentNames) but absent from the parsed configs — exactly what
        // CheckCommand's loop leaves behind when AgentConfig::load() throws.
        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], ['impl']));

        $this->assertSame([], $findings);
    }

    /**
     * ⛔ THE OPERATOR RULING (DL-360), PINNED. Seen to fail by widening the population to
     * client-calls names: that mutation makes this test red while every other test in this
     * class stays green, which is what makes it the guard for the ruling rather than a
     * restatement of it.
     */
    public function test_a_client_calls_row_alone_never_produces_a_lost_finding(): void
    {
        $this->recordCall('ghost');
        $this->assertSame(0, BoardToolsConfigSeen::query()->count(), 'the fixture wrote a config-seen row, so this says nothing about the client-calls row alone');

        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], []));

        $this->assertSame([], $findings);

        // NON-VACUOUS CONTROL: the same agent name, with a config-seen row, DOES fail. An
        // empty result above is therefore the ruling being honoured, not the leg being dead.
        $this->recordSeen('ghost');
        $control = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], []));
        $this->assertCount(1, $control);
        $this->assertSame(Severity::Fail, $control[0]->severity);
    }

    public function test_an_install_with_no_history_says_only_that_nothing_is_lost(): void
    {
        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([$this->agent('impl', null)], ['impl']));

        $this->assertSame([], $findings);
        $this->assertSame(
            ['no agent this install has recorded with an enabled board_tools block is now without one, and no config carries a retired key — the scan covers every recorded seat, including a fleet with no board_tools at all'],
            $this->silencesOf($this->ctx([$this->agent('impl', null)], ['impl'])),
        );
    }

    // ─── retirement ───────────────────────────────────────────────────────────

    public function test_a_tombstoned_seat_whose_block_is_gone_is_silenced_with_its_own_declaration(): void
    {
        $this->recordSeen('impl');
        BoardToolsConfigSeen::query()->where('agent', 'impl')->update(['retired_seen_at' => now(), 'retired_reason' => '2026-09-08 — decommissioned']);

        $ctx = $this->ctx([], []);
        $this->assertSame([], $this->findingsOf(new BoardToolsLostCheck, $ctx));
        $this->assertSame([], $ctx->boardToolsLost);
        $this->assertContains(
            'every recorded seat whose block is gone carries an explicit retirement tombstone',
            $this->silencesOf($this->ctx([], [])),
            'the tombstone path inherited the fall-through declaration instead of stating its own reason',
        );
    }

    public function test_a_retired_config_with_its_row_reports_ok(): void
    {
        $this->recordSeen('impl');
        BoardToolsConfigSeen::query()->where('agent', 'impl')->update(['retired_seen_at' => now(), 'retired_reason' => '2026-09-08 — decommissioned']);

        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([$this->agent('impl', ['retired' => '2026-09-08 — decommissioned'])], ['impl']));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('board_tools: agent impl: RETIRED — 2026-09-08 — decommissioned (tombstone on record)', $findings[0]->message);
        $this->assertStringContainsString('remove the retired key and re-add the block to bring it back', $findings[0]->message);
    }

    /**
     * ⭐ THE LINE CONFIRMS THE ROW, NEVER THE CONFIG, and this is the arm that makes that
     * true. The write is best-effort, so it CAN have failed — and the cure this leg prints
     * ends "run bridge:check once, then delete the YAML", so a `recorded` line sourced from
     * the config the operator just wrote would send them to delete the only statement of the
     * decision over a tombstone that was never written.
     */
    public function test_a_retired_config_with_no_row_is_unvalidated_and_says_not_to_delete_the_yaml(): void
    {
        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([$this->agent('impl', ['retired' => '2026-09-08 — decommissioned'])], ['impl']));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('retired in config but the tombstone could NOT be recorded', $findings[0]->message);
        $this->assertStringContainsString('do not delete impl.yml until a run prints RETIRED', $findings[0]->message);
    }

    // ─── the directory gate, and its ordering ─────────────────────────────────

    public function test_recorded_seats_and_an_unscanned_config_dir_report_one_unvalidated_and_no_fail(): void
    {
        $this->recordSeen('impl');
        $this->recordSeen('impl2');
        $ctx = $this->ctx([], [], scanned: false);

        $findings = $this->findingsOf(new BoardToolsLostCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('2 recorded seat(s) cannot be checked for a LOST block', $findings[0]->message);
        $this->assertSame([], $ctx->boardToolsLost, 'an unscanned dir minted a LOST name, which would then mute a next step over a question this run never asked');
    }

    /**
     * ⭐ THE ORDERING CONTROL, and it is the reason the two halves are one test. An install
     * with NO board-tools history has no subject here, so an unreadable config dir must
     * produce nothing at all — asking "could I read the dir?" before "is there anything to
     * check?" would turn every unreadable directory on every fleet that never had board tools
     * into a finding about a question nobody asked. The half above proves the gate fires; this
     * half proves it fires only where there is a subject.
     */
    public function test_an_unscanned_config_dir_with_no_recorded_seats_says_nothing(): void
    {
        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], [], scanned: false));

        $this->assertSame([], $findings);
        $this->assertSame(
            ['no agent this install has recorded with an enabled board_tools block is now without one, and no config carries a retired key — the scan covers every recorded seat, including a fleet with no board_tools at all'],
            $this->silencesOf($this->ctx([], [], scanned: false)),
        );
    }

    // ─── the fail-soft envelope ───────────────────────────────────────────────

    /**
     * ⚑ THE FAILURE IS REAL, NOT SYNTHETIC: the query runs against a genuinely unmigrated
     * SQLite connection and comes back with the driver's own `no such table`. An install that
     * pulled the code and has not run `php artisan migrate` reaches this on every run, and
     * `bridge:check` must not ABORT on it (CheckRunner deliberately does not catch).
     */
    public function test_an_unmigrated_ledger_is_unvalidated_and_does_not_abort_the_run(): void
    {
        $findings = $this->withUnmigratedDatabase(fn () => $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], [])));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('could NOT read the config-seen ledger', $findings[0]->message);
        $this->assertStringContainsString('run migrations', $findings[0]->message);
    }

    /**
     * ⭐ A BACKING VALUE THIS BUILD CANNOT INTERPRET MUST NOT ABORT `bridge:check`. The
     * Eloquent enum cast is applied LAZILY, on attribute access, so the `ValueError` lands
     * wherever the attribute is first READ — and this leg reads the client-half rows through
     * a reader that touches the cast INSIDE the envelope, precisely so the throw arrives at a
     * site the envelope covers.
     *
     * ⛔ NOT A GUARD OVER AN UNREACHABLE STATE: nothing is added to defend against the value.
     * What is asserted is that the read happens inside the envelope the leg already has.
     */
    public function test_an_uninterpretable_provenance_value_is_reported_and_does_not_abort_the_run(): void
    {
        $this->recordSeen('impl');
        $this->recordCall('impl');
        // MUST FIT varchar(16) — SQLite ignores the width, MariaDB enforces it, so a longer
        // literal is green here and red on both CI database legs.
        DB::table('board_tools_client_calls')->where('agent', 'impl')->update(['call_provenance' => 'future-case']);

        // Non-vacuous: the row really does hydrate, so the throw really is at the READ.
        $this->assertNotNull(BoardToolsClientCall::query()->where('agent', 'impl')->first());

        $findings = $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], []));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('could NOT read the config-seen ledger', $findings[0]->message);
        $this->assertStringNotContainsString('block LOST', $findings[0]->message);
    }

    /**
     * The FAIL line ends by sending the operator to a runbook section, and that pointer is a
     * CLAIM about another file. Nothing else in this repo joins the two, so a heading renamed
     * in the doc would leave every lost-block failure pointing at a section that is not there.
     */
    public function test_the_doc_pointer_names_a_heading_that_docs_board_tools_actually_has(): void
    {
        $this->assertDocPointerNamesARealHeading(BoardToolsLostCheck::DOC);

        // …and the line actually PRINTS it, so the constant cannot be checked while the
        // message carries a second, unchecked spelling.
        $this->recordSeen('impl');
        $this->assertStringContainsString(BoardToolsLostCheck::DOC, $this->findingsOf(new BoardToolsLostCheck, $this->ctx([], []))[0]->message);
    }

    // ─── fixtures ─────────────────────────────────────────────────────────────

    /**
     * The DECLARED silences one execution yielded, in order.
     *
     * ⚑ NOT A SECOND `CheckRunner::materialize()`. That method's job is to STRIP the sentinel
     * before a renderer can be handed one, and its safety argument is that it is the only
     * strip site; this reads the declarations the runner discards, which is a question the
     * runner cannot answer at all — {@see Silence} is by design invisible downstream of it.
     *
     * @return list<string>
     */
    private function silencesOf(CheckContext $ctx): array
    {
        $out = [];
        foreach ((new BoardToolsLostCheck)->run($ctx) as $yielded) {
            if ($yielded instanceof Silence) {
                $out[] = $yielded->reason;
            }
        }

        return $out;
    }

    /**
     * @param  list<AgentConfig>  $configs
     * @param  list<string>  $agentNames
     */
    private function ctx(array $configs, array $agentNames, bool $scanned = true): CheckContext
    {
        $ctx = new CheckContext;
        $ctx->configs = $configs;
        $ctx->agentNames = $agentNames;
        $ctx->configDirScanned = $scanned;

        return $ctx;
    }

    /** @param array<string, mixed>|null $block */
    private function agent(string $name, ?array $block): AgentConfig
    {
        $raw = ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []];
        if ($block !== null) {
            $raw['board_tools'] = $block;
        }

        return AgentConfig::fromArray($name, $raw);
    }

    private function recordSeen(string $agent): void
    {
        BoardToolsConfigSeen::query()->updateOrCreate(
            ['agent' => $agent],
            ['transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'first_seen_at' => now()->subDays(30), 'last_seen_at' => now()],
        );
    }

    private function recordCall(string $agent): void
    {
        BoardToolsClientCall::query()->updateOrCreate(
            ['agent' => $agent],
            ['transport' => 'ssh', 'call_provenance' => CallProvenance::Sshd, 'last_success_at' => now()->subHour()],
        );
    }
}
