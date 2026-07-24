<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;
use PHPUnit\Framework\TestCase;

/**
 * The bridge:check SSH-transport probe (card 4952, Finding D). Drives every root-gated /
 * FIPS / sshd branch through an in-memory {@see SshProbeEnvironment} fake — no root, no
 * sshd, no /proc. Asserts the DR2-3 severity split (unverifiable ⇒ warn, present-but-bad
 * ⇒ fail) and the FIPS-ed25519 red. (The sshd account-posture leg was retired in card 5091 —
 * see test_posture_probe_is_retired_no_account_hardening_assertions.)
 */
class SshTransportProbeTest extends TestCase
{
    private const GOOD_LINE = 'command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAAKEYBLOB me';

    /** @param array{severity: string, message: string}[] $findings */
    private function hasSeverity(array $findings, string $severity): bool
    {
        foreach ($findings as $f) {
            if ($f['severity'] === $severity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{severity: string, message: string}[]  $findings
     * @return array{severity: string, message: string}|null
     */
    private function firstMatching(array $findings, string $needle): ?array
    {
        foreach ($findings as $f) {
            if (str_contains($f['message'], $needle)) {
                return $f;
            }
        }

        return null;
    }

    // ─── pinned-line OUTCOME ──────────────────────────────────────────────────

    public function test_good_line_produces_no_fail(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE);
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
        $this->assertTrue($this->hasSeverity($findings, 'ok'));
    }

    public function test_present_but_bad_line_at_assumed_path_fails(): void
    {
        // DR2-3b: a line found at the assumed default path that grants a pty is
        // authoritative-enough to FAIL (not merely warn) even unprivileged.
        $env = new FakeSshProbeEnvironment(authorizedKeys: 'command="php artisan bridge:tools-call --agent=me",restrict,pty ssh-ed25519 AAAA me');
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_absent_line_at_assumed_path_warns_not_fails(): void
    {
        // DR2-3b: pure ABSENCE at an assumed (non-authoritative) path is a WARN — the
        // AuthorizedKeysFile may be relocated; never a false FAIL.
        $env = new FakeSshProbeEnvironment(authorizedKeys: "# empty\n");
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
        $this->assertTrue($this->hasSeverity($findings, 'warn'));
    }

    public function test_absent_line_at_authoritative_path_fails(): void
    {
        // As root, sshd -T resolves the real AuthorizedKeysFile — absence there is a
        // definitive "not wired" FAIL.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: "# empty\n",
            isRoot: true,
            sshdConfig: "authorizedkeysfile /etc/ssh/keys/%u\npasswordauthentication no\n",
        );
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_ambiguous_duplicate_lines_fail(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE."\n".self::GOOD_LINE."\n");
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    // ─── FIPS ─────────────────────────────────────────────────────────────────

    public function test_fips_mode_with_ed25519_key_fails(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE, fips: true);
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_fips_mode_with_ecdsa_key_passes(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: 'command="php artisan bridge:tools-call --agent=me",restrict ecdsa-sha2-nistp256 AAAA me',
            fips: true,
        );
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
    }

    // ─── retired: sshd account posture (card 5091) ────────────────────────────
    // The password-auth + idle/concurrency posture assertions were REMOVED: they demanded
    // the account-level `Match User` drop-in that card 5091 retired (it locked out an
    // operator sharing the ssh-account). The sole board-tools boundary is now the pinned
    // forced-command key (probePinnedLine, above) + the live round-trip (probeLive, below).
    // The absence guard below fails RED if probeSshdPosture is reintroduced.

    public function test_posture_probe_is_retired_no_account_hardening_assertions(): void
    {
        $this->assertFalse(
            method_exists(SshTransportProbe::class, 'probeSshdPosture'),
            'the retired sshd account-posture leg (operator-lockout, card 5091) must not return',
        );
    }

    // ─── forced-command account resolution (card 4977) ───────────────────────

    public function test_split_topology_resolves_the_configured_ssh_account_not_the_invoker(): void
    {
        // Invoking account is root (sudo bridge:check); the forced command runs as
        // `device`. The authorized_keys resolution must target `device`, never root.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys\n",
            runUser: 'root',
            runUserHome: '/root',
            userHomes: ['device' => '/home/device'],
        );
        $probe = new SshTransportProbe($env, 'device');

        $findings = $probe->probePinnedLine('me');

        // authorized_keys resolved via device's %h, NOT /root/...
        $this->assertContains('/home/device/.ssh/authorized_keys', $env->readPaths);
        $this->assertNotContains('/root/.ssh/authorized_keys', $env->readPaths);

        // The sshd -T AuthorizedKeysFile lookup targeted `device`, never root.
        $this->assertContains('device', $env->sshdQueriedUsers);
        $this->assertNotContains('root', $env->sshdQueriedUsers);

        // The pinned forced-command line for device resolved clean.
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
        $this->assertTrue($this->hasSeverity($findings, 'ok'));
    }

    public function test_fallback_unset_account_queries_the_invoking_account_exactly_as_before(): void
    {
        // canon #6: ssh_account unset ⇒ byte-identical to pre-4977 — the authorized_keys
        // sshd query passes NO -C (null) and the keys path uses the invoking run-user's home.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys\n",
            runUser: 'bridge',
            runUserHome: '/home/bridge',
        );
        $probe = new SshTransportProbe($env);   // no ssh_account

        $probe->probePinnedLine('me');

        $this->assertSame('bridge', $probe->forcedCommandAccount());
        // The keys-path sshd query passed no -C (null) — byte-identical to pre-4977.
        $this->assertContains(null, $env->sshdQueriedUsers);
        // The default keys path used the invoking run-user's home.
        $this->assertContains('/home/bridge/.ssh/authorized_keys', $env->readPaths);
    }

