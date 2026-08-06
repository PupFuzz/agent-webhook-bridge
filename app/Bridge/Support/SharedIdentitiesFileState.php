<?php

namespace App\Bridge\Support;

/**
 * The four states one read of `shared-identities.json` can end in — the discriminant of
 * {@see SharedIdentitiesFile}.
 *
 * THE THREE NON-PARSED CASES ARE NOT ONE STATE (DL-259). The loader that answers a
 * `list<SharedIdentity>` answers `[]` for all three, which is correct for the runtime
 * (fail-soft — the receiver must not 5xx over an optional policy file) and is exactly
 * what let `bridge:check` report a green `0 shared account(s)` over two real faults.
 * Keeping the discrimination on the read's own result is what lets one reader serve both
 * postures.
 */
enum SharedIdentitiesFileState
{
    /** No file at the path. The common install: nothing shares an account. */
    case Absent;
    /** A regular file is there and THIS process could not open it — nothing was measured. */
    case Unreadable;
    /** The bytes were read and are not a JSON object — a measured fault. */
    case Malformed;
    /** Parsed: {@see SharedIdentitiesFile::$identities} is the answer, and may legitimately be empty. */
    case Parsed;
}
