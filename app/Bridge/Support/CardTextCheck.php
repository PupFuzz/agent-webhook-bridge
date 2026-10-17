<?php

namespace App\Bridge\Support;

/**
 * The plain-language card-text checks the board tools apply, over the VENDORED rule file
 * ({@see CardTextRules}). This is the surface `docs/card-text-parity-corpus.json` publishes and the
 * toolkit end compares against `kbcard title-check` / `description-check`: each answers the text
 * kbcard would print without its `kbcard: ` prefix — `$verb` and `$tail` are the caller's — or null
 * when the text passes.
 *
 * The two constants pin WHICH rule file is vendored: {@see CardTextRules::VENDORED_PATH} is a
 * byte-for-byte copy of the toolkit's `bin/_card-text-rules.json` at SOURCE_COMMIT, whose sha256 is
 * SOURCE_SHA256. Never edit the copy here: re-vendor, update both, re-run `bin/card-text-parity.sh`.
 */
final class CardTextCheck
{
    public const SOURCE_COMMIT = 'c4c7fa65f9be9d2e7f898983cfe0fed9087dd360';

    public const SOURCE_SHA256 = 'fa825cb983a79f244075600e934aab19c2b13d9e61a9f8cbfe71ad43a75fc4e8';

    public static function title(string $title, string $verb, string $tail): ?string
    {
        return CardTextRules::vendored()->titleRefusal($title, $verb, $tail);
    }

    public static function description(string $description, string $verb, string $tail): ?string
    {
        return CardTextRules::vendored()->descriptionRefusal($description, $verb, $tail);
    }
}
