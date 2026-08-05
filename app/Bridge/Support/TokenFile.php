<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Exceptions\UnreadableSecretException;

/**
 * Read a secret token from a file: the single trim/non-empty primitive shared
 * by every token reader (the API token in bridge:provision and the channel auth
 * token), so the "is it empty / how is it trimmed" edge can't drift between
 * call sites. Callers layer their own policy on the null return (skip vs throw)
 * and any perms enforcement (see ChannelToken).
 *
 * The absent-vs-unreadable discrimination is NOT owned here — it is {@see FileContents},
 * which this layers trimming and the secret subtype onto (card#5789). What stays here is
 * only what is specific to a token: the trim, the blank-is-absent rule, and the type.
 */
final class TokenFile
{
    /**
     * The trimmed token; null when the file is absent or blank; throws
     * {@see UnreadableSecretException} when a file IS there and this process could not
     * read it.
     *
     * The reasoning for why that third outcome is a type at all — and why the open's own
     * return value is the authority rather than an `is_readable()` gate — lives on
     * {@see FileContents} and on the exception. Do not restate it here; there were two
     * copies of this read and that is the defect card#5789 closed.
     */
    public static function readTrimmed(string $path): ?string
    {
        try {
            $raw = FileContents::read($path, 'secret file');
        } catch (UnreadableFileException $e) {
            // Re-typed rather than allowed to escape: six callers are written against the
            // secret subtype and must not start catching an unreadable config file too.
            throw new UnreadableSecretException($e->getMessage(), previous: $e);
        }
        if ($raw === null) {
            return null;
        }
        $token = trim($raw);

        return $token !== '' ? $token : null;
    }
}
