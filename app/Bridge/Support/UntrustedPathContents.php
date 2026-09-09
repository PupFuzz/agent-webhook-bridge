<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableFileException;

/**
 * THE read for a path whose OWNER IS NOT THE READER — a file inside an unprivileged
 * account's directory, opened by a process running as root (card#9037).
 *
 * ⭐ THE DISTINCTION FROM {@see FileContents}, and it is the whole reason this class
 * exists rather than a flag on that one. `FileContents` reasons about WHICH UID THE
 * ANSWER IS FOR — its docblock is about `is_readable()` answering for the real uid, about
 * ACLs, about "another OS user may read it fine". It never asks WHO CONTROLS THE PATH,
 * and that is the question that decides whether the read is safe. Every one of its guards
 * assumes the bytes at the path are the bytes somebody with the reader's own privilege
 * put there. When the reader is root and the directory is `~agent/.ssh`, that assumption
 * is inverted: THE ACCOUNT BEING INSPECTED CHOOSES WHAT ROOT OPENS. `is_file()` is true
 * for a symlink to any file on the box (it stats the TARGET), and it is true for
 * `/proc/kcore`, which `file_get_contents()` will then read without bound. Neither is a
 * defect in `FileContents` — they are outside the question it asks — so the fix is a
 * sibling that asks the other question, and a pointer on that class so the next root-read
 * leg does not have to re-derive which of the two it needs.
 *
 * ⛔ WHAT THIS DOES NOT CLOSE. Stated first, and in full, because a half-guard believed to
 * be a whole one is worse than no guard:
 *  - **A RACING PARENT-DIRECTORY REDIRECT.** The guards below are taken on the FINAL
 *    component. Every directory above it is resolved by the kernel, twice — once for the
 *    `lstat`, once for the `open` — and the account owns those directories. It can swap
 *    `.ssh` for a symlink between the two calls, and it can do so between any two of the
 *    path components. The `dev`/`ino` comparison catches the ordinary form of that race
 *    (the two calls then land on different inodes and the read is refused), but it
 *    establishes only that ONE INODE ANSWERED BOTH CALLS — never that the inode is the
 *    file that lives under the home directory this leg meant to inspect.
 *  - **A HARD LINK.** A regular file in the account's directory may be a second name for
 *    an inode root can read and the account cannot. `lstat` and `fstat` both answer about
 *    the inode and neither can say where else it is named, so this read will return those
 *    bytes. (Linux's `fs.protected_hardlinks` normally stops the link being created in the
 *    first place — that is the KERNEL's guarantee on the host, not this class's.)
 *  - **GROWTH AFTER THE MEASUREMENT.** The bound comes from the `fstat` taken at open, so
 *    a file that grows after it is read up to that measurement and no further. The result
 *    is the file as it was measured, which is the only file any reader ever gets.
 * ⚑ THE CLOSED FORM IS NOT AVAILABLE FROM PHP, which is why the above is the residue and
 * not an omission. The defence that closes a parent redirect is a descriptor chain —
 * `openat(dirfd, …, O_NOFOLLOW)` one component at a time, which is what the python
 * provisioner's writer does. Measured on this runtime (PHP 8.5.9): `O_NOFOLLOW` is not
 * defined, there is no `openat`, and `fopen()` exposes no mode that carries the flag.
 * Reaching it would mean calling libc through FFI from inside a preflight check — a much
 * larger surface than the guard buys. `lstat` → `open` → `fstat` is the substitute, its
 * window is named above, and it is a strict improvement on `is_file()` + an unbounded
 * `file_get_contents()`, which refuses NOTHING.
 *
 * ⚑ THE GUARDS ARE UNCONDITIONAL — never gated on "am I root". Root is not the only
 * privilege asymmetry (any account reading another account's directory has the same
 * problem), and a guard that runs only on the rare path is a guard nothing exercises: the
 * common path would then be the one carrying no evidence that the refusal works at all.
 *
 * ⚑ IT IS THE READ THAT IS BOUNDED, NOT THE VERDICT. Refusal here is not an accusation
 * about the file — this process declined to read it, so it measured nothing, and the
 * caller owes the same "could not look" answer it owes for a permissions fault. That is
 * why refusal and EACCES share ONE exception type: a caller that already distinguishes
 * "could not look" from "looked, and it is not there" needs no new arm.
 */
final class UntrustedPathContents
{
    /**
     * The most this class will read from one file. An `authorized_keys` two orders of
     * magnitude past anything sshd is asked to parse is already not the file this leg
     * meant to read, and the bound is what makes a `/proc` file (whose `st_size` is a
     * fiction) a refusal rather than an unbounded read. Public so a test can build the
     * one input that crosses it.
     */
    public const MAX_BYTES = 1_048_576;

