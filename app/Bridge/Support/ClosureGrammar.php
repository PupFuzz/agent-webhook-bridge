<?php

namespace App\Bridge\Support;

/**
 * THE CLOSURE grammar: which forms in a PR title assert that a merge COMPLETES the
 * card the token names (card#7348 / DL-305).
 *
 * WHY THIS CLASS EXISTS. *"This PR mentions card N"* and *"card N's work is shipped"*
 * are different propositions, and the writeback collapsed them: `PrOutcome` derived
 * the merge stage from the base ref alone — a pure function with no completion signal
 * in it at all — and {@see CardTokenGrammar} / {@see DlTokenGrammar} answer only WHICH
 * card a subject names, never whether it claims that card is done. So a PR citing a
 * card for context moved it into a terminal, irreversible stage on merge, and the
 * release sweep faithfully propagated the result. A peer measured 17 wrong-retirement
 * candidates in one release bundle and one card whose explicit human ruling the
 * writeback reversed. This class is the missing predicate.
 *
 * THE TOKEN HALF IS NOT RESTATED HERE, and that is the whole reason this reads the way
 * it does. A closing form is a verb BRIDGE followed by a token, and the token is
 * {@see CardTokenGrammar}'s or {@see DlTokenGrammar}'s to spell — so this class matches
 * the bridge, then hands the REMAINDER to the owning grammar's
 * {@see CardTokenGrammar::parseAnchored()} / {@see DlTokenGrammar::parseAnchored()}.
 * Pasting either pattern in here would be the exact restatement DL-239 removed (a
 * near-miss warning spent two releases naming a narrower accept-set than the code
 * enforced), and a grammar move would then silently stop being a closure.
 *
 * ADJACENCY IS THE RULE, and it is what makes the predicate mean anything: the token
 * must sit FLUSH against the verb. `Closes card#123` closes it; `Closes the regression
 * card#123 documents` does not, because the verb's object is not the token. That is
 * GitHub's own rule for its linking keywords, and it is why the anchored parse exists
 * rather than a "verb somewhere, token somewhere" co-occurrence test — the loose form
 * would read almost every real PR title as a closure and close nothing at all.
 *
 * ⛔ ONE VERB CLOSES ONE TOKEN. `Closes card#1 and card#2` closes only card 1 — the
 * second is a bare mention under this grammar, exactly as it is under GitHub's. Write
 * `Closes card#1, closes card#2`. The cost of the strict reading is an UNDER-promoted
 * card, which is recoverable by hand; the cost of the loose one is the terminal move
 * this class exists to stop.
 *
 * THE VERB SET is GitHub's documented linking-keyword set (close/closes/closed,
 * fix/fixes/fixed, resolve/resolves/resolved), adopted rather than invented so an
 * author who already writes PR titles for GitHub's issue-linking needs to learn
 * nothing. Case-insensitive, like both token grammars.
 *
 * ⛔ A QUOTED REVERT TITLE IS NOT THIS AUTHOR'S CLAIM (card#8306). GitHub composes a
 * revert PR's title by quoting the original's, so once titles carry `(closes card#N)`
 * every revert inherits a closing form for work it UNDOES. The wrapper is stripped
 * before the verb bridge is matched — {@see RevertGrammar::withoutQuotedRevert()} owns
 * the shape, this class owns only the consequence: text inside `Revert "…"` names no
 * closure, text outside it is read exactly as it always was. It is subtracted HERE
 * rather than at either caller because both consumers of this predicate — the
 * classifier's closure filter and `bridge:reconcile`'s backstop — ask the identical
 * question about the identical field, and DL-305 §6 / DL-308 ruled that a term on one
 * path and not the other means the two disagree about which merges close a card.
 *
 * ⛔ `[no-close]` VETOES THE WHOLE TITLE (card#8344), and the difference from the revert
 * rule above is the whole reason it is not expressed the same way. A quoted revert is
 * someone ELSE's text, so it is SUBTRACTED and the rest of the title still speaks; the
 * marker is THIS author saying *this PR cites the card, it does not finish it* — a claim
 * about the PR, not about a span of it — so once it is present no position in the title
 * can carry a closing form. {@see NoCloseGrammar} owns the marker and applies the
 * quotation rule to itself; this class owns only the consequence, and it is applied at
 * the same single choke point for the same reason (both consumers of this predicate must
 * get the identical answer — DL-305 §6 / DL-308's lockstep).
 *
 * A BARE MENTION IS A NO-OP, NEVER A DEMOTION — the consumer's rule, stated here
 * because this predicate is what makes it reachable. The writeback re-classifies
 * in-window PRs on EVERY pass, so a rule that returned an earlier stage for a bare
 * mention would mass-demote every already-correct card on the first run after this
 * shipped. Withholding the move instead leaves every existing stage exactly where it
 * is, which is also what makes the migration free.
 */
