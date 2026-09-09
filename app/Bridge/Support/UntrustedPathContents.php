<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
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
 * is inverted: THE ACCOUNT BEING INSPECTED CHOOSES WHAT ROOT OPENS. `is_file()` follows
 * the link and answers about the TARGET, so a symlink to any regular file on the box
 * passes it — `/proc/kcore` included — and `file_get_contents()` then reads what it names
 * with no bound. Neither is a defect in `FileContents` — they are outside the question it
 * asks — so the fix is a sibling that asks the other question, and a pointer on that class
 * so the next root-read leg does not have to re-derive which of the two it needs.
 *
 * ⭐ TWO KINDS OF REFUSAL, AND THE CALLER MUST BE ABLE TO TELL THEM APART. A refusal that
 * withholds a verdict is not the same as one that DELIVERS a measurement, and collapsing
 * them onto one answer is how a guard like this turns permissive:
 *  - **ESTABLISHING** — the path names a directory, FIFO, socket or device, or a symlink to
 *    one of those or to a target measured absent. No reader following this path gets bytes,
 *    and that is a FACT this run established. Raised as {@see PathResolvesToNoFileException}.
 *  - **WITHHOLDING** — a symlink to a regular file (we decline to attribute those bytes to
 *    the account), a resolution this process could not complete, an identity that changed
 *    under the open, an unmeasurable descriptor, or a file past the size bound. Nothing was
 *    established. Raised as the plain {@see UnreadableFileException}.
 * The split is a property of the FILESYSTEM, not of any check: this class states which one
 * happened and never what a caller should do about it. A caller with no use for the
 * distinction catches the parent and treats both as "could not look" — understating what was
 * measured, which is the safe direction.
 *
 * ⛔ WHAT THIS DOES NOT CLOSE — the residues known when this was written, and the list is
 * NOT claimed closed. It is written out because a half-guard believed to be a whole one is
 * worse than no guard; it is not claimed exhaustive because the fourth item below was found
 * by a reviewer AFTER the first three were published as "the residue, in full", and the
 * fifth will be found the same way:
 *  - **A RACING PARENT-DIRECTORY REDIRECT.** The guards below are taken on the FINAL
 *    component. Every directory above it is resolved by the kernel, twice — once for the
 *    `lstat`, once for the `open` — and the account owns those directories. It can swap
 *    `.ssh` for a symlink between the two calls, and it can do so between any two of the
 *    path components. The `dev`/`ino` comparison catches the ordinary form of that race
 *    (the two calls then land on different inodes and the read is refused), but it
 *    establishes only that ONE INODE ANSWERED BOTH CALLS — never that the inode is the
 *    file that lives under the home directory this leg meant to inspect.
 *  - **A RACING FIFO WEDGES THE READER, INDEFINITELY** — worse in kind than the others,
 *    because the process does not get a wrong answer, it gets NO answer. `fopen($p, 'rb')`
 *    on a FIFO with no writer BLOCKS, and the block is INSIDE the open: `fstat` never runs,
 *    so neither the type guard nor the `dev`/`ino` guard is ever reached. A FIFO that is
 *    already at the path when the `lstat` runs is refused (that is the establishing arm
 *    above); an account that replaces a regular file with a FIFO in the window BETWEEN the
 *    `lstat` and the `open` hangs the run — demonstrated deterministically with a widened
 *    window, where the process had to be killed at a timeout. ⛔ A `pcntl_alarm()` around
 *    the open is NOT the fix and was rejected: `pcntl` is present in the CLI SAPI here and
 *    absent under FPM, so it would be a guard that silently does not exist on one of the two
 *    SAPIs this code runs under — the defect class this whole file is closing.
 *  - **A HARD LINK.** A regular file in the account's directory may be a second name for
 *    an inode root can read and the account cannot. `lstat` and `fstat` both answer about
 *    the inode and neither can say where else it is named, so this read will return those
 *    bytes. (Linux's `fs.protected_hardlinks` normally stops the link being created in the
 *    first place — that is the KERNEL's guarantee on the host, not this class's.)
 *  - **GROWTH AFTER THE MEASUREMENT.** The bound comes from the `fstat` taken at open, so
 *    a file that grows after it is read up to that measurement and no further. The result
 *    is the file as it was measured, which is the only file any reader ever gets.
 * ⚑ THE CLOSED FORM IS NOT AVAILABLE FROM PHP, which is why there is a residue at all. The
 * defence that closes a parent redirect — and, with `O_NONBLOCK`, the FIFO wedge with it — is
 * a descriptor chain: `openat(dirfd, …, O_NOFOLLOW)` one component at a time, which is what
 * the python provisioner's writer does. Measured on this runtime (PHP 8.5.9): `O_NOFOLLOW` is
 * not defined, there is no `openat`, and `fopen()` exposes no mode that carries either flag.
 * Reaching them would mean calling libc through FFI from inside a preflight check — a much
 * larger surface than the guard buys. `lstat` → `open` → `fstat` is the substitute, its
 * window is named above, and it is a strict improvement on `is_file()` + an unbounded
 * `file_get_contents()`, which refuses NOTHING.
 *
 * ⚑ THE GUARDS ARE UNCONDITIONAL — never gated on "am I root". Root is not the only
 * privilege asymmetry (any account reading another account's directory has the same
 * problem), and a guard that runs only on the rare path is a guard nothing exercises: the
 * common path would then be the one carrying no evidence that the refusal works at all.
 */
