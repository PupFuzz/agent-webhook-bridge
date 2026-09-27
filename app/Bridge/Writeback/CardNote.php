<?php

namespace App\Bridge\Writeback;

/**
 * A card-visible record of something the writeback deliberately did NOT write
 * (card#7064) — the body of a kanban card comment, plus the marker line that makes
 * re-posting it detectable.
 *
 * The writeback correlates ONE pull request per card: `pr_number` / `pr_url` /
 * `dl_number` are stamped add-if-missing and first-write-wins. A SECOND pull request
 * naming the same card is therefore dropped, and until this existed the drop left no
 * trace on the more common path — a well-formed PR (its head branch carrying the card
 * token, so the corroboration gate never fires) fell straight into the stamp's
 * nothing-left-to-write return. The better-behaved the contributor, the less evidence
 * the lost leg left. These notes convert that silent absence into a recorded one; they
 * change nothing about which PR ends up stamped.
 *
 * The card is where the note goes because the card is where somebody looking for the
 * missing correlation actually looks — a log line and an alert push are the operator's
 * surfaces, not the reader-of-the-card's.
 *
 * The MARKER LINE is the first line of the comment and identifies the note by its
 * FACTS (which card, which refs, which values), never by the event that produced it:
 * one note per card per dropped SET of values, so the same drop re-asserted with the
 * same values — `opened`, then `merged`, then a redelivery of either — re-derives a
 * byte-identical marker and is written once. The unit is the SET, not the pull request,
 * and the difference is reachable: the offered refs are re-derived from the event every
 * time (`stamp_dl` from the title+branch text via `DlTokenGrammar::sole`), so a title
 * EDITED between two events can offer a DL the earlier one did not, growing the dropped
 * set into a different marker and a second note about the same pull request. That is the
 * honest outcome — the second note records a drop the first one could not have named —
 * but it is not one-note-per-PR, and reading it as that would make a real second record
 * look like a dedup failure. A genuinely different second pull request is likewise a
 * different marker, and gets its own note.
 */
final class CardNote
{
    /**
     * Marker-line lead-in — the grep handle for every note this file mints. The marker is
     * CLOSED with a `]` (see {@see marker}) rather than left open-ended, and that bracket is
     * load-bearing for {@see alreadyOn}: an open-ended marker ending in a `pr_url` is a
     * PREFIX of the marker for any PR whose number extends it (`…/pull/26` inside
     * `…/pull/262`), so a prefix match would read the note for PR 262 as already covering
     * the drop of PR 26 and suppress it — the silence this class exists to remove, minted by
     * its own idempotency check.
     */
    public const PREFIX = '[bridge:correlation-note';

    /**
     * Why a `pr_number` EQUAL to this pull request's is still not taken as its own (DL-429,
     * {@see StoredPrRef::numberUnconfirmed}) — one wording for both notes
     * that report it, the stamp's dropped match and the corroboration refusal.
     */
    private const NUMBER_UNCONFIRMED = 'the card carries no `pr_url` confirming WHICH repo that number belongs to.'
        .' A number alone does not identify a pull request (two repos can share one, and a repo'
        .' moved to a new GitHub org restarts its numbers)';

    /**
     * What a bare `pr_number` that differs from this pull request's is (DL-429 Decision 1) — one
     * wording for every surface that reports one: the corroboration refusal's note here, its log
     * line ({@see CardTokenCorroboration::refusalCause}) and the pull-request comment
     * ({@see PrCorrelationComment}), and the stamp's drop heading, which interpolates it.
     */
    public const BARE_NUMBER = 'a bare number no `pr_url` attributes to a repo, so it names no pull request';

    /** The hand remedy for a card whose `pr_number` names no pull request this one could be. */
    private const PATCH_BOTH_REFS = '`kbcard patch --task %d --pr <number> --pr-url <url>`';

    private function __construct(
        public readonly string $marker,
        private readonly string $body,
    ) {}

