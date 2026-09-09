<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when a file IS there and this process could not read it — the third outcome of
 * `FileContents::read` (NAMED, never `{@see}`-linked: pint rewrites a docblock FQCN into a
 * real `use`, and importing `Support` here would invert the layer — this exception sits
 * BELOW the primitive that throws it).
 *
 * THE FAULT IS UID-RELATIVE, AND THAT IS THE WHOLE REASON IT HAS A TYPE AT ALL.
 * Ownership and mode are relative to the asking process, and the bridge routinely runs as a
 * different OS user than the agent (DL-227's same-box topology): `bridge:check` reads these
 * files as the OPERATOR, the receiver reads them as the web user. So a file THIS process
 * cannot read may be perfectly readable where it actually matters, and a caller that renders
 * this as a verdict about the runtime is asserting past its evidence.
 *
 * NOT the same as a null return. Null means absent; this means present and unreadable.
 * Collapsing the two — an `is_readable()` gate that returns null — was considered and
 * rejected: it converts an unreadable file into a confident "nothing here", which is
 * card#5698's assert-absence-off-a-permission-denial defect minted into the primitive that
 * every reader shares.
 *
 * CATCH THIS TYPE ONLY WHERE THE SUBJECT GENUINELY IS ANY FILE. A secret reader that widens
 * to it loses the ability to tell a token fault from a config one — that is what
 * {@see UnreadableSecretException} is for, and why the subtype was kept rather than collapsed
 * into this one (card#5789).
 */
class UnreadableFileException extends RuntimeException
{
    /**
     * THE PERMISSIONS-FAULT SENTENCE, owned here rather than at the throw sites — hoisted at
     * its SECOND producer (card#9037). Two readers now raise this state (the ordinary one and
     * the root-safe one for a path a lower-trust account controls), the wording is
     * operator-facing and load-bearing — it says the file WAS THERE, so the operator looks at
     * permissions and not at provisioning, and it says the reading is uid-relative — and two
     * copies of one sentence are a sentence free to drift on one of them.
     *
     * `new self`, not `new static`: the ONE subtype re-types by wrapping this exception's
     * MESSAGE (`TokenFile` — NAMED, not `{@see}`-linked, for the layer reason above), so no
     * subtype calls this factory and a `static` here would only be an unenforceable promise
     * about constructors this class does not own.
     *
     * @param  string  $subject  the noun the operator would recognize, which is not always
     *                           the basename of $path.
     */
    public static function permissionsFault(string $subject, string $path): self
    {
        return new self(
            "{$subject} at {$path} could not be read by this process (a regular file was "
            .'found at the path, so this is a permissions fault rather than an absence) — '
            .'ownership and mode are relative to the asking user, so another OS user may read it fine'
        );
    }
}
