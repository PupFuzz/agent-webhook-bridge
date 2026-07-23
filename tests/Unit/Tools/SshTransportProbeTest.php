<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;
use PHPUnit\Framework\TestCase;

/**
 * The bridge:check SSH-transport probe (card 4952, Finding D). Drives every root-gated /
 * FIPS / sshd branch through an in-memory {@see SshProbeEnvironment} fake — no root, no
 * sshd, no /proc. Asserts the DR2-3 severity split (unverifiable ⇒ warn, present-but-bad
 * ⇒ fail) and the FIPS-ed25519 + password-auth reds.
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

    // ─── sshd password-auth posture ───────────────────────────────────────────

    public function test_posture_unverified_when_not_root_warns_never_fails(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE);   // isRoot false → sshdConfig null
        $findings = (new SshTransportProbe($env))->probeSshdPosture();
        $this->assertTrue($this->hasSeverity($findings, 'warn'));
        $this->assertFalse($this->hasSeverity($findings, 'fail'));
    }

    public function test_password_auth_enabled_fails(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "passwordauthentication yes\nauthorizedkeysfile .ssh/authorized_keys\n",
        );
        $findings = (new SshTransportProbe($env))->probeSshdPosture();
        $this->assertTrue($this->hasSeverity($findings, 'fail'));
    }

    public function test_password_auth_disabled_passes(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "passwordauthentication no\nauthorizedkeysfile .ssh/authorized_keys\n",
        );
        $findings = (new SshTransportProbe($env))->probeSshdPosture();
        $this->assertTrue($this->hasSeverity($findings, 'ok'));
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
    public function __construct(
        private string $authorizedKeys = '',
        private bool $isRoot = false,
        private bool $fips = false,
        private ?string $sshdConfig = null,
        private int $sshExit = 0,
        private string $sshStdout = '',
        private string $sshStderr = '',
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
        return 'bridge';
    }

    public function runUserHome(): string
    {
        return '/home/bridge';
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        // Mirrors the real impl: unavailable (null) unless root.
        return $this->isRoot ? $this->sshdConfig : null;
    }

    public function readAuthorizedKeys(string $path): ?string
    {
        return $this->authorizedKeys === '' ? null : $this->authorizedKeys;
    }

    public function sshRoundTrip(string $target, string $stdin): array
    {
        return ['exit' => $this->sshExit, 'stdout' => $this->sshStdout, 'stderr' => $this->sshStderr];
    }
}
