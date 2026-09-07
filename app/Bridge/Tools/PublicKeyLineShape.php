<?php

namespace App\Bridge\Tools;

/**
 * Is a string exactly ONE well-formed `authorized_keys` PUBLIC-KEY line?
 *
 * ⛔ THIS IS A LOCKSTEP COPY AND THE PYTHON IS THE OWNER. `bin/provision-board-tools.py`
 * `is_authorized_key_shape()` is the definition — it is what actually decides what gets
 * pinned — and this class exists only because `bridge:provision-tools --pubkey-from`
 * must refuse the same strings BEFORE it renders a packet telling an operator to pin the
 * file. Two implementations of one rule is a defect (they drift), so the drift is
 * GUARDED rather than tolerated: a test reads `_KEY_TYPES` and `_KEY_LINE_RE` out of the
 * python source and reds if this file disagrees. Change the python first, then this.
 *
 * ⚠ NOT {@see AuthorizedKeysLine}, and the difference is not stylistic. That parser
 * reads lines that are ALREADY IN a file, so its key-type test is a deliberately loose
 * prefix regex — it must classify whatever sshd already accepted. This one decides
 * whether a string a stranger sent may become such a line, so it is a strict POSITIVE
 * allowlist: unknown type, non-base64 blob, or any CR/LF (a two-line paste whose second
 * line would land as an UNRESTRICTED key) is refused.
 */
final class PublicKeyLineShape
{
    /**
     * The accepted key types, in the python's own order so the lockstep test compares
     * two lists rather than two sets.
     *
     * @var list<string>
     */
    public const KEY_TYPES = [
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'ssh-ed25519',
        'ssh-rsa',
        'sk-ecdsa-sha2-nistp256@openssh.com',
        'sk-ssh-ed25519@openssh.com',
    ];

    /**
     * Everything after the key type: one space, the base64 blob, an optional comment.
     * Held as its own constant because it is the half the lockstep test compares
     * CHARACTER FOR CHARACTER against the python regex's literal tail.
     */
    public const BODY_PATTERN = ' [A-Za-z0-9+/]+={0,2}(?: .*)?';

    public static function isSingleAuthorizedKeyLine(string $candidate): bool
    {
        // PCRE's `.` excludes LF but NOT CR, exactly as python's does, so this reject is
        // load-bearing rather than a belt on the anchors: a CR would otherwise ride into
        // the optional comment.
        if (str_contains($candidate, "\r") || str_contains($candidate, "\n")) {
            return false;
        }

        return preg_match(self::pattern(), $candidate) === 1;
    }

    /** The full anchored pattern — `\A`/`\z`, so it is python's `fullmatch`. */
    public static function pattern(): string
    {
        $types = implode('|', array_map(
            static fn (string $t): string => preg_quote($t, '/'),
            self::KEY_TYPES,
        ));

        return '/\A(?:'.$types.')'.str_replace('/', '\/', self::BODY_PATTERN).'\z/';
    }
}
