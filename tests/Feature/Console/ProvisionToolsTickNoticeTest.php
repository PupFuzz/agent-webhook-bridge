<?php

namespace Tests\Feature\Console;

use App\Bridge\Scheduling\TickAdoptionNotice;
use App\Bridge\Scheduling\TickRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `bridge:provision-tools` offers this INSTALL its `bridge:tick` crontab line (card#9058 /
 * DL-361) — and stops offering it once the install has answered.
 *
 * WHAT IT CLOSES. The line was reachable only by READING. `bridge:check`'s `jobs.posture` leg is
 * deliberately silent on an install that adopted nothing (an install that never wanted a tick is
 * not failing by not ticking), and the freshness alarm arms off a declaration — so an operator
 * who never opened `docs/periodic-jobs.md` was never told the ingress existed, and never would
 * be. This is the surface that fires once, at enablement.
 *
 * ⭐ THE SCOPE IS HELD BY THE STRUCTURE, NOT ONLY BY THE TEXT. `bridge:tick` is ONE line per
 * bridge INSTALL — `scheduled_jobs` has no agent column, and neither the tick record's cache key
 * nor the scheduler's lock and interval markers carry an agent segment — so the offer is printed
 * OUTSIDE the per-agent loop. A roster-wide run over N agents prints it ONCE. Telling N
 * onboarding agents to each add their own line would be a worse defect than the silence.
 */
