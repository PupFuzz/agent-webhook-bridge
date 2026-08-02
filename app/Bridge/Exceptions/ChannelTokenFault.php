<?php

namespace App\Bridge\Exceptions;

/**
 * WHICH of the channel-token contract's rules the read broke — decided at the THROW
 * site, where the read happened, and carried on {@see ChannelTokenException} (card#5698).
 *
 * WHY THE MESSAGE WAS NOT ENOUGH. `bridge:check` reported every fault as one `warn`
 * ending "channel_push will FAIL until fixed", and for two of them that prediction is
 * not the checker's to make: it reads the token as the OPERATOR, while `channel_push`
 * and the writeback alert notifier read it inside the receiver request, as the OS user
 * the receiver runs as. A file that this process cannot read may be perfectly readable
 * there — so a definite verdict off that read is a claim about a runtime this process
 * never observed.
 *
 * SO THE CASES SPLIT ON ONE QUESTION: *is the fault the same for every reader?*
 *
 *   UID-INDEPENDENT — the receiver hits it too, so a definite verdict is EARNED:
 *     {@see self::Missing} · {@see self::InsecurePerms} · {@see self::EmptyFile}
 *   THIS PROCESS ONLY — nothing was established about the receiver's read:
 *     {@see self::NotVisible} · {@see self::NotReadable}
 *
 * THE DISCRIMINATION LIVES HERE AND NOT AT THE CONSUMER. A check re-deriving it from
 * the path afterwards would be a second copy of `ChannelToken`'s rules — the copy the
 * leg's design notes were careful not to create — and it would re-stat a file that can
 * change between the read and the re-derivation, so the two copies could disagree about
 * one throw.
 *
 * THE EXCEPTION MESSAGES ARE DELIBERATELY UNSPLIT. At runtime the reader IS the subject
 * of the message, so "not readable at <path>" is true and useful on all three of the
 * first gate's paths; only the PROXY reader needs the split, and it gets it from this
 * enum rather than from prose.
 */
enum ChannelTokenFault: string
{
    /**
     * Nothing readable is at the path and this process could confirm that: the nearest
     * existing ancestor directory IS traversable, and the path holds no regular file
     * (absent, a dangling symlink, or a directory/socket). Given the traversal, that
     * answer does not depend on who is asking.
     */
    case Missing = 'missing';

    /**
     * A directory above the token denies THIS process traversal, so the read never
     * reached the question. Rendered through the shared not-visible guard.
     */
    case NotVisible = 'not_visible';

    /**
     * A regular file is there and this process cannot read it. Ownership and mode are
     * relative to the asking uid, so this says nothing about the receiver's read.
     */
    case NotReadable = 'not_readable';

    /** Group/world-readable (mode & 0o077). A mode bit — the same for every reader. */
    case InsecurePerms = 'insecure_perms';

    /** Read fine, holds no token after trimming. Content — the same for every reader. */
    case EmptyFile = 'empty';
}
