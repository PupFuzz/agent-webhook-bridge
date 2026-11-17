<?php

namespace App\Bridge\Support;

/**
 * The ONE display rule for text an operator configured that may be a pasted credential: a value
 * with a credential's shape is printed as a non-reversible `sha256:` fingerprint, anything else as
 * written. Mirrors the coordination framework's `looks_like_pasted_secret` / `_safe_coordinate`
 * (`coord_credentials.py`) and its credential vocabulary, which the bridge cannot import.
 *
 * Two uses, one shape test:
 *  - {@see displayName()} — a coord credential-store NAME (a `[git-credential-map]` value, a
 *    `[github]` key), where a token pasted as a map value then stands as the key (card#11208);
 *  - {@see displayPathSetting()} — the value of a path-valued SETTING (an `.env` path or a config-file path), where a token pasted instead of a path would otherwise be
 *    printed by the messages that name the settings listed in docs/config-schema.md (card#11261). A message that prints such a
 *    value is meant to go through it; `Tests\Feature\Support\PathSettingDisplayCensusTest` is a
 *    tripwire, not a completeness guarantee: it recognises only the shapes its controls
 *    exercise, which its class docblock names.
 *
 * Its own class rather than `CoordCredentialStore`'s since card#11261: the file-read primitives
 * (`SecretFile`, `ChannelToken`, `FileContents`, `UntrustedPathContents`), the checks,
 * handlers and commands print path settings that have nothing to do with the credential store, and
 * a dependency on the store's class for a rule that is not about the store would mislead.
 *
 * ⚑ ELIDED, NEVER REFUSED, as the framework does: the shape test also matches a legitimate value of
 * 24+ characters with no dot or separator, which is shortened in messages and used as written.
 *
 * ⛔ IT CANNOT HELP A PATH COMPOSED UNDER A PASTED VALUE: `<token>/state/inbox.jsonl` holds a `/`,
 * so it does not have the shape and is printed whole. That is why a DIRECTORY setting is not covered
 * by this rule — `docs/config-schema.md` § *A token pasted where a path belongs* owns that bound.
 */
final class PastedSecretShape
{
    /**
     * The framework's credential vocabulary, row for row: `VENDOR_TOKENS` and `AWS_KEY_ID` in
     * `plugins/coord/_vendor/credential-prefix.py.txt` at agent-board-framework `a38d5eb9`. Each row
     * is a (prefix, body alphabet) pair of Python `re` source, used here verbatim as PCRE. A hand
     * copy until card#13420 replaces it with a check against the framework's pinned published copy.
     */
    public const VENDOR_TOKENS = [
        'gh-token-family' => ['gh[pousr]_', '[A-Za-z0-9]'],
        'gh-fine-grained-pat' => ['github_pat_', '[A-Za-z0-9_]'],
        'gitlab-pat' => ['glpat-', '[A-Za-z0-9_\-]'],
        'slack-token' => ['xox[bp]-', '[A-Za-z0-9_\-]'],
        'sk-api-key' => ['(?:(?<![A-Za-z])|(?<=%[0-9A-Fa-f]{2}))sk-(?=[A-Za-z0-9_\-]{16})', '[A-Za-z0-9_\-]'],
    ];

    public const AWS_KEY_ID = '(?:A3T[A-Z0-9]|AKIA|ASIA|ABIA|ACCA|AGPA|AIDA|AROA|ANPA|ANVA)[0-9A-Z]{16,}(?![0-9A-Z])';

    /**
     * The minimum body length after a vendor prefix; the framework leaves it to the caller. One, not
     * the framework sanitizer's 16: a sanitizer scanning prose needs a long body to avoid false
     * matches, but this value is one configured name or setting, where a prefixed value is elided
     * whatever its length. The `sk-` row carries its own 16-character lookahead regardless.
     */
    private const VENDOR_TOKEN_FLOOR = 1;

    /**
     * One row as a PCRE pattern — the framework's `token_pattern`. Case-insensitive, as the
     * bridge's prefix match always was, so an upper-cased `GHP_` is still elided.
     */
    public static function vendorTokenPattern(string $row): string
    {
        [$prefix, $body] = self::VENDOR_TOKENS[$row];

        return sprintf('~%s%s{%d,}~i', $prefix, $body, self::VENDOR_TOKEN_FLOOR);
    }

    /** A credential-store name as a message may print it — the framework's `_safe_coordinate`. */
    public static function displayName(string $name): string
    {
        return self::elide($name, 'name');
    }

    /**
     * A path-valued setting as a message may print it. An absolute or `~/` path never has the shape
     * (it holds a separator, or starts with `~`), so a real path is printed as written.
     */
    public static function displayPathSetting(string $value): string
    {
        return self::elide($value, 'value');
    }

    /**
     * The framework's `looks_like_pasted_secret`: no separator, no leading `~`, and either a token
     * from the vocabulary above or 24+ characters with no dot.
     */
    public static function looksLikePastedSecret(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || str_starts_with($v, '~') || str_contains($v, '/') || str_contains($v, '\\')) {
            return false;
        }
        foreach (array_keys(self::VENDOR_TOKENS) as $row) {
            if (preg_match(self::vendorTokenPattern($row), $v) === 1) {
                return true;
            }
        }
        if (preg_match('~'.self::AWS_KEY_ID.'~', $v) === 1) {
            return true;
        }

        return strlen($v) >= 24 && ! str_contains($v, '.');
    }

    /** The framework's `pointer_fingerprint`: `sha256:` and 8 hex characters. */
    public static function fingerprint(string $value): string
    {
        return 'sha256:'.substr(hash('sha256', $value), 0, 8);
    }

    private static function elide(string $value, string $noun): string
    {
        return self::looksLikePastedSecret($value)
            ? "<a credential-shaped {$noun}, ".self::fingerprint($value).'>'
            : $value;
    }
}
