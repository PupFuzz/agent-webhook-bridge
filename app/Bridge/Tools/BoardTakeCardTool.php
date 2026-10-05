<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Writeback\BoardStructure;
use App\Bridge\Writeback\CardTags;
use App\Bridge\Writeback\FinishedStages;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\OwnerTag;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\ProgramCardGuard;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_take_card (card#9170, DL-372) — CLAIM a card for the calling seat by writing
 * kanban's native `assigned_user_id`. A write tool on the board-tools door;
 * {@see BoardToolsRegistry} IS the set of tools that door offers, and
 * `docs/board-tools.md`'s tool table is held against it, so neither is restated here.
 *
 * ⭐ WHAT IT IS FOR: a card whose COLUMN never moved is indistinguishable from an
 * unclaimed one, so two seats pull the same work and neither finds out. The assignee is
 * the board's own answer to that, and until this the only writer of it was a human at a
 * terminal — so every impl seat's claim had to route through the one privileged seat,
 * which is the serial hub this door exists to remove.
 *
 * ⛔⭐ THE ID IS RESOLVED FROM THE COORD ROSTER AND THERE IS NO ARGUMENT FOR IT. This
 * tool accepts `card_id` and the boolean `start` (card#11150) AND NOTHING ELSE, and neither names
 * a user; the value written is
 * {@see SeatKanbanUser::forCallingSeat}'s answer for the seat the DOOR sealed at dispatch
 * entry ({@see CallingSeat}) — never a value that travelled in the request, and not even a
 * name this method could pass, because that method takes none. That is the constraint the
 * whole feature rests on, and it is a CONSTRUCTION, not a validation: this door is driven
 * by a lower-trust principal, and a tool that
 * accepted an `assigned_user_id` — even a validated one — would put "a seat may claim
 * only for itself" one forgotten branch away from false, letting one seat assign work to
 * another or impersonate a take. Here there is no expressible call that writes another
 * seat's id. ⚠ AND THE ACCEPT SET IS WHERE THAT HOLDS, NOT THE REFUSAL LIST: `card_id` and
 * `start` are the whole of it, and EVERY other key throws before any board request. What
 * {@see USER_NAMING_ARGS} changes is the MESSAGE and never the outcome — the spellings it
 * enumerates are refused in a sentence that names the key and says why the tool will never
 * have it, and every other unknown key (`owner`, `assigned_to`, a padded spelling) is
 * refused just as hard, with a reason that says the assignee comes from the bridge identity
 * and never from the arguments. No caller is left believing it
 * assigned somebody either way.
 *
 * ⭐ AND IT LOOKS UP EXACTLY ONE SEAT: ITS OWN. The fleet's seat→kanban-user map is the coord
 * roster, which the bridge READS and never copies (DL-450); this tool asks it about the calling
 * seat and no other — see {@see SeatKanbanUser}.
 *
 * ⭐ WHY A SEPARATE TOOL AND NOT AN ARGUMENT ON `board_correct_card` — the fork this card
 * turned on, recorded so it can be attacked rather than inherited:
 *  - THE AUTHORIZATIONS ARE DIFFERENT, AND THIS ONE IS WIDER. A correction is scoped to
 *    cards that are ALREADY the seat's — MINTED by it (`created-by:<agent>`) or, since
 *    DL-376, ASSIGNED to it. A take must work on cards that are neither yet — the pm mints
 *    work INTO a seat's lane for that seat to pull, which is the case the feature exists
 *    for — so the two cannot share a predicate. Folding a WIDER authority into that tool, keyed on which
 *    argument happened to be passed, is the laundering shape its own docblock refuses for
 *    the field set.
 *  - A CORRECTION WRITES WHAT THE CALLER SUPPLIED; A TAKE WRITES WHAT THE BRIDGE RESOLVED.
 *    Every `board_correct_card` argument is caller-owned content by construction. Putting a
 *    claim in that grammar invites the one argument this feature may never have.
 *  - A TAKE READS THE CARD'S HOLDER FIRST AND A CORRECTION HAS NO ANALOGUE: a correction writes
 *    unconditionally once ownership is proven, while a take decides from the holder whether it
 *    writes silently, takes over with a warning, or refuses (a finished card).
 *
 * ⭐ THE AUTHORIZATION, STATED RATHER THAN INHERITED. `board_correct_card`'s model was
 * widened by DL-376 (card#9201/#9202) to minted-OR-assigned, and nothing here rests on
 * it. A take is authorized by TWO independent narrowings, both read off the ROW and neither
 * sufficient alone:
 *  1. THE CARD IS ON THIS AGENT'S CONFIGURED BOARD — established through
 *     {@see KanbanClient::cardRowsOnBoard} (`q=board_id=<b> id=<n>`) and read off the rows
 *     by {@see BoardScopedRow}, never through the unscoped `getCard()` that DL-323 records
 *     as the defect for a caller-supplied id.
 *  2. THE CARD IS IN A LANE THIS SEAT WORKS — its own `swimlane_id`, or the configured
 *     shared lane. ⚠ That is the same LANE SCOPE {@see BoardMyCardsTool} reads, and
 *     deliberately NOT the same SET — the shorthand "exactly the population `board_my_cards`
 *     reports" was false in BOTH directions and is corrected here rather than repeated: that
 *     tool CAPS its response by card count (card#8985 / DL-365), so a card it did not return
 *     can still be takeable, and a card it DOES show can be refused because another user
 *     holds it. What makes the scope legible is the LANE, not the listing.
 *     ⛔ The COORD board is deliberately OUT: those cards live on a separately
 *     configured board, are addressed by TAG rather than by lane, and reaching them would
 *     put a write on a second board this door has never written to. Narrow first; widening
 *     later is a decision an operator can make, un-widening is not.
 * The MINT STAMP is deliberately NOT part of it — a seat that could only take what it filed
 * could not take the work anybody else queued for it, which is the whole ask.
 *
 * ⛔ WARN, THEN TAKE — AND NEVER A FINISHED CARD (card#10869; operator rulings on card#10868:
 * Q3 and 7624). This tool used to REFUSE a card held by a different user. The operator ruled
 * that an agent claiming a card another user holds WARNS, THEN TAKES IT, and posts a card comment
 * naming the holder it replaced — the toolkit's card-start claim does the same. So a held card is
 * now taken: the holder is named in the durable log BEFORE the write, the write is re-read, and
 * only a re-read naming this seat gets the comment ({@see takeOver}). The response names the
 * replaced holder, carries a `warning`, and says whether the takeover was CONFIRMED.
 * ⛔ The exception is a card in a FINISHED column (Done, Won't Do, Shipped to dev, Shipped to main):
 * its assignee is the record of who did the work, and replacing it needs an explicit steal, which
 * this door does not have — so replacing a finished card's ASSIGNEE is refused, and so is replacing
 * the assignee of a card whose column cannot be SHOWN not to be finished ({@see FinishedStages}).
 * The steal stays where a human is: `kbcard patch --assign <seat> --steal`. With no assignee,
 * another seat's legacy `owner:` tag counts as the holder ({@see otherSeatsOwnerTags}), the
 * migration fallback the toolkit also reads — and that takeover is not column-gated, because it
 * replaces no record: the take writes `assigned_user_id` alone, so the tag stays on the card.
 *
 * ⭐ RE-TAKING A CARD THE SEAT ALREADY HOLDS SUCCEEDS AND WRITES NOTHING (`already_held`).
 * It is not a conflict — the board already says what the call is asking it to say — so
 * refusing would make a retry-safe operation fail on its own success, and writing would
 * spend an upstream request to store the value that is already there. The toolkit half
 * re-sends the id in that case; the difference is unobservable on the board and the
 * response says which happened.
 *
 * ⚠ DISCLOSED BOUND — THE HOLDER CHECK SEES A CLAIM THAT HAS LANDED, NOT A RACE.
 * {@see currentHolder} reads the search row and {@see KanbanClient::patchCard} then writes
 * unconditionally: there is no compare-and-swap, because kanban's task PATCH offers no
 * conditional update to express one. So two seats that read `assigned_user_id: null` at the
 * same instant BOTH write, last-writer-wins, and both are answered `taken: true,
 * already_held: false` on the unassigned path, which does not re-read. ⭐ THE WINDOW IS
 * ACCEPTED on its size: the gap between one read and one write on one card, against a workflow
 * where a seat claims a card once and then works it for minutes or hours. A TAKEOVER does
 * re-read, and reports `takeover_confirmed: false` with `board_now_names` when it lost.
 *
 * ⛔ A ROW THAT CARRIES NO READABLE `assigned_user_id` IS A DEGRADED READ AND REFUSES.
 * Present-null is a real value meaning UNASSIGNED and is the ordinary case; an ABSENT key
 * or a non-numeric one means the read cannot say whose claim the write would take, and
 * fail-closed is the only safe direction for a guard whose whole job is not to overwrite
 * somebody. MEASURED, not assumed: `GET /tasks/search.json` carries `assigned_user_id` on
 * every row it returns, so the degraded arm is a real fail-closed guard rather than the
 * ordinary path. ⛔ THE MEASUREMENT ITSELF LIVES IN `docs/kanban-integration-contract.md`,
 * on the `PATCH /tasks/{id}.json` row that declares this cross-system dependency — that doc
 * owns the figure and its date, and a second copy in a docblock is a number free to disagree
 * with the contract the far end is read against.
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
     * left to the unknown-argument reason every other key gets because that one opens
     * "unknown argument", which reads as a spelling mistake — and the caller sending
     * one of these has a MODEL of the tool that is wrong in the one way that matters.
     *
     * ⛔ IT IS A MESSAGE-QUALITY LIST, NOT A BOUNDARY, and reading it as one inverts where
     * the guarantee lives. The boundary is {@see acceptedArguments} — the exact keys `card_id`
     * and `start` — which {@see BoardToolDispatcher} enforces, so a user-naming spelling absent
     * from this list is refused too, with that reason, before any board request. Adding a spelling
     * here buys a better sentence; it does not widen or narrow what this tool accepts.
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

    /**
     * `card_id` and the `start` flag are the whole accepted set, and no member of it names a user
     * — that is the tool's central property rather than a small contract — so the two classes of
     * near-miss get their own reason, and every other key is still told where the assignee comes
     * from.
     */
    public function acceptedArguments(): array
    {
        return ['card_id', 'start'];
    }

    public function refusedArgumentReason(string $key): string
    {
        $lower = strtolower($key);
        if (in_array($lower, self::USER_NAMING_ARGS, true)) {
            return "`{$key}` is not an argument here, and it never will be — this tool assigns the card to YOU and to nobody else, and it works out who you are from the bridge identity your call authenticated as, NOT from anything you send. (If you are trying to assign work to a DIFFERENT seat, no board tool can do that: ask your operator.)";
        }

        if (in_array($lower, self::OVERRIDE_ARGS, true)) {
            return "`{$key}` is not an argument here — this tool has no override. A card another user holds is TAKEN with a warning and a card comment naming them, EXCEPT that replacing the ASSIGNEE of a card in a finished column is refused and NOTHING is written; replacing that record, or releasing a card, is a decision for your operator (`kbcard patch --assign <seat> --steal` / `--unassign`).";
        }

        return "unknown argument `{$key}` — the assignee is resolved from your bridge identity, never from your arguments.";
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Arguments first, then identity, then the board — so a refused call reads
        // nothing and writes nothing, and an install fault is reported as itself rather
        // than as a board lookup that went nowhere.
        $cardId = $this->requireCardId($args);
        $start = $this->requireStart($args);
        $userId = SeatKanbanUser::forCallingSeat($this->name());

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

        if ($start) {
            return $result + $this->start($client, $row, $boardId, $cardId, $userId, $holder, $agentName);
        }

        if ($holder === $userId) {
            Log::info('board_take_card: already held by the calling seat — nothing written', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            return $result;
        }

        $ownerTags = $holder === null ? $this->otherSeatsOwnerTags($row) : [];
        if ($holder === null && $ownerTags === []) {
            try {
                $client->patchCard($cardId, ['assigned_user_id' => $userId]);
            } catch (RequestException $e) {
                throw $this->writeRefusal($e, $cardId, ['assigned_user_id' => $userId], $agentName);
            }

            Log::info('board_take_card: taken', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'assigned_user_id' => $userId,
            ]);

            return $result;
        }

        return $result + $this->takeOver($client, $row, $boardId, $cardId, $userId, $holder, $ownerTags, $agentName);
    }

    /**
     * Q3 (operator ruling, card#10868): a card another user holds is WARNED about, then TAKEN, and
     * a card comment names the holder it replaced — except an ASSIGNEE on a card in a FINISHED
     * column, which is the record of who did the work and is refused (ruling 7624: replacing it
     * needs an explicit steal, which this door does not have).
     *
     * ⭐ THE HOLDER IS NAMED BEFORE THE WRITE, in the durable log, and not only in the response: a
     * call cut off after the PATCH lands would otherwise lose whom it replaced, and a retry then
     * finds the card already this seat's and says nothing (the toolkit's claim orders it the same
     * way). The comment is posted only once a RE-READ of the row names this seat's user — a comment
     * naming a replacement that did not happen would be the one false record here.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $ownerTags  legacy `owner:` tags naming another seat (only when unassigned)
     * @return array<string, mixed>
     */
    private function takeOver(KanbanClient $client, array $row, int $boardId, int $cardId, int $userId, ?int $holder, array $ownerTags, string $agentName): array
    {
        $replaced = $this->holderPhrase($holder, $ownerTags);
        // Only an ASSIGNEE is a record the take would overwrite; a legacy tag stays on the card.
        if ($holder !== null) {
            $this->refuseIfFinished($client, $row, $boardId, $cardId, $replaced, $agentName);
        }

        Log::warning('board_take_card: TAKING a card another holder has — named before the write', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            'replaced_assignee' => $holder, 'replaced_owner_tags' => $ownerTags, 'assigned_user_id' => $userId,
        ]);

        try {
            $client->patchCard($cardId, ['assigned_user_id' => $userId]);
        } catch (RequestException $e) {
            throw $this->writeRefusal($e, $cardId, ['assigned_user_id' => $userId], $agentName);
        }

        $nowNames = $this->rowAfterWrite($client, $boardId, $cardId, $agentName)['assigned_user_id'] ?? null;
        $nowNames = is_numeric($nowNames) ? (int) $nowNames : null;
        $confirmed = $nowNames === $userId;
        $comment = $confirmed ? $this->postTakeoverComment($client, $cardId, $userId, $replaced, $agentName) : 'not_attempted';

        Log::info('board_take_card: taken over', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'assigned_user_id' => $userId,
            'replaced' => $replaced, 'confirmed' => $confirmed, 'comment' => $comment,
        ]);

        return [
            'replaced' => ['assigned_user_id' => $holder, 'owner_tags' => $ownerTags],
            'warning' => "card {$cardId} was held by {$replaced}; it has been reassigned to you (kanban user {$userId}). If that holder is still working it, talk to them.",
            'takeover_confirmed' => $confirmed,
            'takeover_comment' => $comment,
        ] + ($confirmed || $nowNames === null ? [] : ['board_now_names' => $nowNames]);
    }

    /**
     * THE START FORM (card#11150, DL-449): in ONE write, move the card into the board's In
     * Progress column AND assign it to the calling seat, then read both back.
     *
     * ⭐ WHY ONE WRITE. A card the seat's branch push moves is moved by the writeback, which names
     * no seat (one shared GitHub actor), so it lands In Progress with nobody on it and the bridge
     * can only alert (`owner.moved_without_owner`, DL-439). The seat DECIDES to start, so the
     * decision is where the owner is known: kanban applies a stage change and another field in one
     * transaction (`docs/kanban-integration-contract.md`'s task PATCH row declares that dependency),
     * so this write never leaves a card moved but unowned. The later
     * push finds the card already in the `started` column, which is the writeback's existing
     * no-op — the writeback is unchanged.
     *
     * ⭐ THE COLUMNS ARE THE WRITEBACK'S OWN. In Progress is the `started` stage writeback.json maps
     * on this board, and a card is start-eligible exactly when it sits in that mapping's
     * `started_from_stages` — the set the writeback's `started` move promotes from (DL-160), so the
     * start form moves a card only where the push would have. A card already In Progress is a take
     * with no move. Everything else is refused with NOTHING WRITTEN: a finished column by name, any
     * other column (Backlog when it is not declared, In Review, …) because the writeback refuses to
     * drag it there too, a column the row cannot name, and a board writeback.json does not map to
     * one In Progress column. ⛔ `unpark_from_stages` is deliberately NOT start-eligible: the
     * writeback moves a parked card only by OVERRIDING a human hold and alerting (DL-194), and this
     * door does not reproduce that override.
     *
     * ⛔ A `program` PARENT is not moved either: the writeback writes nothing to one (DL-403), its
     * `started` move included, so a start on one is refused (`program_parent`).
     *
     * ⛔ THE PIN HOLDS THE MOVE. A pinned card (DL-178: a `block_reason` or `no-automove`) is one the
     * writeback's `started` move refuses, and this is that move made earlier — so a start on one is
     * refused, and a take WITHOUT `start` still claims it (the pin governs the column and the name,
     * not the claim; {@see PinGuard::PINNED_FIELDS}).
     *
     * Takeover is the plain take's rule (DL-439 Decision 3): another holder is named in the log
     * before the write, an ASSIGNEE is replaced only outside a finished column, and a comment names
     * whom the start replaced once the read-back confirms it.
     *
     * ⛔ A 2xx IS NOT A START. The card is read back after the write and the call succeeds only when
     * the board now says In Progress AND this seat; anything else is refused naming what the board
     * stored, and one that does not answer is refused `not_confirmed` — calling again is safe: a
     * start that landed answers `already_held` with nothing written. On a takeover both name the
     * displaced holder.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function start(KanbanClient $client, array $row, int $boardId, int $cardId, int $userId, ?int $holder, string $agentName): array
    {
        $inProgress = $this->inProgressColumn($boardId, $cardId, $agentName);
        $from = $row['workflow_stage_id'] ?? null;
        if (! is_numeric($from)) {
            throw new ToolRefusalException("board_take_card: the board's row for card {$cardId} names no readable column, so whether it may be started cannot be read — NOTHING WAS WRITTEN. This is an INSTALL fault (the board is answering a shape the bridge does not recognise); report it to your operator.", installFault: true, reason: 'column_unknown');
        }
        $from = (int) $from;
        $moves = $from !== $inProgress['stage'];
        if ($moves) {
            $this->refuseUnstartable($client, $row, $boardId, $cardId, $from, $inProgress, $agentName);
        }

        $ownerTags = $holder === null ? $this->otherSeatsOwnerTags($row) : [];
        $replacesAssignee = $holder !== null && $holder !== $userId;
        $replacing = $replacesAssignee || $ownerTags !== [];
        $report = [
            'moved' => $moves,
            'assigned' => $holder !== $userId,
            'replaced' => $replacing ? ['assigned_user_id' => $holder, 'owner_tags' => $ownerTags] : null,
            'from_stage_id' => $from,
            'stage_id' => $inProgress['stage'],
        ];
        if (! $moves && $holder === $userId) {
            Log::info('board_take_card: start — already In Progress and held by the calling seat — nothing written', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            return $report;
        }

        $replaced = $this->holderPhrase($holder, $ownerTags);
        if ($replacesAssignee) {
            $this->refuseIfFinished($client, $row, $boardId, $cardId, $replaced, $agentName, $inProgress['mappings']);
        }
        if ($replacing) {
            Log::warning('board_take_card: TAKING a card another holder has — named before the write', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
                'replaced_assignee' => $holder, 'replaced_owner_tags' => $ownerTags, 'assigned_user_id' => $userId,
            ]);
        }

        $assignTo = $holder === $userId ? null : $userId;
        $fields = ($moves ? ['workflow_stage_id' => $inProgress['stage']] : []) + ($assignTo === null ? [] : ['assigned_user_id' => $assignTo]);
        try {
            if ($moves) {
                $client->moveCard($cardId, $inProgress['stage'], $assignTo);
            } else {
                $client->patchCard($cardId, ['assigned_user_id' => $userId]);
            }
        } catch (RequestException $e) {
            throw $this->writeRefusal($e, $cardId, $fields, $agentName);
        }

        $this->confirmStarted($client, $boardId, $cardId, $userId, $inProgress['stage'], $replacing ? $replaced : null, $agentName);
        Log::info('board_take_card: started', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'assigned_user_id' => $userId,
            'from_stage' => $from, 'stage' => $inProgress['stage'], 'moved' => $moves, 'replaced' => $replacing ? $replaced : null,
        ]);
        if (! $replacing) {
            return $report;
        }

        return $report + [
            'warning' => "card {$cardId} was held by {$replaced}; it has been reassigned to you (kanban user {$userId}). If that holder is still working it, talk to them.",
            'takeover_confirmed' => true,
            'takeover_comment' => $this->postTakeoverComment($client, $cardId, $userId, $replaced, $agentName),
        ];
    }

    /**
     * Post the card comment naming the holder a takeover replaced, and say whether it landed. Only
     * a CONFIRMED takeover calls this: a comment naming a replacement that did not happen would be
     * the one false record here. Never throws — the takeover has already landed.
     *
     * @return 'posted'|'failed'
     */
    private function postTakeoverComment(KanbanClient $client, int $cardId, int $userId, string $replaced, string $agentName): string
    {
        try {
            $client->addComment($cardId, "{$agentName} (kanban user {$userId}) took this card over from {$replaced} with board_take_card.");
        } catch (RequestException|ConnectionException $e) {
            Log::warning('board_take_card: the card was taken, but the comment naming the replaced holder could not be posted — the holder is in this log line and in the response', [
                'agent' => $agentName, 'card_id' => $cardId, 'replaced' => $replaced, 'error' => RedactedErrorText::of($e),
            ]);

            return 'failed';
        }

        return 'posted';
    }

    /**
     * The board's In Progress column and the columns a start may move a card from, read off the
     * writeback.json mappings on THIS board — or a refusal naming why there is no one answer.
     * Mappings that map no `started` stage contribute nothing; several that map one must agree,
     * because a start that picked one of two In Progress columns would be a guess.
     *
     * @return array{stage: int, from: list<int>, mappings: list<WritebackMapping>}
     */
    private function inProgressColumn(int $boardId, int $cardId, string $agentName): array
    {
        try {
            $writeback = WritebackConfig::loadDefault();
        } catch (ConfigException $e) {
            Log::warning('board_take_card: start refused — writeback.json will not parse, so the In Progress column is unknown', [
                'agent' => $agentName, 'card_id' => $cardId, 'error' => $e->getMessage(),
            ]);

            throw new ToolRefusalException("board_take_card: the bridge cannot read its own writeback.json, which names this board's In Progress column, so card {$cardId} cannot be started — NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator. A take without `start` does not need it.", installFault: true, reason: 'install_fault.writeback_config_unreadable');
        }

        $mappings = $writeback?->mappingsOnBoard($boardId) ?? [];
        $stages = [];
        $from = [];
        foreach ($mappings as $mapping) {
            $stage = $mapping->stageFor('started');
            if ($stage === null) {
                continue;
            }
            $stages[] = $stage;
            array_push($from, ...($mapping->startedFromStages ?? []));
        }
        $stages = array_values(array_unique($stages));

        if ($stages === []) {
            throw new ToolRefusalException("board_take_card: no writeback.json mapping on board {$boardId} maps `started` (the In Progress column), so the bridge does not know where a start moves card {$cardId} — NOTHING WAS WRITTEN. This is an INSTALL fault: map `stages.started` for this board (docs/writeback.md), and report it to your operator. A take without `start` claims the card without moving it.", installFault: true, reason: 'install_fault.start_unmapped');
        }
        if (count($stages) > 1) {
            throw new ToolRefusalException("board_take_card: the writeback.json mappings on board {$boardId} map `started` to DIFFERENT columns (".implode(', ', $stages).'), so which one is In Progress cannot be told — NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.', installFault: true, reason: 'install_fault.start_ambiguous');
        }

        return ['stage' => $stages[0], 'from' => array_values(array_unique($from)), 'mappings' => $mappings];
    }

    /**
     * Refuse a start from a column the writeback's `started` move would not promote from, or on a
     * card the pin holds — each by name, NOTHING WRITTEN. The board's columns are read only to
     * name a refused column; an eligible card costs no read here.
     *
     * @param  array<string, mixed>  $row
     * @param  array{stage: int, from: list<int>, mappings: list<WritebackMapping>}  $inProgress
     */
    private function refuseUnstartable(KanbanClient $client, array $row, int $boardId, int $cardId, int $from, array $inProgress, string $agentName): void
    {
        if (in_array($from, $inProgress['from'], true)) {
            // The writeback writes NOTHING to a `program` parent (DL-403), its `started` move
            // included (`KanbanMoveCardHandler`'s consult), so a start does not move one either. Asked
            // BEFORE the pin, in the handler's order, so a pinned parent is refused as a parent.
            if (ProgramCardGuard::isProgramParent($row)) {
                Log::warning('board_take_card: start refused — the card is a program parent, which no single start may move', [
                    'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'reason' => ProgramCardGuard::REASON,
                ]);

                throw new ToolRefusalException("board_take_card: card {$cardId} carries the `program` tag — it is a PARENT naming several legs, and the bridge moves no parent card on any one piece of work (the writeback refuses the same move) — NOTHING WAS WRITTEN. Start the LEG you are working, or call board_take_card without `start` to claim the parent where it is.", reason: 'program_parent');
            }

            if (PinGuard::isPinned($row)) {
                Log::warning('board_take_card: start refused — the card is pinned, so its column is held', [
                    'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'reason' => PinGuard::REASON,
                ]);

                throw new ToolRefusalException("board_take_card: card {$cardId} is PINNED (a block_reason or a no-automove tag): a human is holding its column, and the bridge's own start move is refused on a pinned card — NOTHING WAS WRITTEN. Call board_take_card without `start` to claim it where it is, or ask whoever pinned it to lift the hold.", reason: 'pinned');
            }

            return;
        }

        [$structure, $order] = $this->boardColumns($client, $boardId, "board {$boardId}'s columns to name why card {$cardId} cannot be started");
        $name = static fn (int $stage): string => isset($structure->stageNames[$stage]) ? "{$structure->stageNames[$stage]} ({$stage})" : "column {$stage}";

        $mappings = $inProgress['mappings'];
        if (FinishedStages::unanswerable($from, $mappings, $structure, $order) === null && FinishedStages::isFinished($from, $mappings, $structure, $order)) {
            throw new ToolRefusalException("board_take_card: card {$cardId} is in a FINISHED column (".($structure->stageNames[$from] ?? "column {$from}").') — a finished card is not started again — NOTHING WAS WRITTEN.', reason: 'finished_column');
        }

        $eligible = $inProgress['from'] === []
            ? 'writeback.json declares no `started_from_stages` for this board, so no column is start-eligible (the writeback refuses every `started` move here too)'
            : 'a start moves a card only from '.implode(', ', array_map($name, $inProgress['from'])).' — the `started_from_stages` the writeback\'s own `started` move promotes from — or takes one already in '.$name($inProgress['stage']).' without moving it';
        Log::info('board_take_card: start refused — the card is not in a start-eligible column', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId, 'stage' => $from,
        ]);

        throw new ToolRefusalException("board_take_card: card {$cardId} is in ".$name($from).", which is not a column a start moves a card from: {$eligible}. NOTHING WAS WRITTEN. Call board_take_card without `start` to claim it where it is.", reason: 'not_start_eligible');
    }

    /**
     * Read the card back after the start's write and refuse unless the board now says In Progress
     * AND this seat. A start is NEVER answered `ok` on an unverified write: a read-back that does not
     * answer — a transport failure, a broken read, or a card no longer live on the board — is
     * refused `not_confirmed` (whether it landed is unknown; calling again is safe, because a start
     * that landed answers `already_held` and writes nothing), and one that answers something else
     * is refused `not_stored`. On a takeover both name the holder the write was sent over, so the
     * displaced holder is never lost.
     *
     * @param  ?string  $replaced  the displaced holder's phrase on a takeover, else null
     */
    private function confirmStarted(KanbanClient $client, int $boardId, int $cardId, int $userId, int $inProgress, ?string $replaced, string $agentName): void
    {
        $over = $replaced === null ? '' : " The write was sent over {$replaced}, who held the card before it.";
        $live = $this->rowAfterWrite($client, $boardId, $cardId, $agentName);
        if ($live === null) {
            throw new ToolRefusalException("board_take_card: the board answered the start of card {$cardId} with success, but the card could not be read back (the read failed, answered a row that is not this card, or the card is no longer live on your board) — so whether the start landed is UNKNOWN.{$over}".($replaced === null ? '' : ' Check with that holder: if the write landed, nothing on the card told them (the takeover comment is posted only after a confirmed read-back).').' Calling again is safe: a start that landed answers `already_held` and writes nothing.', reason: 'not_confirmed');
        }
        $stage = $live['workflow_stage_id'] ?? null;
        $assignee = $live['assigned_user_id'] ?? null;
        $stage = is_numeric($stage) ? (int) $stage : null;
        $assignee = is_numeric($assignee) ? (int) $assignee : null;
        if ($stage === $inProgress && $assignee === $userId) {
            return;
        }

        Log::warning('board_take_card: start write answered 2xx, but the read-back does not show it stored', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            'expected_stage' => $inProgress, 'stage' => $stage, 'assignee' => $assignee, 'replaced' => $replaced,
        ]);

        throw new ToolRefusalException("board_take_card: the board answered the start of card {$cardId} with success, but reading it back shows it in ".($stage === null ? 'no readable column' : "column {$stage}").' with '.($assignee === null ? 'no assignee' : "kanban user {$assignee} as assignee")." — not column {$inProgress} with you (kanban user {$userId}). The start did NOT land as asked; another writer may have changed the card in between.{$over} Read the card with board_get_cards before calling again.", reason: 'not_stored');
    }

    /**
     * The card's live row as a re-read finds it after a write, or null when the re-read did not
     * answer it (a transport failure, a broken read) or the card is no longer live on the board —
     * never an exception: the write has landed or not by now, and each caller reports what it
     * could establish. The ONE read-back both the takeover and the start use.
     *
     * @return ?array<string, mixed>
     */
    private function rowAfterWrite(KanbanClient $client, int $boardId, int $cardId, string $agentName): ?array
    {
        try {
            return BoardScopedRow::lookUp($client, $boardId, $cardId, $this->name(), $agentName)->live;
        } catch (RequestException|ConnectionException|ToolRefusalException $e) {
            Log::warning('board_take_card: the write was sent, but the re-read that confirms it failed', [
                'agent' => $agentName, 'card_id' => $cardId, 'error' => RedactedErrorText::of($e),
            ]);

            return null;
        }
    }

    /**
     * Legacy `owner:<project>/<seat>` tags naming a seat OTHER than this one — the migration
     * fallback for "who holds this card" on a card with no assignee (card#10868 migration step 1;
     * deleted with the other tag readers at step 3). The bridge cannot spell this seat's own tag —
     * `<project>` lives in the coord config, which the request path cannot read — so it compares
     * the SEAT part only: a tag whose seat is this seat's roster name may be this seat's own and is
     * not treated as another holder.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function otherSeatsOwnerTags(array $row): array
    {
        $ownerTags = array_values(array_filter(CardTags::readable($row) ?? [], OwnerTag::is(...)));
        if ($ownerTags === []) {
            return [];
        }
        $mySeat = SeatKanbanUser::seatNameForCallingSeat($this->name());
        $others = [];
        foreach ($ownerTags as $tag) {
            $slash = strrpos($tag, '/');
            $seat = $slash === false ? null : substr($tag, $slash + 1);
            if ($seat !== $mySeat) {
                $others[] = $tag;
            }
        }

        return $others;
    }

    /**
     * @param  list<string>  $ownerTags
     */
    private function holderPhrase(?int $holder, array $ownerTags): string
    {
        return $holder !== null
            ? "kanban user {$holder}"
            : 'the seat named by its legacy owner tag '.implode(', ', $ownerTags);
    }

    /**
     * Refuse a takeover of a card in a FINISHED column, or of one whose column cannot be shown
     * not to be finished — {@see FinishedStages} owns both answers and why an unanswerable leg is
     * never read as "current". Each cause carries its own `reason`: `finished_column` (it is),
     * `column_unknown` (it cannot be shown not to be) and
     * `install_fault.writeback_config_unreadable` (writeback.json will not parse).
     *
     * @param  array<string, mixed>  $row
     * @param  ?list<WritebackMapping>  $mappings  the board's mappings when the caller already read them
     */
    private function refuseIfFinished(KanbanClient $client, array $row, int $boardId, int $cardId, string $replaced, string $agentName, ?array $mappings = null): void
    {
        $refuse = function (string $why, string $reason) use ($cardId, $replaced, $agentName): never {
            Log::warning('board_take_card: refused a takeover — the card is, or cannot be shown not to be, in a finished column', [
                'agent' => $agentName, 'card_id' => $cardId, 'replaced' => $replaced, 'why' => $why, 'reason' => $reason,
            ]);

            throw new ToolRefusalException("board_take_card: card {$cardId} is held by {$replaced}, and {$why}. A finished card's assignee is the record of who did the work, and replacing it needs an explicit steal, which this door does not have — NOTHING WAS WRITTEN. If the card really must change hands, your operator can do it with `kbcard patch --assign <seat> --steal`.", installFault: str_starts_with($reason, 'install_fault.'), reason: $reason);
        };

        $stage = $row['workflow_stage_id'] ?? null;
        if (! is_numeric($stage)) {
            $refuse("the board's row for it names no readable column, so whether it is finished cannot be read", 'column_unknown');
        }
        $stage = (int) $stage;

        if ($mappings === null) {
            try {
                $mappings = WritebackConfig::loadDefault()?->mappingsOnBoard($boardId) ?? [];
            } catch (ConfigException $e) {
                Log::warning('board_take_card: writeback.json will not parse, so which columns are finished is unknown', [
                    'agent' => $agentName, 'card_id' => $cardId, 'error' => $e->getMessage(),
                ]);
                $refuse("the bridge cannot read its own writeback.json, which names this board's Shipped and Released columns, so whether it is finished cannot be read (an INSTALL fault; report it to your operator)", 'install_fault.writeback_config_unreadable');
            }
        }

        [$structure, $order] = $this->boardColumns($client, $boardId, "board {$boardId}'s columns to establish whether card {$cardId} is finished");
        $why = FinishedStages::unanswerable($stage, $mappings, $structure, $order);
        if ($why !== null) {
            $refuse("whether it is in a finished column cannot be read — {$why}", 'column_unknown');
        }
        if (FinishedStages::isFinished($stage, $mappings, $structure, $order)) {
            $name = $structure->stageNames[$stage] ?? "column {$stage}";
            $refuse("it is in a FINISHED column ({$name})", 'finished_column');
        }
    }

    /**
     * The board's columns — names, terminal declaration and order — from the two reads every
     * column question here needs, or a named refusal when the board refuses them (a permanent 4xx;
     * anything else is re-thrown for the retryable 502). One place for the read and its refusal,
     * so the start's eligibility test and the takeover's finished test cannot drift apart.
     *
     * @param  string  $what  what the read was for, as the refusal names it
     * @return array{0: BoardStructure, 1: array<int, float>}
     */
    private function boardColumns(KanbanClient $client, int $boardId, string $what): array
    {
        try {
            return [$client->boardStructure($boardId), $client->boardStageOrder($boardId)];
        } catch (RequestException $e) {
            $status = BoardCallRefusal::permanentOnRead($e);
            if ($status === null) {
                throw $e;
            }

            throw BoardCallRefusal::readRefusal($this->name(), BoardReadRoute::BoardScoped, $status, $what, 'so nothing was written');
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
            throw new ToolRefusalException('board_take_card: `card_id` is required and must be a positive integer (the id `board_my_cards` reports for the card)', reason: 'bad_arguments');
        }

        return $cardId;
    }

    /**
     * `start` is optional and strictly boolean — the same no-coercion rule {@see requireCardId}
     * states, because `"false"` coerced to true would move a card the caller asked not to move.
     *
     * @param  array<string, mixed>  $args
     */
    private function requireStart(array $args): bool
    {
        if (! array_key_exists('start', $args)) {
            return false;
        }
        $start = $args['start'];
        if (! is_bool($start)) {
            throw new ToolRefusalException('board_take_card: `start` must be a boolean when provided — true to move the card to In Progress as you take it, false or omitted for a plain take. A string, number or null is refused, never coerced. Nothing was sent to the board.', reason: 'bad_arguments');
        }

        return $start;
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
            $found = BoardScopedRow::lookUp($client, $boardId, $cardId, $this->name(), $agentName);
        } catch (RequestException $e) {
            throw $this->lookupRefusal($e, $cardId, $agentName);
        }

        if ($found->live !== null) {
            $row = $found->live;
            $lane = $this->workableLane($row, $cfg);
            if ($lane === null) {
                Log::warning('board_take_card: refused — the card is on the agent\'s board but not in a lane it works', [
                    'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
                    'row_swimlane' => is_scalar($row['swimlane_id'] ?? null) ? $row['swimlane_id'] : null,
                ]);

                throw new ToolRefusalException($this->outOfScopeMessage($cardId, $boardId, $cfg), reason: 'out_of_scope');
            }

            return [$row, $lane];
        }

        $retired = $found->archived;
        if ($retired !== null && $this->workableLane($retired, $cfg) !== null) {
            throw new ToolRefusalException("board_take_card: card {$cardId} is ARCHIVED — an archived card is a deliberate retire, so there is no work on it to claim and nothing was written. Unarchive it if the work is live again.", reason: 'archived');
        }

        Log::warning('board_take_card: refused — no card with this id is in a lane this agent works', [
            'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
        ]);

        throw new ToolRefusalException($this->outOfScopeMessage($cardId, $boardId, $cfg), reason: 'out_of_scope');
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

            throw new ToolRefusalException("board_take_card: the board's row for card {$cardId} says NOTHING about who holds it — the field is missing from the response, so this call cannot tell an unclaimed card from one another seat is working, and it refuses rather than risk overwriting a claim. NOTHING WAS WRITTEN. This is an INSTALL fault (the board is answering a shape the bridge does not recognise); report it to your operator.", reason: 'holder_unreadable');
        }

        $holder = $row['assigned_user_id'];
        if ($holder === null) {
            return null;
        }
        if (! is_numeric($holder)) {
            Log::warning('board_take_card: refused — the row\'s assigned_user_id is not a readable id', [
                'agent' => $agentName, 'card_id' => $cardId, 'board_id' => $boardId,
            ]);

            throw new ToolRefusalException("board_take_card: the board's row for card {$cardId} carries an `assigned_user_id` the bridge cannot read as a user id, so this call cannot tell an unclaimed card from one another seat is working, and it refuses rather than risk overwriting a claim. NOTHING WAS WRITTEN. This is an INSTALL fault; report it to your operator.", reason: 'holder_unreadable');
        }

        return (int) $holder;
    }

    /**
     * A 4xx the BOARD answered on the scope lookup, mapped to a named refusal.
     * Which statuses refuse and which are re-thrown is {@see BoardCallRefusal}'s; what a call
     * that gets no answer returns is `docs/board-tools.md` § A PERMANENT board 4xx.
     * The route is named explicitly: this lookup is a card SEARCH, which kanban
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
     * bare 403 the seat would retry. The start form's column-only move (a card the seat already
     * holds) is the one write here that is authorized as `task.move` instead, and its refusal
     * says so.
     *
     * @param  array<string, int>  $fields  exactly what the PATCH carried
     */
    private function writeRefusal(RequestException $e, int $cardId, array $fields, string $agentName): \Throwable
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

        $moves = array_key_exists('workflow_stage_id', $fields);
        $assigns = array_key_exists('assigned_user_id', $fields);
        $write = match (true) {
            $moves && $assigns => 'start write (the In Progress move and the assignment, in one PATCH)',
            $moves => 'start move',
            default => 'assignment write',
        };

        Log::warning("board_take_card: the board refused the {$write}", [
            'agent' => $agentName, 'card_id' => $cardId, 'status' => $status,
        ]);

        $sent = implode(' and ', array_map(static fn (string $field, int $value): string => "`{$field}: {$value}`", array_keys($fields), $fields));
        $forbidden = $assigns
            ? BoardCallRefusal::writeGatesClause('PATCH', 'task.update', ' — '.($moves ? 'a PATCH that moves the column AND sets the assignee' : 'an assignee PATCH').' carries a field other than `workflow_stage_id` alone, so kanban authorizes it as update rather than move (kanban DL-204); `bridge:check` reads whether the role grants it')
            : BoardCallRefusal::writeGatesClause('PATCH', 'task.move', ' — a column-only PATCH is authorized as move');

        return match ($status) {
            404 => new ToolRefusalException("board_take_card: card {$cardId} no longer exists — it was removed between the scope check and the write, so NOTHING was written. Re-read your cards with `board_my_cards`.", reason: 'card_gone'),
            403 => new ToolRefusalException("board_take_card: the board refused the {$write} to card {$cardId} (403) — the card is one you may take, but the bridge's writeback user may not write it. ".$forbidden.' Nothing was written. This is an INSTALL fault, not something your arguments can fix; report it to your operator.', installFault: true, reason: 'install_fault.write_forbidden'),
            401 => new ToolRefusalException("board_take_card: the board did not accept the bridge's writeback token at all on the write to card {$cardId} (401) — it has been revoked, rotated or replaced with a value the board does not know. Nothing was written. This is an INSTALL fault; retrying will not change it.", installFault: true, reason: 'install_fault.token_rejected'),
            422 => new ToolRefusalException("board_take_card: the board REJECTED the {$write} to card {$cardId} (422), so nothing was written, and re-sending the same call unchanged will be refused the same way. This call sends {$sent} and nothing else".($assigns ? ", and the assignee is your seat's kanban user id in the coord roster — so if the reason at the end of this message names `assigned_user_id`, the id the roster gives your seat for this kanban instance does not name a user the board accepts" : '').($moves ? '; a move the board refuses (an enforced WIP limit on the In Progress column, for one) is named in that reason too' : '').'. Report it to your operator. '.BoardCallRefusal::boardReason($e), reason: 'board_rejected'),
        };
    }
}
