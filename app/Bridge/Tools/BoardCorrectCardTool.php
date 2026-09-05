<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_correct_card (DL-326, card#8378) — CORRECT a card the calling agent
 * ITSELF filed. The third tool beside {@see BoardMyCardsTool} (read) and
 * {@see BoardCreateCardTool} (create), and the one that stops duplicate-minting
 * from being a seat's only available response to its own wrong card: before this,
 * an impl seat's whole board surface was create + read, so a card minted with a
 * wrong title could only be answered with a SECOND card — which then defeats every
 * downstream instrument that keys on one card per subject.
 *
 * ⭐ WHOSE CARD — the scoping question that decides this tool. A seat may correct
 * ONLY what it minted, and the discriminator is the tag {@see BoardCreateCardTool}
 * already stamps: `created-by:<agent>`, bridge-written and caller-unforgeable (the
 * `created-by:` prefix is reserved on create and the guard casefolds, so no caller
 * can plant any case variant of another agent's stamp). Provenance therefore
 * already existed — nothing had to be minted for it.
 *
 * ⛔ NOT `actor_type`, and NOT `actor_id`. `actor_type: service` covers the bridge
 * AND every CLI writer, so it discriminates nothing; and `actor_id` identifies the
 * kanban USER, which every agent shares (they all write through the one writeback
 * user). Neither can answer "which SEAT filed this", which is the question. The
 * tag is the only per-seat provenance a card carries.
 *
 * ⛔ The stamp compare is CASE-SENSITIVE, deliberately the narrow direction. Agent
 * names are filesystem-cased config names, so `me` and `ME` can be two seats and a
 * casefolded compare would let one correct the other's cards. Nothing is lost by
 * being narrower than the writer: the bridge is the only writer of a `created-by:`
 * tag and it writes the exact agent name.
 *
 * ⭐ THE ID IS CALLER-SUPPLIED, SO IT IS ESTABLISHED BOARD-SCOPED BEFORE ANYTHING
 * READS IT. kanban's card id space is GLOBAL across every board on the instance,
 * so this tool resolves the card through
 * {@see KanbanClient::cardRowsOnBoard} — `q=board_id=<b> id=<n>` — and never
 * through `getCard()`, whose own docblock records that a caller reaching it with an
 * author-supplied id and no such check is the card#8375 / DL-323 defect. The
 * verdict is read off the ROWS, never off the call: the endpoint drops a term it
 * does not recognise and still answers 200, so a row establishes the card only if
 * its own `id` names it and its own `board_id` is this agent's configured board.
 * Board scope and the mint stamp are two INDEPENDENT narrowings and both are
 * required.
 *
 * The LANE is deliberately not part of the authorization. A human may re-lane a
 * card legitimately, and the mint stamp is what says the card is the seat's; a lane
 * test would make a re-laned card permanently uncorrectable by the seat that filed
 * it. The response therefore reports no lane — it reports only what was checked.
 *
 * WHAT IS CORRECTABLE: `name`, `description`, `tags` — the caller-owned content,
 * and nothing else. Everything a caller might name that this tool does not own is
 * refused BY NAME with its owner ({@see FIELD_OWNERS}), never ignored: a silently
 * dropped argument leaves the seat believing it corrected something it did not,
 * which is the "refuse loudly, never silently no-op" this card was filed on. ⛔ The
 * offered set is deliberately NARROWER than `kbcard patch`'s corrective setters —
 * `type`, `external_id` and `origin` are refused — because THIS TOOL MUST NEVER
 * WRITE A FIELD `board_create_card` WOULD REFUSE AT BIRTH. A wider correction
 * authority than create authority is a laundering route: mint a clean card, then
 * "correct" in the reserved `type:` key, `triaged`, or a payload key the create
 * tool rejects outright.
 *
 * TAGS ARE REPLACED WHOLESALE BY KANBAN, so the write re-sends every tag on the card
 * that is somebody else's control ({@see CallerTagPolicy::isPreserved}) and lets the
 * caller's list replace only the rest. ⭐ THAT SET IS A SUPERSET OF THE TAGS A CALLER
 * MAY NOT SUPPLY, and the difference is the whole point: the bridge-stamped ones
 * (`created-by:` — losing it locks the seat out of its own card; `idem:` —
 * re-opening duplicate minting under that key; `triaged`/`type:` — undoing the triage
 * pass) a caller could not restore because it may not supply them, but `no-automove`
 * and this install's `hold_marker_tags` are a HUMAN's pin on the card, forgeable and
 * therefore absent from the refuse list, and deleting one hands the next `merged`
 * event the terminal move card#8289 exists to prevent. This tool already refuses
 * `block_reason` by name; preserving only half the same pin would protect one half and
 * destroy the other.
 *
 * ⚠ `kbcard` records the sharp edge of a read-merge-write on this field — an unreadable
 * tag list treated as "no tags" destroys every tag — and it is UNREACHABLE here rather
 * than guarded: the preserved set is built from the same row whose tags had to contain
 * `created-by:<agent>` for the call to be authorized at all, so a row with no readable
 * tag list is refused before any write is composed. The OPERATOR-declared half of the
 * set is a different matter, because it comes from `writeback.json` rather than from
 * the row: a config the bridge cannot parse means the hold vocabulary is UNKNOWN, and a
 * wholesale replace under an unknown hold vocabulary is exactly the deletion this
 * paragraph exists to stop — so a `tags` correction refuses there, naming the config.
 *
 * ⭐ A PINNED CARD REFUSES A `name` CORRECTION (card#8557). The DL-178 hold — a non-empty
 * `block_reason` or a `no-automove` tag — governs a card's STAGE, its LIFECYCLE and, since
 * that card, the fields {@see PinGuard::PINNED_FIELDS} names, and this door is one of the
 * producers that writes one. The seat is told BY NAME and nothing is written; a silent
 * no-op would leave it believing it renamed a card it did not, which is the same defect
 * the foreign-argument refusal above exists to prevent, and the pin's whole point is that
 * an operator returns to the card they froze. ⛔ IT IS SCOPED TO THE GOVERNED FIELDS, not to
 * the call: `description` and `tags` corrections still land on a held card, because the
 * ruling deliberately did not freeze every field (freezing them all would stop the
 * correlation stamps automation legitimately writes). ⚠ A call that sends `name` ALONGSIDE
 * them writes NOTHING — the PATCH is one request and there is no half-applied form of it —
 * so the refusal says so rather than leaving the seat to infer which half landed. ⚠ Its
 * REPORT is this tool's, not `PinGuard`'s: the writeback arms mint an `alert_channel` push
 * keyed `(repo, outcome, reason)` and this door has neither a repo nor an outcome, so the
 * refusal travels back down the call that asked, carrying {@see PinGuard::REASON} in the log.
 *
 * REFUSALS ARE DETERMINISTIC, WHICH IS WHY THE BOARD'S OWN 4xx ARE REPORTED AS ONE.
 * Every refusal here is a {@see ToolRefusalException} (422-class): a request that fails
 * identically however many times it is sent. That deliberately includes a 401, 403, 404
 * or 422 the BOARD answers — a rotated token, a permission fault, a card that no longer
 * exists and a value kanban's validator rejects are all permanent — and reporting them
 * as the dispatcher's retryable 502 would send a seat into the retry loop DL-020 exists
 * to warn about. Each names its own cause, and the ones that are an INSTALL fault
 * rather than a caller fault say so. ⛔ The 422 arm never echoes the board's body: it is
 * an upstream response, and the tool's own bounded messages are what the seat can act
 * on.
 */
final class BoardCorrectCardTool implements Tool
{
    /**
     * The arguments this tool accepts. Anything else is refused — see
     * {@see FIELD_OWNERS} for the ones refused with a named owner.
     *
     * @var list<string>
     */
    private const CORRECTABLE = ['name', 'description', 'tags'];

    /**
     * Fields a caller may plausibly try to correct that are NOT this tool's to
     * write, each with the authority that owns it. Keyed LOWERCASE; the arg name is
     * casefolded before the lookup so a `Column` gets the named reason rather than
     * the generic unknown-argument one.
     *
     * @var array<string, string>
     */
    private const FIELD_OWNERS = [
        'workflow_stage_id' => 'a column move is a DIFFERENT authority and is deliberately not exposed here',
        'stage' => 'a column move is a DIFFERENT authority and is deliberately not exposed here',
        'column' => 'a column move is a DIFFERENT authority and is deliberately not exposed here',
        'move' => 'a column move is a DIFFERENT authority and is deliberately not exposed here',
        'swimlane_id' => 'your write scope is forced from your bridge identity — an argument never names a lane or a board',
        'board_id' => 'your write scope is forced from your bridge identity — an argument never names a lane or a board',
        'payload' => 'the bridge stamps card payload (correlation refs); a seat never writes it',
        'dl_number' => 'a correlation ref the bridge writeback stamps from a merged PR, never a caller',
        'pr_number' => 'a correlation ref the bridge writeback stamps from a merged PR, never a caller',
        'pr_url' => 'a correlation ref the bridge writeback stamps from a merged PR, never a caller',
        'issue_number' => 'a correlation ref the bridge writeback stamps, never a caller',
        'issue_url' => 'a correlation ref the bridge writeback stamps, never a caller',
        'version' => 'a correlation ref the bridge writeback stamps at release, never a caller',
        'origin' => 'a payload custom field — the bridge owns card payload',
        'external_id' => 'the board-unique sync id; the bridge does not set it even at create (a colliding id 422s the whole board)',
        'external_link' => 'the correlation link the by-ref lookup derives its source from; bridge-owned',
        'type' => '`type:` is a RESERVED tag prefix (the triage/coord typing key) and is refused at create too',
        'card_type_id' => '`type:` is a RESERVED tag prefix (the triage/coord typing key) and is refused at create too',
        'triaged' => 'tool-filed cards are born untriaged by design; the triage pass owns that tag',
        'block_reason' => "the writeback's pinned-card opt-out (DL-193) — bridge-owned",
        'archived' => 'a retire is a lifecycle act, not a field write, and is not this tool\'s to make or undo',
        'archived_at' => 'a retire is a lifecycle act, not a field write, and is not this tool\'s to make or undo',
        '_action' => 'a lifecycle control key, not a field — not this tool\'s to send',
        'priority' => 'not part of this tool\'s contract',
        'due_date' => 'not part of this tool\'s contract',
        'assigned_user_id' => 'not part of this tool\'s contract',
    ];

    public function name(): string
    {
        return 'board_correct_card';
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // EVERY argument is validated before any request is made, so a refused
        // call reads nothing and writes nothing.
        $this->refuseForeignArguments($args);
        $cardId = $this->requireCardId($args);
        $fields = $this->textCorrections($args);
        $callerTags = $this->callerTags($args);
        if ($fields === [] && $callerTags === null) {
            throw new ToolRefusalException('board_correct_card: nothing to correct — pass at least one of `name`, `description`, `tags`. Nothing was written.');
        }

        $boardId = (int) $cfg->boardId;
        // Resolved BEFORE the lookup, so a call that cannot establish the hold
        // vocabulary reads nothing and writes nothing — and so a config fault is
        // reported as itself rather than as a tag correction that half-applied.
        $holdTags = $callerTags === null ? [] : $this->installHoldTags($boardId);
        $row = $this->ownedRow($client, $boardId, $cardId, $agentName);

        $tagsWritten = $callerTags === null ? null : $this->tagsToWrite($row, $callerTags, $holdTags);
        if ($tagsWritten !== null) {
            $fields['tags'] = $tagsWritten;
        }
        if (PinGuard::isPinnedAgainst($row, $fields)) {
            Log::warning('board_correct_card: refused — the card is PINNED and this correction writes a field the pin governs', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
                'fields' => array_keys($fields), 'reason' => PinGuard::REASON,
            ]);

            throw new ToolRefusalException("board_correct_card: card {$cardId} is PINNED — it carries a `block_reason` or a `no-automove` tag, which is how a human says this card is frozen, and a `name` correction is one of the writes that hold covers (the bridge's own automation is refused the same write on the same card). NOTHING WAS WRITTEN — not the name, and not the `description` or `tags` you may have sent with it. Ask whoever pinned it to lift the pin, or correct `description`/`tags` on their own without `name`.");
        }

        try {
            $client->patchCard($cardId, $fields);
        } catch (RequestException $e) {
            throw $this->writeRefusal($e, $cardId, $agentName);
        }

        $corrected = array_keys($fields);
        Log::info('board_correct_card: corrected', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'fields' => $corrected,
        ]);

        $result = [
            'corrected' => true,
            'card_id' => $cardId,
            // OBSERVED, not restated (the DL-299 rule): the row that authorized this
            // write was accepted only because its OWN `board_id` equalled this value,
            // so on any call that reaches here the two are the same reading — a
            // divergence is a refusal, not a response. That is why there is no
            // `*_observed` flag: there is no state in which this tool answers with a
            // board it did not read.
            'board_id' => $boardId,
            'fields' => $corrected,
        ];
        if ($tagsWritten !== null) {
            $result['tags_written'] = $tagsWritten;
        }

        return $result;
    }

    /**
     * Refuse any argument this tool does not own — with the OWNER named when the
     * field has one. `board_create_card` can ignore an out-of-scope argument
     * because its answer ("your card was created") stays true; a correction that
     * ignored one would answer 200 for a change it never made.
     *
     * @param  array<string, mixed>  $args
     */
    private function refuseForeignArguments(array $args): void
    {
        $accepted = array_merge(['card_id'], self::CORRECTABLE);
        foreach (array_keys($args) as $key) {
            $key = (string) $key;
            if (in_array($key, $accepted, true)) {
                continue;
            }
            $owner = self::FIELD_OWNERS[strtolower($key)] ?? null;
            if ($owner !== null) {
                throw new ToolRefusalException("board_correct_card: `{$key}` is not correctable here — {$owner}. Nothing was written.");
            }

            throw new ToolRefusalException("board_correct_card: unknown argument `{$key}` — this tool accepts `card_id` plus ".implode(', ', array_map(static fn (string $f): string => "`{$f}`", self::CORRECTABLE)).'. Nothing was written.');
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function requireCardId(array $args): int
    {
        $cardId = $args['card_id'] ?? null;
        // is_int, not is_numeric: a float or a decorated string is a caller bug
        // whose coercion would name a DIFFERENT card, and this id selects the row
        // a write lands on.
        if (! is_int($cardId) || $cardId < 1) {
            throw new ToolRefusalException('board_correct_card: `card_id` is required and must be a positive integer (the id `board_my_cards` reports for the card)');
        }

        return $cardId;
    }

    /**
     * The TEXT fields this call writes.
     *
     * ⭐ ONE RULE FOR EVERY ARGUMENT: a key that is PRESENT is a correction; a key
     * that is ABSENT leaves its field alone. So `description` present with an empty
     * value CLEARS the description, and omitting it is how you keep it.
     *
     * ⛔ `null` and `""` are ONE VALUE here, and that is a measured property of the
     * HTTP door rather than a choice: Laravel's global `ConvertEmptyStringsToNull`
     * (with `TrimStrings` ahead of it) rewrites `"description": ""` to null before
     * the controller reads `args`, while the ssh door
     * (`bridge:tools-call`) json_decodes the body itself and preserves it. Treating
     * them as one value is what keeps this tool's contract identical on both
     * transports; discriminating them would give the same call two meanings
     * depending on how the seat is wired. ⚠ This is why `board_create_card`'s rule
     * (present-null == absent) is deliberately NOT copied — there, null cannot mean
     * "clear", because a card being born has nothing to clear.
     *
     * `name` has no empty form: a card must have one, so a present-but-empty name is
     * refused rather than written — and it is bounded at
     * {@see KanbanFieldLimits::NAME_MAX}, kanban's own cap, so an over-long title is a
     * named caller-fixable refusal instead of a board 422 the seat reads as a retryable
     * `502 upstream board error`.
     *
     * ⭐ THE DESCRIPTION IS TRIMMED, AND THAT IS WHAT MAKES "CLEAR" MEAN THE SAME THING
     * ON BOTH DOORS. `TrimStrings` runs ahead of `ConvertEmptyStringsToNull` on the HTTP
     * door only, so `"   "` arrives as null there (⇒ clear) and as three spaces over
     * ssh (⇒ a card whose body is whitespace). Trimming here converges them on the
     * behaviour the HTTP door already has, rather than leaving one transport with a
     * second meaning for the same call.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    private function textCorrections(array $args): array
    {
        $fields = [];

        if (array_key_exists('name', $args)) {
            $name = $args['name'];
            if (! is_string($name) || trim($name) === '') {
                throw new ToolRefusalException('board_correct_card: `name` must be a non-empty string — a card cannot be left without one, so there is no "clear" for this field (omit `name` to leave it alone)');
            }
            $tooLong = BoardCallRefusal::overLongName($this->name(), 'name', $name, 'Nothing was written');
            if ($tooLong !== null) {
                throw $tooLong;
            }
            $fields['name'] = $name;
        }

        if (array_key_exists('description', $args)) {
            $description = $args['description'];
            if ($description !== null && ! is_string($description)) {
                throw new ToolRefusalException('board_correct_card: `description` must be a string, or null/"" to CLEAR it (omit `description` to leave it alone)');
            }
            $fields['description'] = trim($description ?? '');
        }

        return $fields;
    }

    /**
     * The caller's sanitized tag list, or NULL when the call names no tags at all —
     * the same present/absent rule {@see textCorrections} states. An empty list (or
     * a null, which the HTTP door cannot tell from one) is a real instruction:
     * *drop my tags*. It is not the same as omitting the key, and the two must not
     * collapse when the write replaces the list wholesale.
     *
     * @param  array<string, mixed>  $args
     * @return list<string>|null
     */
    private function callerTags(array $args): ?array
    {
        if (! array_key_exists('tags', $args)) {
            return null;
        }

        return CallerTagPolicy::sanitize($args, $this->name());
    }

    /**
     * The card's row, established on THIS agent's board AND carrying THIS agent's
     * mint stamp — or a refusal. Both narrowings are required and neither is
     * sufficient (see the class docblock).
     *
     * ⚠ A not-yours refusal and a no-such-card refusal are DELIBERATELY one
     * message: the seat is not told whether a card it does not own exists, which is
     * the same non-disclosure posture the door already takes on an unknown bearer.
     * The one exception is the card the seat DOES own on the archived side — naming
     * the retire there is not a disclosure (the stamp proves the card is the
     * caller's), and the alternative is telling a seat that a card it demonstrably
     * filed is "not one of yours", which is a false statement made by a guard.
     *
     * @return array<string, mixed>
     */
    private function ownedRow(KanbanClient $client, int $boardId, int $cardId, string $agentName): array
    {
        try {
            $live = $client->cardRowsOnBoard($boardId, $cardId);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        $row = $this->matchingRow($live, $boardId, $cardId);
        if ($row !== null) {
            if (! $this->stampedBy($row, $agentName)) {
                Log::warning('board_correct_card: refused — the card is on the agent\'s board but does not carry its mint stamp', [
                    'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
                ]);

                throw new ToolRefusalException($this->notYoursMessage($cardId, $boardId));
            }

            return $row;
        }

        if ($live !== []) {
            // The lookup answered SOMEBODY ELSE'S row: a broken read, never a
            // verdict about this card (DL-323 Decision 2's `board_scope_lookup_unfiltered`).
            Log::warning('board_correct_card: the board-scoped lookup answered a row that is not this card on this board — refusing without a tenant verdict', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'rows' => count($live),
            ]);

            throw new ToolRefusalException("board_correct_card: the board lookup for card {$cardId} answered a row that is not that card on your board — that is a BROKEN READ, not a verdict about the card, so nothing was written. Report it to your operator.");
        }

        // Only now — on a live MISS, so it costs nothing on any successful call —
        // ask the other side of kanban's archive SWITCH (DL-296: no both-sides
        // mode). Without it a retired card of the seat's own is refused as "not
        // yours", which is untrue and unactionable.
        try {
            $archived = $client->cardRowsOnBoard($boardId, $cardId, archivedOnly: true);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        $retired = $this->matchingRow($archived, $boardId, $cardId);
        if ($retired !== null && $this->stampedBy($retired, $agentName)) {
            throw new ToolRefusalException("board_correct_card: card {$cardId} is ARCHIVED — an archived card is a deliberate retire, and un-retiring one is not this tool's to do, so nothing was written. Unarchive it if the work is live again.");
        }

        Log::warning('board_correct_card: refused — no card with this id is on the agent\'s board', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException($this->notYoursMessage($cardId, $boardId));
    }

    /**
     * ⚠ THE THIRD DISJUNCT IS NOT PADDING — IT IS THE ONLY CHANNEL AN UNREADABLE BOARD
     * HAS. kanban's search floors a caller to the boards it is a MEMBER of and answers
     * 200 with zero rows for every other one, so a board the writeback token cannot see
     * and a board with no such card are ONE answer here (DL-323's
     * `mapped_board_unreadable_to_this_token`); without this sentence the seat is told
     * "not one of yours" about a card that may well be its own. It is on the SHARED
     * message deliberately: a sentence added to the no-rows arm alone would make the
     * three arms distinguishable and undo the non-disclosure Decision 9 is built on.
     */
    private function notYoursMessage(int $cardId, int $boardId): string
    {
        return "board_correct_card: card {$cardId} is not one of yours — this tool corrects only cards YOU filed (the bridge's `created-by:` mint stamp) on your own board. Nothing was written. ⚠ A board the bridge's writeback token is not a MEMBER of answers exactly the same way: kanban's search returns zero rows rather than an error, so an unreadable board and an empty one are one answer here — if you believe you filed this card, have your operator check that token's membership of board {$boardId}. Use `board_my_cards` to see the cards you can correct, or `board_create_card` if this is new work.";
    }

    /**
     * The one row that IS this card on this board, or null. The rows are what
     * establish the scope — never the fact that the call was made with a scoped
     * query (an unrecognised term degrades to free text and still answers 200).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function matchingRow(array $rows, int $boardId, int $cardId): ?array
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $board = $row['board_id'] ?? null;
            if (is_numeric($id) && (int) $id === $cardId && is_numeric($board) && (int) $board === $boardId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether the row carries THIS agent's mint stamp. Case-SENSITIVE (class
     * docblock), and a row whose `tags` are unreadable is not stamped — fail-closed,
     * and the same read that makes the wholesale tag rewrite safe.
     *
     * @param  array<string, mixed>  $row
     */
    private function stampedBy(array $row, string $agentName): bool
    {
        return in_array("created-by:{$agentName}", $this->rowTags($row), true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowTags(array $row): array
    {
        $tags = [];
        foreach (is_array($row['tags'] ?? null) ? $row['tags'] : [] as $tag) {
            if (is_string($tag)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * The tag list the PATCH sends: the caller's list, plus every tag the card
     * already carries that is somebody ELSE'S control. kanban replaces `tags`
     * wholesale, so a tag not re-sent is a tag deleted.
     *
     * De-duplicated preserving first-seen order (the caller's own order survives),
     * so a caller re-sending a tag the card already carries writes it once.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $callerTags  the sanitized caller list
     * @param  list<string>  $holdTags  this board's declared `hold_marker_tags`
     * @return list<string>
     */
    private function tagsToWrite(array $row, array $callerTags, array $holdTags): array
    {
        $tags = $callerTags;
        foreach ($this->rowTags($row) as $existing) {
            if (CallerTagPolicy::isPreserved($existing, $holdTags)) {
                $tags[] = $existing;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * This board's OPERATOR-DECLARED hold-marker tags (DL-194), or a refusal when the
     * config that declares them cannot be read.
     *
     * ⭐ THE SEAT KNOWS A BOARD AND `writeback.json` IS KEYED BY REPO, which is the only
     * reason this needs a primitive at all: {@see WritebackConfig::holdMarkerTagsForBoard}
     * is the board-keyed read, deduped across every repo mapping that targets the board
     * (several can).
     *
     * THE THREE STATES ARE DIFFERENT ANSWERS AND ARE NOT COLLAPSED:
     *  - no `writeback.json` (or no config dir) ⇒ `loadDefault()` is null ⇒ this install
     *    declares no hold tags. `[]` is the TRUE answer, not a fallback.
     *  - a parsed config ⇒ the declared set, possibly empty for this board.
     *  - a config that will not parse ⇒ the hold vocabulary is UNKNOWN, and a wholesale
     *    tag replace under an unknown hold vocabulary can silently delete a human's
     *    hold. Refuse the correction and name the file: an install fault the seat cannot
     *    fix and must not retry. ⚠ This is reachable, not defensive — the board-tools
     *    path builds its client through `WritebackClientFactory`, which never touches
     *    `writeback.json`, so a malformed file breaks nothing else on this door.
     *
     * @return list<string>
     */
    private function installHoldTags(int $boardId): array
    {
        try {
            $writeback = WritebackConfig::loadDefault();
        } catch (ConfigException $e) {
            Log::warning('board_correct_card: refused a tag correction — writeback.json will not parse, so this board\'s hold-marker vocabulary is unknown', [
                'board_id' => $boardId, 'error' => $e->getMessage(),
            ]);

            throw new ToolRefusalException("board_correct_card: the bridge cannot read its own `writeback.json`, so it cannot tell which tags this install treats as a HOLD on board {$boardId} — and correcting `tags` replaces the card's whole list, which would silently delete one. Nothing was written. This is an INSTALL fault, not something your arguments can fix; report it to your operator. Correcting `name`/`description` alone is unaffected.");
        }

        return $writeback?->holdMarkerTagsForBoard($boardId) ?? [];
    }

    /**
     * A 4xx the BOARD answered on the ownership lookup, mapped to a named refusal.
     * Anything else (5xx, a timeout) is re-thrown for the dispatcher's 502, which is
     * the correct answer for a fault that MAY clear.
     *
     * ⭐ WHICH STATUSES THOSE ARE, AND WHY EACH IS AN INSTALL FAULT, IS NOT THIS TOOL'S
     * TO DECIDE ANY MORE — {@see BoardCallRefusal} owns both for the whole door
     * (card#8486). It was decided here first (DL-326), which is exactly what made
     * `board_my_cards` and `board_create_card` answer the same faults with the
     * dispatcher's retryable 502. This tool keeps only what is ITS OWN: the log line,
     * and the two clauses saying what was being read and what the call did not do.
     * ⛔ In particular the 403 cause names the token's ABILITIES and not its board
     * membership — a membership gap arrives as a not-found refusal here
     * ({@see notYoursMessage} carries that disjunct), never as a 403. ⚠ That is a property
     * of the ROUTE, not of the door: this lookup is a card SEARCH, which kanban floors to
     * the caller's own boards, so the route class is named explicitly rather than defaulted
     * ({@see BoardReadRoute} — a board-scoped read 403s on exactly the membership this
     * message rules out).
     */
    private function lookupRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_correct_card: the board-scoped ownership lookup was refused by the board', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal(
            $this->name(),
            BoardReadRoute::Search,
            $status,
            "your board to establish that card {$cardId} is yours",
            'so nothing was written and nothing was read about the card',
        );
    }

    /**
     * A 4xx the BOARD answered on the WRITE. Every arm is deterministic, so every one
     * is a refusal rather than the retryable 502: a 404 means the card stopped existing
     * between the ownership check and the write (deleted or archived under us), a 403
     * means the token may read the card and not write it, a 401 means the token is no
     * longer accepted at all, and a 422 means kanban's own validator rejected a VALUE —
     * which no number of retries will change either.
     *
     * ⛔ THE 422 ARM IS THE ONE THAT MAKES THE BRIDGE-SIDE VALUE BOUNDS SAFE TO GET
     * WRONG. Those bounds ({@see KanbanFieldLimits}) mirror rules that live in kanban's
     * repo, so they can go stale; with 422 on the retryable path, a stale bound became a
     * seat retrying forever against `502 upstream board error` with no diagnosis. It is
     * mapped here instead, and the message is BRIDGE-AUTHORED: the board's response body
     * is never echoed — it is an upstream artefact whose shape and contents this tool
     * does not control, and the caller can act on {@see BoardCallRefusal::bridgeBoundsClause}.
     *
     * ⭐ WHICH statuses are permanent is {@see BoardCallRefusal}'s (card#8486); WHAT each one
     * means for a CORRECTION stays here, because that is a property of this write and not of
     * the door: only this tool has an ownership check for a 404 to have raced, and only its
     * PATCH takes `task.update`. ⛔ The GATES a 403 sends the operator to audit are NOT that
     * kind of property — they are kanban's, identical for every write on this door — so they
     * are {@see BoardCallRefusal::writeGatesClause}'s. Written out longhand here they were
     * copied onto `board_create_card` missing kanban's board write gate, with nothing red.
     */
    private function writeRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        Log::warning('board_correct_card: the board refused the correction write', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $e->response->status(),
        ]);

        $status = BoardCallRefusal::permanentOnWrite($e);
        if ($status === null) {
            return $e;
        }

        return new ToolRefusalException(match ($status) {
            404 => "board_correct_card: card {$cardId} no longer exists — it was removed between the ownership check and the write, so NOTHING was written. Re-read your cards with `board_my_cards`.",
            403 => "board_correct_card: the board refused the write to card {$cardId} (403) — the card is yours, but the bridge's writeback user may not write it. ".BoardCallRefusal::writeGatesClause('PATCH', 'task.update', ' — a PATCH carrying anything other than `workflow_stage_id` alone authorizes update, not move (kanban DL-204), and `task.update` is new for this door (`board_my_cards` and `board_create_card` never needed it)').' Nothing was written. This is an INSTALL fault, not something your arguments can fix; report it to your operator.',
            401 => "board_correct_card: the board did not accept the bridge's writeback token at all on the write to card {$cardId} (401) — it has been revoked, rotated or replaced with a value the board does not know. Nothing was written. This is an INSTALL fault; retrying will not change it.",
            422 => "board_correct_card: the board REJECTED the value you sent for card {$cardId} (422) — kanban's own validator refused it, so nothing was written and re-sending the same call cannot succeed. ".BoardCallRefusal::bridgeBoundsClause().' Shorten or simplify the field you were correcting, and report it to your operator if it persists.',
        });
    }
}
