<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

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
     * {@see CardTokenCorroboration::matchesNumberUnconfirmed}) — one wording for both notes
     * that report it, the stamp's dropped match and the corroboration refusal.
     */
    private const NUMBER_UNCONFIRMED = 'the card carries no `pr_url` confirming WHICH repo that number belongs to.'
        .' A number alone does not identify a pull request (two repos can share one, and a repo'
        .' moved to a new GitHub org restarts its numbers)';

    private function __construct(
        public readonly string $marker,
        private readonly string $body,
    ) {}

    /**
     * A correlation ref this event offered that the card already answers with a
     * DIFFERENT value, so the stamp was not written.
     *
     * $dropped is keyed by ref name (`pr_number` / `pr_url` / `dl_number`), each entry
     * `['card' => <what the card stores>, 'offered' => <what this event carried>]`.
     * Both are rendered, because "which PR won" is the first question a reader has and
     * neither value alone answers it.
     *
     * $keptNamesNoPullRequest is true for exactly one shape: the card's kept `pr_url` is
     * the `.../pull/0` source-only placeholder of ANOTHER repo — a repo, not a pull request.
     * The default heading claims "this card stays correlated to the pull request it already
     * names", which is false for that card: it names a REPO it was deliberately qualified
     * to, and no pull request at all. A `pr_number` beside the placeholder does not change
     * that — a bare number names no pull request (DL-429) — so that shape gets its own
     * heading whatever else was dropped. Asserting a PR that does not exist inside the note
     * that exists to stop this handler asserting PRs that do not exist would re-mint the
     * defect the note is for.
     *
     * $withheldPrNumber is this pull request's number when the stamp did NOT write it to a
     * card that had none (DL-429 r1): `pr_number` is written only beside a `pr_url` naming
     * this pull request, and the ref dropped above means the card will not carry one. It
     * gets its own line so the note says what the card is left without. It is not part of
     * the marker: the marker identifies the drop, and the withheld number follows from it.
     *
     * $keptNumberUnverifiedRepo is true for the shape $withheldPrNumber does not cover: the
     * card ALREADY had a `pr_number`, numerically IDENTICAL to the value this event offered
     * — the ref that was refused is the SAME value, not a different one, so the default
     * heading's "different correlation ref" would be false rather than merely imprecise. It
     * is refused anyway because a number alone never identifies a pull request (two repos
     * can share one, and a repo moved to a new GitHub org restarts its numbers) and the card
     * carries no `pr_url` confirming which repo its number belongs to, so an apparent match
     * cannot be told apart from a same-numbered pull request in another repo. Mutually
     * exclusive with $withheldPrNumber by construction: one fires only when the card had NO
     * `pr_number`, the other only when it already had one.
     *
     * @param  array<string, array{card: mixed, offered: mixed}>  $dropped
     */
    public static function droppedCorrelationRef(
        int $cardId,
        string $repo,
        array $dropped,
        bool $keptNamesNoPullRequest = false,
        ?int $withheldPrNumber = null,
        bool $keptNumberUnverifiedRepo = false,
    ): self {
        ksort($dropped);

        $fields = ['card' => (string) $cardId];
        foreach ($dropped as $key => $pair) {
            $fields[$key] = self::render($pair['offered']);
        }
        $marker = self::marker('correlation-ref-not-stamped', $fields);

        $lines = '';
        foreach ($dropped as $key => $pair) {
            $lines .= '- `'.$key.'` — the card keeps `'.self::render($pair['card'])
                .'`; this pull request offered `'.self::render($pair['offered'])."`\n";
        }
        if ($withheldPrNumber !== null) {
            $lines .= '- `pr_number` — not written either: a number is written only beside a `pr_url`'
                .' naming this pull request; this pull request offered `'.$withheldPrNumber."`\n";
        }

        if ($keptNamesNoPullRequest) {
            return new self($marker, <<<BODY
                A pull request in `{$repo}` names this card, but the card already carries a
                different correlation ref — a repo-only placeholder set before any pull
                request existed, and a card carries one of each, first write wins. So the
                refs below were **not** written, and this card stays correlated to the REPO
                it already names, not to this pull request:

                {$lines}
                Nothing else about the card was changed. This pull request is not
                reachable by a by-ref lookup on this card; record the link by hand if you need it.
                BODY);
        }

        if ($keptNumberUnverifiedRepo) {
            $why = self::NUMBER_UNCONFIRMED;

            return new self($marker, <<<BODY
                A pull request in `{$repo}` names this card, and its `pr_number` matches the
                card's own — but {$why}, so
                a matching number cannot be told apart from a same-numbered pull request in a
                DIFFERENT repo. So the refs below were **not** written, and this card's
                existing `pr_number` is left exactly as it was:

                {$lines}
                Nothing else about the card was changed. If this pull request really is the
                one this card already tracks, stamp its `pr_url` by hand
                (`kbcard patch --task {$cardId} --pr-url <url>`) so a future event can verify it.
                BODY);
        }

        return new self($marker, <<<BODY
            A pull request in `{$repo}` names this card, but the card already carries a
            different correlation ref — and a card carries one of each, first write wins.
            So the refs below were **not** written, and this card stays correlated to the
            pull request it already names:

            {$lines}
            Nothing else about the card was changed. This second pull request is not
            reachable by a by-ref lookup on this card; record the link by hand if you need it.
            BODY);
    }

    /**
     * The move REFUSED because the `card#` token was title-only, uncorroborated by the
     * head branch, and this card already tracks a pull request not provably this one (DL-270). The
     * refusal is the right outcome — the note exists so the card shows that an event
     * claiming to be about it was turned away, rather than that nothing happened.
     *
     * What the card tracks is shown by its `pr_url` where that names a pull request — two
     * same-numbered PRs differ only by repo (DL-429), and "`148` is not `148`" would explain
     * nothing — else by its `pr_number`. A bare `pr_number` EQUAL to this event's is not "a
     * different pull request": the gate refused it because nothing confirms its repo
     * ({@see CardTokenCorroboration::matchesNumberUnconfirmed}), and the note says that
     * instead, in the stamp's own unconfirmed-number words.
     *
     * @param  array<string, mixed>  $card  the card as already read by getCard()
     */
    public static function refusedUncorroboratedMove(int $cardId, string $repo, array $card, mixed $eventPr): self
    {
        $url = PrUrlRef::parse(CardTokenCorroboration::cardPrUrl($card), new ExternalReferenceNormalizer);
        [$cardKey, $tracked] = $url !== null && $url->namesPr()
            ? ['pr_url', $url->raw]
            : ['pr_number', self::render(CardTokenCorroboration::cardPr($card))];
        // The event legitimately carries NO pull-request number — that is the fail-closed
        // arm of the gate (nothing corroborates the title, so the move is refused). Saying
        // `pr_number null` would read as a value; say what actually happened instead.
        $known = is_numeric($eventPr);
        $event = $known ? self::render($eventPr) : 'none';
        $which = $known
            ? "A pull request in `{$repo}` (`pr_number` `{$event}`) cited this card in its TITLE"
            : "An event from `{$repo}` carrying no pull-request number cited this card in a TITLE";

        $marker = self::marker('move-refused-uncorroborated-card-token', ['card' => (string) $cardId, 'pr_number' => $event]);

        if (CardTokenCorroboration::matchesNumberUnconfirmed($card, $eventPr)) {
            $tracks = "{$which} with nothing in its head branch agreeing, and this card already tracks `pr_number` `{$tracked}` — the same number — but "
                .self::NUMBER_UNCONFIRMED.', so the card cannot be shown to track THIS pull request.';

            return new self($marker, <<<BODY
                {$tracks} A title is prose — a descriptive
                citation of somebody else's card is written exactly like a claim to own this one —
                so the move was **refused** and nothing on this card was changed.

                If that pull request really is work on this card, name the card in its head branch
                (`card-{$cardId}-…`) so the token is corroborated, or stamp its `pr_url` by hand
                (`kbcard patch --task {$cardId} --pr-url <url>`) so this card names it.
                BODY);
        }

        return new self($marker, <<<BODY
            {$which} with nothing in its head branch agreeing, and this card already tracks a
            different pull request (`{$cardKey}` `{$tracked}`). A title is prose — a descriptive
            citation of somebody else's card is written exactly like a claim to own this one —
            so the move was **refused** and nothing on this card was changed.

            If that pull request really is work on this card, name the card in its head branch
            (`card-{$cardId}-…`) so the token is corroborated.
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