class ProvisionToolsTickNoticeTest extends TestCase
{
    /** The heading the offer opens on — the token every assertion here keys on. */
    private const HEADING = 'PERIODIC TICK — ';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tick-notice-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir]);

        // The un-adopted install: nothing declared, nothing ever recorded.
        Cache::flush();
        config(['bridge.jobs.tick_expected_every' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ─── the offer appears where it should ────────────────────────────────────

    public function test_an_install_with_no_tick_is_handed_a_pasteable_crontab_line(): void
    {
        $this->writeHttpAgent('impl');

        $out = $this->runFor('impl');

        $this->assertStringContainsString(self::HEADING, $out);

        // ⚑ THE SUBJECT HERE IS THE WIRING, NOT THE STRING. The exact line is the unit test's
        // subject, driven off fixed inputs; what only this test can answer is whether the command
        // hands the renderer THIS install's own base path and interpreter and prints back every
        // line it returns. Baking PHP_BINARY and base_path() into an expected string instead
        // reds on a runner whose php path or checkout carries a space — SafePathShape sends the
        // renderer down its fallback arm while the expectation is built from the raw values, so
        // the test would be reporting an environment rather than a defect.
        $rendered = (new TickAdoptionNotice(
            posture: TickRecord::posture(),
            basePath: base_path(),
            phpBinary: PHP_BINARY,
            declarationProblem: TickRecord::declarationProblem(),
        ))->lines();

        $this->assertNotSame([], $rendered);
        foreach ($rendered as $line) {
            $this->assertStringContainsString($line, $out);
        }

        // And this install's own values reached it: the base path is in the offer on BOTH arms —
        // in the crontab line when it renders, and in the refusal naming it when it cannot.
        $this->assertStringContainsString(base_path(), $out);
        $this->assertStringContainsString('BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS(), $out);
    }

    public function test_the_offer_is_transport_agnostic(): void
    {
        // The ssh SETUP PACKET is where an ssh agent's enablement lives, but the tick is not an
        // agent's property — an http-transport install needs it just as much and has no packet
        // to carry it. Offering it only alongside the packet would have made the fix's reach a
        // function of the door the agent happens to use.
        $this->writeSshAgent('impl');

        $this->assertStringContainsString(self::HEADING, $this->runFor('impl'));
    }

    public function test_the_offer_prints_once_per_run_and_not_once_per_agent(): void
    {
        // ⭐ THE STRUCTURAL HALF OF THE PER-INSTALL SCOPE. A roster-wide run touches three
        // agents; the tick belongs to the install, so the operator is offered ONE line.
        // ⛔ CONTROL: move printTickNotice() inside the per-agent foreach and this reds with 3.
        $this->writeHttpAgent('impl');
        $this->writeHttpAgent('pm');
        $this->writeSshAgent('ops');

        Artisan::call('bridge:provision-tools');

        $this->assertSame(1, substr_count(Artisan::output(), self::HEADING));
    }

    // ─── the offer STOPS, which is the property that makes it safe ────────────

    public function test_an_install_that_declared_a_horizon_is_offered_nothing(): void
    {
        // ⭐ THE DIRECTION THAT MATTERS, at the command. Once the install has adopted a tick the
        // whole subject belongs to `bridge:check`'s jobs.posture leg — which reports the state,
        // the staleness and whether anything reads the alarm. A step that came back for every
        // agent onboarded afterwards is exactly the nag this design refuses.
        $this->writeHttpAgent('impl');
        config(['bridge.jobs.tick_expected_every' => TickAdoptionNotice::horizonS()]);

        $out = $this->runFor('impl');

        $this->assertStringNotContainsString(self::HEADING, $out);
        $this->assertStringNotContainsString(TickAdoptionNotice::schedule(), $out);
        // The command still did its own job — the absence above is the notice, not a dead run.
        $this->assertStringContainsString('[impl]', $out);
    }

    public function test_an_install_already_ticking_is_asked_for_the_horizon_and_offered_no_second_line(): void
    {
        $this->writeHttpAgent('impl');
        TickRecord::stamp();

        $out = $this->runFor('impl');

        $this->assertStringContainsString('already running on this bridge INSTALL', $out);
        $this->assertStringContainsString('do NOT add a crontab line', $out);
        $this->assertStringNotContainsString(TickAdoptionNotice::schedule(), $out);
    }

    public function test_an_install_whose_declaration_cannot_be_read_is_told_that_by_the_command(): void
    {
        // ⛔ THE WIRING OF THE THIRD INPUT. The renderer is pure, so the reason a declaration
        // cannot be read has to be HANDED to it — and a command that forgot to pass it would
        // print the ordinary ask, telling an operator to add a key their `.env` already assigns.
        // Only a test at the command can see that argument arrive.
        $this->writeHttpAgent('impl');
        config(['bridge.jobs.tick_expected_every' => 'ten']);

        $out = $this->runFor('impl');

        $this->assertStringContainsString('ALREADY SETS THE KEY AND THE VALUE CANNOT BE READ', $out);
        $this->assertStringContainsString((string) TickRecord::declarationProblem(), $out);
        $this->assertStringNotContainsString('BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS(), $out);
    }

    // ─── it never moves the verdict ───────────────────────────────────────────

    public function test_the_offer_does_not_move_the_exit_code(): void
    {
        // A periodic ingress this install has not adopted is not a provisioning fault, and
        // `bridge:provision-tools` gates runbooks. The un-adopted install exits exactly as the
        // adopted one does.
        $this->writeHttpAgent('impl');

        $unadopted = Artisan::call('bridge:provision-tools', ['--agent' => 'impl']);
        $this->assertStringContainsString(self::HEADING, Artisan::output());

        config(['bridge.jobs.tick_expected_every' => TickAdoptionNotice::horizonS()]);
        $adopted = Artisan::call('bridge:provision-tools', ['--agent' => 'impl']);
        $this->assertStringNotContainsString(self::HEADING, Artisan::output());

        // ⛔ PINNED TO SUCCESS, not merely to each other: `$unadopted === $adopted` also holds
        // when BOTH runs fail, which would report "the offer moved nothing" about a command that
        // was failing on both sides of the comparison.
        $this->assertSame(Command::SUCCESS, $unadopted);
        $this->assertSame(Command::SUCCESS, $adopted);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function runFor(string $agent): string
    {
        Artisan::call('bridge:provision-tools', ['--agent' => $agent]);

        return Artisan::output();
    }

    private function writeHttpAgent(string $agent): void
    {
        File::put($this->dir."/{$agent}.yml", "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/{$agent}-tok\n"
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
    }

    private function writeSshAgent(string $agent): void
    {
        File::put($this->dir."/{$agent}.yml", "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: ssh\n  ssh_account: bridge-user\n"
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
    }
}