    /**
     * Correlation refs this event offered that the stamp did not write: the card already
     * answers with a different value, or with a `pr_number` no `pr_url` confirms or that
     * names no pull request, or a `pr_url` was withdrawn with its dropped `pr_number`
     * (DL-429). The heading is chosen from $stored — what the card's refs actually name —
     * read against which refs were dropped, in one place below.
     *
     * $dropped is keyed by ref name (`pr_number` / `pr_url` / `dl_number`), each entry
     * `['card' => <what the card stores>, 'offered' => <what this event carried>]`.
     * Both are rendered, because "which PR won" is the first question a reader has and
     * neither value alone answers it.
     *
     * The headings, one table, first match wins. Two arms say the card already names ANOTHER
     * pull request ({@see StoredPrUrlKind::NamesOtherPr}) — the sixth when its `pr_number` also
     * happens to equal this event's AND that other pull request is of THIS EVENT'S OWN repo
     * ({@see StoredPrRef::otherPrSameRepoWithMatchingNumber}), the seventh otherwise; every
     * other shape gets a heading saying what the card really holds, or (the fourth) that it
     * already names THIS pull request (DL-429 r5 — the seventh used to be the fallback, and so
     * said "the pull request it already names" of cards naming none; DL-429 r8 split the sixth
     * out of the seventh — a card whose bare `pr_number` equals this event's is not merely
     * "correlated to another PR", its OWN two refs disagree with EACH OTHER, and kanban's
     * by-ref derivation (repo from `pr_url`, number from `pr_number`) can index such a card
     * under THIS event's own `(repo, number)` even though nothing was written — the seventh's
     * "not reachable by a by-ref lookup" remedy line is false of it and does not belong on the
     * sixth; DL-429 r9 added the repo gate the sixth was missing — a card correctly tracking
     * `oldorg/repo#7` reads `NamesOtherPr` against an event for `owner/repo#7` too, its
     * `pr_number` coincidentally equal, but its two refs AGREE with each other and the sixth's
     * "disagree … correct both refs by hand" would be false of it and would tell an operator to
     * re-point a card that already tracks the right pull request; the seventh is where it now
     * lands, and "not reachable" holds of it under a repo-qualified by-ref lookup on a card with
     * no explicit `payload.repo` override — kanban's `pr_url`-derived ref there uses
     * `oldorg/repo`, never this event's `owner/repo` (DL-429 r10 bound: an explicit
     * `payload.repo` naming `owner/repo`, or an unqualified lookup on a 1:1 board mapping
     * per DL-174, could still land on the card — neither is what this class classifies):
     *  - a dropped `pr_url` the card keeps as ANOTHER repo's `.../pull/0` placeholder: the card
     *    names a REPO it was deliberately qualified to, and no pull request. A `pr_number`
     *    beside it does not change that — a bare number names no pull request (DL-429) — so
     *    this heading wins whatever else was dropped. Asserting a PR that does not exist inside
     *    the note that exists to stop this handler asserting PRs that do not exist would re-mint
     *    the defect the note is for;
     *  - a dropped `pr_number` EQUAL to this event's with no `pr_url` confirming its repo: the
     *    refused ref is the SAME value, so "different correlation ref" would be false. A number
     *    alone never identifies a pull request (two repos can share one, and a repo moved to a
     *    new GitHub org restarts its numbers), so the match cannot be told apart from a
     *    same-numbered pull request in another repo;
     *  - a dropped `pr_number` that is not a pull-request number at all (`0`, free text —
     *    DL-309): it does not DIFFER from this one, it names none;
     *  - a dropped `pr_number` that differs beside a `pr_url` naming THIS pull request: the card
     *    already names this one, so it is neither "a second pull request" nor correlated to
     *    another — its two refs disagree;
     *  - a dropped `pr_number` that differs and that no `pr_url` of the card's attributes to a
     *    repo: it names no pull request (DL-429 Decision 1);
     *  - a dropped PR ref beside a `pr_url` naming ANOTHER pull request of THIS EVENT'S OWN repo
     *    whose `pr_number` also equals this event's (DL-429 r8, repo-gated by r9): the card's
     *    own two refs disagree with each other, so the heading says exactly that instead of
     *    calling the number "different" or asserting the offered pull request is unreachable —
     *    the remedy is to correct both refs by hand;
     *  - a dropped PR ref beside a `pr_url` naming ANOTHER pull request — of a DIFFERENT repo
     *    (any `pr_number`), or of this repo with a different or absent `pr_number`: the card
     *    stays correlated to the pull request it already names, and this one is not reachable by
     *    a REPO-QUALIFIED by-ref lookup on a card with no explicit `payload.repo` override
     *    (DL-429 r9/r10: a same-numbered pull request in ANOTHER repo derives a `github_pr` ref
     *    under that OTHER repo, never this event's, in that scope — the rendered note's own
     *    "not reachable" wording makes no such qualification; DL-429 records that as a residual);
     *  - a dropped `pr_url` (with no `pr_number` dropped) that is not a pull-request URL — an
     *    operator's free text, the one remaining shape the stamp drops rather than writes over;
     *  - otherwise only the `dl_number` was dropped, and the heading says nothing about a pull
     *    request: the card's pull-request refs are not what this drop is about.
     *
     * $withheldPrNumber is this pull request's number when the stamp did NOT write it to a
     * card that had none (DL-429 r1): `pr_number` is written only beside a `pr_url` naming
     * this pull request, and the ref dropped above means the card will not carry one. It
     * gets its own line so the note says what the card is left without. It is not part of
     * the marker: the marker identifies the drop, and the withheld number follows from it.
     *
     * A value the card does not hold renders as "none", never as `null`.
     *
     * @param  array<string, array{card: mixed, offered: mixed}>  $dropped
     */
    public static function droppedCorrelationRef(
        int $cardId,
        string $repo,
        array $dropped,
        StoredPrRef $stored,
        ?int $withheldPrNumber = null,
    ): self {
        ksort($dropped);

        $fields = ['card' => (string) $cardId];
        foreach ($dropped as $key => $pair) {
            $fields[$key] = self::render($pair['offered']);
        }
        $marker = self::marker('correlation-ref-not-stamped', $fields);

        $lines = '';
        foreach ($dropped as $key => $pair) {
            $kept = ($pair['card'] ?? '') === '' ? 'none' : '`'.self::render($pair['card']).'`';
            $lines .= '- `'.$key.'` — the card keeps '.$kept
                .'; this pull request offered `'.self::render($pair['offered'])."`\n";
        }
        if ($withheldPrNumber !== null) {
            $lines .= '- `pr_number` — not written either: a number is written only beside a `pr_url`'
                .' naming this pull request; this pull request offered `'.$withheldPrNumber."`\n";
        }

        $numberDropped = isset($dropped['pr_number']);
        $urlDropped = isset($dropped['pr_url']);
        $patchBoth = sprintf(self::PATCH_BOTH_REFS, $cardId);
        $unconfirmed = self::NUMBER_UNCONFIRMED;
        $bare = self::BARE_NUMBER;

        $body = match (true) {
            $urlDropped && $stored->url === StoredPrUrlKind::PlaceholderOtherRepo => <<<BODY
                A pull request in `{$repo}` names this card, but the card already carries a
                different correlation ref — a repo-only placeholder set before any pull
                request existed, and a card carries one of each, first write wins. So the
                refs below were **not** written, and this card stays correlated to the REPO
                it already names, not to this pull request:

                {$lines}
                This pull request is not reachable by a by-ref lookup on this card; record the
                link by hand if you need it.
                BODY,
            $numberDropped && $stored->number === StoredPrNumberKind::SameNumber => <<<BODY
                A pull request in `{$repo}` names this card, and its `pr_number` matches the
                card's own — but {$unconfirmed}, so
                a matching number cannot be told apart from a same-numbered pull request in a
                DIFFERENT repo. So the refs below were **not** written, and this card's
                existing `pr_number` is left exactly as it was:

                {$lines}
                If this pull request really is the one this card already tracks, stamp its
                `pr_url` by hand (`kbcard patch --task {$cardId} --pr-url <url>`) so a future
                event can verify it.
                BODY,
            $numberDropped && $stored->number === StoredPrNumberKind::NamesNoPr => <<<BODY
                A pull request in `{$repo}` names this card, but the card's `pr_number`
                holds a value that is not a pull-request number, and a stamp never
                overwrites a value a card holds. A `pr_url` is written only beside a
                `pr_number` it names, so the refs below were **not** written, and this
                card keeps the `pr_number` it holds:

                {$lines}
                If this pull request is the one this card tracks, correct both refs by hand
                (`kbcard patch --task {$cardId} --pr <number> --pr-url <url>`).
                BODY,
            $numberDropped && $stored->url === StoredPrUrlKind::NamesThisPr => <<<BODY
                A pull request in `{$repo}` names this card, and the card's `pr_url`
                already names this pull request — but its `pr_number` holds a different
                number, and a stamp never overwrites a value a card holds. So the refs
                below were **not** written, and this card keeps the number it holds:

                {$lines}
                If this pull request is the one this card tracks, correct its `pr_number` by
                hand (`kbcard patch --task {$cardId} --pr <number>`).
                BODY,
            $numberDropped && $stored->numberIsBare() => <<<BODY
                A pull request in `{$repo}` names this card, but the card already carries a
                different `pr_number` — {$bare}. A card carries one of each, first write wins,
                so the refs below were **not** written, and this card keeps the number it holds:

                {$lines}
                If this pull request is the one this card tracks, replace both refs by hand
                (`kbcard patch --task {$cardId} --pr <number> --pr-url <url>`); if not,
                stamp the `pr_url` of the pull request the card's number belongs to.
                BODY,
            ($numberDropped || $urlDropped) && $stored->otherPrSameRepoWithMatchingNumber() => <<<BODY
                A pull request in `{$repo}` names this card, and its `pr_number` matches this
                pull request's number — but the card's `pr_url` already names a DIFFERENT pull
                request, and a stamp never overwrites a value a card holds. So the refs below
                were **not** written, and this card stays correlated to the pull request its
                `pr_url` already names:

                {$lines}
                This card's `pr_number` and `pr_url` disagree about which pull request it
                tracks; correct both refs by hand ({$patchBoth}).
                BODY,
            ($numberDropped || $urlDropped) && $stored->url === StoredPrUrlKind::NamesOtherPr => <<<BODY
                A pull request in `{$repo}` names this card, but the card already carries a
                different correlation ref — and a card carries one of each, first write wins.
                So the refs below were **not** written, and this card stays correlated to the
                pull request it already names:

                {$lines}
                This second pull request is not reachable by a by-ref lookup on this card;
                record the link by hand if you need it.
                BODY,
            $urlDropped => <<<BODY
                A pull request in `{$repo}` names this card, but the card's `pr_url` holds a
                value that is not a pull-request URL, so it names no pull request, and a stamp
                never overwrites a value a card holds. So the refs below were **not** written,
                and this card keeps the `pr_url` it holds:

                {$lines}
                If this pull request is the one this card tracks, correct both refs by hand
                ({$patchBoth}).
                BODY,
            default => <<<BODY
                A pull request in `{$repo}` names this card, but the card already carries a
                different `dl_number` — and a card carries one of each, first write wins. So
                its `dl_number` was **not** written, and this card keeps the one it holds:

                {$lines}
                If this pull request's DL is the one this card should carry, replace it by
                hand (`kbcard patch --task {$cardId} --dl <DL>`).
                BODY,
        };

        return new self($marker, $body);
    }