final class ClosureGrammar
{
    /**
     * The verb BRIDGE: a closing keyword at a word boundary, then at least one
     * separator (whitespace and/or a colon) before the token starts.
     *
     * The leading `\b` is what keeps `unfixes` / `prefixes` out — `fix` there is
     * preceded by a word character, so the boundary never matches. The trailing
     * `[\s:]+` is mandatory: a glued `Closescard#123` names no closing form, and
     * requiring the separator is what stops the verb pattern from reaching into the
     * middle of a word.
     */
    private const BRIDGE = '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)[\s:]+/i';

    /**
     * The canonical example shapes the operator-facing sentence is built from —
     * examples only, never a verdict: which side of the sentence each lands on is
     * decided by the code at render time, so this list carries no copy of the
     * accept-set that could disagree with it (the DL-239 discipline, applied to a
     * third grammar).
     *
     * The rejected side is the judgement-bearing half and is exemplary, not
     * exhaustive: `card#123` alone is the DEFECT — the bare mention that used to move
     * a card into a terminal stage — and `Closes the bug in card#123` is the adjacency
     * ruling, which prose would have to assert. `Unfixes card#123` pins the leading
     * boundary. The set may GROW; `ClosureGrammarTest` pins the ratified rows so it
     * cannot shrink below them.
     *
     * ⛔ IT MAY NOT GROW A REVERT ROW, and the reason is a constraint two consumers place
     * on this corpus that nothing stated until card#8306 measured it. `PrTitleLintTest`
     * ties this list to `.github/workflows/pr-title-lint.yml` in two ways a revert vector
     * breaks by construction: (a) the tie asserts the lint's bash regex and this grammar
     * return the SAME answer set over these rows, and the lint handles reverts by exempting
     * the `revert-*` BRANCH — a dimension a title-only corpus cannot express, so a revert
     * row makes the two disagree about a case on which neither is wrong; and (b) the
     * operator-message tie derives a verb STEM from each accepted row's FIRST WORD, which
     * assumes every accepted vector opens with its closing verb — false of
     * `Revert "…" (Closes card#456)`, whose first word is not a verb at all. The revert
     * ruling is rendered where an operator actually meets it — {@see RevertGrammar::describeRefusal()}
     * on the withheld-merge warning and `bridge:reconcile`'s skip line — and is asserted
     * against the predicate in `RevertGrammarTest`, never through this list.
     *
     * @var list<string>
     */
    public const VECTORS = [
        'Closes card#123',
        'closes card-123',
        'Closed card123',
        'Close card#123',
        'Fixes card#123',
        'Fixed card#123',
        'Fix card#123',
        'Resolves card#123',
        'Closes: card#123',
        'Closes DL-239',
        'Fixes DL-239',
        'card#123',
        'DL-239',
        'Closes the bug in card#123',
        'Unfixes card#123',
        'Related to card#123',
    ];

