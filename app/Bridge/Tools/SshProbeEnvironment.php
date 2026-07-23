<?php

namespace App\Bridge\Tools;

/**
 * The host facts the `bridge:check` SSH-transport probe (Finding D, card 4952) reads,
 * behind a seam so the root-gated / FIPS / sshd legs are unit-testable without being
 * root or running sshd. The default {@see SystemSshProbeEnvironment} reads the real
 * host; a test binds an in-memory fake.
 */
interface SshProbeEnvironment
{
    public function isRoot(): bool;

    /** `/proc/sys/crypto/fips_enabled == 1`. */
    public function fipsEnabled(): bool;

    /** The bridge run-user's name. */
    public function runUser(): string;

    /** The bridge run-user's home directory. */
    public function runUserHome(): string;

    /**
     * The home directory of a NAMED OS account (posix_getpwnam), or '' when it
     * cannot be resolved. Used to resolve the forced-command account's home when
     * board_tools.ssh_account names an account other than the invoking one; the
     * invoking account still routes through runUserHome().
     */
    public function homeForUser(string $user): string;

    /**
     * The EFFECTIVE (Match-resolved) sshd config text from `sshd -T [-C user=<user>]`,
     * or null when it cannot be run (not root — `sshd -T` loads host private keys — or
     * no sshd binary). Null means UNVERIFIED, never "posture is fine".
     */
    public function sshdEffectiveConfig(?string $forUser = null): ?string;

    /** The authorized_keys file text at $path, or null when absent/unreadable. */
    public function readAuthorizedKeys(string $path): ?string;

    /**
     * Round-trip one board-tools call over ssh to $target (`user@host`), sending
     * $stdin (the `{tool, args}` JSON) — the client passes NO command (sshd
     * substitutes the forced `bridge:tools-call`). Used only by the opt-in
     * `--probe-tools-ssh` live leg.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function sshRoundTrip(string $target, string $stdin): array;
}
