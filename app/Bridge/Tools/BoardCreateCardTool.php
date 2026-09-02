<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_create_card (DL-217) — create a card in the CALLING AGENT's OWN swimlane.
 * The write scope is forced from {@see BoardToolsConfig}: swimlane_id and
 * create_stage_id come from config, args cannot name a lane or stage. The card is
 * born UNTRIAGED deliberately (the fleet triage contract is "new cards surface to
 * the PM's triage pass" — an agent-captured card is exactly what triage is for),
 * so a caller-supplied `triaged` tag is REFUSED.
 *
 * Provenance + correlation are bridge-stamped, never caller-forgeable:
 *  - `created-by:<agent>` — the audit stamp; a caller cannot forge another
 *    agent's stamp (the `created-by:` prefix is reserved).
 *  - `idem:<agent>:<key>` — when an idempotency_key is passed, the correlation
 *    key for the FULL DL-198 pattern (correlate-before-create + post-create
 *    re-read + CardCollapse); the `idem:` prefix is reserved so a caller cannot
 *    poison a future idempotency probe.
 *
 * The idempotency probe reads BOTH sides of kanban's archive axis (DL-297),
 * because that axis is a SWITCH and not a widening: a live tag search cannot see
 * an archived row, so re-using a key whose card was retired used to read as
 * un-carded and mint a SECOND card reporting `"created": true`. A live twin is
 * still the idempotent hit; an ARCHIVED-only twin is a deliberate retire and
 * REFUSES the create (422) naming the card to unarchive — a retire is not this
 * tool's to undo, and handing the archived card back as a hit would answer with a
 * card the caller's own `board_my_cards` window (live-only, correctly) does not
 * show. Class item card#7222.
 *
 * The placement the response carries is READ BACK from the card, never restated
 * from this agent's config (card#7225, DL-299) — `board_id`/`swimlane_id` are the
 * card's own, `placement_observed` says whether the bridge actually saw them, and
 * a false there means the response claims no placement at all. The config values
 * still ride along, on their OWN keys (`configured_board_id` /
 * `configured_swimlane_id`, both arms), because a caller whose read-back failed
 * has no other channel to the scope this agent was aiming at. {@see placement}
 * owns the reasoning.
 *
 * Caller tags matching a reserved prefix (`created-by:` / `idem:` / `id:` /
 * `type:`) or the bare `triaged` are refused (422-class): no forging an audit
 * stamp, no poisoning idempotency, no hijacking the coord adoption/type keys, no
 * defeating born-untriaged. ⭐ The GUARD itself moved to {@see CallerTagPolicy} when
 * card#8378 gave it a second caller (`board_correct_card` must refuse exactly what
 * this tool refuses, or the correction becomes the route around it); this docblock
 * stays the owner of WHY each entry is on the list, and the paragraph below of why
 * the match is casefolded and charset-constrained. The reserved match is CASEFOLDED (`strtolower(trim)`
 * vs the lowercase reserved set) as defense-in-depth across driver collations:
 * whether the backing tag search folds case is a per-driver fact (MariaDB's JSON
 * column collates utf8mb4_bin ⇒ case-SENSITIVE, measured; a kanban running on
 * SQLite folds ASCII in LIKE), so the guard refuses every case variant rather
 * than betting on the deployed collation — a case-exact guard would let
 * `IDEM:agentB:daily` through to a folding backend, where it collides with
 * another agent's lowercase idempotency probe and poisons it. To keep the
 * bridge-side `strtolower` fold deterministic for the surviving charset, each
 * caller tag is first charset-constrained to printable ASCII with the
 * tag-search metacharacters (`"`/`*`/`_`/`%`) excluded — non-ASCII bytes fold
 * differently under an ASCII casefold than under a Unicode-aware collation, and
 * the metacharacters mis-split or wildcard-over-match the kanban tokenizer. The
 * idempotency_key is charset-constrained to `[A-Za-z0-9.-]{1,64}` for the same
 * tokenizer reason AND lowercased after it validates, so the bridge always
 * stores/searches one deterministic `idem:<agent>:<key>` tag (a
 * `Report`/`report` pair cannot mint two probe tags whose correlation would
 * then depend on the backend's collation).
 *
 * ⭐ A PERMANENT 4xx FROM THE BOARD IS A NAMED REFUSAL, NOT THE RETRYABLE 502 (card#8486).
 * DL-326 decided that for `board_correct_card` and the mapping now lives in
 * {@see BoardCallRefusal} for the whole door: a rotated writeback token (401) or a
 * writeback user without `task.create` (403) used to reach a seat as `502 upstream board
 * error`, which is an instruction to RETRY a call that fails identically every time. Both
 * idempotency reads and the create itself are mapped; the post-create re-read deliberately
 * is not (see the comment on that branch). `title` is bounded before the request for the
 * same reason the correction tool bounds `name` — the 422 the board would answer is the
 * message the seat could not read.
 */
final class BoardCreateCardTool implements Tool
{
    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $title = $this->requireTitle($args);
        $description = $this->optionalDescription($args);
        $callerTags = CallerTagPolicy::sanitize($args, $this->name());
        $idemKey = $this->validateIdempotencyKey($args);

        $boardId = (int) $cfg->boardId;
        $tags = $callerTags;
        $tags[] = "created-by:{$agentName}";
        $idemTag = null;
        if ($idemKey !== null) {
            $idemTag = "idem:{$agentName}:{$idemKey}";
            $tags[] = $idemTag;

            // Correlate-before-create (DL-198 leg 1): a prior call with the same
            // key already minted the card → return it, no second create.
            try {
                $existing = $client->cardsByTag($boardId, $idemTag);
            } catch (RequestException $e) {
                throw $this->lookupRefusal($e, $boardId, $agentName, 'idempotency correlation');
            }
            if ($existing !== []) {
                sort($existing);
                $hitId = $existing[0];
                Log::info('board_create_card: idempotency hit — returning the existing card, no create', ['agent' => $agentName, 'idem_tag' => $idemTag, 'card_id' => $hitId]);

                return ['created' => false, 'idempotent_hit' => true, 'card_id' => $hitId]
                    + $this->placement($client, $cfg, $hitId, $agentName, 'idempotency hit');
            }

            // The ARCHIVE side of the same key (DL-297): the read above is
            // live-only, because kanban's search applies `whereNull('archived_at')`
            // unless `?archived` is passed — so without this second read a key whose
            // card was RETIRED reads as un-carded and mints a replacement over the
            // retire. Placed here, on the last branch before the create, so it costs
            // one search per card actually minted and nothing at all on the hit path.
            try {
                $retired = self::archivedIds($client->cardRowsByTag($boardId, $idemTag, true));
            } catch (RequestException $e) {
                throw $this->lookupRefusal($e, $boardId, $agentName, 'archive-side idempotency probe');
            }
            if ($retired !== []) {
                Log::warning('board_create_card: the only card for this idempotency key is ARCHIVED — refusing, no replacement created', ['agent' => $agentName, 'idem_tag' => $idemTag, 'archived_card_ids' => $retired]);

                throw new ToolRefusalException(
                    'board_create_card: this `idempotency_key` correlates only to ARCHIVED card(s) — '
                    .implode(', ', $retired).' — and an archived card is a deliberate retire, which un-retiring is not this '
                    .'tool\'s to do, so NO replacement was created. Unarchive that card if the work is live again, or pass a '
                    .'NEW `idempotency_key` if this is genuinely new work.'
                );
            }
        }

        try {
            $newId = $client->createCard(
                $boardId,
                (int) $cfg->createStageId,
                $title,
                [],                       // payload {} in v1 — no by-ref keys, no origin (identity rides the tag)
                $tags,
                (int) $cfg->swimlaneId,   // FORCED from config — a caller can never name a lane
                $description,
            );
        } catch (RequestException $e) {
            throw $this->createRefusal($e, $boardId, $agentName);
        }
        Log::info('board_create_card: created', ['agent' => $agentName, 'card_id' => $newId, 'board_id' => $boardId, 'stage' => (int) $cfg->createStageId, 'swimlane_id' => (int) $cfg->swimlaneId, 'idem_tag' => $idemTag]);

        // Post-create re-read + collapse (DL-198 leg 2): two concurrent same-key
        // calls can both correlate-empty and both create; the deterministic
        // lowest-id survivor is what closes that race.
        //
        // ⛔ DELIBERATELY NOT ROUTED THROUGH {@see BoardCallRefusal} (card#8486): this leg
        // runs only when an idempotency_key was passed, i.e. exactly when a RETRY is
        // idempotent by construction — and the card has ALREADY been created here, so a
        // "permanent, do not retry" answer would be the wrong instruction. The retryable 502
        // sends the seat back through the correlate-before-create read above, which returns
        // the card on a fault that cleared and names the install fault on one that did not.
        if ($idemTag !== null) {
            // ROWS, not ids (card#8523): the collapse reads the DL-178 pin off the card it is
            // about to archive, and the ids-only `cardsByTag` this used to call let it archive
            // a card nobody had read — the predicate answering "not pinned" for every row by
            // construction. `cardRowsByTag` is the row-returning twin of the same one search,
            // so this costs no extra request; the archived-axis probe above already uses it.
            // ⛔ The DL-026 degraded-read warning `cardsByTag` carried is NOT lost in that swap:
            // it was, in the first cut of this change, and card#8523's review caught it — the
            // warning now lives in `cardRowsByTag` too, so this read still says so when kanban
            // answers 200 with no card collection instead of silently reading "no duplicates".
            $live = self::rowsById($client->cardRowsByTag($boardId, $idemTag));
            if (count($live) > 1) {
                CardCollapse::toSurvivor($client, $live, 'board_create_card', '', ['agent' => $agentName, 'idem_tag' => $idemTag]);
                // The collapse keeps the deterministic lowest id (so racing workers
                // converge); report that survivor, not the id kanban happened to
                // return to THIS worker.
                $newId = min(array_keys($live));
            }
        }

        return ['created' => true, 'idempotent_hit' => false, 'card_id' => $newId]
            + $this->placement($client, $cfg, $newId, $agentName, 'created');
    }

    public function name(): string
    {
        return 'board_create_card';
    }

    /**
     * WHERE THE CARD ACTUALLY IS — the placement half of this tool's answer, read
     * back from the card itself (card#7225, DL-299). Both arms used to restate
     * `$cfg->boardId` / `$cfg->swimlaneId`, which is where this agent is
     * CONFIGURED to write, never a reading of the card being handed back; the two
     * are equal until something has gone wrong, so the response was silently
     * correct exactly until the moment a caller needed it. The idempotency-hit arm
     * was the sharp one: that card id came from a tag SEARCH, so the tool asserted
     * the configured board for a card it had not created and had not re-checked.
     *
     * `placement_observed` is the discrimination this needs to be honest, and is
     * NOT redundant: a card legitimately in NO lane reports `swimlane_id: null`,
     * so a null alone cannot say whether the bridge read "no lane" or read
     * nothing. False ⇒ both ids are null and the response claims NO placement —
     * never a config value dressed as an observation.
     *
     * `configured_board_id` / `configured_swimlane_id` ride along on BOTH arms,
     * and they are NOT the fallback this change rejected: a fallback puts the
     * config value on the OBSERVATION key, where a caller cannot tell the two
     * apart. Named separately they answer a question the observation cannot —
     * *"what scope was this agent aiming at?"* — which is exactly what the caller
     * has no other channel to when `placement_observed` is false and both ids are
     * null. `MappedBoardGuard::boardContext` ruled the same shape for the
     * writeback record (card#7212: both keys on both arms, because a record
     * carrying one can answer "did we stop it?" and never "did this happen?").
     *
     * ⛔ ONE flag for the PAIR here, one PER AXIS on the matching `board_my_cards`
     * correction (card#7295 / DL-302 — a SEPARATE change, not depended on here).
     * A deliberate divergence, not drift, recorded because an unrecorded one is
     * indistinguishable from drift to the next reader: that tool reads two
     * INDEPENDENT row sets (own+shared, coord), so either can be readable while
     * the other is not and each needs its own flag. Here both axes come off ONE
     * read-back of ONE card — they are observed or unobserved together, and a
     * per-axis flag would advertise an independence this tool cannot produce. The
     * shared rule is one flag per unit that can independently fail to be read.
     *
     * FAIL-SOFT, deliberately — and the reason is NOT the same on both arms this
     * primitive serves. On the CREATE arm the read-back runs after the create has
     * already landed, so throwing would answer a failure for a card that exists
     * and whose id the caller would then never see (and, with no
     * idempotency_key, its retry double-creates). That argument does NOT hold on
     * the IDEMPOTENCY-HIT arm: no create happened there and a retry is idempotent
     * by construction. What holds there instead is the contract of a SHARED
     * primitive — a caller cannot tell which arm answered it, so the response
     * shape must not vary by arm; a hit that threw where a create degrades would
     * make the tool's failure mode a function of board state the caller cannot
     * see. Either way, losing the placement is strictly cheaper than losing the
     * id.
     *
     * A read-back that answers nothing usable about EITHER axis is reported as
     * unobserved on BOTH, never as a null lane. Kanban returns `board_id` on
     * every task row and `swimlane_id` top-level beside it, so a missing or
     * non-numeric `board_id`, an ABSENT `swimlane_id` key, or a present
     * non-numeric one, all mean the body answered nothing about placement. The
     * one real null is a PRESENT `swimlane_id: null` — that card is genuinely in
     * no lane, and `array_key_exists` is what tells the two apart (`?? null`
     * cannot, and would report "no lane" for a card sitting in one).
     * `placement_observed` answers for the PLACEMENT, not per axis, which is why
     * one unreadable axis unobserves the pair.
     *
     * A divergence from config is WARNED, not refused: what a card's placement
     * makes the tool do is a separate question from what it reports, and this
     * change is scoped to the report.
     *
     * @return array{board_id: ?int, swimlane_id: ?int, placement_observed: bool, configured_board_id: int, configured_swimlane_id: int}
     */
    private function placement(KanbanClient $client, BoardToolsConfig $cfg, int $cardId, string $agentName, string $arm): array
    {
        $configured = ['configured_board_id' => (int) $cfg->boardId, 'configured_swimlane_id' => (int) $cfg->swimlaneId];
        $unobserved = ['board_id' => null, 'swimlane_id' => null, 'placement_observed' => false] + $configured;

        try {
            $card = $client->getCard($cardId);
        } catch (\Throwable $e) {
            Log::warning('board_create_card: the card could not be read back, so the response reports NO placement rather than the configured board/lane', [
                'agent' => $agentName, 'arm' => $arm, 'card_id' => $cardId, 'error' => $e->getMessage(),
            ]);

            return $unobserved;
        }

        $board = is_numeric($card['board_id'] ?? null) ? (int) $card['board_id'] : null;
        // A PRESENT `swimlane_id: null` is a reading (no lane); an ABSENT key is
        // the body answering nothing about the lane. See the docblock — the whole
        // placement is unobserved when either axis is.
        $laneObserved = array_key_exists('swimlane_id', $card)
            && ($card['swimlane_id'] === null || is_numeric($card['swimlane_id']));

        $unusable = [];
        if ($board === null) {
            $unusable[] = 'board_id';
        }
        if (! $laneObserved) {
            $unusable[] = 'swimlane_id';
        }
        if ($unusable !== []) {
            Log::warning('board_create_card: the card read-back carried no usable board_id/swimlane_id, so the response reports NO placement', [
                'agent' => $agentName, 'arm' => $arm, 'card_id' => $cardId, 'unusable' => implode(', ', $unusable),
            ]);

            return $unobserved;
        }

        $swimlane = $card['swimlane_id'] === null ? null : (int) $card['swimlane_id'];
        if ($board !== (int) $cfg->boardId || $swimlane !== (int) $cfg->swimlaneId) {
            Log::warning('board_create_card: the card this call answers with is NOT where this agent is configured to write — the response reports where it actually is', [
                'agent' => $agentName, 'arm' => $arm, 'card_id' => $cardId,
                'observed_board_id' => $board, 'configured_board_id' => (int) $cfg->boardId,
                'observed_swimlane_id' => $swimlane, 'configured_swimlane_id' => (int) $cfg->swimlaneId,
            ]);
        }

        return ['board_id' => $board, 'swimlane_id' => $swimlane, 'placement_observed' => true] + $configured;
    }

    /**
     * The card's title, bounded at {@see KanbanFieldLimits::NAME_MAX} — kanban's own cap on
     * the field this becomes. Until card#8486 it was unbounded, so an over-long title
     * reached the board, came back 422, and the dispatcher reported it as `502 upstream
     * board error`: a retryable answer to a request that can never succeed, i.e. the seat
     * retries forever with no diagnosis. Refused here instead, by name, before anything is
     * read or written.
     *
     * @param  array<string, mixed>  $args
     */
    private function requireTitle(array $args): string
    {
        $title = $args['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new ToolRefusalException('board_create_card: `title` is required and must be a non-empty string');
        }
        $tooLong = BoardCallRefusal::overLongName($this->name(), 'title', $title, 'No card was created');
        if ($tooLong !== null) {
            throw $tooLong;
        }

        return $title;
    }

    /**
     * A 4xx the BOARD answered on one of the two idempotency READS, mapped to a named
     * refusal; anything else (5xx, a timeout) is re-thrown for the dispatcher's retryable
     * 502 (card#8486, the mapping DL-326 built for `board_correct_card`).
     *
     * ⭐ THE READS ARE WHERE A ROTATED TOKEN SURFACES ON THIS TOOL, and until this they
     * surfaced as a 502 the seat retried: kanban's v3 API is `auth:sanctum`, so a 401 is
     * answered identically on every subsequent attempt. Both reads run BEFORE the create,
     * so nothing was written when either refuses — and that is what the message says.
     * ⛔ Failing closed here rather than creating anyway is DL-297's rule, unchanged: a
     * create the bridge could not correlate is the duplicate (or the re-mint over a retire)
     * the probes exist to prevent.
     *
     * ⚠ BOTH READS ARE CARD SEARCHES, and the route class is named rather than defaulted:
     * what a 403/404 means is a property of the route ({@see BoardReadRoute}), and this tool
     * touches no board-scoped read at all.
     */
    private function lookupRefusal(RequestException $e, int $boardId, string $agentName, string $leg): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_create_card: the board refused an idempotency read', [
            'agent' => $agentName, 'board_id' => $boardId, 'leg' => $leg, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal(
            $this->name(),
            BoardReadRoute::Search,
            $status,
            "your board {$boardId} to run the `idempotency_key` {$leg}",
            'so NO card was created — the bridge does not create a card it could not correlate first',
        );
    }

    /**
     * A 4xx the BOARD answered on the CREATE itself. Every arm is deterministic, so every
     * one is a refusal rather than the retryable 502: a 401 means the token is no longer
     * accepted, a 403 that the writeback user may not create here, a 404 that the create
     * ROUTE is not there, and a 422 that kanban's own validator rejected a VALUE — which no
     * number of retries will change either.
     *
     * ⛔ THE 422 ARM IS WHAT MAKES THE BRIDGE-SIDE BOUNDS SAFE TO GO STALE — the title cap
     * above and {@see CallerTagPolicy}'s tag cap mirror rules that live in kanban's repo, so
     * reaching a board 422 with both satisfied is precisely the signal that one has moved.
     * The message is BRIDGE-AUTHORED: the board's response body is never echoed.
     *
     * ⭐ THE 403 ARM DOES NOT ENUMERATE ITS OWN GATES — {@see BoardCallRefusal::writeGatesClause}
     * does, for every write on this door. This arm enumerated them longhand when it was first
     * written and inherited `board_correct_card`'s omission of kanban's board write gate; a
     * gate the clause does not name is a gate the operator does not audit.
     */
    private function createRefusal(RequestException $e, int $boardId, string $agentName): \Throwable
    {
        Log::warning('board_create_card: the board refused the create', [
            'agent' => $agentName, 'board_id' => $boardId, 'status' => $e->response->status(),
        ]);

        $status = BoardCallRefusal::permanentOnWrite($e);
        if ($status === null) {
            return $e;
        }

        return new ToolRefusalException(match ($status) {
            404 => "board_create_card: the board answered 404 for the create itself, which is an API-surface fault rather than anything about board {$boardId} — NO card was created. This is an INSTALL fault, not something your arguments can fix; report it to your operator.",
            403 => "board_create_card: the board refused the create (403) — the bridge's writeback user may not create cards on board {$boardId}. ".BoardCallRefusal::writeGatesClause('POST', 'task.create').' NO card was created. This is an INSTALL fault, not something your arguments can fix; report it to your operator.',
            401 => 'board_create_card: the board did not accept the bridge\'s writeback token at all on the create (401) — it has been revoked, rotated or replaced with a value the board does not know. NO card was created. This is an INSTALL fault; retrying will not change it.',
            422 => 'board_create_card: the board REJECTED the value you sent (422) — kanban\'s own validator refused it, so NO card was created and re-sending the same call cannot succeed. '.BoardCallRefusal::bridgeBoundsClause().' Shorten or simplify your `title`, `description` or tags, and report it to your operator if it persists.',
        });
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function optionalDescription(array $args): ?string
    {
        if (! array_key_exists('description', $args) || $args['description'] === null) {
            return null;
        }
        $description = $args['description'];
        if (! is_string($description)) {
            throw new ToolRefusalException('board_create_card: `description` must be a string when provided');
        }

        return $description;
    }

    /**
     * The card ids out of an `archived=1` tag search — every row, because on THIS
     * surface every archived twin is a retire (DL-297).
     *
     * ⚠ This is deliberately NOT the coord create leg's `retiredTwins()`
     * (`KanbanCoordCardHandler`), which exempts a whole thread when any row carries the
     * consumer's `coord:reroute-archived` marker. That carve-out exists because the
     * consumer's reconcile is a SECOND MOVER that re-creates the exempted thread on
     * its next pass, so refusing there would be a refusal the other mover undoes
     * (DL-296 Decision 3b). No second mover creates a tool card: an `idem:` card is
     * minted only by this tool, by this agent's own call, and nothing reconciles it —
     * so the refusal is durable and its remedy ("unarchive that card") is accurate.
     * The two partitions are the same rule (a retire suppresses) applied to
     * populations with different movers, not a divergence to reconcile; the shared
     * READ (`cardRowsByTag(..., archivedOnly: true)`) is already single-sourced on
     * the client. Hoisting the surrounding read-then-decide sequence into one
     * primitive both call is the open consolidation on card#7222.
     *
     * A row kanban answered without a usable `id` still counts as a retire —
     * dropping it would silently un-suppress the create — and reports as `0`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private static function archivedIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $ids[] = is_numeric($id) ? (int) $id : 0;
        }

        return $ids;
    }

    /**
     * Search rows keyed by their own card id — the shape {@see CardCollapse::toSurvivor()}
     * takes, and the reason this tool reads rows rather than ids at all (card#8523: the
     * collapse consults the pin on the row it is handed).
     *
     * A row kanban answered without a usable `id` is DROPPED rather than keyed to `0`,
     * which is the opposite call from {@see archivedIds} and for the opposite reason: there
     * an unusable row must still SUPPRESS a create, so it counts; here it would name a card
     * to ARCHIVE, and `0` is not a card. Dropping it can only shrink the collapse set, and
     * the survivor of a set kanban described badly is the next event's problem, not this
     * request's.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function rowsById(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_numeric($id)) {
                $byId[(int) $id] = $row;
            }
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function validateIdempotencyKey(array $args): ?string
    {
        if (! array_key_exists('idempotency_key', $args) || $args['idempotency_key'] === null) {
            return null;
        }
        $key = $args['idempotency_key'];
        if (! is_string($key) || preg_match('/^[A-Za-z0-9.-]{1,64}$/D', $key) !== 1) {
            throw new ToolRefusalException('board_create_card: `idempotency_key` must match [A-Za-z0-9.-]{1,64} — other characters (notably " * _ %) are kanban tag-search metacharacters that could correlate the wrong card');
        }

        // Normalize case AFTER the charset check: whether the stored/searched
        // `idem:<agent>:<key>` tag correlates case-sensitively is a per-driver
        // collation fact (see class docblock) — on a case-sensitive backend a
        // re-sent key differing only in case would miss its own prior card and
        // mint a duplicate. Lowercasing makes the correlation deterministic.
        // The key is used nowhere case-sensitively (only to build the idem tag
        // above).
        return strtolower($key);
    }
}
