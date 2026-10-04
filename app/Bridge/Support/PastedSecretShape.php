<?php

namespace App\Bridge\Support;

/**
 * The ONE display rule for text an operator configured that may be a pasted credential: a value
 * with a credential's shape is printed as a non-reversible `sha256:` fingerprint, anything else as
 * written. Mirrors the coordination framework's `looks_like_pasted_secret` / `_safe_coordinate`
 * (`coord_credentials.py`), which the bridge cannot import.
 *
 * Two uses, one shape test:
 *  - {@see displayName()} — a coord credential-store NAME (a `[git-credential-map]` value, a
 *    `[github]` key), where a token pasted as a map value then stands as the key (card#11208);
 *  - {@see displayPathSetting()} — the value of a path-valued SETTING (an `.env` path or a config-file path), where a token pasted instead of a path would otherwise be
 *    printed by every message naming the setting (card#11261). A message that prints such a
 *    value is meant to go through it; `Tests\Feature\Support\PathSettingDisplayCensusTest` is a
 *    tripwire for the shapes listed in its class docblock, not a completeness guarantee.
 *
 * Its own class rather than `CoordCredentialStore`'s since card#11261: the file-read primitives
 * (`SecretFile`, `ChannelToken`, `UnreadableFileException`, `UntrustedPathContents`), the checks,
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
     * The framework's `TOKEN_PREFIXES` (`coord_credentials.py`): the credential formats its store
     * holds. Mirrored, not imported.
     */
    private const TOKEN_PREFIXES = ['ghp_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'github_pat_', 'glpat-', 'xoxb-', 'xoxp-'];

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
     * The framework's `looks_like_pasted_secret`, line for line: no separator, no leading `~`, and
     * either a known token prefix or 24+ characters with no dot.
     */
    public static function looksLikePastedSecret(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || str_starts_with($v, '~') || str_contains($v, '/') || str_contains($v, '\\')) {
            return false;
        }
        foreach (self::TOKEN_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($v), $prefix)) {
                return true;
            }
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
