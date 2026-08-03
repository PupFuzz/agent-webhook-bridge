<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallSecretDirCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The secret-dir leg (DL-242 stage 6), and in particular the DL-014 split-layout guard on
 * its permissions warn.
 *
 * THE GUARD IS WHAT THIS FILE OWNS. The warn's own text is rendered by one golden fixture
 * — but incidentally: `GoldenInstall` gives every fixture a `secret_dir` equal to its
 * `config_dir`, and `config-dir-missing` renders the warn only because it repoints
 * `config_dir` at a path that does not exist while leaving `secret_dir` at the install
 * root. Nothing in the corpus exercises a deliberate split layout, and nothing there can
 * distinguish a check that consults the guard from one that ignores it — the fixture that
 * renders the warn and the fixtures that do not differ in `config_dir`, which is exactly
 * the variable under test, so the corpus reads as agreeing with either behaviour.
 *
 * THE THREE PERMISSION CASES BELOW ARE ONE ASSERTION IN THREE PARTS. Same-path-insecure
 * and split-secure are not extra coverage of the split-insecure case; they are the two
 * controls that make it mean something, one per operand of the `&&`.
 *
 * IT ALSO OWNS BOTH FAILED-STAT ARMS (card#5774), for the same reason it owns the guard:
 * this is the only caller that can REACH them. Its sibling gates on `is_dir()` before
 * calling, so a `DirectoryPermissions` unit test would be asserting a state no golden
 * fixture and no other check can produce — the arms are a property of the primitive, but
 * their reachability is a property of THIS check.
 */
class InstallSecretDirCheckTest extends TestCase
{
    use MaterializesChecks;

    private string $configDir;

    private string $secretDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/install-secret-dir-check-'.uniqid();
        $this->configDir = $base.'/config';
        $this->secretDir = $base.'/secrets';
        File::ensureDirectoryExists($this->configDir);
        File::ensureDirectoryExists($this->secretDir);
        chmod($this->configDir, 0o700);
        chmod($this->secretDir, 0o700);

        config(['bridge.config_dir' => $this->configDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->configDir));
        parent::tearDown();
    }

    public function test_an_unset_secret_dir_is_reported(): void
    {
        config(['bridge.secret_dir' => null]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame('bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute', $findings[0]->message);
    }

    /**
     * A relative path is the second operand of the same guard, and it is the one a
     * `is_string()`-only check would accept: the bridge resolves secret paths from the
     * process working directory it happens to be started in, so a relative value would
     * read secrets from a different place per invocation.
     */
    public function test_a_relative_secret_dir_is_refused_with_the_same_message(): void
    {
        config(['bridge.secret_dir' => 'relative/secrets']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame('bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute', $findings[0]->message);
    }

    public function test_a_split_layout_with_a_group_accessible_secret_dir_warns_about_that_dir(): void
    {
        chmod($this->secretDir, 0o755);
        config(['bridge.secret_dir' => $this->secretDir]);

        $findings = $this->findings();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("secret dir: {$this->secretDir}", $findings[0]->message);
        $this->assertSame(Severity::Warn, $findings[1]->severity);
        $this->assertSame(
            "secret dir {$this->secretDir} is group/world-accessible (mode 0755) — chmod 700 (it holds secrets)",
            $findings[1]->message,
        );
    }

    /**
     * The first control: the same insecure mode, reported by nothing, because the
     * config-dir check has already reported that very directory. This is the case the
     * golden corpus cannot distinguish — remove the guard and every single-dir install
     * gains a duplicate line here.
     */
    public function test_a_single_dir_layout_stays_silent_about_permissions_even_when_insecure(): void
    {
        chmod($this->configDir, 0o755);
        config(['bridge.secret_dir' => $this->configDir]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("secret dir: {$this->configDir}", $findings[0]->message);
    }

    /** The second control: a split layout is not itself the fault — an owner-only secret dir is silent. */
    public function test_a_split_layout_with_an_owner_only_secret_dir_says_nothing_about_permissions(): void
    {
        config(['bridge.secret_dir' => $this->secretDir]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("secret dir: {$this->secretDir}", $findings[0]->message);
    }

    /**
     * The card#5774 arm, and the reason this check is where it is asserted: it is the ONLY
     * caller that can reach a failed stat, because it never existence-checks its dir. Before
     * the split, this exact config printed the `ok` above and nothing else — a directory the
     * run could not measure, rendered identically to one measured and found owner-only.
     */
    public function test_a_split_layout_secret_dir_that_does_not_exist_is_reported_not_certified(): void
    {
        $absent = dirname($this->secretDir).'/gone';
        config(['bridge.secret_dir' => $absent]);

        $findings = $this->findings();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(Severity::Warn, $findings[1]->severity);
        $this->assertSame(
            "secret dir {$absent} does not exist, so its mode could not be checked and nothing can be read from it — create it (chmod 700), or correct the setting that points here",
            $findings[1]->message,
        );
    }

    /**
     * The other half of the same split, and the control that makes the case above mean
     * something: an identically-failed `fileperms()` whose CAUSE is this process's own
     * blindness must not be reported as the measured fact "does not exist" — the operator
     * would go create a directory that is already there.
     */
    public function test_a_secret_dir_this_process_cannot_stat_is_unvalidated_not_declared_absent(): void
    {
        $this->skipAsRoot();
        $nested = $this->secretDir.'/inner';
        File::ensureDirectoryExists($nested);
        chmod($this->secretDir, 0o600);
        config(['bridge.secret_dir' => $nested]);

        try {
            $findings = $this->findings();
        } finally {
            chmod($this->secretDir, 0o700);
        }

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[1]->message);
        $this->assertStringNotContainsString('does not exist', $findings[1]->message);
    }

    /**
     * The card#5796 arm, and the third state the existence gate alone cannot express: a path
     * that stats fine and is not a directory. It is asserted here for the same reason as the
     * two arms above — this is the only caller that reaches the primitive without an
     * `is_dir()` of its own.
     *
     * THE MODE ASSERTION IS THE POINT. Before the fix this rendered the file's real mode
     * under the secret dir's label and told the operator to `chmod 700` it, so the negative
     * assertion below is the regression the positive one cannot catch: a message naming the
     * right fault would still be wrong if it kept reporting a mode for a subject that has
     * none worth reporting.
     */
    public function test_a_split_layout_secret_dir_that_is_not_a_directory_fails_instead_of_reporting_its_mode(): void
    {
        $file = dirname($this->secretDir).'/not-a-dir';
        file_put_contents($file, "x\n");
        chmod($file, 0o644);
        config(['bridge.secret_dir' => $file]);

        $findings = $this->findings();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(Severity::Fail, $findings[1]->severity);
        $this->assertSame(
            "secret dir {$file} exists but is not a directory, so no secret or token can be read from it — correct the setting that points here, or replace that path with a directory (chmod 700)",
            $findings[1]->message,
        );
        $this->assertStringNotContainsString('group/world-accessible', $findings[1]->message);
    }

    /**
     * An unusable `config_dir` must not suppress this dir's own verdict. The comparison is
     * a strict `!==` against the RAW config value, so a non-string one is unequal to any
     * path and the warn still fires — which is the behaviour the operator needs, since the
     * check that would otherwise have reported this mode never got as far as reporting
     * anything.
     */
    public function test_an_unusable_config_dir_does_not_suppress_the_secret_dir_permissions_warn(): void
    {
        chmod($this->secretDir, 0o755);
        config(['bridge.config_dir' => null, 'bridge.secret_dir' => $this->secretDir]);

        $findings = $this->findings();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Warn, $findings[1]->severity);
        $this->assertStringContainsString('group/world-accessible', $findings[1]->message);
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->findingsOf((new InstallSecretDirCheck), new CheckContext);
    }
}
