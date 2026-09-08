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
     * The home directory of a NAMED OS account. Used to resolve the forced-command
     * account's home when board_tools.ssh_account names an account other than the
     * invoking one; the invoking account still routes through runUserHome().
     *
     * THREE STATES, and the third is why this is nullable (DL-259, card#5698):
     *   - a non-empty path — the account resolved;
     *   - `''`             — the account database was CONSULTED and has no such account
     *                        (or it has no home). A measured answer; callers may accuse.
     *   - `null`           — this process cannot look OS accounts up AT ALL (no
     *                        `posix_getpwnam`; the extension is optional and is commonly
     *                        absent or disabled on hardened/shared hosts — this codebase
     *                        already guards `posix_getuid` the same way). NOTHING was
     *                        measured, so "no such account" is not a conclusion a caller
     *                        is entitled to draw from it.
     * Returning `''` for the third state made a missing CAPABILITY indistinguishable from
     * a missing ACCOUNT, and the caller spent it as an exit-code-bearing accusation.
     */
    public function homeForUser(string $user): ?string;

    /**
     * The numeric uid of a NAMED OS account, or null when this run did not establish
     * one — either because the account database has no such account, or because this
     * process cannot look OS accounts up at all (no `posix_getpwnam`).
     *
     * ⛔ THE TWO NON-ANSWERS ARE DELIBERATELY COLLAPSED HERE, unlike
     * {@see self::homeForUser()}, and the reason is the consumer rather than economy.
     * The only reader is the setup packet's *does the pin need `sudo`* decision, which
     * compares this against {@see self::euid()}: a non-null EQUAL pair prints the
     * self-account form, and EVERY other combination prints the `sudo` form. Both
     * non-answers therefore reach the same, SAFER, branch — an unnecessary `sudo` costs
     * the operator a password prompt, a missing one costs them a failed pin — so a third
     * state would be a distinction no caller could spend. Nothing here accuses an
     * account of not existing.
     */
    public function uidForUser(string $user): ?int;

    /**
     * This process's EFFECTIVE uid, or null when it cannot be read (no `posix_geteuid`
     * — the extension is optional and commonly absent on hardened hosts). Null is
     * UNMEASURED, never 0: see {@see self::uidForUser()} for what the one consumer does
     * with it.
     */
    public function euid(): ?int;

    /**
     * The EFFECTIVE (Match-resolved) sshd config text from `sshd -T [-C user=<user>]`,
     * or null when it cannot be run (not root — `sshd -T` loads host private keys — or
     * no sshd binary). Null means UNVERIFIED, never "posture is fine".
     */
    public function sshdEffectiveConfig(?string $forUser = null): ?string;

    /**
     * One read of the `authorized_keys` file at $path.
     *
     * THREE STATES, and {@see AuthorizedKeysRead} owns why: a file that is NOT THERE was
     * consulted and contributes nothing (so an absence drawn over it is established), while
     * a file this process could not LOOK at establishes nothing at all. Returning null for
     * both made the authoritative "not wired" FAIL unreachable on the OpenSSH default, whose
     * second file is absent on essentially every host (card#8976).
     */
    public function readAuthorizedKeys(string $path): AuthorizedKeysRead;

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
