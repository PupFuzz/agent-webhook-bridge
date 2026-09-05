<?php

namespace Tests\Unit\Console;

use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Console\Commands\Bridge\CheckCommand;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins WHICH checks `bridge:check` registers — the DL-242 plan's disproved-claims item 7,
 * closed in stage 8.
 *
 * THE CLAIM THIS CLOSES, quoted because it was believed for seven stages: *"`CheckRunnerTest`
 * pins the registered set."* It does not — it pins the runner's PROPERTIES using synthetic
 * checks. Nothing asserted what `CheckCommand` registers, so a check deleted from the
 * registration list was caught only if its absence changed golden output. At the stage-8
 * measurement, 26 of the 37 checks then registered yielded nothing on at least one install
 * shape and most were silent on the baseline, so for most of them the answer was: it would
 * not have been caught. (The counts are that measurement, not a running total — the
 * registered set has grown since, and re-stating a number nobody re-measured is how a
 * historical fact turns into a false present-tense claim.)
 *
 * IT READS THE COMMAND'S OWN REGISTRATION rather than rebuilding the list, via reflection
 * on the private builder. A test that constructed its own registry would be asserting a
 * copy against a copy — agreeing with itself while the command drifted. Reaching into a
 * private is the honest cost of pinning an internal, and the alternative (making the
 * builder public purely for this test) would put a test-shaped seam on the command's
 * public surface.
 *
 * WHEN THIS TEST REDS, IT IS USUALLY RIGHT. Adding or removing a check is a deliberate act
 * — update the literal below IN THE SAME COMMIT as the registration change, so the diff
 * shows both halves. A red here on a change you did not intend to make to the registered
 * set means you changed it.
 */
class CheckCommandRegistrationTest extends TestCase
{
    /**
     * Every check `bridge:check` registers, in registration order.
     *
     * ORDER IS PART OF THE PIN, not incidental: slot ordinal fixes operator-visible output
     * order, and stages 0-7 held a byte-identical output contract on exactly that.
     *
     * @return list<string>
     */
    private const REGISTERED = [
        // pre-loop install plane (stage 6)
        'install.config_dir',
        'install.secret_dir',
        'database.connectivity',
        'database.install_suffix',
        'install.inbox_config',
        'retention.posture',
        'jobs.posture',
        'standup.posture',
        'install.endpoint_urls',
        'install.provider_adapters',
        // per-agent planes (stages 1, 5a, 5b)
        'agent.classifier_resolvable',
        'agent.ci_failure_filter',
        'agent.wake_membership',
        'agent.webhook_secrets',
        'agent.api_tokens',
        'channel.token_path',
        'channel.transport',
        'channel.server_snapshot',
        // post-loop roster plane (stage 5c)
        'agent.identity_collisions',
        'agent.treat_as_signal',
        'agent.default_agent',
        'agent.shared_identities',
        // writeback config plane (stage 3a)
        'writeback.config',
        'writeback.identity',
        'writeback.alert_channel',
        'writeback.token',
        'reconcile.repo_tokens',
        'writeback.mapping_config',
        // writeback probe plane (stage 3b)
        'writeback.by_ref',
        'writeback.board_state',
        'writeback.source_coverage',
        // event-follows-consumer (stage 7a)
        'event.follows_consumer',
        // board-tools plane (stage 7b)
        'board_tools.suppressed',
        'board_tools.bearer',
        'board_tools.board_state',
        // card#7756 / DL-313 — the one leg on this plane whose subject is the CALLING SEAT
        // rather than the bridge. Registered after the board-STATE plane and before the ssh
        // one, which is where its output lands.
        'board_tools.client_half',
        'board_tools.ssh_pinned_line',
        'board_tools.ssh_flipped_default',
        // the two opt-in probes (stage 1)
        'board_tools.http_live_probe',
        'board_tools.ssh_live_probe',
    ];

    /**
     * The command's OWN registration, with both opt-in flags absent.
     *
     * The flag values do not change WHICH checks register — that is plan constraint (a),
     * and this test would red if an `if` ever appeared around a `register()` call.
     */
    private function registry(): CheckRunner
    {
        $command = $this->app->make(CheckCommand::class);
        $command->setLaravel($this->app);
        $method = new ReflectionMethod($command, 'registry');

        return $method->invoke($command, $this->app->make(SshProbeEnvironment::class), null, null);
    }

    public function test_it_registers_exactly_the_pinned_check_set_in_order(): void
    {
        $this->assertSame(self::REGISTERED, $this->registry()->registeredIds());
    }

    public function test_the_pinned_set_has_no_duplicates(): void
    {
        // The literal above is hand-maintained by copy-paste, which is how a duplicated
        // line gets in. It cannot HIDE anything — `assertSame` compares element-wise
        // including count and order, and `CheckRunner::claimId()` throws on a duplicate id
        // so `registeredIds()` can never contain one — so what this buys is a named cause:
        // without it a duplicated line reds the assertion above with a whole-array diff
        // that reads as "the registered set changed", pointing at the command rather than
        // at the list.
        $this->assertSame(
            self::REGISTERED,
            array_values(array_unique(self::REGISTERED)),
            'the pinned list itself contains a duplicate id',
        );
    }

    public function test_every_registered_check_starts_unaccounted_until_it_runs(): void
    {
        // The accounting's baseline: a registry nothing has run yet accounts for every
        // check as NotRun. This is what makes a forgotten slot invocation visible instead
        // of absent, and it is asserted against the REAL registered set rather than
        // synthetic doubles.
        $inventory = $this->registry()->inventory();

        $this->assertSame(count(self::REGISTERED), $inventory->registered());
        $this->assertSame(count(self::REGISTERED), $inventory->count(CheckDisposition::NotRun));
        $this->assertSame(0, $inventory->ran());
    }
}