    /**
     * The move REFUSED because the `card#` token was title-only, uncorroborated by the
     * head branch, and this card already tracks a pull request not provably this one
     * (DL-270). The refusal is the right outcome — the note exists so the card shows that an event
     * claiming to be about it was turned away, rather than that nothing happened.
     *
     * What the card holds is said from $stored, in one place below — the same four cases, in the
     * same order, as the refusal's log line ({@see CardTokenCorroboration::refusalCause}) and PR
     * comment. Only a `pr_url` naming ANOTHER pull request ({@see StoredPrUrlKind::NamesOtherPr};
     * the gate never refuses a card whose `pr_url` names this one) makes it "a different pull
     * request", shown by that url — two same-numbered PRs differ only by repo (DL-429), and
     * "`148` is not `148`" would explain nothing. Otherwise the card holds only a `pr_number`, which names no pull request
     * (DL-429 Decision 1), and the note says which kind: EQUAL to this event's with nothing
     * confirming its repo (in the stamp's own unconfirmed-number words), not a pull-request
     * number at all (DL-309), or a bare number. The gate refuses only a card that tracks
     * something ({@see CardTokenCorroboration::refuses}), so the last arm is the bare number.
     *
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     */
    public static function refusedUncorroboratedMove(int $cardId, string $repo, array $card, mixed $eventPr, StoredPrRef $stored): self
    {
        // The event legitimately carries NO pull-request number — that is the fail-closed
        // arm of the gate (nothing corroborates the title, so the move is refused). Saying
        // `pr_number null` would read as a value; say what actually happened instead.
        $known = is_numeric($eventPr);
        $event = $known ? self::render($eventPr) : 'none';
        $which = $known
            ? "A pull request in `{$repo}` (`pr_number` `{$event}`) cited this card in its TITLE"
            : "An event from `{$repo}` carrying no pull-request number cited this card in a TITLE";

        $marker = self::marker('move-refused-uncorroborated-card-token', ['card' => (string) $cardId, 'pr_number' => $event]);

        $number = self::render(CardTokenCorroboration::cardPr($card));
        $patchBoth = sprintf(self::PATCH_BOTH_REFS, $cardId);
        [$tracks, $orFix] = match (true) {
            $stored->url === StoredPrUrlKind::NamesOtherPr => [
                "{$which} with nothing in its head branch agreeing, and this card already tracks a\n"
                    .'different pull request (`pr_url` `'.self::render(CardTokenCorroboration::cardPrUrl($card)).'`).',
                '',
            ],
            $stored->numberUnconfirmed() => [
                "{$which} with nothing in its head branch agreeing, and this card already tracks `pr_number` `{$number}` — the same number — but "
                    .self::NUMBER_UNCONFIRMED.', so the card cannot be shown to track THIS pull request.',
                ", or stamp its `pr_url` by hand\n(`kbcard patch --task {$cardId} --pr-url <url>`) so this card names it",
            ],
            $stored->number === StoredPrNumberKind::NamesNoPr => [
                "{$which} with nothing in its head branch agreeing, and this card's `pr_number` (`{$number}`) holds a value that is not a pull-request number, so the card cannot be shown to track THIS pull request.",
                ", or correct both refs by hand\n({$patchBoth}) so this card names it",
            ],
            default => [
                "{$which} with nothing in its head branch agreeing, and this card already carries `pr_number` `{$number}` — "
                    .self::BARE_NUMBER.' — and the card cannot be shown to track THIS pull request.',
                ", or correct both refs by hand\n({$patchBoth}) so this card names it",
            ],
        };

        return new self($marker, <<<BODY
            {$tracks} A title is prose — a descriptive
            citation of somebody else's card is written exactly like a claim to own this one —
            so the move was **refused** and nothing on this card was changed.

            If that pull request really is work on this card, name the card in its head branch
            (`card-{$cardId}-…`) so the token is corroborated{$orFix}.
            BODY);
    }

