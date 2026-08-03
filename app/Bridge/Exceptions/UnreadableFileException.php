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
class UnreadableFileException extends RuntimeException {}
