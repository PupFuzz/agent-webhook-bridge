<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallSuffixDsnCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * That the check RENDERS the guard's two outcomes, which nothing else asserts
 * (DL-242 stage 4).
 *
 * `InstallGuardTest` owns whether `InstallGuard::dsnCrosstalk()` decides correctly, and
 * already drives `bridge:check` to a non-zero exit on a crosstalking install — so the
 * decision and the exit contract are both covered and are deliberately not repeated here.
 * What no test reaches is the mismatch MESSAGE: every golden fixture prints the
 * `install-suffix DSN check: ok` line, so the golden corpus never renders the real
 * diagnosis. Mutating the mismatch branch reds this file and nothing else in the suite —
 * measured by mutation, not inferred from a grep (CLAUDE_TESTING.md). A typo in the message
 * would otherwise land silently.
 *
 * THE OK-BRANCH TEST TAKES THE OPTED-IN ROUTE, not the fixtures'. Every golden install
 * leaves `BRIDGE_INSTALL_SUFFIX` unset, so it exits at the guard's not-opted-in early
 * return and the compare never runs; a check wired to a guard that always returned null
 * would satisfy that measurement exactly as the real one does.
 */
class InstallSuffixDsnCheckTest extends TestCase
{
    public function test_a_crosstalking_install_reports_the_guards_own_diagnosis(): void
    {
        config([
            'bridge.install_suffix' => '-prod',
            'database.default' => 'checktest',
            'database.connections.checktest.database' => 'agent_webhook_bridge_dev',
        ]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("BRIDGE_INSTALL_SUFFIX is '-prod'", $findings[0]->message);
        $this->assertStringContainsString("the database name 'agent_webhook_bridge_dev'", $findings[0]->message);
        $this->assertStringContainsString("does not contain '_prod'", $findings[0]->message);
    }

    public function test_a_matching_install_reports_ok(): void
    {
        config([
            'bridge.install_suffix' => '-prod',
            'database.default' => 'checktest',
            'database.connections.checktest.database' => 'agent_webhook_bridge_prod',
        ]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('install-suffix DSN check: ok', $findings[0]->message);
    }

    /** @return list<Finding> */
    private function findingsFrom(): array
    {
        return iterator_to_array((new InstallSuffixDsnCheck)->run(new CheckContext), false);
    }
}
