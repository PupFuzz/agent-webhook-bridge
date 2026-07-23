<?php

namespace App\Bridge\Tools;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The real-host {@see SshProbeEnvironment} for `bridge:check` (card 4952). Every leg
 * fails SAFE: an unavailable fact returns null/false (UNVERIFIED), never a fabricated
 * "all good". `sshd -T` is only attempted as root (it loads host private keys); as the
 * unprivileged run-user it returns null so the caller emits an explicit UNVERIFIED warn.
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

    public function readAuthorizedKeys(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return is_string($content) ? $content : null;
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