    /**
     * The marker line: the lead-in, the note kind, then the identifying fields, CLOSED.
     *
     * @param  array<string, string>  $fields
     */
    private static function marker(string $kind, array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return self::PREFIX.' '.$kind.' · '.implode(' · ', $parts).']';
    }

    /** The comment as kanban stores it: the marker line, then the prose. */
    public function content(): string
    {
        return $this->marker."\n\n".$this->body;
    }

    /**
     * Is this exact note already on the card? Reads the `comments` collection the full
     * `GET /tasks/{id}.json` aggregate already returns, so the check costs no extra read.
     *
     * ⛔ A comment IS the note only when it STARTS with the marker — the marker is the note's
     * first line. Anywhere else it is a QUOTE: seats comment through the same writeback user
     * (`board_comment_card`), so a comment that merely mentions a marker must not suppress the
     * note it names.
     *
     * Degrades toward WRITING: a kanban whose task aggregate carries no `comments` key
     * yields a duplicate note rather than a suppressed one. That direction is deliberate
     * — a duplicated record is visible and correctable, a suppressed one is the silence
     * this whole class exists to remove. A note somebody DELETES falls the same way and
     * for the same reason: kanban's delete is a soft one and the aggregate then omits the
     * row entirely, so the next event asserting that drop re-posts the note rather than
     * reading the deletion as "already recorded".
     *
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     */
    public function alreadyOn(array $card): bool
    {
        foreach (is_array($card['comments'] ?? null) ? $card['comments'] : [] as $comment) {
            $content = is_array($comment) ? ($comment['content'] ?? null) : null;
            if (is_string($content) && str_starts_with($content, $this->marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A card-payload / event-payload value as text. Both are system boundaries, so the
     * type is whatever kanban or a JSON round-trip produced; a non-scalar renders as its
     * JSON rather than vanishing into an empty string.
     */
    private static function render(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return var_export($value, true);
        }

        return $value === null ? 'null' : (string) json_encode($value);
    }
}
