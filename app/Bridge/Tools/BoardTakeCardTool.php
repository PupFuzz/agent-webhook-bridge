<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\PinGuard;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_take_card (card#9170, DL-372) — CLAIM a card for the calling seat by writing
 * kanban's native `assigned_user_id`. The fourth tool on the door, beside
 * {@see BoardMyCardsTool} (read), {@see BoardCreateCardTool} (create) and
 * {@see BoardCorrectCardTool} (correct).
 *
 * ⭐ WHAT IT IS FOR: a card whose COLUMN never moved is indistinguishable from an
 * unclaimed one, so two seats pull the same work and neither finds out. The assignee is
 * the board's own answer to that, and until this the only writer of it was a human at a
 * terminal — so every impl seat's claim had to route through the one privileged seat,
 * which is the serial hub this door exists to remove.
 *
 * ⛔⭐ THE ID IS RESOLVED FROM THE AGENT REGISTRY AND THERE IS NO ARGUMENT FOR IT. This
 * tool accepts `card_id` AND NOTHING ELSE; the value written is
 * {@see SeatKanbanUser::forCallingAgent}'s answer for the agent name the DOOR derived (from the
 * bearer, or from the pinned ssh forced command) — never a value that travelled in the
 * request. That is the constraint the whole feature rests on, and it is a CONSTRUCTION,
 * not a validation: this door is driven by a lower-trust principal, and a tool that
 * accepted an `assigned_user_id` — even a validated one — would put "a seat may claim
 * only for itself" one forgotten branch away from false, letting one seat assign work to
 * another or impersonate a take. Here there is no expressible call that writes another
 * seat's id. Every argument that NAMES a user is refused BY NAME
 * ({@see USER_NAMING_ARGS}) rather than ignored, so a caller that tries learns why
 * instead of believing it assigned somebody.
 *
 * ⭐ AND IT LOOKS UP EXACTLY ONE SEAT: ITS OWN. The bridge therefore never needs, and must
 * never grow, a fleet-wide seat→kanban-user map — see {@see SeatKanbanUser} for why that
 * absence is the point rather than a gap.
 *
 * ⭐ WHY A SEPARATE TOOL AND NOT AN ARGUMENT ON `board_correct_card` — the fork this card
 * turned on, recorded so it can be attacked rather than inherited:
 *  - THE AUTHORIZATIONS ARE DIFFERENT, AND THIS ONE IS WIDER. A correction is scoped to
 *    cards the seat MINTED (`created-by:<agent>`, bridge-stamped and caller-unforgeable).
 *    A take must work on cards the seat did NOT mint — the pm mints work INTO a seat's
 *    lane for that seat to pull, which is the case the feature exists for — so the two
 *    cannot share a predicate. Folding a WIDER authority into that tool, keyed on which
 *    argument happened to be passed, is the laundering shape its own docblock refuses for
 *    the field set.
 *  - A CORRECTION WRITES WHAT THE CALLER SUPPLIED; A TAKE WRITES WHAT THE BRIDGE RESOLVED.
 *    Every `board_correct_card` argument is caller-owned content by construction. Putting a
 *    claim in that grammar invites the one argument this feature may never have.
 *  - REFUSE-ON-CONFLICT HAS NO ANALOGUE IN A CORRECTION, which writes unconditionally once
 *    ownership is proven. A take's central behaviour is a REFUSAL that reads the card first.
 *
 * ⭐ THE AUTHORIZATION, STATED RATHER THAN INHERITED. `board_correct_card`'s mint-stamp
 * model is under an open question of its own (card#9201/#9202) and nothing here rests on
 * it. A take is authorized by TWO independent narrowings, both read off the ROW and neither
 * sufficient alone:
 *  1. THE CARD IS ON THIS AGENT'S CONFIGURED BOARD — established through
 *     {@see KanbanClient::cardRowsOnBoard} (`q=board_id=<b> id=<n>`) and read off the rows
 *     by {@see BoardScopedRow}, never through the unscoped `getCard()` that DL-323 records
 *     as the defect for a caller-supplied id.
 *  2. THE CARD IS IN A LANE THIS SEAT WORKS — its own `swimlane_id`, or the configured
 *     shared lane. That is exactly the population `board_my_cards` reports for this seat,
 *     which is what makes the tool's scope legible: a card you can see is a card you can
 *     take. ⛔ The COORD board is deliberately OUT: those cards live on a separately
 *     configured board, are addressed by TAG rather than by lane, and reaching them would
 *     put a write on a second board this door has never written to. Narrow first; widening
 *     later is a decision an operator can make, un-widening is not.
 * The MINT STAMP is deliberately NOT part of it — a seat that could only take what it filed
 * could not take the work anybody else queued for it, which is the whole ask.
 *
 * ⛔ REFUSE-ON-CONFLICT, MATCHING THE TOOLKIT HALF (card#9169's `kbcard patch --assign`).
 * A card already held by a DIFFERENT user is refused, the holder is NAMED, and nothing is
 * written — that refusal IS the collision detector, and it works precisely in the case the
 * feature was filed for (the column never moved). ⚠ ONE DELIBERATE DIVERGENCE from the
 * toolkit half, and it is a narrowing: there is no `--steal`. Stealing is a decision about
 * ANOTHER seat's work, `kbcard` takes it from a human at a terminal who can go and talk to
 * them, and this door's caller is an agent — so the override is left where a human is, and
 * this tool has no way to express it. A seat that genuinely must take a held card asks its
 * operator, who has `kbcard patch --assign <seat> --steal`.
 *
 * ⭐ RE-TAKING A CARD THE SEAT ALREADY HOLDS SUCCEEDS AND WRITES NOTHING (`already_held`).
 * It is not a conflict — the board already says what the call is asking it to say — so
 * refusing would make a retry-safe operation fail on its own success, and writing would
 * spend an upstream request to store the value that is already there. The toolkit half
 * re-sends the id in that case; the difference is unobservable on the board and the
 * response says which happened.
 *
 * ⚠ DISCLOSED BOUND — REFUSE-ON-CONFLICT DETECTS A CLAIM THAT HAS LANDED, NOT A RACE.
 * {@see currentHolder} reads the search row and {@see KanbanClient::patchCard} then writes
 * unconditionally: there is no compare-and-swap, because kanban's task PATCH offers no
 * conditional update to express one. So two seats that read `assigned_user_id: null` at the
 * same instant BOTH write, last-writer-wins, and both are answered `taken: true,
 * already_held: false` — the loser believing it holds a card another seat is on, which is the
 * very state this tool exists to make visible. ⭐ THE WINDOW IS ACCEPTED, and it is accepted
 * on its size rather than waved through: it is the gap between one read and one write on one
 * card, against a workflow where a seat claims a card once and then works it for minutes or
 * hours. What the refusal covers is the whole of the rest of that span, which is where the
 * collision this was filed for actually happens (the operator's case is a card sitting
 * claimed-but-unmoved, not two agents typing at once). ⛔ It is written down because every
 * other bound here is — a guard whose limits are undisclosed reads as a guarantee.
 *
 * ⛔ A ROW THAT CARRIES NO READABLE `assigned_user_id` IS A DEGRADED READ AND REFUSES.
 * Present-null is a real value meaning UNASSIGNED and is the ordinary case; an ABSENT key
 * or a non-numeric one means the read cannot say whose claim the write would take, and
 * fail-closed is the only safe direction for a guard whose whole job is not to overwrite
 * somebody. MEASURED, not assumed: `GET /tasks/search.json` carries `assigned_user_id` on
 * every row it returns (381/381 rows on the reference board, 2026-09-09), so the degraded
 * arm is a real fail-closed guard rather than the ordinary path.
 *
 * ⚠ THE PIN DOES NOT GOVERN THIS WRITE, AND THAT IS A RULING RATHER THAN AN OVERSIGHT.
 * {@see PinGuard::PINNED_FIELDS} IS the field rule and names `name`
 * alone: the DL-178 hold exists so a card stops changing UNDER the operator through
 * automation with no human in the loop, and an assignee is neither a restatement of what
 * the card IS nor an automatic write — it is a deliberate claim made by the caller, and
 * knowing who is looking at a frozen card is useful rather than harmful. Widening the pin
 * to cover it would widen it for every producer that shares that const, which is a change
 * to what the system refuses and belongs to its own operator gate.
 *
 * REFUSALS ARE DETERMINISTIC, so a permanent board 4xx is a NAMED refusal on both the read
 * and the write ({@see BoardCallRefusal}), never the dispatcher's retryable 502. ⚠ The 403
 * on the WRITE is the one this card names explicitly: a writeback user whose board role
 * cannot `task.update` gets it on every take, forever, and the refusal says INSTALL FAULT
 * and names the ability rather than surfacing a bare 403 — the consuming framework relays
 * that sentence verbatim.
 */
final class BoardTakeCardTool implements Tool
{
    /**
     * Arguments that NAME A USER, each refused by name. They are enumerated rather than
     * left to the generic unknown-argument refusal because the generic one says "this
     * tool accepts `card_id`", which reads as a spelling mistake — and the caller sending
     * one of these has a MODEL of the tool that is wrong in the one way that matters.
     *
     * @var list<string>
     */
    private const USER_NAMING_ARGS = ['assigned_user_id', 'assignee', 'assign', 'user_id', 'kanban_user_id', 'user', 'agent', 'agent_name', 'seat'];

    /**
     * Arguments that name an OVERRIDE this tool deliberately does not have, refused with
     * where the override actually lives.
     *
     * @var list<string>
     */
    private const OVERRIDE_ARGS = ['steal', 'force', 'override', 'unassign', 'release'];

    public function name(): string
    {
        return 'board_take_card';
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Arguments first, then identity, then the board — so a refused call reads
        // nothing and writes nothing, and an install fault is reported as itself rather
        // than as a board lookup that went nowhere.
        $this->refuseForeignArguments($args);
        $cardId = $this->requireCardId($args);
        $userId = SeatKanbanUser::forCallingAgent($agentName, $this->name());

        $boardId = (int) $cfg->boardId;
        [$row, $lane] = $this->takeableRow($client, $cfg, $boardId, $cardId, $agentName);
        $holder = $this->currentHolder($row, $cardId, $boardId, $agentName);

        $result = [
            'taken' => true,
            'card_id' => $cardId,
            // OBSERVED, not restated: the row was accepted only because its OWN `board_id`
            // and `swimlane_id` carried these values, so on any call that reaches here the
            // reading and the configured scope are the same number — a divergence is a
            // refusal, not a response.
            'board_id' => $boardId,
            'swimlane_id' => $lane,
            'assigned_user_id' => $userId,
            'already_held' => $holder === $userId,
        ];

        if ($holder === $userId) {
            Log::info('board_take_card: already held by the calling seat — nothing written', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            return $result;
        }

        if ($holder !== null) {
            Log::warning('board_take_card: refused — the card is already assigned to a different kanban user', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'holder' => $holder,
            ]);

            throw new ToolRefusalException("board_take_card: card {$cardId} is ALREADY HELD by kanban user {$holder}, and you are kanban user {$userId} — NOTHING WAS WRITTEN. That seat may be working it right now: a card whose column never moved looks unclaimed and is not, which is exactly what this refusal exists to tell you. Talk to whoever that is, or pick up different work. This door has no override: taking a card off another seat is a decision for your operator, who can make it with `kbcard patch --assign <seat> --steal`.");
        }

        try {
            $client->patchCard($cardId, ['assigned_user_id' => $userId]);
        } catch (RequestException $e) {
            throw $this->writeRefusal($e, $cardId, $userId, $agentName);
        }

        Log::info('board_take_card: taken', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'assigned_user_id' => $userId,
        ]);

        return $result;
    }

    /**
     * Refuse any argument this tool does not own. `card_id` is the whole accepted set, and
     * that is the tool's central property rather than a small contract — so the two classes
     * of near-miss get their own message.
     *
     * @param  array<string, mixed>  $args
     */
    private function refuseForeignArguments(array $args): void
    {
        foreach (array_keys($args) as $key) {
            $key = (string) $key;
            if ($key === 'card_id') {
                continue;
            }

            $lower = strtolower($key);
            if (in_array($lower, self::USER_NAMING_ARGS, true)) {
                throw new ToolRefusalException("board_take_card: `{$key}` is not an argument here, and it never will be — this tool assigns the card to YOU and to nobody else, and it works out who you are from the bridge identity your call authenticated as, NOT from anything you send. NOTHING WAS WRITTEN and the value you sent was ignored entirely. Call it with `card_id` alone. (If you are trying to assign work to a DIFFERENT seat, no board tool can do that: ask your operator.)");
            }

            if (in_array($lower, self::OVERRIDE_ARGS, true)) {
                throw new ToolRefusalException("board_take_card: `{$key}` is not an argument here — this tool has no override. A card already held by another seat is refused and NOTHING is written; taking one off them, or releasing one, is a decision for your operator (`kbcard patch --assign <seat> --steal` / `--unassign`). Call it with `card_id` alone.");
            }

            throw new ToolRefusalException("board_take_card: unknown argument `{$key}` — this tool accepts `card_id` and nothing else (the assignee is resolved from your bridge identity, never from your arguments). Nothing was written.");
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function requireCardId(array $args): int
    {
        $cardId = $args['card_id'] ?? null;
        // is_int, not is_numeric — the same rule `board_correct_card` states: a float or a
        // decorated string is a caller bug whose coercion would name a DIFFERENT card, and
        // this id selects the row a write lands on.
        if (! is_int($cardId) || $cardId < 1) {
            throw new ToolRefusalException('board_take_card: `card_id` is required and must be a positive integer (the id `board_my_cards` reports for the card)');
        }

        return $cardId;
    }

    /**
     * The card's row, established on THIS agent's board AND in a lane this seat works — or
     * a refusal. Both narrowings are required and neither is sufficient (class docblock).
     *
     * ⚠ A not-in-your-lanes refusal and a no-such-card refusal are DELIBERATELY one
     * message, the same non-disclosure posture `board_correct_card` takes: a seat is not
     * told whether a card outside its scope exists. The ARCHIVED probe is the one
     * exception, and only for a card in the seat's OWN lanes — naming the retire there
     * discloses nothing the seat could not already read, and the alternative is telling a
     * seat that a card sitting in its own lane is out of scope, which is a false statement
     * made by a guard.
     *
     * @return array{0: array<string, mixed>, 1: int} the row, and the lane it was accepted in
     */
    private function takeableRow(KanbanClient $client, BoardToolsConfig $cfg, int $boardId, int $cardId, string $agentName): array
    {
        try {
            $live = $client->cardRowsOnBoard($boardId, $cardId);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        $row = BoardScopedRow::forCard($live, $boardId, $cardId);
        if ($row !== null) {
            $lane = $this->workableLane($row, $cfg);
            if ($lane === null) {
                Log::warning('board_take_card: refused — the card is on the agent\'s board but not in a lane it works', [
                    'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
                    'row_swimlane' => is_scalar($row['swimlane_id'] ?? null) ? $row['swimlane_id'] : null,
                ]);

                throw new ToolRefusalException($this->outOfScopeMessage($cardId, $boardId, $cfg));
            }

            return [$row, $lane];
        }

        if ($live !== []) {
            // The lookup answered SOMEBODY ELSE'S row: a broken read, never a verdict about
            // this card (DL-323 Decision 2's `board_scope_lookup_unfiltered`).
            Log::warning('board_take_card: the board-scoped lookup answered a row that is not this card on this board — refusing without a scope verdict', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'rows' => count($live),
            ]);

            throw new ToolRefusalException("board_take_card: the board lookup for card {$cardId} answered a row that is not that card on your board — that is a BROKEN READ, not a verdict about the card, so nothing was written. Report it to your operator.");
        }

        // Only on a live MISS, so it costs nothing on any successful call — the other side
        // of kanban's archive SWITCH (DL-296: no both-sides mode).
        try {
            $archived = $client->cardRowsOnBoard($boardId, $cardId, archivedOnly: true);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        $retired = BoardScopedRow::forCard($archived, $boardId, $cardId);
        if ($retired !== null && $this->workableLane($retired, $cfg) !== null) {
            throw new ToolRefusalException("board_take_card: card {$cardId} is ARCHIVED — an archived card is a deliberate retire, so there is no work on it to claim and nothing was written. Unarchive it if the work is live again.");
        }

        Log::warning('board_take_card: refused — no card with this id is in a lane this agent works', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException($this->outOfScopeMessage($cardId, $boardId, $cfg));
    }

    /**
     * The lane this row sits in when it is one this seat works — its own, or the configured
     * shared one — and null otherwise. Fail-closed on a row whose `swimlane_id` cannot be
     * read: a card that cannot be SHOWN to be in the seat's lane is not in it, which is the
     * same rule `board_my_cards`' read-isolation filter applies to the same field.
     *
     * @param  array<string, mixed>  $row
     */
    private function workableLane(array $row, BoardToolsConfig $cfg): ?int
    {
        $lane = $row['swimlane_id'] ?? null;
        if (! is_numeric($lane)) {
            return null;
        }

        return in_array((int) $lane, $this->workableLanes($cfg), true) ? (int) $lane : null;
    }

    /**
     * @return list<int>
     */
    private function workableLanes(BoardToolsConfig $cfg): array
    {
        $lanes = [(int) $cfg->swimlaneId];
        if ($cfg->sharedSwimlaneId !== null) {
            $lanes[] = $cfg->sharedSwimlaneId;
        }

        return array_values(array_unique($lanes));
    }

    /**
     * ⚠ THE LAST SENTENCE IS NOT PADDING — IT IS THE ONLY CHANNEL AN UNREADABLE BOARD HAS.
     * kanban's search floors a caller to the boards it is a MEMBER of and answers 200 with
     * zero rows for every other one, so a board the writeback token cannot see and a board
     * with no such card are ONE answer here (DL-323's `mapped_board_unreadable_to_this_token`).
     */
    private function outOfScopeMessage(int $cardId, int $boardId, BoardToolsConfig $cfg): string
    {
        $lanes = implode(', ', array_map(static fn (int $lane): string => (string) $lane, $this->workableLanes($cfg)));

        return "board_take_card: card {$cardId} is not one you can take — this tool claims only cards on your own board {$boardId} and in a lane you work (swimlane ".$lanes.').'.$this->coordClause($cfg).' Nothing was written. ⚠ A board the bridge\'s writeback token is not a MEMBER of answers exactly the same way: kanban\'s search returns zero rows rather than an error, so an unreadable board and an empty one are one answer here — if you believe this card is in your lane, have your operator check that token\'s membership of board '.$boardId.'. Use `board_my_cards` to see the cards you can take.';
    }

    /**
     * ⚠ THE LIKELIEST CAUSE, NAMED — but only on an install where it EXISTS.
     *
     * `board_my_cards` returns coordination cards in the same response as the seat's own, and
     * since card#9170 every card in it carries `assigned_user_id` — so reaching for
     * `board_take_card` with a coord card's id is the obvious next move, and it is out of
     * scope by name (DL-372 Decision 3). Those cards are on a DIFFERENT board, so the
     * product-board-scoped lookup returns zero rows and they are indistinguishable from a card
     * that does not exist. Without this clause the refusal's only actionable sentence sends the
     * operator to audit the token's membership of the PRODUCT board — a cause that is
     * definitely not the cause, which {@see BoardCallRefusal::readCause} calls worse than
     * saying nothing.
     *
     * ⛔ IT IS CONDITIONAL ON THE INSTALL, NOT PROSE. An install with no coord leg has no such
     * board, and telling that seat about coordination cards would be inventing a second id
     * space it does not have. ⚠ It NAMES the likely cause rather than establishing it: proving
     * the id is a coord card would cost a second board-scoped read on every miss, and the
     * verdict would still be a refusal.
     */
    private function coordClause(BoardToolsConfig $cfg): string
    {
        if ($cfg->coordBoardId === null || $cfg->addressTags === []) {
            return '';
        }

        return " ⚠ If you took this id from the `coord_cards` block of `board_my_cards`, that is very likely why: coordination cards live on board {$cfg->coordBoardId}, they are addressed to you by TAG rather than held in a lane, and this tool does not claim them — their ids are a different space from your product board's.";
    }

    /**
     * WHO HOLDS THE CARD NOW — an int, or null for an explicitly unassigned card. A row
     * that answers NOTHING about its assignment is a degraded read and refuses (class
     * docblock): present-null is the ordinary unassigned case and is a real answer, while
     * an absent or non-numeric key means this run cannot say whose claim a write would
     * take.
     *
     * @param  array<string, mixed>  $row
     */
    private function currentHolder(array $row, int $cardId, int $boardId, string $agentName): ?int
    {
        if (! array_key_exists('assigned_user_id', $row)) {
            Log::warning('board_take_card: refused — the row carries no assigned_user_id at all, so this run cannot say whether the write would take another seat\'s claim', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            throw new ToolRefusalException("board_take_card: the board's row for card {$cardId} says NOTHING about who holds it — the field is missing from the response, so this call cannot tell an unclaimed card from one another seat is working, and it refuses rather than risk overwriting a claim. NOTHING WAS WRITTEN. This is an INSTALL fault (the board is answering a shape the bridge does not recognise); report it to your operator.");
        }

        $holder = $row['assigned_user_id'];
        if ($holder === null) {
            return null;
        }
        if (! is_numeric($holder)) {
            Log::warning('board_take_card: refused — the row\'s assigned_user_id is not a readable id', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            throw new ToolRefusalException("board_take_card: the board's row for card {$cardId} carries an `assigned_user_id` the bridge cannot read as a user id, so this call cannot tell an unclaimed card from one another seat is working, and it refuses rather than risk overwriting a claim. NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.");
        }

        return (int) $holder;
    }

    /**
     * A 4xx the BOARD answered on the scope lookup. Anything else (5xx, a timeout) is
     * re-thrown for the dispatcher's retryable 502 — the correct answer for a fault that
     * MAY clear. The route is named explicitly: this lookup is a card SEARCH, which kanban
     * floors to the caller's own boards, so a membership gap arrives as a not-found refusal
     * (carried by {@see outOfScopeMessage}) and never as a 403.
     */
    private function lookupRefusal(RequestException $e, int $cardId, string $agentName): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_take_card: the board-scoped lookup was refused by the board', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal(
            $this->name(),
            BoardReadRoute::Search,
            $status,
            "your board to establish that card {$cardId} is one you can take",
            'so nothing was written and nothing was read about the card',
        );
    }

    /**
     * A 4xx the BOARD answered on the WRITE. Every arm is deterministic, so every one is a
     * refusal rather than the retryable 502.
     *
     * ⛔ THE 403 IS THE ARM THIS TOOL WAS BOUNDED ON. `assigned_user_id` is not
     * `workflow_stage_id`, so kanban authorizes this PATCH as `task.update` (kanban DL-204)
     * — the same ability `board_correct_card` needs and the one a NARROWED custom board role
     * can lack. An install in that state gets this answer on EVERY take, permanently, so the
     * refusal names it as an INSTALL FAULT and enumerates the gates rather than surfacing a
     * bare 403 the seat would retry.
     */
    private function writeRefusal(RequestException $e, int $cardId, int $userId, string $agentName): \Throwable
    {
        // ⛔ CLASSIFY FIRST, LOG SECOND — the order {@see lookupRefusal} has, and the first cut
        // of this method had backwards. A 500 / 429 / 400 is re-thrown for the dispatcher's
        // retryable 502, which is the correct answer for a fault that MAY clear: the board did
        // not REFUSE it, and a warning saying so puts 5xx noise into the one line an operator
        // greps for refusals. Two halves of one file must not disagree about what a refusal is.
        $status = BoardCallRefusal::permanentOnWrite($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_take_card: the board refused the assignment write', [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        return new ToolRefusalException(match ($status) {
            404 => "board_take_card: card {$cardId} no longer exists — it was removed between the scope check and the write, so NOTHING was written. Re-read your cards with `board_my_cards`.",
            403 => "board_take_card: the board refused the assignment write to card {$cardId} (403) — the card is one you may take, but the bridge's writeback user may not write it. ".BoardCallRefusal::writeGatesClause('PATCH', 'task.update', ' — an assignee PATCH carries a field other than `workflow_stage_id` alone, so kanban authorizes it as update rather than move (kanban DL-204)').' Nothing was written. This is an INSTALL fault, not something your arguments can fix; report it to your operator.',
            401 => "board_take_card: the board did not accept the bridge's writeback token at all on the write to card {$cardId} (401) — it has been revoked, rotated or replaced with a value the board does not know. Nothing was written. This is an INSTALL fault; retrying will not change it.",
            422 => "board_take_card: the board REJECTED the assignment write to card {$cardId} (422) — kanban's own validator refused it, so nothing was written and re-sending the same call cannot succeed. The only value this call sends is `assigned_user_id: {$userId}`, resolved from this bridge's config for your agent, so the likeliest cause is that this install's `identity.kanban_user_id` for you does not name a user the board accepts. Report it to your operator.",
        });
    }
}
