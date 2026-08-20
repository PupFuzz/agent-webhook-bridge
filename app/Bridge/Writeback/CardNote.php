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
 * one card + one dropped pull request is one note however many times a `pull_request`
 * event re-asserts it, so the same drop seen on `opened`, then `merged`, then a
 * redelivery of either, re-derives a byte-identical marker and is written once. That
 * holds because the dropped SET is stable per (card, pull request): add-if-missing can
 * only ever fill a ref with THIS event's own value, which then compares equal and never
 * enters the set, while a ref that already differs keeps differing. A genuinely
 * different second pull request is a different marker, and gets its own note.
 */
final class CardNote
{
    /**
     * Marker-line lead-in — the grep handle for every note this file mints. The marker is
     * CLOSED with a `]` (see {@see marker}) rather than left open-ended, and that bracket is
     * load-bearing for {@see alreadyOn}: an open-ended marker ending in a `pr_url` is a
     * PREFIX of the marker for any PR whose number extends it (`…/pull/26` inside
     * `…/pull/262`), so a substring match would read the note for PR 262 as already covering
     * the drop of PR 26 and suppress it — the silence this class exists to remove, minted by
     * its own idempotency check.
     */
    public const PREFIX = '[bridge:correlation-note';

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
     * @param  array<string, array{card: mixed, offered: mixed}>  $dropped
     */
    public static function droppedCorrelationRef(int $cardId, string $repo, array $dropped): self
    {
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

        return new self($marker, <<<BODY
            A pull request in `{$repo}` names this card, but the card already carries a
            different correlation ref — and a card carries one of each, first write wins.
            So the ref below was **not** written, and this card stays correlated to the
            pull request it already names:

            {$lines}
            Nothing else about the card was changed. This second pull request is not
            reachable by a by-ref lookup on this card; record the link by hand if you need it.
            BODY);
    }

    /**
     * The move REFUSED because the `card#` token was title-only, uncorroborated by the
     * head branch, and this card already tracks a different pull request (DL-270). The
     * refusal is the right outcome — the note exists so the card shows that an event
     * claiming to be about it was turned away, rather than that nothing happened.
     */
    public static function refusedUncorroboratedMove(int $cardId, string $repo, mixed $cardPr, mixed $eventPr): self
    {
        $card = self::render($cardPr);
        // The event legitimately carries NO pull-request number — that is the fail-closed
        // arm of the gate (nothing corroborates the title, so the move is refused). Saying
        // `pr_number null` would read as a value; say what actually happened instead.
        $known = is_numeric($eventPr);
        $event = $known ? self::render($eventPr) : 'none';
        $which = $known
            ? "A pull request in `{$repo}` (`pr_number` `{$event}`) cited this card in its TITLE"
            : "An event from `{$repo}` carrying no pull-request number cited this card in a TITLE";

        return new self(
            self::marker('move-refused-uncorroborated-card-token', ['card' => (string) $cardId, 'pr_number' => $event]),
            <<<BODY
            {$which} with nothing in its head branch agreeing, and this card already tracks a
            different pull request (`pr_number` `{$card}`). A title is prose — a descriptive
            citation of somebody else's card is written exactly like a claim to own this one —
            so the move was **refused** and nothing on this card was changed.

            If that pull request really is work on this card, name the card in its head branch
            (`card-{$cardId}-…`) so the token is corroborated.
            BODY
        );
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
            if (is_string($content) && str_contains($content, $this->marker)) {
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
