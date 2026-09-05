<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Writeback\KanbanFieldLimits;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\WritebackConfig;

/**
 * WHICH TAGS A CALLER MAY SUPPLY, and which tags ALREADY ON A CARD must survive a
 * wholesale replace — one owner for both board tools that touch a tag list
 * (card#8378).
 *
 * ⭐ THOSE ARE TWO DIFFERENT QUESTIONS AND THIS CLASS ANSWERS THEM SEPARATELY. REFUSE
 * ({@see sanitize}) is *what a caller may not forge*; PRESERVE ({@see isPreserved}) is
 * *what on this card is somebody ELSE'S control*. The first cut of this class derived
 * preserve FROM refuse and called them one rule read two ways; they are not, and the
 * derived set is strictly the smaller one: `no-automove` is forgeable (a seat may
 * pin its own card) and yet it is the writeback's all-outcome hold
 * ({@see PinGuard::isPinned}), so deriving one from the other made a `tags` correction
 * DELETE a human's pin and hand the next `merged` event the terminal move card#8289
 * exists to prevent. PRESERVE ⊇ REFUSE is therefore the invariant, and
 * the containment is asserted rather than assumed.
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
     * Bare tags that must SURVIVE a wholesale tag replace although a caller may
     * legitimately supply them. LOWERCASE — the match casefolds.
     *
     * `no-automove` is {@see PinGuard}'s all-outcome writeback hold: the tag half of
     * the pin whose other half (`block_reason`) the correction tool already refuses to
     * touch by name. It is deliberately NOT in the refuse set — pinning your
     * own card is a legitimate thing for a seat to do, and `board_create_card` has
     * always accepted it — which is exactly why preserve cannot be derived from refuse.
     *
     * ⚠ The install's own `hold_marker_tags` (DL-194) are the OPERATOR-declared other
     * half of this set and are not constants: they arrive per call from
     * {@see WritebackConfig::holdMarkerTagsForBoard}.
     *
     * @var list<string>
     */
    public const PRESERVED_BARE = ['no-automove'];

    /**
     * Whether a tag ALREADY ON A CARD is somebody else's control rather than the
     * calling seat's content — so a wholesale `tags` replace must re-send it.
     *
     * A SUPERSET of the refuse vocabulary, never its mirror (see the class docblock):
     * the bridge-stamped provenance/correlation/typing tags a caller may not supply,
     * PLUS the pin and hold markers a caller MAY supply but which, once on the card,
     * belong to whoever put them there — the writeback's `no-automove` and every
     * `hold_marker_tags` entry this install declares for the card's board.
     *
     * ⛔ The hold set is passed IN rather than read here: it is per-board operator
     * config (`writeback.json`), and a policy class that loaded config would make every
     * caller's reachability question invisible. The caller resolves it and decides what
     * an unreadable config means.
     *
     * Casefolded like the sanitizer, and for the same collation reason — a card
     * carrying `Type:feature` is still the board's tag, not the caller's. The fold is
     * WIDER than the exact compares `PinGuard`/`KanbanMoveCardHandler` make on the same
     * tags, deliberately: over-preserving costs a caller one tag it can re-drop by
     * re-supplying the list, while under-preserving destroys a control it cannot
     * restore.
     *
     * @param  list<string>  $installHoldTags  this board's `hold_marker_tags` (DL-194)
     */
    public static function isPreserved(string $tag, array $installHoldTags): bool
    {
        $folded = strtolower(trim($tag));
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($folded, $prefix)) {
                return true;
            }
        }
        if (in_array($folded, self::RESERVED_BARE, true) || in_array($folded, self::PRESERVED_BARE, true)) {
            return true;
        }
        foreach ($installHoldTags as $hold) {
            if ($folded === strtolower(trim($hold))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The caller's `tags` argument, validated. Absent or null ⇒ `[]` (a caller
     * that named no tags); the CALLER decides what an absent list means (create
     * builds the birth list from it, the correction leaves the field alone), which
     * is why that discrimination stays at the call site.
     *
     * Refuses (422-class, via {@see ToolRefusalException}) a non-list, a
     * non-string/empty entry, an entry outside printable ASCII or carrying a
     * kanban tag-search metacharacter (`"`/`*`/`_`/`%`), an entry longer than
     * {@see KanbanFieldLimits::TAG_MAX}, and any entry matching the reserved
     * vocabulary above.
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
            // Kanban's own cap ({@see KanbanFieldLimits::TAG_MAX}, a mirror — that class
            // states what a mirror is worth), so an over-long tag is a NAMED
            // caller-fixable refusal instead of a board 422 the seat cannot read.
            // mb_strlen, because Laravel's `max` sizes a string with it — the charset
            // guard above already forces one byte per character here, so the two agree
            // by construction and this stays right if that rule ever widens.
            if (mb_strlen($tag) > KanbanFieldLimits::TAG_MAX) {
                throw new ToolRefusalException("{$tool}: the tag `{$tag}` is ".mb_strlen($tag).' characters — kanban accepts at most '.KanbanFieldLimits::TAG_MAX.' per tag (`tags.* => string|max:64`), so the board would reject the write. Shorten it.');
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
                throw new ToolRefusalException("{$tool}: the tag `{$tag}` is reserved — tool-created cards are born untriaged by design (they surface to the triage pass)");
            }
            $tags[] = $tag;
        }

        return $tags;
    }
}
