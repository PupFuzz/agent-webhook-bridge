<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\UntrustedPathContents;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The real-host {@see SshProbeEnvironment} for `bridge:check` (card 4952). Every leg
 * fails SAFE: an unavailable fact answers UNVERIFIED — null/false, or
 * {@see AuthorizedKeysRead::unreadable()} on the one leg that has to tell an unavailable
 * fact from an established absence — never a fabricated "all good", and never a throw.
 * `sshd -T` is only attempted as root (it loads host private keys); as the
 * unprivileged run-user it returns null so the caller emits an explicit UNVERIFIED
 * finding — `unvalidated` since DL-251, because a leg that could not resolve the file it
 * was going to read did not answer its own question.
 */
final class SystemSshProbeEnvironment implements SshProbeEnvironment
{
    public function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    public function fipsEnabled(): bool
    {
        $flag = @file_get_contents('/proc/sys/crypto/fips_enabled');

        return is_string($flag) && trim($flag) === '1';
    }

    public function runUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw)) {
                return $pw['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    public function runUserHome(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw)) {
                return $pw['dir'];
            }
        }

        return '';
    }

    public function homeForUser(string $user): ?string
    {
        // The capability test comes FIRST and returns null, because without it there is no
        // account database to consult and '' would claim one answered (see the interface).
        if (! function_exists('posix_getpwnam')) {
            return null;
        }

        $pw = posix_getpwnam($user);

        return is_array($pw) && $pw['dir'] !== '' ? $pw['dir'] : '';
    }

    public function uidForUser(string $user): ?int
    {
        if (! function_exists('posix_getpwnam')) {
            return null;
        }
        $pw = posix_getpwnam($user);

        return is_array($pw) ? $pw['uid'] : null;
    }

    public function euid(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        if (! $this->isRoot()) {
            return null;   // sshd -T loads host keys — unprivileged cannot run it
        }
        $sshd = (new ExecutableFinder)->find('sshd', '/usr/sbin/sshd', ['/usr/sbin', '/sbin', '/usr/bin']);
        if ($sshd === null) {
            return null;
        }
        $args = [$sshd, '-T'];
        if ($forUser !== null) {
            $args[] = '-C';
            $args[] = 'user='.$forUser;
        }
        try {
            $proc = new Process($args);
            $proc->setTimeout(10);
            $proc->run();
            if (! $proc->isSuccessful()) {
                return null;
            }

            return $proc->getOutput();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ⭐ THE TWO SHARED GUARDS, ADOPTED RATHER THAN RE-DERIVED (card#8976). The shape this
     * replaces was `if (! is_file($path) || ! is_readable($path)) return null;` — which is
     * the card#5698 stat conflation and the card#5789 read conflation in one line, minted
     * a third time here.
     *
     * ORDERED AS `ChannelToken::unreadableFault()` ORDERS IT, and the order is
     * load-bearing: without traversal `lstat()` cannot answer at all, and the reader reads
     * its false as ABSENT — so the visibility question is asked first, by the primitive that
     * owns it. `is_readable()` is gone on purpose: {@see UntrustedPathContents} states why
     * the open's own return is the only answer that is not a proxy (it answers for the real
     * uid, and cannot see an ACL).
     *
     * ⭐ THE READER IS {@see UntrustedPathContents}, NOT `FileContents`, and this is the one
     * leg in the tree where that choice matters (card#9037). `bridge:check` runs this leg AS
     * ROOT over paths inside an UNPRIVILEGED ACCOUNT'S `.ssh/` — so the account being
     * inspected chooses what root opens. `FileContents` is `is_file()` + an unbounded
     * `file_get_contents()`: `is_file()` follows the link and answers about the TARGET, so
     * `.ssh/authorized_keys` pointing at any regular file on the box was read as though it
     * were the account's own, with no size bound in front of it. The sibling reader refuses a
     * symlinked or non-regular final component and bounds the read from the open file's own
     * `fstat`; what it does NOT close — a racing redirect of a PARENT directory, and a racing
     * FIFO that wedges the open — is named in its docblock and is not closable from PHP on
     * this runtime.
     *
     * ⛔ THE TWO REFUSALS LAND ON DIFFERENT ARMS, AND ROUTING BOTH TO `unreadable()` WOULD
     * HAND THE INSPECTED ACCOUNT A WAY TO SUPPRESS ROOT'S OWN FAIL. `unreadable()` unmakes the
     * authoritative *not wired* FAIL in {@see SshTransportProbe::probePinnedLine()} — that is
     * its entire purpose — so a refusal routed there is an exit code that stops firing. A path
     * sshd takes NO KEYS from — WHICH SHAPES that covers is `UntrustedPathContents`'s own
     * docblock to own and not a count restated here (it grew members TWICE within this same
     * branch without this file changing: a symlink chain that loops or resolves to absence
     * through more than one hop, card#9037 r2, then a chain exhausted at exactly the kernel's
     * own hop limit, r3) — is CONSULTED and the leg reports `absent()`, so the FAIL was earned
     * and MUST remain earned. Measured on the tree this change branched from: `mkdir
     * ~/.ssh/authorized_keys` and a dangling symlink each produced `fail`, and collapsing them
     * onto `unreadable()` turned both into `unvalidated` — `sudo bridge:check` going non-zero
     * → 0 on a genuinely unwired account, at the choice of the very principal this reader
     * defends against. So the ESTABLISHING refusal ({@see PathResolvesToNoFileException}) is
     * caught FIRST and answers `absent()`, which is what it means: consulted, no keys,
     * nothing withheld.
     *
     * ⚠ ANY REFUSAL `UntrustedPathContents` CLASSIFIES AS WITHHOLDING costs a read that used
     * to succeed, and the cost is accepted rather than unnoticed — a count is not restated
     * here for the same reason as above. One worked example: an operator who SYMLINKS
     * `authorized_keys` to a regular file (sshd follows it, so the account really is wired)
     * now gets `unreadable()` for that path — the probe names it as unconsulted and reports
     * `unvalidated` where it might have reported `ok` or `fail`. That is the fails-safe
     * direction: the leg says it did not look, which is TRUE, instead of trusting bytes it
     * cannot attribute — and it holds for every WITHHOLDING shape alike, not only this one:
     * an account CAN convert its own earned FAIL into `unvalidated` by constructing any of
     * them, and that is the honest answer rather than a defect. It is `fileIdentity()` — not
     * this method — that dedupes a symlinked SPELLING of one file, and that is unaffected.
     */
    public function readAuthorizedKeys(string $path): AuthorizedKeysRead
    {
        if (! PathVisibility::ancestorIsTraversable($path)) {
            return AuthorizedKeysRead::unreadable();
        }
        try {
            $text = UntrustedPathContents::read($path, 'authorized_keys');
        } catch (PathResolvesToNoFileException) {
            // MEASURED, not withheld: sshd reads this path and takes no keys from it, exactly
            // as it takes none from a path with no file. The subtype is caught FIRST because
            // it extends the type below.
            return AuthorizedKeysRead::absent();
        } catch (UnreadableFileException) {
            return AuthorizedKeysRead::unreadable();
        }

        return $text === null ? AuthorizedKeysRead::absent() : AuthorizedKeysRead::text($text);
    }

    /**
     * ⭐ THE INODE IS THE ANSWER WHEN THERE IS ONE, and `realpath()` alone is not it: it
     * resolves symlinks and spellings, and a HARD link has neither — two names of one inode
     * would still compare unequal, and one physical `authorized_keys` line would still be
     * counted twice (card#8976 r2). `stat()` follows symlinks, so `dev:ino` answers for the
     * symlinked, hard-linked and re-spelled cases in one measurement.
     *
     * The fallback is for a path that names no file THIS PROCESS CAN STAT — it does not
     * exist, or an ancestor is not traversable — where there is no inode to compare and the
     * normalised path is the most that can be said. Runs of `/` inside the path collapse
     * (POSIX: `/a//b` and `/a/b` are the same file) while a LEADING `//` is left alone,
     * because that one is implementation-defined and is not ours to normalise away.
     */
    public function fileIdentity(string $path): string
    {
        $stat = @stat($path);
        if (is_array($stat)) {
            return 'inode:'.$stat['dev'].':'.$stat['ino'];
        }

        return 'path:'.preg_replace('#(?<=.)/{2,}#', '/', $path);
    }

    public function sshRoundTrip(string $target, string $stdin): array
    {
        $ssh = (new ExecutableFinder)->find('ssh', '/usr/bin/ssh');
        if ($ssh === null) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'ssh binary not found'];
        }
        $key = getenv('BRIDGE_TOOLS_SSH_KEY');
        $args = [$ssh, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'];
        if (is_string($key) && $key !== '') {
            $args[] = '-i';
            $args[] = $key;
        }
        $args[] = $target;
        try {
            $proc = new Process($args, null, null, $stdin);
            $proc->setTimeout(30);
            $proc->run();

            return ['exit' => (int) $proc->getExitCode(), 'stdout' => $proc->getOutput(), 'stderr' => $proc->getErrorOutput()];
        } catch (\Throwable $e) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => $e->getMessage()];
        }
    }
}
