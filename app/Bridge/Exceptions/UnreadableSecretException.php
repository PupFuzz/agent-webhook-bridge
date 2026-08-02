<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when a secret file IS there and this process could not read it — the third
 * outcome of `SecretFile::read` (NAMED, never `{@see}`-linked: pint rewrites a docblock
 * FQCN into a real `use`, and importing `Support` here would invert the layer — this
 * exception sits BELOW the primitive that throws it). It used to be an `ErrorException`
 * nobody documented and four of seven callers never expected (card#5778). The message
 * carries the PATH only, never the secret value.
 *
 * THE FAULT IS UID-RELATIVE, AND THAT IS THE WHOLE REASON IT HAS ITS OWN TYPE.
 * Ownership and mode are relative to the asking process, and the bridge routinely runs
 * as a different OS user than the agent (DL-227's same-box topology): `bridge:check`
 * reads these files as the OPERATOR, the receiver reads them as the web user. So a file
 * THIS process cannot read may be perfectly readable where it actually matters, and a
 * caller that renders this as a verdict about the runtime is asserting past its
 * evidence. It is the same discrimination {@see ChannelTokenFault::NotReadable} draws
 * for the channel token (DL-260) — this is that ruling reaching the shared primitive.
 *
 * NOT the same as a null return. Null means absent-or-blank; this means present and
 * unreadable. Collapsing the two — an `is_readable()` gate that returns null — was
 * considered and rejected: it converts an unreadable secret into a confident "no secret
 * here", which is card#5698's defect minted into the primitive every caller shares.
 */
final class UnreadableSecretException extends RuntimeException {}
