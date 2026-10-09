<?php

namespace App\Bridge\Writeback;

/**
 * Why a GitHub token FILE did not resolve — whichever source named it ({@see TokenSource}) —
 * carried on the {@see TokenResolution} that says so (card#11201, DL-456).
 *
 * A TYPE, NOT A PARSE OF THE PROBLEM TEXT. `bridge:check`'s token-file leg must say different
 * things for these: three are facts about the file that hold for every reader, so a leg whose
 * consumers need the file is proven inert; one is a fact about THIS process only. A check that
 * re-read the file to tell them apart would be a second classification of one read, free to
 * disagree with the one the receiver acts on.
 */
enum TokenFileFault
{
    /** Nothing at the path, as far as this process can see — an untraversable ancestor reads the same way. */
    case Absent;

    /** A file is there and holds only whitespace. */
    case Empty;

    /** Something other than a regular file is at the path (a directory, a socket). */
    case NotAFile;

    /** Group/world-readable. Every reader refuses it, the receiver included (DL-010). */
    case InsecurePermissions;

    /** A file is there and THIS process could not read it — no claim about any other user. */
    case Unreadable;

    /**
     * What NAMES the file is wrong for every reader, so there is no file to read: the coord
     * credential store is unparseable or outside the shape the bridge reads, its setting is unset or
     * relative, or the store maps the repo to a key with no usable `<key>_file` pointer (DL-456). A
     * `~` pointer this process could not expand is {@see self::Undetermined}, not this.
     */
    case Misconfigured;

    /**
     * A source that takes precedence could not be read by this process, so which file applies was
     * not determined — `writeback.json` did not load (it may declare a `write_token_path`), or the
     * store's owner could not be identified to expand its `~` (DL-456). Nothing is resolved rather
     * than a lower source standing in.
     */
    case Undetermined;
}
