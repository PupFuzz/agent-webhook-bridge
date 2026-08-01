<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallConfigDirCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The config-dir leg (DL-242 stage 6): the two states in which the directory is unusable,
 * and the permissions warn that is only defined once it resolved.
 *
 * THE UNSET MESSAGE IS NOT RENDERED BY THE GOLDEN CORPUS — no fixture leaves
 * `bridge.config_dir` unset (every one points it somewhere, including the fixture that
 * points it at a missing path). That is a FIXTURE-SCOPE measurement and licenses nothing
 * about the rest of the suite: this arm was not among the ones measured by mutation, so no
 * whole-suite claim is made for it here. What is certain is that this file asserts the
 * message directly.
 *
 * THE PERMS WARN IS NOT IN THAT POSITION, and is covered here for the opposite reason:
 * every fixture that resolves a config dir renders it, and `BridgeCommandsTest` drives it
 * through the command. What none of them can show is the warn's ABSENCE, because the
 * golden install root is 0755 and no fixture builds an owner-only dir — so the 0700 case
 * below is the discriminating control without which the 0755 case would pass just as well
 * against a check that warned unconditionally.
 */
class InstallConfigDirCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/install-config-dir-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        chmod($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_an_unset_config_dir_is_reported_as_not_set(): void
    {
        config(['bridge.config_dir' => null]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame('bridge.config_dir (BRIDGE_CONFIG_DIR) is not set', $findings[0]->message);
    }

    /**
     * The empty string is the case the CONTEXT FIELD cannot express — `CheckContext`
     * narrows with `is_string()`, under which `''` is a perfectly good path — and this
     * check's first branch exists to catch it. Driving it with a usable directory ON the
     * context is what makes the test discriminating: a check "simplified" to read
     * `$ctx->configDir` would report that directory as ok and this would go red. Without
     * the populated context the same assertion would pass against the simplification,
     * because a bare context carries null and null takes the same branch.
     */
    public function test_the_empty_string_reports_as_not_set_even_when_the_context_carries_a_usable_dir(): void
    {
        config(['bridge.config_dir' => '']);
        $ctx = new CheckContext;
        $ctx->configDir = $this->dir;

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame('bridge.config_dir (BRIDGE_CONFIG_DIR) is not set', $findings[0]->message);
    }

    public function test_a_configured_dir_that_does_not_exist_is_named_in_the_failure(): void
    {
        $missing = $this->dir.'/does-not-exist';
        config(['bridge.config_dir' => $missing]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        // Both causes, because `is_dir()` cannot tell them apart (card#5698). The severity
        // stays `fail` on purpose — see the check's own comment: the agent loop is gated on
        // this same stat, so either cause means this run loaded no agent config.
        $this->assertSame(
            "config dir is not usable: {$missing} — it does not exist, or a directory above it denies this process traversal (is_dir() cannot distinguish them). Either way NO agent config was loaded this run, so every per-agent check below is MISSING, not clean; create the directory, or re-run bridge:check as a user that can traverse to it",
            $findings[0]->message,
        );
    }

    public function test_an_owner_only_dir_reports_ok_and_says_nothing_about_permissions(): void
    {
        config(['bridge.config_dir' => $this->dir]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("config dir: {$this->dir}", $findings[0]->message);
    }

    public function test_a_group_accessible_dir_adds_the_permissions_warn_after_the_ok_line(): void
    {
        chmod($this->dir, 0o755);
        config(['bridge.config_dir' => $this->dir]);

        $findings = $this->findings();

        // Order is the assertion, not an incidental: the ok line establishes which
        // directory the warn is about, and the migration's contract is byte-identical
        // output — a warn hoisted above its ok line is a diff.
        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("config dir: {$this->dir}", $findings[0]->message);
        $this->assertSame(Severity::Warn, $findings[1]->severity);
        $this->assertSame(
            "config dir {$this->dir} is group/world-accessible (mode 0755) — chmod 700 (it holds secrets)",
            $findings[1]->message,
        );
    }

    /** @return list<Finding> */
    private function findings(?CheckContext $ctx = null): array
    {
        return iterator_to_array((new InstallConfigDirCheck)->run($ctx ?? new CheckContext), false);
    }
}