final class UntrustedPathContents
{
    /**
     * The most this class will read from one file. An `authorized_keys` two orders of
     * magnitude past anything sshd is asked to parse is already not the file this leg meant
     * to read. Public so a test can build the one input that crosses it.
     *
     * ⚠ IT BOUNDS THE READ; IT DOES NOT VALIDATE `st_size`. It catches a file whose reported
     * size is enormous — `/proc/kcore` is the worked case — and it is BLIND in the other
     * direction: a `/proc` file that reports 0 and yields content on read (`/proc/self/status`,
     * `/proc/self/environ`) is a regular file well under the bound, so it is read, and at zero
     * reported bytes this class returns `''`. On a leg whose paths live under a home directory
     * such a file is reachable only THROUGH a symlink, which the guards above refuse — but
     * that is a property of the caller's paths, not a guarantee this bound provides.
     */
    public const MAX_BYTES = 1_048_576;

    /**
     * The file's bytes; null when the path is ABSENT. Throws when this process did not read
     * it — see the class docblock for the two kinds of refusal and which type carries which.
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
     * @throws PathResolvesToNoFileException the path resolves to something that holds no
     *                                       bytes — MEASURED, and a subtype of the below
     * @throws UnreadableFileException this process did not look, and established
     *                                 nothing
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
            throw self::nonRegularRefusal($path, $subject, $before['mode']);
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw UnreadableFileException::permissionsFault($subject, $path);
        }

        try {
            $after = fstat($handle);
            if ($after === false) {
                // The arm is kept because reading on would be reading with no bound and no
                // type check — the two facts both remaining guards are taken on. ⚑ No input
                // reaching it is known: a review across the stream wrappers this path can
                // name could not construct one, so treat it as unexercised rather than as a
                // state with a demonstrated trigger.
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
            // its own: it is safe ONLY because the throw just below re-raises the state as
            // something the caller can switch on. A read that fails mid-stream raises a
            // warning that this app's error handler converts into an `ErrorException` — a
            // type this fails-safe seam does not catch, so the whole check would die instead
            // of reporting one unconsulted path.
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

    /**
     * WHICH KIND OF REFUSAL a non-regular final component earns — the class docblock's
     * establishing/withholding split, decided here and nowhere else.
     *
     * ⭐ THE SYMLINK IS THE ONLY CASE THAT NEEDS A SECOND MEASUREMENT, and it needs it
     * because the two kinds live on opposite sides of one `lstat` answer: a link to a regular
     * file is the accepted cost (the account really is wired and we decline to attribute
     * those bytes), while a link to a directory, a device or nothing at all is a file sshd —
     * or any other follower of that path — takes nothing from. A FOLLOWING `stat()` answers
     * that, and it is a stat and never an open, so it neither reads the target nor blocks on
     * one.
     *
     * ⛔ A FAILED `stat()` IS NOT EVIDENCE OF ABSENCE (card#5698's whole rule), so it does not
     * reach the establishing arm on its own: a dangling link and a link into a directory this
     * process may not traverse both answer false. Absence is confirmed POSITIVELY, one hop,
     * by {@see self::targetIsConfirmedAbsent()}, and everything it cannot confirm withholds.
     */
    private static function nonRegularRefusal(string $path, string $subject, int $mode): UnreadableFileException
    {
        if (! self::isLink($mode)) {
            return new PathResolvesToNoFileException(self::refusal(
                $subject, $path,
                'the path names a '.self::describe($mode).' rather than a regular file, so nothing '
                .'following this path reads any bytes from it'
            ));
        }

        $target = @stat($path);
        if (is_array($target) && self::isRegular($target['mode'])) {
            return new UnreadableFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link to a regular file elsewhere, and the bytes it names '
                .'cannot be attributed to whoever owns this path'
            ));
        }
        if (is_array($target)) {
            return new PathResolvesToNoFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link to a '.self::describe($target['mode']).', so nothing '
                .'following this path reads any bytes from it'
            ));
        }
        if (self::targetIsConfirmedAbsent($path)) {
            return new PathResolvesToNoFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link whose target does not exist, so nothing following '
                .'this path reads any bytes from it'
            ));
        }

        return new UnreadableFileException(self::refusal(
            $subject, $path,
            'the path is a symbolic link this process could not resolve — the target may be '
            .'present behind a directory this process may not traverse'
        ));
    }

    /**
     * Can this process POSITIVELY establish that the link's target is not there? One hop, and
     * deliberately not a walk: a target that is itself a symlink EXISTS, so `lstat` finds it
     * and this answers false — the chain beyond it is unmeasured, and unmeasured withholds.
     * `PathVisibility` is what separates "not there" from "not visible to me"; without it this
     * would assert absence off a permission denial, which is the defect the whole family of
     * these primitives exists to stop.
     */
    private static function targetIsConfirmedAbsent(string $path): bool
    {
        $raw = @readlink($path);
        if (! is_string($raw)) {
            return false;
        }
        $target = str_starts_with($raw, '/') ? $raw : dirname($path).'/'.$raw;

        return @lstat($target) === false && PathVisibility::ancestorIsTraversable($target);
    }

    private static function isRegular(int $mode): bool
    {
        return ($mode & 0o170000) === 0o100000;
    }

    private static function isLink(int $mode): bool
    {
        return ($mode & 0o170000) === 0o120000;
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
     * which path, why, and — load-bearing — that NOTHING THIS PROCESS MEANT TO READ WAS READ,
     * which is what stops a caller spending the refusal as a verdict about the intended
     * file's contents.
     *
     * ⚑ It says what this READER assumes, never what it measured about the directory: whether
     * the parent is owned by another account is not something this class looks up, and a
     * message asserting it would be a claim past its own evidence.
     */
    private static function refusal(string $subject, string $path, string $because): string
    {
        return "{$subject} at {$path} was NOT read: {$because}. This read is taken over a path "
            .'whose directory is controlled by an account other than the one running this process, '
            .'so what the path resolves to is that account\'s choice rather than a fact about the '
            .'file this process meant to read — the contents of the intended file are not a '
            .'conclusion available from this refusal';
    }
}
