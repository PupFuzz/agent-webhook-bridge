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
 *  - **ESTABLISHING** — the path names a directory, FIFO, socket or device, or a symlink
 *    whose chain resolves to NO BYTES EVER — because it ends at a target measured absent,
 *    or because it LOOPS (a self-symlink, a mutual pair, or any chain the kernel itself
 *    would refuse with `ELOOP`). No reader following this path gets bytes, at any uid,
 *    under any permission, and that is a FACT this run established. Raised as
 *    {@see PathResolvesToNoFileException}.
 *  - **WITHHOLDING** — a symlink to a regular file (we decline to attribute those bytes to
 *    the account), a chain this process could not fully resolve (an ancestor along it may
 *    deny traversal), an identity that changed under the open, an unmeasurable descriptor,
 *    or a file past the size bound. Nothing was established. Raised as the plain
 *    {@see UnreadableFileException}.
 * ⚠ THE ESTABLISHING SET IS A SUBSET OF `is_file() === false`, and deliberately not its
 * equal in either direction. It is never WIDER: by construction {@see self::nonRegularRefusal()}
 * only ever calls something ESTABLISHING after failing to find a regular file at the end of
 * the chain, so nothing this class establishes was ever a path `is_file()` would have called
 * true — migrating a reader onto this class mints no new false FAIL over what `is_file()`
 * used to certify. It is also not NARROWER by accident: `is_file()` itself commits
 * card#5698's error for one shape — a chain blocked by an ancestor this process may not
 * traverse reads `is_file()`-false (a confident "not a file"), and this class WITHHOLDS it
 * instead, deliberately, because "cannot look" is not "confirmed absent" no matter what the
 * older predicate concluded. Pinned as a SET property, not per-shape, so a shape neither
 * side has been tested against yet cannot silently violate it.
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
     * that in ONE syscall, because `stat()` itself fully dereferences the chain; it is a stat
     * and never an open, so it neither reads the target nor blocks on one.
     *
     * ⛔ A FAILED `stat()` IS NOT EVIDENCE OF ABSENCE (card#5698's whole rule) — but it is
     * not evidence of a LOOP either, and conflating "stat failed" with "the immediate target
     * is absent" is exactly the bug card#9037 r2 found: `stat()` fails identically for a
     * dangling chain, an `ELOOP` chain, and a chain blocked by a directory this process may
     * not traverse, and a check that only re-tries the FIRST hop answers "cannot confirm" for
     * all three — including the two that a kernel-following `is_file()` would have reported
     * false for, which is a refusal this reader must not soften into `unvalidated`.
     * {@see self::symlinkChainVerdict()} walks the WHOLE chain, hop by hop, exactly as the
     * kernel does, so a loop and a multi-hop absence are each confirmed on their own terms —
     * and a chain blocked by traversal still withholds, unchanged.
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

        return match (self::symlinkChainVerdict($path)) {
            self::CHAIN_LOOP => new PathResolvesToNoFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link whose chain LOOPS back on itself rather than '
                .'resolving (the same condition a kernel open() refuses with ELOOP), so nothing '
                .'following this path ever reads any bytes from it'
            )),
            self::CHAIN_ABSENT => new PathResolvesToNoFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link whose chain ends at a target that does not exist, '
                .'so nothing following this path reads any bytes from it'
            )),
            default => new UnreadableFileException(self::refusal(
                $subject, $path,
                'the path is a symbolic link this process could not fully resolve — a directory '
                .'along the chain may not be traversable, or the chain may exceed '
                .self::MAX_SYMLINK_HOPS.' hops without this walk confirming either an absence or a loop'
            )),
        };
    }

    /**
     * The most hops this walk follows before treating an unresolved chain as a LOOP rather
     * than merely long — the Linux kernel's own bound (`MAXSYMLINKS` / `SYMLOOP_MAX`, enforced
     * in `namei.c` since 2.6.18). A chain that has not resolved in this many hops is a chain
     * `open()` itself would refuse with `ELOOP` on this host, for ANY caller — so treating it
     * as ESTABLISHING here is not a guess, it is the same fact the kernel would report.
     */
    private const MAX_SYMLINK_HOPS = 40;

    private const CHAIN_LOOP = 'loop';

    private const CHAIN_ABSENT = 'absent';

    private const CHAIN_UNRESOLVABLE = 'unresolvable';

    /**
     * card#9037 r2 — ONE HOP WAS NOT ENOUGH. `ln -s authorized_keys authorized_keys`
     * (self-`ELOOP`), a two-file A↔B loop, and a target reached only through a SECOND
     * symlink (`hop1 → hop2 → nowhere`) all defeated the single `readlink` + `lstat` this
     * replaces: each one's IMMEDIATE target EXISTS (it is another symlink), so a check that
     * inspects only that one hop answers "cannot confirm" and WITHHOLDS — the exact
     * suppression this class exists to close, one `ln -s` away from the `mkdir` r1 closed.
     * `is_file()` is false for every one of these (a kernel-following predicate correctly
     * refuses to certify a chain it cannot resolve either), so `origin/dev` reported the
     * earned FAIL; withholding here would have been a REGRESSION this branch introduced, not
     * an inherited residual — caught before merge rather than shipped.
     *
     * ⭐ A LOOP AND A MULTI-HOP ABSENCE ARE BOTH ESTABLISHING, ON THE SAME GROUND AS THE
     * SINGLE-HOP CASE: no follower of this path — at any uid, under any permission — EVER
     * gets bytes from it. A loop is not "we could not attribute the bytes" (the accepted cost
     * for a symlink to a regular file, where bytes genuinely exist and are genuinely
     * reachable); it is "there are no bytes to attribute", a stronger and different claim,
     * and the kernel itself is the authority for it.
     *
     * ⛔ THE ASYMMETRY THAT CLOSED card#5698 IS PRESERVED, NOT WIDENED — this walks toward a
     * POSITIVE confirmation only, never infers one from a failure it cannot attribute. A hop
     * this process cannot even `lstat` because an ANCESTOR directory denies traversal reaches
     * {@see self::CHAIN_UNRESOLVABLE} exactly as the single-hop check withheld before —
     * `PathVisibility::ancestorIsTraversable()` is consulted at the SAME hop the old check
     * consulted it at, just carried forward through however many hops the chain has. The walk
     * adds two more ESTABLISHING shapes; it does not touch what "cannot confirm" means.
     *
     * ⭐ EACH HOP RESOLVES A RELATIVE TARGET AGAINST THE DIRECTORY CONTAINING **THAT HOP'S
     * OWN LINK** — never against the original `$path`. `hop1 → hop2` (relative) and
     * `hop2 → nowhere` (relative) both resolve against the one directory both links happen to
     * share in the deterministic reproduction, but nothing here assumes they share one: a
     * chain crossing directories joins each `readlink()` against `dirname()` of the link that
     * carried it, which is the only join a relative symlink target is ever specified against.
     *
     * ⭐ TERMINATION IS GUARANTEED BY THE HOP CAP ALONE, independent of whether this walk's
     * own repeat-detection (a literal path string seen twice) fires first. A cycle built with
     * `..` segments could in principle present a growing string that never repeats by literal
     * comparison before the cap — the cap still ends the walk at {@see self::CHAIN_LOOP}, and
     * correctly: a chain that has not resolved in `MAX_SYMLINK_HOPS` hops is one the kernel
     * itself would refuse for every caller, looped or merely long, so classifying it
     * ESTABLISHING misclaims nothing.
     * ⚑ MEASURED, not assumed: deleting the repeat check outright (`$visited`, kept only
     * for early exit) leaves every test in this tree green, because a self-symlink and an
     * A↔B pair both still terminate at `self::CHAIN_LOOP` via the cap alone, just 38 hops
     * later. The repeat check has no red-once witness for the same reason the `dev`/`ino`
     * race guard elsewhere in this class does not — it is disclosed rather than removed,
     * because it is what keeps a genuinely long (not looping) chain from being walked to
     * `MAX_SYMLINK_HOPS` on every single call instead of returning at its own repeat.
     */
    private static function symlinkChainVerdict(string $path): string
    {
        $current = $path;
        $visited = [];
        for ($hop = 0; $hop < self::MAX_SYMLINK_HOPS; $hop++) {
            if (isset($visited[$current])) {
                return self::CHAIN_LOOP;
            }
            $visited[$current] = true;

            $lstat = @lstat($current);
            if ($lstat === false) {
                // The chain ends here — nothing answers to this name. The SAME guard the
                // single-hop check used decides whether that is a confirmed absence or an
                // unmeasurable one: an ancestor this process cannot traverse makes "absent" a
                // conclusion it is not entitled to draw (card#5698), at any hop.
                return PathVisibility::ancestorIsTraversable($current)
                    ? self::CHAIN_ABSENT
                    : self::CHAIN_UNRESOLVABLE;
            }
            if (! self::isLink($lstat['mode'])) {
                // Resolved to something real with no loop and no absence. Not reachable
                // given the `@stat($path) === false` precondition the caller already
                // established (a kernel `stat()` would have found this same node), but
                // handled rather than assumed impossible: nothing here supports an
                // ESTABLISHING claim, so withhold.
                return self::CHAIN_UNRESOLVABLE;
            }

            $raw = @readlink($current);
            if (! is_string($raw)) {
                return self::CHAIN_UNRESOLVABLE;
            }
            $current = str_starts_with($raw, '/') ? $raw : dirname($current).'/'.$raw;
        }

        // The cap itself IS the kernel's own ELOOP bound (see the constant's docblock) — a
        // chain that has not resolved in MAX_SYMLINK_HOPS hops loops, whether or not this
        // walk's own string-based repeat detection happened to catch it sooner.
        return self::CHAIN_LOOP;
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
