<?php

namespace App\Bridge\Tools;

/**
 * ONE READ of ONE `authorized_keys` file, in the three states the pinned-line probe has to
 * tell apart (card#8976).
 *
 * ⛔ WHY THE THIRD STATE EXISTS. A `?string` collapsed "there is no file here" and "this
 * process could not look" into one null, and the caller spends that null on a claim where
 * the two are OPPOSITES — the authoritative *not wired* FAIL is a claim about EVERY file
 * sshd consults for the account:
 *   - a file that IS NOT THERE supplies sshd no keys. It WAS consulted, it contributes
 *     nothing, and the absence it leaves is ESTABLISHED — so the FAIL is earned.
 *   - a file this process COULD NOT LOOK AT establishes nothing: the pinned line may be in
 *     exactly that one, so the leg owes `unvalidated`.
 * Collapsed, the second reading won everywhere, and the OpenSSH DEFAULT is two files of
 * which `.ssh/authorized_keys2` is absent on essentially every host — so the exit-code
 * bearing FAIL was unreachable on the default install and a genuinely unwired agent exited
 * 0 under `sudo bridge:check`.
 *
 * IT IS THE READ PRIMITIVE'S DISCRIMINATION, carried as a VALUE rather than raised as a type.
 * (Those primitives are NAMED, never `{@see}`-linked: pint rewrites a docblock FQCN into a
 * real `use`, and this value object must not import the reader that happens to produce it.)
 * `FileContents` first drew the absent/unreadable line; since card#9037 the reader behind
 * {@see SystemSshProbeEnvironment::readAuthorizedKeys()} is `UntrustedPathContents`, which
 * draws the same line and splits the refusal in two — a path that RESOLVES TO NO FILE (a
 * directory, FIFO, socket, device or dangling symlink) is measured and lands on
 * {@see self::absent()}, because sshd takes no keys from it exactly as it takes none from a
 * path with no file; only a refusal that established nothing lands on
 * {@see self::unreadable()}. Either primitive signals its unreadable state by THROWING,
 * which every other adopter wants and this seam cannot have:
 * {@see SshProbeEnvironment} is a fails-safe seam whose every leg answers with a value and
 * never throws, so a throw would be one exception each of five implementations had to
 * re-derive a contract for.
 *
 * The fourth combination — text this run never consulted — is UNCONSTRUCTIBLE: the
 * constructor is private and the three factories below are the only doors.
 */
final class AuthorizedKeysRead
{
    private function __construct(
        /** The file's text, or null when this run holds no text for the path. */
        public readonly ?string $text,
        /** Whether this run ANSWERED "what keys does sshd take from this path?" at all. */
        public readonly bool $consulted,
    ) {}

    /** The file was read. An EMPTY file is this state, not {@see self::absent()}. */
    public static function text(string $text): self
    {
        return new self($text, true);
    }

    /**
     * An ANSWER of "no keys from here", never a missing answer — no file at the path, and
     * equally a path that RESOLVES to no file (a directory, FIFO, socket, device or dangling
     * symlink). sshd takes no keys from any of them, so the absence they leave is
     * ESTABLISHED and the authoritative FAIL drawn over it is earned (card#9037).
     */
    public static function absent(): self
    {
        return new self(null, true);
    }

    /**
     * This process could not look — no traversal to the path, or an open it was refused.
     * ⛔ Nothing was measured, so absence is not a conclusion available from it.
     *
     * THE TWO CAUSES ARE DELIBERATELY COLLAPSED, as {@see SshProbeEnvironment::uidForUser()}
     * collapses its own two non-answers and for the same reason — the consumer: the probe
     * names every unconsulted path in one list and takes one `unvalidated` arm for all of
     * them, so a fourth state would be a distinction no caller could spend.
     */
    public static function unreadable(): self
    {
        return new self(null, false);
    }
}
