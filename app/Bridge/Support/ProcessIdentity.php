<?php

namespace App\Bridge\Support;

/**
 * WHO THIS PROCESS RUNS AS, and who owns a file it is about to replace — the facts a writer of a
 * state file another OS user must keep reading has to ask before it writes.
 *
 * ⚠ IT IS A SEAM BECAUSE THE INTERESTING ANSWERS ARE NOT CONSTRUCTIBLE IN A SUITE. A test run is
 * not root and cannot `chown` a file to another account, so a writer that read `posix_geteuid()`
 * and `fileowner()` directly could only ever be exercised on its all-clear arm. Bound in
 * `BridgeServiceProvider`; replaced with `$this->app->instance()` in tests, as
 * {@see TerminalProbe} is.
 *
 * ⛔ NULL IS UNMEASURED, NEVER A UID. `0` is root, so a reader that folded "could not tell" into
 * an int would either call every host without the posix extension root or call root nobody.
 */
interface ProcessIdentity
{
    /** This process's EFFECTIVE uid, or null when it cannot be read (no `posix_geteuid`). */
    public function euid(): ?int;

    /** The uid owning the file at $path, or null when there is no file this process can stat there. */
    public function ownerOf(string $path): ?int;

    /** The account name for $uid, or null when it cannot be looked up (no such account, or no `posix_getpwuid`). */
    public function accountName(int $uid): ?string;
}