    /**
     * The file's bytes; null when it is ABSENT. Throws when this process could not read
     * it — EITHER because the open was refused (a permissions fault, exactly as
     * `FileContents` reports it) OR because one of the guards above declined to read what
     * the path resolved to. Both are "this process did not look", and the message says
     * which happened and names the path.
     *
     * ⛔ Null is ABSENT ONLY AS FAR AS THIS PROCESS CAN SEE, and the bound is the one
     * `FileContents` carries: an untraversable ancestor directory answers `lstat` false
     * exactly as a removed file does, and no read-side primitive can tell them apart.
     * `PathVisibility::ancestorIsTraversable()` is the stat-side guard for that, asked
     * FIRST by the caller — this class deliberately does not re-ask it, because a second
     * measurement can disagree with the first about one answer.
     *
     * @param  string  $subject  what to NAME in the message — the noun the operator would
     *                           recognize, as on `FileContents::read` and for its reason.
     *
     * @throws UnreadableFileException
     */
    public static function read(string $path, string $subject): ?string
    {
        // lstat, not stat: this is the one measurement that answers ABOUT THE FINAL
        // COMPONENT ITSELF rather than about whatever it points at, and a symlink here is
        // the account naming a file for root to open.
        $before = @lstat($path);
        if ($before === false) {
            return null;
        }
        if (! self::isRegular($before['mode'])) {
            throw new UnreadableFileException(self::refusal(
                $subject, $path,
                'the path names a '.self::describe($before['mode']).' rather than a regular file'
            ));
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw UnreadableFileException::permissionsFault($subject, $path);
        }

        try {
            $after = fstat($handle);
            if ($after === false) {
                // Not defensive padding: `fstat` answers for whatever the descriptor turned
                // out to be, and a stream that cannot answer leaves the type and the size —
                // the two facts BOTH remaining guards are taken on — unmeasured. Reading on
                // would be reading with no bound and no type check at all.
                throw new UnreadableFileException(self::refusal(
                    $subject, $path,
                    'the opened file could not be stat-ed, so neither its type nor its size is known'
                ));
            }
            if (! self::isRegular($after['mode'])) {
                throw new UnreadableFileException(self::refusal(
                    $subject, $path,
                    'the opened file is a '.self::describe($after['mode']).' rather than a regular file'
                ));
            }
            // The open resolved the whole path a SECOND time. Comparing the inode it
            // landed on with the one measured above is what makes the check above a
            // statement about the file that was actually opened.
            if ($after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino']) {
                throw new UnreadableFileException(self::refusal(
                    $subject, $path,
                    'the file at the path CHANGED between being checked and being opened '
                    .'(a different inode answered the open)'
                ));
            }
            $size = $after['size'];
            if ($size > self::MAX_BYTES) {
                throw new UnreadableFileException(self::refusal(
                    $subject, $path,
                    'the file reports '.$size.' bytes, past the '.self::MAX_BYTES.'-byte bound this read applies'
                ));
            }

            // ⚠ THE SUPPRESSION IS LOAD-BEARING, for the reason `FileContents` states about
            // its own: it is safe ONLY because the throw two lines down re-raises the state
            // as something the caller can switch on. A read that fails mid-stream (the
            // guards above make a directory unreachable HERE, but a device or a revoked
            // descriptor can still error) raises a warning that this app's error handler
            // converts into an `ErrorException` — a type this fails-safe seam does not catch,
            // so the whole check would die instead of reporting one unconsulted path.
            $raw = $size > 0 ? @fread($handle, $size) : '';
            if ($raw === false) {
                throw new UnreadableFileException(
                    "{$subject} at {$path} was opened but could not be read from by this process"
                );
            }

            return $raw;
        } finally {
            fclose($handle);
        }
    }

    private static function isRegular(int $mode): bool
    {
        return ($mode & 0o170000) === 0o100000;
    }

    /** The file type as an operator would name it, for a message about a path they own. */
    private static function describe(int $mode): string
    {
        return match ($mode & 0o170000) {
            0o120000 => 'symbolic link',
            0o040000 => 'directory',
            0o010000 => 'named pipe (FIFO)',
            0o140000 => 'socket',
            0o020000 => 'character device',
            0o060000 => 'block device',
            default => 'file of an unrecognized type',
        };
    }

    /**
     * ONE refusal sentence, so every guard reports the same shape: what was refused, at
     * which path, why, and — load-bearing — that NOTHING WAS MEASURED, which is what stops
     * a caller spending the refusal as a verdict about the file's contents.
     */
    private static function refusal(string $subject, string $path, string $because): string
    {
        return "{$subject} at {$path} was NOT read: {$because}. The directory holding it is "
            .'writable by an account other than the one running this process, so what the path '
            .'resolves to is that account\'s choice, not a fact about the file this process meant '
            .'to read — nothing was measured here, and neither the presence nor the contents of '
            .'the intended file is a conclusion available from this refusal';
    }
}
