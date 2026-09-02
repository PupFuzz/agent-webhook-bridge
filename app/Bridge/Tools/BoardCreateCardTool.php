<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\KanbanClient;
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
            $existing = $client->cardsByTag($boardId, $idemTag);
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
            $retired = self::archivedIds($client->cardRowsByTag($boardId, $idemTag, true));
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

        $newId = $client->createCard(
            $boardId,
            (int) $cfg->createStageId,
            $title,
            [],                       // payload {} in v1 — no by-ref keys, no origin (identity rides the tag)
            $tags,
            (int) $cfg->swimlaneId,   // FORCED from config — a caller can never name a lane
            $description,
        );
        Log::info('board_create_card: created', ['agent' => $agentName, 'card_id' => $newId, 'board_id' => $boardId, 'stage' => (int) $cfg->createStageId, 'swimlane_id' => (int) $cfg->swimlaneId, 'idem_tag' => $idemTag]);

        // Post-create re-read + collapse (DL-198 leg 2): two concurrent same-key
        // calls can both correlate-empty and both create; the deterministic
        // lowest-id survivor is what closes that race.
        if ($idemTag !== null) {
            $live = $client->cardsByTag($boardId, $idemTag);
            if (count($live) > 1) {
                CardCollapse::toSurvivor($client, array_fill_keys($live, []), 'board_create_card', ['agent' => $agentName, 'idem_tag' => $idemTag]);
                // The collapse keeps the deterministic lowest id (so racing workers
                // converge); report that survivor, not the id kanban happened to
                // return to THIS worker.
                $newId = min($live);
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
     * @param  array<string, mixed>  $args
     */
    private function requireTitle(array $args): string
    {
        $title = $args['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new ToolRefusalException('board_create_card: `title` is required and must be a non-empty string');
        }

        return $title;
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
