<?php

namespace App\Bridge\Support;

/**
 * THE card-token grammar: the pattern, the canonical example shapes, and the
 * operator-facing accept-set sentence — one owner, with the sentence DERIVED
 * from the pattern rather than restated beside it (card#5267 / DL-239).
 *
 * WHY THIS CLASS EXISTS. The grammar has moved three times (DL-201, DL-231,
 * DL-233) and every move left prose behind: after DL-233 widened the token to
 * the glued spelling, the near-miss WARN string still told operators the
 * accepted set was `card-<id>` or `card#<id>` — narrower than what the code
 * enforced, live in the authority, and read by humans and by the next agent
 * writing an emulator (one built an orientation paragraph from a downstream
 * copy of it and asserted that glued `card5086` does not parse). A restatement
 * cannot be kept in lockstep by discipline; {@see self::describe()} renders the
 * sentence by RUNNING {@see self::PATTERN} over {@see self::VECTORS}, so the
 * next grammar move rewrites the operator-facing text by construction.
 *
 * The token: `card-<id>`, `card#<id>`, or the GLUED `card<id>`, case-insensitive.
 * DL-shaped boundary — leading `\b` only, deliberately NO trailing `\b`,
 * mirroring the DL regex (DL-201 / roundtable #48): a trailing `\b` made
 * `card#3054_fix` a SILENT no-op (`_` is a word char, so `\b` never matches
 * digit→`_`) while `DL-200_fix` was immune to the identical input.
 * Greedy-and-loud beats strict-and-silent: a wrong-but-parsed id fails at the
 * card lookup with a warn; an unparsed token fails silently.
 *
 * THE GLUED ARM (DL-233 / roundtable #159) requires **≥2 digits**, while a
 * SEPARATED token still accepts one. That asymmetry is the toolkit's, adopted
 * rather than invented: `board-card-start` has accepted glued since v0.17.0 at
 * `0*[0-9]{2,}`, and the 2-digit floor is what keeps an ordinary word from
 * correlating — `card2go` names no card, but `card-3` legitimately does. The
 * branch-reset `(?|…)` gives both arms the SAME capture group.
 *
 * The digit class stays ASCII (no `/u`) — DL-231, ratified fleet-wide.
 */
final class CardTokenGrammar
{
    private const PATTERN = '/\bcard(?|[-#](\d+)|(\d{2,}))/i';

    /**
     * The canonical example shapes the operator-facing sentence is built from —
     * examples only, never a verdict: which side of the sentence each lands on
     * is decided by {@see self::PATTERN} at render time, so this list carries no
     * copy of the accept-set that could disagree with it.
     *
     * `card4` (single-digit glued) is here because DL-233 CREATED that near-miss
     * and no prose site named it for two releases. The Unicode-digit spelling is
     * here because DL-231 made it correlate to nothing, silently.
     *
     * The set may GROW; `CardTokenGrammarTest` pins the ratified rows so it
     * cannot shrink below them.
     */
    public const VECTORS = [
        'card-123',
        'card#123',
        'card123',
        'card4',
        'card_123',
        'card.123',
        'card:123',
        'card #123',
        "card#\u{0663}",
    ];

    /** The card id this text names, or null when no token parses. */
    public static function parse(string $text): ?int
    {
        return preg_match(self::PATTERN, $text, $m) === 1 ? (int) $m[1] : null;
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
     * The operator-facing accept-set, derived. Every caller that tells a human
     * what correlates MUST render it from here rather than spell it out.
     */
    public static function describe(): string
    {
        return 'accepted: '.implode(', ', self::accepted())
            .' — not accepted: '.implode(', ', self::rejected());
    }
}
