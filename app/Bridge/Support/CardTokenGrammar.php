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
 * mirroring {@see DlTokenGrammar} (DL-201 / roundtable #48): a trailing `\b` made
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
 *
 * THE SECOND GRAMMAR (DL-250). {@see looksLikeCardToken()} is the near-miss
 * probe: "does this text APPEAR to name a card?", asked only where
 * {@see parse()} already returned null. It is deliberately NOT derivable from
 * {@see self::PATTERN} — the near-miss set is a different set. Its MECHANISM
 * (the separator data, the assembled pattern, the derived corpus) is
 * {@see NearMissProbe}'s, bound here to the `card` stem: DL-250 put it on this
 * class when cards were its only stem, and card#5310 moved it out rather than
 * mint a second copy inside `DlTokenGrammar` — the restatement shape DL-250
 * exists to remove. What stays here is what is genuinely card-specific: the
 * accept-set, and the stem this grammar probes for. Ask
 * {@see looksLikeCardToken()} / {@see probeVectors()}; never recompile the
 * separators into a second pattern.
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
     * and no prose site named it for two releases. It sits beside `card-3`
     * deliberately: accepted-at-one-digit-separated next to
     * rejected-at-one-digit-glued SHOWS the ≥2-digit floor, where prose would
     * have to assert it. The Unicode-digit spelling is here because DL-231 made
     * it correlate to nothing, silently.
     *
     * This is the SENTENCE corpus: curated, judgment-bearing, and exemplary on
     * its rejected side — never exhaustive, because the set of strings that do
     * not parse is infinite. Separator COVERAGE is not its job and must not be
     * pasted in here: {@see probeVectors()} derives one row per separator from
     * {@see NearMissProbe::SEPARATORS}, so a widened separator class grows the
     * covered set by itself. `cards #123` is the one plural row, naming the
     * family in the operator sentence; the other six plural spellings are the
     * derived property's, not this list's.
     *
     * The set may GROW; `CardTokenGrammarTest` pins the ratified rows so it
     * cannot shrink below them. It may NOT grow a bare-space spelling
     * (`card 123`, `cards 123`): every consumer of this list asserts
     * warn-iff-not-parsed, and DL-201 ruled that prose stays silent.
     */
    public const VECTORS = [
        'card-123',
        'card#123',
        'card123',
        'card-3',
        'card4',
        'card_123',
        'card.123',
        'card:123',
        'card #123',
        'cards #123',
        "card#\u{0663}",
    ];

    /** The card id this text names, or null when no token parses. */
    public static function parse(string $text): ?int
    {
        return preg_match(self::PATTERN, $text, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The card id this text STARTS with — the token flush at offset 0 — or null.
     *
     * WHO ASKS, AND WHY IT LIVES HERE. {@see ClosureGrammar} matches a closing verb and
     * must then know whether a token sits IMMEDIATELY after it, so `Closes card#123`
     * closes card 123 while `Closes the bug card#123 tracks` closes nothing. It cannot
     * answer that with {@see self::parse()} (which scans the whole remainder and would
     * make every word between the verb and a token invisible), and it must not carry a
     * copy of {@see self::PATTERN} to anchor one itself — that is the restatement DL-239
     * removed. So the anchoring is expressed HERE, against this class's own pattern:
     * the leftmost match is located and accepted only when it begins at offset 0.
     *
     * A POSITION TEST, NEVER A SECOND ACCEPT-SET: it can only ever return what
     * {@see self::parse()} would have returned for the same text, or null. No spelling
     * correlates through this door that does not correlate through that one.
     */
    public static function parseAnchored(string $text): ?int
    {
        if (preg_match(self::PATTERN, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $m[0][1] === 0 ? (int) $m[1][0] : null;
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
     * Does this text APPEAR to name a card? Asked ONLY where {@see parse()}
     * already returned null, so a match means a near-miss: the branch publishes,
     * the card never moves, and without this nobody is told.
     *
     * The plural the probe carries was the whole silent family (DL-250) — see
     * {@see NearMissProbe} for the mechanism.
     */
    public static function looksLikeCardToken(string $text): bool
    {
        return self::probe()->matches($text);
    }

    /**
     * WHICH card a near-miss names — `4811` for `card_4811` — or null when
     * {@see looksLikeCardToken} is false. Asked, like the probe itself, only
     * where {@see parse} already returned null.
     *
     * HARD BOUND (DL-287): the id this returns may REFUSE or WARN about a move,
     * never SELECT the card that moves. It exists so a caller can tell a
     * near-miss that names the card already being moved (redundant — nothing is
     * dropped) from one that names a different card (a hijack in progress).
     * Selecting on it would make every rejected spelling a correlation channel,
     * which is exactly what {@see PATTERN} decides against.
     */
    public static function nearMissCardId(string $text): ?int
    {
        return self::probe()->id($text);
    }

    /**
     * The probe's derived corpus for this stem — {@see NearMissProbe::vectors()}.
     * Which cells WARN is {@see parse()}'s answer, not this list's: the cells
     * that correlate must stay silent.
     *
     * @return list<string>
     */
    public static function probeVectors(): array
    {
        return self::probe()->vectors('123');
    }

    private static function probe(): NearMissProbe
    {
        return new NearMissProbe('card');
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