    /**
     * The card ids this text explicitly CLOSES, in the order they appear. A card named
     * only by a bare mention is deliberately absent — that is the whole point.
     *
     * @return list<int>
     */
    public static function closedCardIds(string $text): array
    {
        $ids = [];
        foreach (self::remainders($text) as $rest) {
            $id = CardTokenGrammar::parseAnchored($rest);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The `DL-NNN` tokens this text explicitly CLOSES, normalized exactly as
     * {@see DlTokenGrammar::parse()} normalizes them (so a caller can compare against a
     * token that grammar produced without re-spelling either).
     *
     * @return list<string>
     */
    public static function closedDls(string $text): array
    {
        $dls = [];
        foreach (self::remainders($text) as $rest) {
            $dl = DlTokenGrammar::parseAnchored($rest);
            if ($dl !== null) {
                $dls[] = $dl;
            }
        }

        return $dls;
    }

    /** Does this text carry a closing form naming THIS card id? */
    public static function closesCard(string $text, int $cardId): bool
    {
        return in_array($cardId, self::closedCardIds($text), true);
    }

    /**
     * Does this text carry a closing form naming THIS `DL-NNN`? The comparison is on
     * the normalized token, so `closes dl-239` closes `DL-239`; leading zeros are NOT
     * folded, matching {@see DlTokenGrammar::parse()} — the board canonicalizes at the
     * stamp site and this grammar must not invent a second answer.
     */
    public static function closesDl(string $text, string $dl): bool
    {
        return in_array($dl, self::closedDls($text), true);
    }

    /** Does this text carry ANY closing form at all? */
    public static function hasClosure(string $text): bool
    {
        return self::closedCardIds($text) !== [] || self::closedDls($text) !== [];
    }

    /** @return list<string> */
    public static function accepted(): array
    {
        return array_values(array_filter(self::VECTORS, fn (string $v) => self::hasClosure($v)));
    }

    /** @return list<string> */
    public static function rejected(): array
    {
        return array_values(array_filter(self::VECTORS, fn (string $v) => ! self::hasClosure($v)));
    }

    /**
     * The operator-facing accept-set, DERIVED by running the predicate over
     * {@see self::VECTORS}. Every caller that tells a human which forms close a card
     * MUST render it from here rather than spell it out — the DL-239 ruling, which two
     * sibling grammars already obey.
     */
    public static function describe(): string
    {
        return 'closes: '.implode(', ', self::accepted())
            .' — does NOT close (the card is named, not claimed done): '.implode(', ', self::rejected());
    }

    /**
     * The text immediately following each verb bridge — the candidate token positions,
     * and the only positions this grammar will read a token out of.
     *
     * THE ONE CHOKE POINT, which is why the revert subtraction sits here (card#8306) and
     * not in the four public predicates above: `closedCardIds()`, `closedDls()` and
     * everything derived from them read a token out of exactly these positions, so a
     * quoted revert wrapper removed once cannot be honoured by one predicate and missed
     * by another. Offsets are taken against the SUBTRACTED text on purpose — the
     * positions this grammar may read from are the ones the author actually wrote.
     *
     * THE `[no-close]` VETO SITS HERE FOR THE SAME REASON (card#8344), and it returns the
     * EMPTY set rather than editing the text: the marker withholds every closing form in
     * the title at once, including a `Closes DL-NNN` that `closedDls()` would otherwise
     * read, so expressing it as a subtraction would leave one predicate to re-derive it.
     * It is asked BEFORE the revert subtraction because {@see NoCloseGrammar::marks()}
     * applies that subtraction itself — the marker inside a quoted revert is the original
     * author's, not this one's.
     *
     * @return list<string>
     */
    private static function remainders(string $text): array
    {
        if (NoCloseGrammar::marks($text)) {
            return [];
        }
        $text = RevertGrammar::withoutQuotedRevert($text);
        if (preg_match_all(self::BRIDGE, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $out = [];
        foreach ($m[0] as $hit) {
            $out[] = substr($text, $hit[1] + strlen($hit[0]));
        }

        return $out;
    }
}
