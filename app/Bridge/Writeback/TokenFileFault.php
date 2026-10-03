<?php

namespace App\Bridge\Writeback;

/**
 * Why the placed GitHub token FILE (legs 1 + 2 of {@see GitHubTokenResolver}) did not resolve,
 * carried on the {@see TokenResolution} that says so (card#11201).
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
}
