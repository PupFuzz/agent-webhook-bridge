<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\PathVisibility;
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
     * load-bearing: without traversal `is_file()` cannot answer at all, and
     * {@see FileContents} reads its false as ABSENT — so the visibility question is asked
     * first, by the primitive that owns it. `is_readable()` is gone on purpose:
     * {@see FileContents} states why the open's own return is the only answer that is not a
     * proxy (it answers for the real uid, and cannot see an ACL).
     */
    public function readAuthorizedKeys(string $path): AuthorizedKeysRead
    {
        if (! PathVisibility::ancestorIsTraversable($path)) {
            return AuthorizedKeysRead::unreadable();
        }
        try {
            $text = FileContents::read($path, 'authorized_keys');
        } catch (UnreadableFileException) {
            return AuthorizedKeysRead::unreadable();
        }

        return $text === null ? AuthorizedKeysRead::absent() : AuthorizedKeysRead::text($text);
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
