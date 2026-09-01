<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;

/**
 * WHICH TAGS A CALLER MAY SUPPLY, and which belong to the bridge — one owner for
 * both board tools that touch a tag list (card#8378).
 *
 * Extracted from {@see BoardCreateCardTool} at its SECOND caller, not before: with
 * {@see BoardCorrectCardTool} there are now two surfaces on which a seat's own tag
 * text reaches a card, and two copies of this vocabulary would let them drift into
 * accepting different tags — which is the whole hazard of adding a second write
 * path. A tag the create tool refuses at birth must be refused by the correction
 * too, or the correction becomes the laundering route around the create guard.
 *
 * The reasoning the two constants encode is unchanged and belongs to DL-217 /
 * {@see BoardCreateCardTool}'s docblock, which still owns it: `created-by:` is the
 * audit stamp (a caller must not forge another agent's), `idem:` is the
 * idempotency correlation key (a caller must not poison a future probe), `id:` and
 * `type:` are the coord adoption / typing keys, and the bare `triaged` would
 * defeat born-untriaged. The match CASEFOLDS the caller's tag because whether the
 * backing tag search folds case is a per-driver collation fact.
 */
final class CallerTagPolicy
{
    /**
     * Tag prefixes a caller may not supply (provenance / correlation / adoption).
     * LOWERCASE — the match casefolds the caller tag before comparing.
     *
     * @var list<string>
     */
    public const RESERVED_PREFIXES = ['created-by:', 'idem:', 'id:', 'type:'];

    /**
     * Bare tags a caller may not supply — `triaged` would defeat born-untriaged.
     * LOWERCASE — the match casefolds the (trimmed) caller tag before comparing.
     *
     * @var list<string>
     */
    public const RESERVED_BARE = ['triaged'];

    /**
     * Whether a tag ALREADY ON A CARD is one the bridge (or the triage pass) owns
     * rather than the calling seat.
     *
     * The read direction of the same rule {@see sanitize} enforces on the write
     * direction, and single-sourced with it deliberately: `board_correct_card`
     * replaces a card's tag list wholesale (kanban stores it as one list), so it
     * must re-send exactly the tags a caller was never allowed to supply. If this
     * predicate and the sanitizer's refusal ever disagreed, a correction would
     * silently DELETE a tag the caller cannot restore.
     *
     * Casefolded like the sanitizer, and for the same collation reason — a card
     * carrying `Type:feature` is still the board's tag, not the caller's.
     */
    public static function isReserved(string $tag): bool
    {
        $folded = strtolower(trim($tag));
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($folded, $prefix)) {
                return true;
            }
        }

        return in_array($folded, self::RESERVED_BARE, true);
    }

    /**
     * The caller's `tags` argument, validated. Absent or null ⇒ `[]` (a caller
     * that named no tags); the CALLER decides what an absent list means (create
     * builds the birth list from it, the correction leaves the field alone), which
     * is why that discrimination stays at the call site.
     *
     * Refuses (422-class, via {@see ToolRefusalException}) a non-list, a
     * non-string/empty entry, an entry outside printable ASCII or carrying a
     * kanban tag-search metacharacter (`"`/`*`/`_`/`%`), and any entry matching
     * the reserved vocabulary above.
     *
     * @param  array<string, mixed>  $args  the caller-supplied argument object
     * @param  string  $tool  the tool name every refusal is prefixed with
     * @return list<string>
     */
    public static function sanitize(array $args, string $tool): array
    {
        if (! array_key_exists('tags', $args) || $args['tags'] === null) {
            return [];
        }
        $raw = $args['tags'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ToolRefusalException("{$tool}: `tags` must be a list of strings");
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (! is_string($tag) || $tag === '') {
                throw new ToolRefusalException("{$tool}: `tags` entries must be non-empty strings");
            }
            // Charset constraint (mirrors the idem-key posture): a tag outside
            // printable ASCII folds differently under the ASCII casefold below
            // than under a Unicode-aware driver collation, and a kanban
            // tag-search metacharacter (" * _ %) mis-splits or
            // wildcard-over-matches the tokenizer.
            // `D` anchor: PCRE `$` otherwise matches before a trailing "\n", so a
            // "tag\n" would pass — self-contained, not reliant on TrimStrings.
            if (preg_match('/^[\x20-\x7E]+$/D', $tag) !== 1 || strpbrk($tag, '"*_%') !== false) {
                throw new ToolRefusalException("{$tool}: the tag `{$tag}` contains a character outside printable ASCII or a kanban tag-search metacharacter (\" * _ %) — these defeat the case-insensitive reserved-tag guard or mis-match the kanban tokenizer");
            }
            // Casefold the reserved match: whether the backing tag search folds
            // case is a per-driver collation fact (see the class docblock), so
            // refuse every case variant (IDEM:… reaches the lowercase idem probe
            // on a folding backend). Safe now the charset is ASCII-constrained.
            $folded = strtolower(trim($tag));
            foreach (self::RESERVED_PREFIXES as $prefix) {
                if (str_starts_with($folded, $prefix)) {
                    throw new ToolRefusalException("{$tool}: the tag `{$tag}` uses the reserved prefix `{$prefix}` and cannot be caller-supplied (provenance/correlation/adoption tags are bridge-stamped)");
                }
            }
            if (in_array($folded, self::RESERVED_BARE, true)) {
                throw new ToolRefusalException("{$tool}: the tag `{$tag}` is reserved — the triage pass owns it, and tool-created cards are born untriaged by design");
            }
            $tags[] = $tag;
        }

        return $tags;
    }
}
