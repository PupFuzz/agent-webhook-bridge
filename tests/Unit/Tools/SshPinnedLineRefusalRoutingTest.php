<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\Severity;
use App\Bridge\Tools\AuthorizedKeysRead;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;
use App\Bridge\Tools\SystemSshProbeEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * THE SEAM NOBODY OWNED, and that is why this file exists rather than another case in one of
 * the two suites beside it (card#9037 r1). `SystemSshProbeEnvironmentTest` measures what the
 * REAL filesystem answers and stops at the `AuthorizedKeysRead` value;
 * `SshTransportProbeTest` measures what the probe does with STATED answers and never touches a
 * disk. Both were green while a change to the reader silently retired an exit-code-bearing
 * FAIL, because the only thing that could have caught it is the composition: a real
 * filesystem state on one end, a `Severity` on the other.
 *
 * ⛔ WHAT IS BEING PINNED. On an AUTHORITATIVE (root-resolved) path, a `authorized_keys` that
 * sshd would take NO KEYS from — a directory, a dangling symlink — must keep producing the
 * `fail` that says the transport is not wired. It is the one finding here that moves the exit
 * code, and the inspected ACCOUNT owns that directory: if a refusal to read those shapes were
 * routed to "could not look", `mkdir ~/.ssh/authorized_keys` would suppress root's own FAIL,
 * which is the principal this reader defends against being handed a new capability.
 *
 * ⚑ The environment below is REAL for the two legs this seam runs through — the read and the
 * file identity — and stated for the host facts a test cannot have (root, sshd's config). That
 * split is the point: a fake read would answer this file's question by assumption.
 */
class SshPinnedLineRefusalRoutingTest extends TestCase
{
    private const PINNED = 'command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAAKEYBLOB me';

    private string $dir;

    private string $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ssh-refusal-routing-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/.ssh', 0o700, true);
        $this->keys = $this->dir.'/.ssh/authorized_keys';
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/.ssh', 0o700);
        foreach ((array) glob($this->dir.'/.ssh/*') as $f) {
            if (is_string($f)) {
                ! is_link($f) && is_dir($f) ? @rmdir($f) : @unlink($f);
            }
        }
        foreach ((array) glob($this->dir.'/*') as $f) {
            if (is_string($f) && ! is_link($f) && ! is_dir($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir.'/.ssh');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return list<Severity> */
    private function severities(): array
    {
        $env = new RootAuthoritativeRealReadEnvironment($this->keys);

        return array_map(
            fn ($f) => $f->severity,
            (new SshTransportProbe($env))->probePinnedLine('me'),
        );
    }

    public function test_the_control_a_pinned_line_at_the_authoritative_path_passes(): void
    {
        // Proves the harness can reach a NON-fail verdict at all — without it, every
        // assertion below is satisfiable by a probe that fails on everything.
        file_put_contents($this->keys, self::PINNED."\n");

        $severities = $this->severities();
        $this->assertContains(Severity::Ok, $severities);
        $this->assertNotContains(Severity::Fail, $severities);
    }

    public function test_the_control_an_absent_file_at_the_authoritative_path_fails(): void
    {
        // The baseline the two refusal cases must match: no file, nothing withheld, the
        // not-wired FAIL is earned. (No file is created.)
        $this->assertContains(Severity::Fail, $this->severities());
    }

    public function test_a_directory_at_the_authoritative_path_still_fails(): void
    {
        // ⛔ THE REGRESSION. `mkdir ~/.ssh/authorized_keys` is an act available to the
        // inspected account, and sshd takes no keys from it. Routed to "could not look" this
        // reported `unvalidated` and `sudo bridge:check` exited 0 on an unwired agent.
        mkdir($this->keys, 0o700);

        $severities = $this->severities();
        $this->assertContains(Severity::Fail, $severities, 'a directory at authorized_keys suppressed the not-wired FAIL');
        $this->assertNotContains(Severity::Unvalidated, $severities);
    }

    public function test_a_dangling_symlink_at_the_authoritative_path_still_fails(): void
    {
        symlink($this->dir.'/.ssh/nothing-here', $this->keys);

        $severities = $this->severities();
        $this->assertContains(Severity::Fail, $severities, 'a dangling symlink at authorized_keys suppressed the not-wired FAIL');
        $this->assertNotContains(Severity::Unvalidated, $severities);
    }

    public function test_a_symlink_to_a_regular_file_is_unvalidated_and_never_a_fail(): void
    {
        // The accepted cost, asserted as such rather than left implicit: the account really
        // is wired (sshd follows the link), so a FAIL would be a false accusation — and the
        // bytes are not attributable to this account, so an `ok` would be a false pass.
        file_put_contents($this->dir.'/elsewhere', self::PINNED."\n");
        symlink($this->dir.'/elsewhere', $this->keys);

        $severities = $this->severities();
        $this->assertContains(Severity::Unvalidated, $severities);
        $this->assertNotContains(Severity::Fail, $severities);
        $this->assertNotContains(Severity::Ok, $severities);
    }
}

/**
 * Root and sshd are STATED (a test process is neither); the read and the file identity are the
 * REAL {@see SystemSshProbeEnvironment} against a real temp directory, because they are the
 * two legs this file exists to measure.
 */
final class RootAuthoritativeRealReadEnvironment implements SshProbeEnvironment
{
    private SystemSshProbeEnvironment $real;

    public function __construct(private string $keysPath)
    {
        $this->real = new SystemSshProbeEnvironment;
    }

    public function isRoot(): bool
    {
        return true;
    }

    public function fipsEnabled(): bool
    {
        return false;
    }

    public function runUser(): string
    {
        return 'bridge';
    }

    public function runUserHome(): string
    {
        return dirname($this->keysPath, 2);
    }

    public function homeForUser(string $user): ?string
    {
        return dirname($this->keysPath, 2);
    }

    public function uidForUser(string $user): ?int
    {
        return 1000;
    }

    public function euid(): ?int
    {
        return 0;
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        // ONE entry, absolute, no tokens — so the resolved path is the fixture and the run is
        // authoritative, which is the only branch on which the FAIL exists at all.
        return "authorizedkeysfile {$this->keysPath}\n";
    }

    public function readAuthorizedKeys(string $path): AuthorizedKeysRead
    {
        return $this->real->readAuthorizedKeys($path);
    }

    public function fileIdentity(string $path): string
    {
        return $this->real->fileIdentity($path);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    public function sshRoundTrip(string $target, string $stdin): array
    {
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
}
