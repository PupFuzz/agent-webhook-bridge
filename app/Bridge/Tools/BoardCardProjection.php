<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * THE CARD SHAPE A BOARD READ TOOL RETURNS — one owner for every read on this door, so
 * `board_my_cards` and `board_get_cards` cannot come to disagree about what a card is
 * (extracted from {@see BoardMyCardsTool} at its second caller, card#10832).
 *
 * {@see FIELDS} is the projection's key vocabulary, and the `fields` argument of a read that
 * offers one selects from it ({@see select}). `BoardCardProjectionTest` holds FIELDS equal to the
 * keys {@see project} + {@see withSwimlane} + {@see withPosition} actually emit, so a key added to the projection and
 * not to FIELDS (or the reverse) reds rather than becoming unselectable.
 *
 * ⚠ {@see withSwimlane} and {@see withPosition} are AUGMENTERS a read applies on top of
 * {@see project}, not part of it: `board_my_cards`' own and shared lists apply both (card#11267),
 * its `tag_cards` only {@see withSwimlane}, and its coord cards neither — so folding either into
 * {@see project} would change the coord answer on every card.
 */
final class BoardCardProjection
{
    /**
     * Every selectable key. `description` selects BOTH description keys (`description` and
     * `description_truncated`): the truncation flag is what stops a seat reading a cut body as the
     * whole scope, so it is never selectable apart from the body it describes.
     *
     * @var list<string>
     */
    public const FIELDS = ['id', 'name', 'stage', 'position', 'swimlane_id', 'tags', 'assigned_user_id', 'dl_number', 'pr_number', 'pr_url', 'source', 'updated_at', 'description'];

    /** The field that is opt-in on every read: a body is ~2 KB, so it is never part of a default. */
    public const OPT_IN_FIELD = 'description';

    /**
     * FIELDS minus the opt-in body — what a read returns when the caller names no projection.
     *
     * @return list<string>
     */
    public static function defaultFields(): array
    {
        return array_values(array_diff(self::FIELDS, [self::OPT_IN_FIELD]));
    }

    /**
     * The `fields` argument of a read that offers a projection, validated — one rule for every such
     * read, so they cannot come to disagree about what a field name is (hoisted from
     * `board_get_cards` at its second caller, `board_search`). Absent (or null — the HTTP door hands
     * `""` over as null) ⇒ {@see defaultFields}, which leaves the body out. A repeated name is kept
     * once.
     *
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    public static function fieldsArgument(array $args, string $tool): array
    {
        $fields = $args['fields'] ?? null;
        if ($fields === null) {
            return self::defaultFields();
        }
        $vocabulary = implode(', ', array_map(fn (string $f): string => "`{$f}`", self::FIELDS));
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new ToolRefusalException("{$tool}: `fields` must be a list of field names, from: {$vocabulary}. Omit it for every field but `description`.");
        }
        $selected = [];
        foreach ($fields as $field) {
            // Trimmed as the HTTP door's middleware would have handed it over, so the ssh door does
            // not refuse a name the HTTP door accepts ({@see BoardToolArgs}).
            $name = is_string($field) ? BoardToolArgs::trimmed($field) : null;
            if ($name === null || ! in_array($name, self::FIELDS, true)) {
                throw new ToolRefusalException("{$tool}: `fields` names ".json_encode($field)." — not a card field. The fields are: {$vocabulary}.");
            }
            $selected[] = $name;
        }

        return array_values(array_unique($selected));
    }

    /**
     * The refusal reason for the one argument every projecting read is asked for and does not take:
     * a card's body is selected per call by naming `description` in `fields`.
     */
    public static function refusedDescriptionArgument(string $key): ?string
    {
        if (in_array(strtolower($key), ['include_description', 'description'], true)) {
            return "`{$key}` is not an argument here — a card's body is selected per call by naming `description` in `fields`.";
        }

        return null;
    }

    /**
     * Keep only the keys `$fields` selects. A key the card does not carry stays absent — the
     * projection's own absent-vs-null rules ({@see withSwimlane}) survive selection.
     *
     * @param  array<string, mixed>  $card  as {@see project} (and optionally {@see withSwimlane}) returned it
     * @param  list<string>  $fields  a subset of {@see FIELDS}
     * @return array<string, mixed>
     */
    public static function select(array $card, array $fields): array
    {
        $keep = array_flip($fields);
        if (isset($keep[self::OPT_IN_FIELD])) {
            $keep['description_truncated'] = true;
        }

        return array_intersect_key($card, $keep);
    }

    /**
     * Project a raw kanban card row to the board tools' card shape (DL-217): id, name,
     * stage, tags, assigned_user_id, dl_number, pr_number, pr_url, source, updated_at —
     * plus, ONLY when the caller opted in (DL-245), description + description_truncated.
     * Nothing else leaves the bridge.
     *
     * `source` (card#9837) is the repo qualifier kanban applies to this card's refs, derived
     * by {@see ExternalReferenceNormalizer::sourceFor} (the bridge's mirror of kanban's own
     * derivation, which owns the input order) over the row's payload and its top-level
     * `external_link`. It matters only on a shared board, only to by-ref correlation, and
     * only on a card carrying a `dl_number` / `pr_number` / `issue_number` — a `card#` token
     * is not filtered by it; `docs/board-tools.md` § `board_my_cards` states the scope.
     * `pr_url` is one input to it, not the answer, and is also returned raw, as stored card
     * text given the same treatment as `name`: a scalar is stringified, and anything else
     * reads null, exactly as an absent key does.
     *
     * ⭐ `assigned_user_id` IS THE RAW BOARD FIELD AND CARRIES NO NAME (card#9170). It is
     * what makes a claimed-but-unmoved card legible: a card whose column never moved is
     * otherwise indistinguishable from an unclaimed one, so two seats pull the same work.
     * ⛔ THE BRIDGE DOES NOT RESOLVE THE ID TO A SEAT, deliberately and permanently —
     * doing so would need a fleet-wide seat→kanban-user map, which is the cross-repo table
     * canon #7 warns about and which {@see SeatKanbanUser} exists to make unnecessary. The
     * raw id is REPORTED and never a failure; a consumer that knows a name for it renders
     * one (`kbcard` does, from its own board env), and one that does not shows the id.
     * `null` is a real value meaning UNASSIGNED; a row answering nothing about its
     * assignment also reads null here, because on a READ projection the two are the same
     * to a caller — {@see BoardTakeCardTool} is where that distinction is load-bearing,
     * and it refuses rather than guessing.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $stageNames
     * @param  ?int  $descriptionCap  null ⇒ omit both description keys entirely
     * @return array<string, mixed>
     */
    public static function project(array $row, array $stageNames, ?int $descriptionCap): array
    {
        $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $tags = [];
        foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        $card = [
            'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : null,
            'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : null,
            'stage' => $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : null,
            'tags' => $tags,
            'assigned_user_id' => is_numeric($row['assigned_user_id'] ?? null) ? (int) $row['assigned_user_id'] : null,
            'dl_number' => is_scalar($payload['dl_number'] ?? null) ? $payload['dl_number'] : null,
            'pr_number' => is_scalar($payload['pr_number'] ?? null) ? $payload['pr_number'] : null,
            'pr_url' => is_scalar($payload['pr_url'] ?? null) ? (string) $payload['pr_url'] : null,
            'source' => (new ExternalReferenceNormalizer)->sourceFor($payload, is_string($row['external_link'] ?? null) ? $row['external_link'] : null),
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        ];

        if ($descriptionCap !== null) {
            [$card['description'], $card['description_truncated']] = self::capDescription($row['description'] ?? null, $descriptionCap);
        }

        return $card;
    }

    /**
     * Cut a description to the byte cap, reporting whether anything was cut. The
     * flag is load-bearing: a seat must never be able to mistake a truncated
     * scope for the whole scope.
     *
     * `mb_strcut`, not `substr` — the cap is a BYTE budget (response size is what
     * the opt-in bounds), and a raw byte cut can split a multi-byte character. The
     * invalid UTF-8 that produces would fail `json_encode` for the WHOLE response,
     * so one emoji at the cut point would blank the caller's entire board window.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function capDescription(mixed $raw, int $cap): array
    {
        if (! is_scalar($raw)) {
            return [null, false];
        }
        $description = (string) $raw;
        $cut = mb_strcut($description, 0, $cap, 'UTF-8');

        return [$cut, $cut !== $description];
    }

    /**
     * The projected card plus the lane the row says it is in. ⚠ A PRESENT NULL AND AN ABSENT KEY
     * ARE DIFFERENT ANSWERS ON THIS AXIS — a card really can be in no lane (DL-302's asymmetry,
     * {@see BoardMyCardsTool::observedBoard}). So `null` is reported only when the row said null; a row that
     * carried no readable lane field gets no `swimlane_id` key at all, never a null that would
     * call it laneless.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withSwimlane(array $card, array $row): array
    {
        if (array_key_exists('swimlane_id', $row) && $row['swimlane_id'] === null) {
            $card['swimlane_id'] = null;
        } elseif (is_numeric($row['swimlane_id'] ?? null)) {
            $card['swimlane_id'] = (int) $row['swimlane_id'];
        }

        return $card;
    }

    /**
     * The projected card plus its `position` — kanban's fractional in-column ordering value
     * (`TaskResource::position`). ⭐ Per the operator's ruling (rt#552), card order within a column IS
     * the priority order: top = lowest position, and the total order key is `(position ASC, id ASC)`
     * within a stage — `id` breaks ties because two cards can share a position. A row with no numeric
     * `position` gets no key at all, never a null a caller could sort as a value.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withPosition(array $card, array $row): array
    {
        if (is_int($row['position'] ?? null) || is_float($row['position'] ?? null)) {
            $card['position'] = (float) $row['position'];
        }

        return $card;
    }
}
