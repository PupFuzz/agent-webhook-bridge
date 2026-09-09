<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_my_cards (DL-217) — a READ-PROXY returning the calling agent's own cards
 * without ever handing out the kanban token: the agent's own product swimlane
 * (grouped by stage name), the shared cross-system swimlane when configured, and
 * (when coord_board_id + address_tags are set) coordination cards addressed to it.
 *
 * Read isolation is 100% BRIDGE-ENFORCED. All agents share the one writeback
 * user, and kanban scopes reads by that user's BOARD membership, never by
 * swimlane — so the boundary keeping agent A out of agent B's lane is the
 * config's swimlane_id plus the fail-closed row filter here: the `swimlane_id=`
 * search term is efficiency + defense-in-depth against an un-upgraded/misbehaving
 * kanban, NOT the boundary. Every returned row is re-checked against the
 * configured swimlane and a non-matching one is DROPPED + logged (a misbehaving
 * upstream must never leak a foreign lane's card into a caller's window).
 *
 * The BOARD axis is the other half of that sentence, and until DL-302 it was the
 * odd one out in this file: the response stated the CONFIGURED board for a row set
 * whose own `board_id` nothing had read. {@see observedBoard} now reports the board
 * the returned rows actually carry, `board_observed` says whether the bridge read
 * one at all, and `configured_board_id` keeps the scope this agent is configured
 * for as a separately named value — never one dressed as the other.
 *
 * `include_description` (DL-245) is OPT-IN, because a card body is ~2 KB and — until
 * card#8985 below — the projection had no bound on how many cards a lane holds. It is
 * no longer the tool's only argument. Absent ⇒ the projected CARD is byte-identical to
 * the DL-217 shape (the two description keys are ABSENT, not null-valued). ⚠ THE
 * ENCLOSING RESPONSE IS NOT: it grew the DL-302 board keys and then card#8985's window
 * blocks, so the byte-identity claim is about the CARD and has never been about the
 * envelope. The field costs no extra API call —
 * `KanbanClient::swimlaneCards()` already fetches `description` and the
 * projection discarded it.
 *
 * ⛔ THE DEFAULT RESPONSE IS CAPPED BY CARD COUNT (card#8985, DL-365). The
 * DL-245 warning above bounds ONE description; nothing bounded the number of
 * cards, and the TITLES-ONLY response — the cheapest call this tool offers —
 * was measured at 121,032 chars / 390 cards on one seat and 81,067 / 292 on
 * another (2026-09-07), which overflows the context window of the very seats it
 * exists for. Each of the three card populations (own lane, shared lane, coord)
 * is now cut to `BoardMyCardsTool::DEFAULT_MAX_CARDS` cards unless the caller
 * raises `limit`, and each carries its own window block — `total` / `returned` / `limit` /
 * `truncated` — so a capped read is legible AS capped and can never be mistaken
 * for "that is all there is". The cut is by CARD COUNT, never by bytes: a byte cut
 * would land in a different place on every call, and a caller cannot reason about
 * a boundary it cannot predict.
 *
 * `stage` (card#8985) is the narrowing filter the window block points at. It keys
 * on the NUMERIC stage id — the id board consumers already key on and the id these
 * rows actually carry — and accepts a stage NAME only as a convenience, only when
 * that name resolves to exactly ONE stage on the board. An ambiguous name is a
 * REFUSAL, never a guess: guessing would silently answer about a different column
 * than the one asked for, and the answer would look exactly like a correct one.
 *
 * ⚠ The cap bounds the RESPONSE, not the upstream reads: `swimlaneCards()` still
 * paginates the whole lane, because `total` has to be the real size of THE POPULATION
 * THE CALLER ASKED ABOUT for the `truncated` flag to mean anything — the whole lane on
 * a plain call, and the named column when `stage` is passed, because `onStage()` runs
 * before the cut.
 *
 * ⛔ THE CUT KEEPS THE NEWEST CARDS, AND THAT IS THE DIFFERENCE BETWEEN A BOUNDED
 * RESPONSE AND A USEFUL ONE (r1). Card ids here are allocated globally and
 * monotonically, so keeping the LOWEST ids returns a lane's first-created cards — on
 * any board with a terminal column, 52 finished ones — and the seat's live work is
 * structurally invisible on the default call, permanently, because the window is stable
 * and never advances. See DEFAULT_MAX_CARDS and `cardWindow` for the ordering rule.
 *
 * ⛔ AND THE RESPONSE NAMES EVERY COLUMN OF THE BOARD (`board_stages`), whether or not a
 * card in it survived the cut. `cards_by_stage` only carries the columns the RETURNED
 * cards sit in, so a truncated read would otherwise advertise `stage` as the remedy
 * while having just hidden the argument it needs — leaving a caller to enumerate the
 * board by provoking a refusal.
 *
 * ⭐ A PERMANENT 4xx FROM THE BOARD IS A NAMED REFUSAL, NOT THE RETRYABLE 502 (card#8486)
 * — see {@see readRefusal}. Every read this tool makes is covered, on both the own/shared
 * and the coord leg. ⛔ It is also the only tool whose refusals span BOTH {@see BoardReadRoute}
 * cases, so each read is caught separately and names its own: what a 403 or a 404 MEANS differs
 * between a board-scoped read and a card search, and a refusal naming the wrong one denies the
 * true cause to the operator by name.
 */
final class BoardMyCardsTool implements Tool
{
    /**
     * The number of cards each list in the response is cut to when the caller
     * names no `limit` (card#8985). DERIVED FROM THE MEASUREMENT, not chosen for
     * roundness, and the derivation is the reason it is odd:
     *
     *   - Measured titles-only responses, 2026-09-07: 121,032 chars / 390 cards
     *     = 310.3 chars per card, and 81,067 / 292 = 277.6.
     *   - The LARGER rate is the one the cap has to hold at — sizing on the mean
     *     would leave the fatter of the two measured seats still over budget.
     *   - Budget: 16,384 chars for one card list, which is this repo's OWN
     *     existing ceiling for a single opted-in card body — the constant
     *     `BoardToolsConfig::DEFAULT_DESCRIPTION_MAX_BYTES`.
     *     A whole titles-only board window costing no more than one description
     *     is a bound this install has already accepted somewhere, rather than a
     *     fresh number invented here.
     *   - 16,384 / 310.3 = 52.8 ⇒ 52 cards per list.
     *
     * ⚠ It bounds ONE list. An install with a shared lane AND a coord leg can
     * return three saturated lists; that is a bound, not a promise of the budget.
     *
     * ⛔ WHICH 52 IS NOT A DETAIL — see `cardWindow`. A cap that kept the OLDEST cards
     * bounds the response and answers the wrong question, which is the same defect this
     * constant exists to fix wearing a smaller number.
     */
    public const DEFAULT_MAX_CARDS = 52;

    public function name(): string
    {
        return 'board_my_cards';
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $descriptionCap = $this->descriptionCap($args, $cfg);
        // Validated BEFORE the first read: it needs nothing from the board, and a
        // refusal that costs an upstream request is a refusal that costs an
        // upstream request on every retry of a caller that has not fixed its args.
        $limit = $this->cardLimit($args);
        $boardId = (int) $cfg->boardId;
        $swimlaneId = (int) $cfg->swimlaneId;
        // ⛔ TWO `try`s FOR TWO KANBAN ROUTE CLASSES, NOT A STYLE CHOICE (card#8486 R1). The
        // stage-name read is board-scoped (`boards/{id}/preload.json`) and the card reads are
        // searches, and a 403/404 means something DIFFERENT on each — see {@see BoardReadRoute}.
        // One `try` over both could only name one of them, so the refusal it composed would
        // deny the true cause by name on whichever call actually failed.
        try {
            $stageNames = $client->boardStageNames($boardId);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, 'stages', BoardReadRoute::BoardScoped, "the structure of your board {$boardId}");
        }

        // Resolved against the stage names just read, so a name that names nothing —
        // or names two things — is refused before any card search is paid for.
        $stageFilter = $this->stageFilter($args, $stageNames, $boardId);

        try {
            $ownRead = $this->filterSwimlane($client->swimlaneCards($boardId, $swimlaneId), $swimlaneId, $agentName, 'own');
            $sharedRead = $cfg->sharedSwimlaneId === null
                ? null
                : $this->filterSwimlane($client->swimlaneCards($boardId, $cfg->sharedSwimlaneId), $cfg->sharedSwimlaneId, $agentName, 'shared');
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, 'own+shared', BoardReadRoute::Search, "your board {$boardId}");
        }

        // ⛔ THE BOARD AXIS IS READ OVER EVERY ROW THIS CALL READ — before the stage filter
        // and before the cut (r1). DL-302 built it as a defence-in-depth report against a
        // window of foreign rows being reported as this board, and narrowing its population
        // to the rows that survive would do two things at once: assert this board as FACT
        // over a window whose hidden rows disagree, and silence `observedBoard`'s
        // multi-board warning for every row past the cut. A caller-requested narrowing is
        // no reason to stop looking at what was actually read, so this stays exactly the
        // population it was before the cap existed. ⚑ Read isolation is a different axis
        // and is unaffected: `filterSwimlane()` above still runs over every row.
        [$observedBoard, $boardObserved] = $this->observedBoard(array_merge($ownRead, $sharedRead ?? []), $boardId, $agentName, $sharedRead === null ? 'own' : 'own+shared');

        [$ownCards, $ownWindow] = $this->filteredWindow($this->onStage($ownRead, $stageFilter), $limit, $stageFilter);
        $result = [
            'board_id' => $observedBoard,
            'board_observed' => $boardObserved,
            'configured_board_id' => $boardId,
            'swimlane_id' => $swimlaneId,
            'board_stages' => $this->boardStages($stageNames),
            'cards_by_stage' => $this->groupByStage($ownCards, $stageNames, $descriptionCap),
            'cards_window' => $ownWindow,
        ];

        if ($sharedRead !== null) {
            [$sharedCards, $sharedWindow] = $this->filteredWindow($this->onStage($sharedRead, $stageFilter), $limit, $stageFilter);
            $result['shared_swimlane'] = [
                'swimlane_id' => (int) $cfg->sharedSwimlaneId,
                'cards_by_stage' => $this->groupByStage($sharedCards, $stageNames, $descriptionCap),
                'cards_window' => $sharedWindow,
            ];
        }

        if ($cfg->coordBoardId !== null && $cfg->addressTags !== []) {
            // Per-key, never `+=`: array-union DISCARDS a right-hand key the left
            // already holds, so a future key added to the literal above would drop
            // the observed coord block with nothing red. Naming each key also puts
            // coordBlock()'s declared shape under phpstan.
            $coord = $this->coordBlock($client, $cfg, $descriptionCap, $agentName, $limit);
            $result['coord_board_id'] = $coord['coord_board_id'];
            $result['coord_board_observed'] = $coord['coord_board_observed'];
            $result['configured_coord_board_id'] = $coord['configured_coord_board_id'];
            $result['coord_cards'] = $coord['coord_cards'];
            $result['coord_cards_window'] = $coord['coord_cards_window'];
        }

        return $result;
    }

    /**
     * A 4xx the BOARD answered on one of this tool's reads, mapped to a named refusal;
     * anything else (5xx, a timeout) is re-thrown for the dispatcher's retryable 502
     * (card#8486 — the mapping DL-326 built for `board_correct_card`, now
     * {@see BoardCallRefusal}'s for the whole door).
     *
     * ⭐ THIS TOOL IS THE ONE A ROTATED WRITEBACK TOKEN HITS FIRST, and it is the reason the
     * hoist matters: a seat's first act is usually to read its own cards, and until this a
     * 401 came back as `502 upstream board error` — an instruction to retry a call that
     * kanban's `auth:sanctum` door will refuse identically forever.
     *
     * ⚠ $route IS LOAD-BEARING, NOT A LABEL. `boardStageNames()` reads
     * `boards/{id}/preload.json`, which kanban authorizes with `view` ON THE BOARD, while the
     * card reads are searches kanban floors to the caller's own boards. So on the board-scoped
     * legs a membership gap is a live 403 cause and a 404 most likely means the configured
     * board id does not resolve — both of which the search strings deny by name. The caller
     * therefore catches each route class on its own `try`; see {@see BoardReadRoute}.
     *
     * ⚠ THE WHOLE CALL REFUSES, INCLUDING WHEN ONLY THE COORD LEG FAILED — unchanged from
     * the 502 this replaces. A partial window is the one answer this tool must never give:
     * `board_my_cards` is what a seat reads to decide what work exists, and a response
     * silently missing its coordination cards reads exactly like a board with none.
     */
    private function readRefusal(RequestException $e, string $agentName, string $leg, BoardReadRoute $route, string $what): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_my_cards: the board refused a read', [
            'agent' => $agentName, 'leg' => $leg, 'route' => $route->name, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal($this->name(), $route, $status, $what, 'so NO cards were returned — this is not an empty window');
    }

    /**
     * The per-card description byte cap for THIS call, or null when the caller did
     * not opt in. Null is what keeps the default response byte-identical: it makes
     * the projection omit both description keys rather than emit them null-valued.
     * A non-bool is REFUSED rather than coerced — a truthy string would silently
     * turn on the expensive projection the opt-in exists to gate.
     *
     * @param  array<string, mixed>  $args
     */
    private function descriptionCap(array $args, BoardToolsConfig $cfg): ?int
    {
        if (! array_key_exists('include_description', $args) || $args['include_description'] === null) {
            return null;
        }
        $include = $args['include_description'];
        if (! is_bool($include)) {
            throw new ToolRefusalException('board_my_cards: `include_description` must be a boolean when provided');
        }

        return $include ? $cfg->descriptionMaxBytes : null;
    }

    /**
     * The number of cards each list in this response is cut to. Absent ⇒ the
     * derived default; a caller that genuinely needs a whole large lane raises it
     * and pays for it, which is what makes the cap a DEFAULT rather than a
     * capability this tool no longer has.
     *
     * ⚠ A non-int is REFUSED rather than coerced, for the reason
     * `include_description` refuses one: `"200"` or `200.0` coerced would spend a
     * caller's context on a bound it never actually asked for, and a coerced 0 or
     * -1 would silently empty the window. `is_int` also excludes `true`, which
     * would otherwise coerce to a one-card window.
     *
     * @param  array<string, mixed>  $args
     */
    private function cardLimit(array $args): int
    {
        if (! array_key_exists('limit', $args) || $args['limit'] === null) {
            return self::DEFAULT_MAX_CARDS;
        }
        $limit = $args['limit'];
        if (! is_int($limit) || $limit < 1) {
            throw new ToolRefusalException('board_my_cards: `limit` must be an integer of at least 1 when provided — it is the number of CARDS each list is cut to (default '.self::DEFAULT_MAX_CARDS.'). Raising it raises the response size in proportion; narrow with `stage` instead where you can.');
        }

        return $limit;
    }

    /**
     * The NUMERIC stage id this call is narrowed to, or null when the caller named
     * none. The numeric id is the primary form — it is what the rows carry and what
     * every other board consumer keys on — and a string is accepted as a NAME, never
     * as an id: `"7"` is looked up as a stage called `7`, not as stage 7. That is the
     * one reading a caller cannot be surprised by, because the alternative (guess
     * which the caller meant) picks a column for them.
     *
     * ⛔ AN AMBIGUOUS NAME IS A REFUSAL, NOT A GUESS. Two stages that differ only in
     * case, or two genuinely identical names, resolve to a set — and answering about
     * one of them produces a window that is indistinguishable from a correct answer
     * about the other. The refusal names the board's stages so the caller can send an
     * id instead.
     *
     * ⛔ A PRESENT `stage` THAT NAMES NO COLUMN IS REFUSED, INCLUDING A PRESENT NULL, AND
     * THE HTTP DOOR IS WHY (r1). Laravel's global `TrimStrings` + `ConvertEmptyStringsToNull`
     * rewrite this argument before the tool sees it, so `""`, `"   "` and a lone
     * non-breaking space all arrive there as a present-and-NULL `stage` while the ssh door
     * hands over the literal string. Folding present-null into "absent" therefore made one
     * door silently DROP the filter and return the whole capped lane — more data than the
     * caller asked for — while the other refused the identical input. Only an ABSENT key
     * means "no filter", and that is the same on both doors.
     *
     * ⚠ The trim is {@see BoardToolArgs::trimmed}, which delegates to the framework's own
     * `Str::trim` — NOT PHP's ASCII `trim()`. That is the same lockstep in the other
     * direction: `TrimStrings` strips a non-breaking space (it is in
     * `Str::INVISIBLE_CHARACTERS`) and `trim()` does not, so a name carrying one resolved
     * at the HTTP door and was refused at the ssh door. This tool was the FIRST site to
     * converge (DL-365 Decision 10) and card#9155 hoisted the rule into a primitive every
     * board tool now shares, which is what keeps the next tool from hand-rolling it again.
     *
     * ⚠ An id is checked against the board's stages ONLY when the stage read
     * produced any. `boardStageNames()` answers an empty map when the preload read
     * carried no stages (already logged upstream), and validating against an empty
     * map would refuse every filter on a board whose structure this bridge could
     * not read — turning a degraded read into a dead argument. A NAME still cannot
     * be resolved in that state and says so.
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, string>  $stageNames
     */
    private function stageFilter(array $args, array $stageNames, int $boardId): ?int
    {
        if (! array_key_exists('stage', $args)) {
            return null;
        }
        $stage = $args['stage'];

        if ($stage === null) {
            throw new ToolRefusalException("board_my_cards: `stage` was sent EMPTY. Omit the argument entirely to read every column of board {$boardId}; an empty value is not a filter and is refused rather than silently ignored, which would hand you more cards than you asked for.");
        }

        if (is_int($stage)) {
            if ($stageNames !== [] && ! isset($stageNames[$stage])) {
                throw new ToolRefusalException("board_my_cards: `stage` {$stage} is not a stage on board {$boardId} — its stages are ".$this->stageList($stageNames).'. Nothing was filtered; no cards were returned for a column that does not exist.');
            }

            return $stage;
        }

        if (! is_string($stage)) {
            throw new ToolRefusalException('board_my_cards: `stage` must be the NUMERIC stage id (as `board_my_cards` reports it under each card\'s `stage`), or a stage NAME as a string. It is never coerced from another type.');
        }

        if ($stageNames === []) {
            throw new ToolRefusalException("board_my_cards: `stage` was given as a NAME, but this bridge read no stages for board {$boardId}, so there is nothing to resolve it against. Pass the numeric stage id, and tell your operator the board structure read came back empty.");
        }

        $wanted = mb_strtolower(BoardToolArgs::trimmed($stage));
        if ($wanted === '') {
            throw new ToolRefusalException("board_my_cards: `stage` was sent EMPTY (it contains nothing but invisible characters). Omit the argument entirely to read every column of board {$boardId}; an empty value is not a filter and is refused rather than silently ignored, which would hand you more cards than you asked for.");
        }
        $matches = [];
        foreach ($stageNames as $id => $name) {
            if (mb_strtolower(BoardToolArgs::trimmed($name)) === $wanted) {
                $matches[$id] = $name;
            }
        }

        if (count($matches) === 1) {
            return (int) array_key_first($matches);
        }
        if ($matches === []) {
            throw new ToolRefusalException("board_my_cards: `stage` does not name any stage on board {$boardId} — its stages are ".$this->stageList($stageNames).'. Names are matched case-insensitively and whitespace-trimmed; nothing else is inferred.');
        }

        throw new ToolRefusalException("board_my_cards: `stage` names MORE THAN ONE stage on board {$boardId} — ".$this->stageList($matches).'. The bridge does not guess which column you meant, because a guessed answer is indistinguishable from a correct one. Pass the numeric stage id.');
    }

    /**
     * Every column of the configured board, in the board's own COLUMN order, as
     * `[{id, name}]` (r1). ⚠ The order is the map's, and the map is ordered by kanban's
     * `position` inside `KanbanClient::boardStageNames()` — the only place that can see
     * that field, because this projection discards it. This method must not re-sort:
     * anything it could sort by here (id, name) is a different claim than the one the
     * key makes. r2 found these four words asserting an order nothing established, over a
     * bare `foreach` of payload-array order, which a two-workflow board reads out
     * backwards.
     *
     * ⚠ AN EMPTY LIST DOES NOT DISTINGUISH "this board has no columns" FROM "the stage
     * read degraded", and no flag here could. `boardStageNames()` answers `[]` for both,
     * so a `board_stages_observed` computed at this level would be `count($stages) === 0`
     * restated — an observation dressed as a measurement, which is precisely what DL-302
     * built the board axis to avoid. The degraded read IS reported, on the bridge's own
     * log (card#8761), and `docs/board-tools.md` names the state for the caller. Telling
     * the two apart ON THE WIRE needs the client seam to stop conflating them, which is
     * not this key's to fix.
     *
     * It rides on EVERY response rather than only a truncated one:
     * `cards_by_stage` names only the columns the returned cards sit in, so a cut can
     * hide a column entirely — and the `stage` remedy the window block points at needs
     * exactly the name or id that was hidden. A conditional key would also make the
     * envelope's shape depend on whether a cut happened, which is a second thing for a
     * consumer to branch on for no gain.
     *
     * ⚑ It is the SAME map the refusals read, so a stage this list omits is a stage no
     * refusal can name either — there is one source, not two.
     *
     * @param  array<int, string>  $stageNames
     * @return list<array{id: int, name: string}>
     */
    private function boardStages(array $stageNames): array
    {
        $stages = [];
        foreach ($stageNames as $id => $name) {
            $stages[] = ['id' => $id, 'name' => $name];
        }

        return $stages;
    }

    /**
     * `50 (Backlog), 51 (In Review)` — the board\'s own stages, id first because the
     * id is what the refusal is asking the caller to send. Sorted by id so the
     * message is stable across calls.
     *
     * @param  array<int, string>  $stageNames
     */
    private function stageList(array $stageNames): string
    {
        ksort($stageNames);
        $parts = [];
        foreach ($stageNames as $id => $name) {
            $parts[] = "{$id} ({$name})";
        }

        return implode(', ', $parts);
    }

    /**
     * Keep only the rows in the named stage. A row whose `workflow_stage_id` is
     * absent or non-numeric is DROPPED by a stage filter — it cannot be shown to be
     * in the asked-for column, and a caller that named a column is asking about that
     * column, not about everything the bridge could not place.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function onStage(array $rows, ?int $stageId): array
    {
        if ($stageId === null) {
            return $rows;
        }

        $kept = [];
        foreach ($rows as $row) {
            $rowStage = $row['workflow_stage_id'] ?? null;
            if (is_numeric($rowStage) && (int) $rowStage === $stageId) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * {@see cardWindow} for a list the `stage` filter CAN narrow, with the filter in
     * effect stated on the window. It exists so the key is added in ONE place rather
     * than at each call site: bolted on per-caller, the third filterable list added
     * later is the one that quietly ships without it. The coord cards call
     * `cardWindow` directly and carry no such key — `stage` cannot reach that board,
     * and a key that could never be non-null is a claim the block does not support.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array{total: int, returned: int, limit: int, truncated: bool, stage_filter: ?int}}
     */
    private function filteredWindow(array $rows, int $limit, ?int $stageFilter): array
    {
        [$cards, $window] = $this->cardWindow($rows, $limit);

        return [$cards, [
            'total' => $window['total'],
            'returned' => $window['returned'],
            'limit' => $window['limit'],
            'truncated' => $window['truncated'],
            'stage_filter' => $stageFilter,
        ]];
    }

    /**
     * Cut one card list to the cap, and report the cut (card#8985). Returns
     * `[the rows the caller gets, the window block describing them]`.
     *
     * ⛔ `total` IS THE WHOLE POPULATION, NOT WHAT WAS RETURNED, and that is the
     * only thing that makes `truncated` worth anything: the flag says a cut
     * happened and the total says how much is behind it, so a capped read can
     * never be read as 'that is all there is'. `returned` and `limit` are stated
     * separately because they differ in the ordinary case — a list shorter than
     * the cap returns everything under a cap that never bit.
     *
     * ⛔ WHICH cards survive is deterministic and is the HIGHEST CARD IDS — the seat's
     * NEWEST work — not whatever order the upstream search happened to answer in.
     * ⚠ THE FIRST CUT OF THIS TOOK THE LOWEST IDS AND WAS WRONG IN A WAY THE CAP'S OWN
     * GOAL DEFINES (r1): card ids here are allocated globally and monotonically, so the
     * lowest ids are a lane's FIRST-CREATED cards, and on any board with a terminal
     * column the default read came back as 52 finished ones with the seat's live work
     * nowhere in it. Worse, `cards_by_stage` then did not carry the live column's KEY at
     * all, so the response hid both the work and the fact that the column existed. The
     * one property ascending bought — a window that does not move when a card is created
     * elsewhere — is precisely the property that makes every NEW card invisible forever.
     *
     * Descending keeps everything that mattered: it is a TOTAL order over a
     * monotonically-allocated key, so it is deterministic, it does not churn when a card
     * is merely touched, and two identical polls answer the same set. The rows are then
     * emitted in their ORIGINAL order, so a list that was not cut is byte-identical to
     * what this tool has always returned. A row carrying no numeric id sorts last (it
     * cannot be placed, and it must not displace a card that can).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array{total: int, returned: int, limit: int, truncated: bool}}
     */
    private function cardWindow(array $rows, int $limit): array
    {
        $total = count($rows);
        if ($total <= $limit) {
            return [$rows, ['total' => $total, 'returned' => $total, 'limit' => $limit, 'truncated' => false]];
        }

        $order = array_keys($rows);
        usort($order, function (int $a, int $b) use ($rows): int {
            $idA = $this->sortableCardId($rows[$a]);
            $idB = $this->sortableCardId($rows[$b]);
            if ($idA === $idB) {
                return $a <=> $b;
            }
            if ($idA === null) {
                return 1;
            }
            if ($idB === null) {
                return -1;
            }

            return $idB <=> $idA;
        });

        $keep = array_slice($order, 0, $limit);
        sort($keep);
        $kept = [];
        foreach ($keep as $index) {
            $kept[] = $rows[$index];
        }

        return [$kept, ['total' => $total, 'returned' => count($kept), 'limit' => $limit, 'truncated' => true]];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortableCardId(array $row): ?int
    {
        $id = $row['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Drop (and log) any row whose own `swimlane_id` field does not match the
     * scope — the fail-closed read-isolation filter. A row missing the field is
     * also dropped (fail-closed: we cannot prove it belongs to this lane).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterSwimlane(array $rows, int $swimlaneId, string $agentName, string $leg): array
    {
        $kept = [];
        foreach ($rows as $row) {
            $rowSwimlane = $row['swimlane_id'] ?? null;
            if (is_numeric($rowSwimlane) && (int) $rowSwimlane === $swimlaneId) {
                $kept[] = $row;

                continue;
            }
            Log::warning('board_my_cards: dropped a row whose swimlane_id does not match the configured scope — the upstream search returned an out-of-scope card; read isolation is bridge-enforced', [
                'agent' => $agentName,
                'leg' => $leg,
                'expected_swimlane' => $swimlaneId,
                'row_swimlane' => is_scalar($rowSwimlane) ? $rowSwimlane : null,
                'card_id' => is_scalar($row['id'] ?? null) ? $row['id'] : null,
            ]);
        }

        return $kept;
    }

    /**
     * WHICH BOARD THE ROWS SAY THEY ARE ON — the board axis's answer to what
     * {@see filterSwimlane} is for the lane axis (card#7295, DL-302). Returns
     * `[observed board, observed?]`. Unobserved on ANY row unobserves the SET (the
     * response states ONE board for a row set, so a set that does not unanimously
     * answer has no honest single value — never a majority, never the configured
     * board), and an EMPTY set is unobserved too: not anomalous, and the one arm
     * that logs nothing. This REPORTS; it does not drop and it does not refuse.
     * DL-302 Decisions 1 and 3 own why — the two axes' asymmetry, and why the
     * compare shares `MappedBoardGuard::belongs()`'s accepted set without being
     * routed through it.
     *
     * ⛔ NO key-absent/value-null discrimination on this axis, deliberately, and
     * that is the one place this must NOT copy its sibling. `tasks.board_id` is a
     * non-nullable foreign key in kanban: a card is always on exactly one board,
     * so there is no such thing as a row legitimately reporting "no board". An
     * absent key, a present null and a non-numeric value therefore all mean the
     * same thing — the row answered nothing about its board — and all three are
     * UNOBSERVED. The lane axis is the opposite (a card really can be in no lane),
     * which is why the sibling correction on `board_create_card` — card#7225, a
     * SEPARATE change, covering BOTH axes of that tool's reported placement and
     * paying a `GET /tasks/{id}.json` for them because a create hands back an id
     * and nothing else — has to tell a present null from a missing key. Do not read
     * that cost across to here: kanban's task resource carries `board_id` on every
     * row `/tasks/search.json` returns, so the rows this tool already holds ARE the
     * reading and this axis costs no extra request.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: ?int, 1: bool}
     */
    private function observedBoard(array $rows, int $configuredBoardId, string $agentName, string $leg): array
    {
        if ($rows === []) {
            return [null, false];
        }

        $boards = [];
        foreach ($rows as $row) {
            $rowBoard = $row['board_id'] ?? null;
            if (! is_numeric($rowBoard)) {
                Log::warning('board_my_cards: a returned row carried no readable board, so the response reports NO board for this row set rather than the configured one', [
                    'agent' => $agentName,
                    'leg' => $leg,
                    'configured_board' => $configuredBoardId,
                    'row_board' => is_scalar($rowBoard) ? $rowBoard : null,
                    'card_id' => is_scalar($row['id'] ?? null) ? $row['id'] : null,
                ]);

                return [null, false];
            }
            $boards[(int) $rowBoard] = true;
        }

        if (count($boards) > 1) {
            Log::warning('board_my_cards: the returned rows are spread across more than one board, so the response reports NO board for this row set', [
                'agent' => $agentName,
                'leg' => $leg,
                'configured_board' => $configuredBoardId,
                'observed_boards' => array_keys($boards),
            ]);

            return [null, false];
        }

        $observed = (int) array_key_first($boards);
        if ($observed !== $configuredBoardId) {
            Log::warning('board_my_cards: the returned rows are NOT on the board this agent is configured to read — the response reports where they actually are, and nothing was dropped', [
                'agent' => $agentName,
                'leg' => $leg,
                'configured_board' => $configuredBoardId,
                'observed_board' => $observed,
                'rows' => count($rows),
            ]);
        }

        return [$observed, true];
    }

    /**
     * Coordination cards addressed to this agent (Q1): the union of cards on the
     * coord board carrying ANY of the agent's address_tags. De-duplicated by id
     * (a card can carry several address tags). Not swimlane-filtered — the
     * addressing IS the scope here.
     *
     * The block carries the SAME board pair as the top level (card#7295 comment
     * append, DL-302). It used to carry no board key at all, which is the
     * missing-key sibling of the wrong-value defect this card names: a caller was
     * handed a second card list, from a DIFFERENT board, with nothing saying so —
     * and the top-level `board_id` sitting above it is the obvious thing for a
     * reader to assume covers it. Stating the coord board explicitly is what stops
     * the top-level reading from being inherited by rows it does not describe; it
     * is the same standard applied to both windows in one file, which is the whole
     * complaint on this card.
     *
     * ⛔ `stage` DOES NOT REACH THIS BLOCK, and that is a property of the ids rather
     * than an omission (card#8985). These cards are on the COORD board; a stage id is
     * only meaningful on the board it belongs to, so applying the caller's product-board
     * id here would silently filter one list against another board's column numbering
     * and answer an empty coord window that looks exactly like "nothing is addressed to
     * you". The `limit` cap DOES reach it — a card count means the same thing on any
     * board — and this block carries its own window so its truncation is legible on its
     * own terms.
     *
     * @return array{coord_board_id: ?int, coord_board_observed: bool, configured_coord_board_id: int, coord_cards: list<array<string, mixed>>, coord_cards_window: array{total: int, returned: int, limit: int, truncated: bool}}
     */
    private function coordBlock(KanbanClient $client, BoardToolsConfig $cfg, ?int $descriptionCap, string $agentName, int $limit): array
    {
        $coordBoardId = (int) $cfg->coordBoardId;
        $byId = [];
        try {
            foreach ($cfg->addressTags as $tag) {
                foreach ($client->cardRowsByTag($coordBoardId, $tag) as $row) {
                    $id = $row['id'] ?? null;
                    if (is_numeric($id)) {
                        $byId[(int) $id] = $row;
                    }
                }
            }
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, 'coord', BoardReadRoute::Search, "the coordination board {$coordBoardId} your address tags are on");
        }
        ksort($byId);
        $rows = array_values($byId);

        // Split for the same reason as the own/shared legs above: this one is board-scoped,
        // and on the COORD board it is the likeliest place a membership gap actually shows —
        // `coord_board_id` is configured separately from `board_id`, so an install can hold
        // membership of one and not the other.
        try {
            $coordStageNames = $client->boardStageNames($coordBoardId);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, 'coord stages', BoardReadRoute::BoardScoped, "the structure of the coordination board {$coordBoardId} your address tags are on");
        }
        // Same rule as the top-level axis, and a SEPARATE call site: the two readings sit
        // in different literals and a change can narrow one alone.
        [$observedBoard, $boardObserved] = $this->observedBoard($rows, $coordBoardId, $agentName, 'coord');
        [$coordCards, $coordWindow] = $this->cardWindow($rows, $limit);

        return [
            'coord_board_id' => $observedBoard,
            'coord_board_observed' => $boardObserved,
            'configured_coord_board_id' => $coordBoardId,
            'coord_cards' => array_map(fn (array $row): array => $this->projectCard($row, $coordStageNames, $descriptionCap), $coordCards),
            'coord_cards_window' => $coordWindow,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, string>  $stageNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByStage(array $rows, array $stageNames, ?int $descriptionCap): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $stageId = is_numeric($row['workflow_stage_id'] ?? null) ? (int) $row['workflow_stage_id'] : null;
            $stageName = $stageId !== null && isset($stageNames[$stageId]) ? $stageNames[$stageId] : ('stage:'.($stageId ?? '?'));
            $grouped[$stageName][] = $this->projectCard($row, $stageNames, $descriptionCap);
        }

        return $grouped;
    }

    /**
     * Project a raw kanban card row to the tool's card shape (DL-217): id, name,
     * stage, tags, dl_number, pr_number, updated_at — plus, ONLY when the caller
     * opted in (DL-245), description + description_truncated. Nothing else leaves
     * the bridge.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $stageNames
     * @param  ?int  $descriptionCap  null ⇒ omit both description keys entirely
     * @return array<string, mixed>
     */
    private function projectCard(array $row, array $stageNames, ?int $descriptionCap): array
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
            'dl_number' => is_scalar($payload['dl_number'] ?? null) ? $payload['dl_number'] : null,
            'pr_number' => is_scalar($payload['pr_number'] ?? null) ? $payload['pr_number'] : null,
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        ];

        if ($descriptionCap !== null) {
            [$card['description'], $card['description_truncated']] = $this->capDescription($row['description'] ?? null, $descriptionCap);
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
    private function capDescription(mixed $raw, int $cap): array
    {
        if (! is_scalar($raw)) {
            return [null, false];
        }
        $description = (string) $raw;
        $cut = mb_strcut($description, 0, $cap, 'UTF-8');

        return [$cut, $cut !== $description];
    }
}
