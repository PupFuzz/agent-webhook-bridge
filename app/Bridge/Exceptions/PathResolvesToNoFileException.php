<?php

namespace App\Bridge\Exceptions;

/**
 * The refusal that is a MEASUREMENT: the path was looked at, and what it names cannot hold
 * file content — a directory, a FIFO, a socket, a device, or a symlink to any of those or to
 * nothing at all. Raised by `UntrustedPathContents::read` only (NAMED, never `{@see}`-linked:
 * pint rewrites a docblock FQCN into a real `use`, and importing `Support` here would invert
 * the layer — this exception sits BELOW the primitive that throws it).
 *
 * ⭐ WHY IT IS A SUBTYPE OF {@see UnreadableFileException} AND NOT A SIBLING — the inheritance
 * direction is the safety property, not a taxonomy preference. Every existing catcher of the
 * parent keeps catching this and treats it as "could not look", which UNDERSTATES what was
 * established and is the fails-safe direction: a caller that has never heard of this type
 * withholds a verdict it was entitled to draw, rather than drawing one it was not. Only a
 * caller that has a use for the stronger fact catches this FIRST and spends it.
 *
 * ⛔ WHAT IT DOES AND DOES NOT ESTABLISH. It says: a reader FOLLOWING THIS PATH gets no bytes,
 * and that was measured rather than assumed. It does NOT say the path is empty, that nothing
 * is there, or that the intended file was never created — the account owning the directory
 * can put a regular file at that name a moment later, exactly as it can create a file that was
 * absent. It is the same standing as an absent path, reached by measurement rather than by a
 * failed `lstat`, and a caller that already models "absent" as an ANSWER (rather than as a
 * missing one) wants this on that arm.
 *
 * ⚠ IT IS NOT A LICENCE TO ASSERT ABSENCE OFF A DENIAL. The producer only raises it where the
 * type it names was positively measured; a path whose resolution this process could not
 * complete raises the plain parent instead, because a stat that failed cannot tell a missing
 * target from a directory it may not traverse (card#5698's class). If you are adding a new
 * producer, that asymmetry is the whole contract: positive evidence for this type, the parent
 * for everything else.
 */
class PathResolvesToNoFileException extends UnreadableFileException {}
