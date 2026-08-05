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
 * THE NEAR-MISS PROBE (card#5310). {@see looksLikeDlToken()} answers "does this
 * text APPEAR to name a DL?", asked only where {@see parse()} already returned
 * null. Until it existed every one of `DL_239` / `DL239` / `DLs-239` published,
 * moved nothing, and told nobody — the same silence DL-234 argued was "as
 * high-value a miss as an unresolvable DL", made about the DL token BY NAME on
 * the one half that never got the fix. Its mechanism is {@see NearMissProbe}'s,
 * bound here to the `DL` stem; the mandatory separator is what makes the glued
 * `DL239` a near-miss here where the card token's glued arm makes it a hit
 * there, and the probe reads that difference off {@see parse()} rather than
 * being told it.
 *
 * `describe()` NOW EXISTS, and the day it was owed is the day the near-miss
 * warning arrived: this class's docblock previously said there was no PHP
 * surface to render into and to add one when there was. That is the caller.
 * The YAML restatement it named is still there and still unrenderable from PHP
 * — `PrTitleLintTest` ties that restatement's ANSWER SET to this class, which
 * remains the strongest tie available across the language boundary.
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
     * `DLs-239` is the ONE plural row (card#5310), naming the family in the
     * operator sentence exactly as `cards #123` does on the card side — the
     * plural is silent by the same mechanism on every stem, and its separator
     * COVERAGE is {@see probeVectors()}'s job, not this list's. The obvious
     * "fix" for the next person is to paste the other spellings in; don't.
     *
     * This is the SENTENCE corpus, and since card#5310 it renders a
     * human-facing log line through {@see describe()}: curated and exemplary on
     * its rejected side, never exhaustive, because the set of strings that do
     * not parse is infinite.
     *
     * The set may GROW; `DlTokenGrammarTest` pins the ratified rows so it
     * cannot shrink below them. It may NOT grow a bare-space spelling
     * (`DL 239`): every consumer asserts warn-iff-not-parsed, and DL-201 ruled
     * that prose stays silent.
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
        'DLs-239',
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

    /**
     * Does this text APPEAR to name a DL? Asked ONLY where {@see parse()}
     * already returned null, so a match means a near-miss: the branch publishes,
     * the card never moves, and without this nobody is told.
     */
    public static function looksLikeDlToken(string $text): bool
    {
        return self::probe()->matches($text);
    }

    /**
     * The probe's derived corpus for this stem — {@see NearMissProbe::vectors()}.
     * Which cells WARN is {@see parse()}'s answer, not this list's: `DL-239` is
     * a cell of the cross-product and it correlates, so it must stay silent.
     *
     * @return list<string>
     */
    public static function probeVectors(): array
    {
        return self::probe()->vectors('239');
    }

    private static function probe(): NearMissProbe
    {
        return new NearMissProbe('DL');
    }

    /**
     * The operator-facing accept-set, derived. Every caller that tells a human
     * which DL spellings correlate MUST render it from here rather than spell it
     * out — the DL-239 ruling, applied to the token that motivated it.
     */
    public static function describe(): string
    {
        return 'accepted: '.implode(', ', self::accepted())
            .' — not accepted: '.implode(', ', self::rejected());
    }
}
