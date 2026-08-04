<?php

namespace App\Bridge\Support;

/**
 * THE `DL-NNN` token grammar: the pattern and the canonical example shapes —
 * one public owner, mirroring {@see CardTokenGrammar} (card#5308 / DL-240).
 *
 * WHY THIS CLASS EXISTS. The card#5267 audit opened every accept-set in the
 * repo individually and this was the one genuine second instance: the pattern
 * was a `private const` on `GitHubPrCardMoveClassifier` (named without `{@see}`
 * deliberately — pint's `fully_qualified_strict_types` turns a docblock FQCN
 * into a real `use`, which would invert Support→Classifiers)
 * with four call sites and no public owner, so the characterization test that
 * had to compare it against the CI lint reached it by REFLECTION — a weaker tie
 * than a public one, and the very shape card#5267 removed on the card side. Its
 * one operator-facing restatement (`.github/workflows/pr-title-lint.yml`) was
 * also NARROWER than the code: it spelled the token `DL-NNNN` at operators and
 * bounded its match at four digits, against this unbounded `\d+`. Being private
 * is what let that drift go unmeasured for three grammar edits.
 *
 * The token: `DL-` followed by one or more ASCII digits, case-insensitive.
 * Leading `\b` only, deliberately NO trailing `\b` — this is the ORIGINAL of
 * that boundary shape (DL-201 / roundtable #48); the card token was widened to
 * match it, not the other way round. `DlTokenGrammarTest` drives one fixture
 * through both grammars so that coupling is executable rather than asserted in
 * two docblocks.
 *
 * Unlike the card token there is NO glued arm and NO digit-count floor: the
 * separator is mandatory (`DL239` names nothing) and a single digit is legal.
 * The digit class stays ASCII (no `/u`) — DL-231, ratified fleet-wide.
 *
 * NO `describe()` HERE, deliberately. The card token has one because a runtime
 * near-miss warning tells operators which spellings correlate; the DL token has
 * no such PHP surface, so a renderer with only a test caller would be a
 * decoration. Its sole operator-facing restatement lives in YAML, which no PHP
 * function can render into — `PrTitleLintTest` ties that restatement's ANSWER
 * SET to this class instead, which is the strongest tie available across the
 * language boundary. Add `describe()` the day a PHP surface needs it.
 */
final class DlTokenGrammar
{
    private const PATTERN = '/\bDL-(\d+)/i';

    /**
     * The canonical example shapes — examples only, never a verdict: which side
     * each lands on is decided by {@see self::PATTERN} at evaluation time, so
     * this list carries no copy of the accept-set that could disagree with it.
     *
     * `DL-12345` is here because it is the measured divergence between this
     * grammar and the CI lint's four-digit bound (card#5300, a hard gate):
     * it is not a hypothetical, and keeping it in the set is what makes the
     * divergence re-measured on every run rather than remembered. `DL-1` sits
     * beside it as the no-floor case, and `DL239` / `DL_239` as the separator
     * near-misses. `IDL-239` is the leading-boundary probe, `DL-239_fix` the
     * no-trailing-boundary one (the DL-201 ruling). The Unicode-digit spelling
     * is here because DL-231 made it correlate to nothing, silently.
     *
     * The set may GROW; `DlTokenGrammarTest` pins the ratified rows so it
     * cannot shrink below them.
     *
     * @var list<string>
     */
    public const VECTORS = [
        'DL-239',
        'dl-239',
        'DL-0239',
        'DL-1',
        'DL-12345',
        'DL-239_fix',
        'DL239',
        'DL_239',
        'IDL-239',
        'DL-',
        "DL-\u{0663}",
    ];

    /**
     * The FIRST `DL-NNN` token in the text, normalized to an uppercase `DL-`
     * prefix with the digits verbatim (leading zeros preserved — the board's
     * `dl_number` is canonicalized at the stamp site, not here), or null when
     * no token parses.
     */
    public static function parse(string $text): ?string
    {
        return preg_match(self::PATTERN, $text, $m) === 1 ? 'DL-'.$m[1] : null;
    }

    /**
     * The token when EXACTLY ONE appears in the text, else null — the
     * sole-DL predicate the stamping paths key on. A text carrying 2+ DLs is
     * bundled / release-shaped, so its DL is foreign to any one card and must
     * never be stamped (card#4852); this is that distinction, owned once
     * rather than re-spelled as a `preg_match_all(...) === 1` at each site.
     */
    public static function sole(string $text): ?string
    {
        return preg_match_all(self::PATTERN, $text, $m) === 1 ? 'DL-'.$m[1][0] : null;
    }

    /** @return list<string> */
    public static function accepted(): array
    {
        return array_values(array_filter(self::VECTORS, fn (string $v) => self::parse($v) !== null));
    }

    /** @return list<string> */
    public static function rejected(): array
    {
        return array_values(array_filter(self::VECTORS, fn (string $v) => self::parse($v) === null));
    }
}
