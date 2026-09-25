<?php

namespace App\Bridge\Support;

/**
 * The real-process {@see ProcessIdentity}. The posix extension is optional and commonly absent on
 * hardened hosts, so each read is guarded and answers null — unmeasured — without it.
 */
final class SystemProcessIdentity implements ProcessIdentity
{
    public function euid(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    public function ownerOf(string $path): ?int
    {
        // The stat cache would answer about the inode a rename has since replaced.
        clearstatcache(true, $path);
        $owner = @fileowner($path);

        return $owner === false ? null : $owner;
    }

    public function accountName(int $uid): ?string
    {
        if (! function_exists('posix_getpwuid')) {
            return null;
        }
        $pw = posix_getpwuid($uid);

        return is_array($pw) ? $pw['name'] : null;
    }
}