    // ─── configured ssh_account that does not resolve (card 4977, Defect 2) ────

    public function test_configured_ssh_account_that_does_not_resolve_fails_not_phantom_path(): void
    {
        // A configured ssh_account with no OS account (homeForUser ⇒ '') must fail honestly
        // on every account-dependent leg — never certify against a phantom
        // '/.ssh/authorized_keys' built from the empty home.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys\n",
            userHomes: ['ghost' => ''],   // '' models an account posix_getpwnam cannot resolve
        );
        $probe = new SshTransportProbe($env, 'ghost');

        $pinned = $probe->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($pinned, 'fail'));
        $this->assertFalse($this->hasSeverity($pinned, 'ok'));
        $this->assertNotNull($this->firstMatching($pinned, 'does not resolve to an OS account'));
        // No phantom-path read attempted (the leg fails before authorizedKeysPath).
        $this->assertNotContains('/.ssh/authorized_keys', $env->readPaths);
    }

    public function test_unset_account_with_empty_run_user_home_is_unchanged_warn_not_hard_fail(): void
    {
        // canon #6: the UNSET fallback path (runUserHome ⇒ '') is a pre-existing edge,
        // deliberately unchanged — it stays a non-authoritative warn, never the new
        // configured-account hard-fail. Pins that the Defect-2 gate is sshAccount-strict.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: '',   // nothing readable at the assumed default path
            runUserHome: '',
        );
        $probe = new SshTransportProbe($env);   // no ssh_account

        $findings = $probe->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, 'warn'));
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
    }

    // ─── live probe ───────────────────────────────────────────────────────────

    public function test_live_probe_clean_matching_scope_passes(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'tool' => 'board_my_cards', 'result' => ['board_id' => 10, 'swimlane_id' => 4]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
        $this->assertTrue($this->hasSeverity($findings, 'ok'));
    }

    public function test_live_probe_dirty_stdout_fails(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: "PHP Warning: something\n".json_encode(['ok' => true, 'result' => ['board_id' => 10, 'swimlane_id' => 4]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_live_probe_isolation_mismatch_fails(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'result' => ['board_id' => 99, 'swimlane_id' => 99]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_live_probe_unreachable_fails(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE, sshExit: 255, sshStderr: 'Connection refused');
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }
}

/**
 * In-memory {@see SshProbeEnvironment} — every host fact is a constructor field so a
 * test drives the root / FIPS / sshd / ssh-round-trip branches deterministically.
 */
class FakeSshProbeEnvironment implements SshProbeEnvironment
{
    /** @var list<?string> every `user=` passed to sshd -T (null = no -C). */
    public array $sshdQueriedUsers = [];

    /** @var list<string> every path readAuthorizedKeys was asked for. */
    public array $readPaths = [];

    /**
     * @param  array<string, string>  $userHomes  home dir per named account (homeForUser)
     */
    public function __construct(
        private string $authorizedKeys = '',
        private bool $isRoot = false,
        private bool $fips = false,
        private ?string $sshdConfig = null,
        private int $sshExit = 0,
        private string $sshStdout = '',
        private string $sshStderr = '',
        private string $runUser = 'bridge',
        private string $runUserHome = '/home/bridge',
        private array $userHomes = [],
    ) {}

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function fipsEnabled(): bool
    {
        return $this->fips;
    }

    public function runUser(): string
    {
        return $this->runUser;
    }

    public function runUserHome(): string
    {
        return $this->runUserHome;
    }

    public function homeForUser(string $user): string
    {
        return $this->userHomes[$user] ?? "/home/{$user}";
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        $this->sshdQueriedUsers[] = $forUser;

        // Mirrors the real impl: unavailable (null) unless root.
        return $this->isRoot ? $this->sshdConfig : null;
    }

    public function readAuthorizedKeys(string $path): ?string
    {
        $this->readPaths[] = $path;

        return $this->authorizedKeys === '' ? null : $this->authorizedKeys;
    }

    public function sshRoundTrip(string $target, string $stdin): array
    {
        return ['exit' => $this->sshExit, 'stdout' => $this->sshStdout, 'stderr' => $this->sshStderr];
    }
}
