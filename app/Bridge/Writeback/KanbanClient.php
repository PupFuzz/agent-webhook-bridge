<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kanban-board writeback client (DL-009/019) — sibling to the
 * provisioning-scoped KanbanProvisionClient, but for the card-move WRITE path.
 * It authenticates with a DEDICATED least-privilege writeback token (its own
 * 0600 file, NOT the broad provisioning token) so a leak is bounded to card
 * moves. Throws on non-2xx.
 *
 * It also owns the board-correlation READ (the search that both finders share),
 * and that is the one place it deviates from "thin verb-only client + caller
 * logs": the read is the single choke-point where a degraded-but-not-erroring
 * board response is observable, so the 0-card / page-cap WARNINGs live HERE
 * (see correlationCards) rather than being duplicated across every caller (DL-026).
 */
final class KanbanClient
{
    /** Endpoint max rows per /tasks/search.json page; a board with more live cards is read across pages (DL-028). */
    public const SEARCH_LIMIT = 200;

    /** Safety ceiling on the page walk: at most MAX_PAGES × SEARCH_LIMIT live cards are read (DL-028). */
    public const MAX_PAGES = 50;

    public function __construct(
        private string $baseUrl,
        private string $token,
        private string $correlation = 'ref',
    ) {}

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(rtrim($this->baseUrl, '/'));
    }

    /**
     * The card's current state (incl. board_id + workflow_stage_id) — read for
     * the belongs-to-mapped-board guard and the idempotent already-in-stage check.
     *
     * @return array<string, mixed>
     */
    public function getCard(int $cardId): array
    {
        $data = $this->http()->get("/tasks/{$cardId}.json")->throw()->json('data');

        return is_array($data) ? $data : [];
    }

    /** Move the card to a workflow stage (column-only; never touches payload/other fields). */
    public function moveCard(int $cardId, int $stageId): void
    {
        $this->http()->patch("/tasks/{$cardId}.json", ['task' => ['workflow_stage_id' => $stageId]])->throw();
    }

    /**
     * The card ids on a board correlated to a DL token (DL-029). Returns ALL
     * matches — a bundled PR/DL legitimately tracks multiple cards (DL-148) — so
     * the writeback moves every one; an empty list is a graceful no-op.
     *
     * `correlation = 'ref'` (DL-147/148): one indexed `by-ref` query, server-side
     * canonicalized. `'scan'` (fallback): download the board and digit-match
     * `payload.dl_number` client-side ("DL-9"/"9"/"dl-9" compare equal).
     *
     * @return list<int>
     */
    public function correlateDl(int $boardId, string $dl): array
    {
        if ($this->correlation === 'ref') {
            return $this->findCardsByRef($boardId, 'dl', $dl);
        }

        $want = self::digits($dl);
        if ($want === '') {
            return [];
        }
        $ids = [];
        foreach ($this->correlationCards($boardId) as $card) {
            $cardDl = $card['payload']['dl_number'] ?? null;
            if (is_scalar($cardDl) && self::digits((string) $cardDl) !== '' && (int) self::digits((string) $cardDl) === (int) $want
                && isset($card['id']) && is_numeric($card['id'])) {
                $ids[] = (int) $card['id'];
            }
        }

        return $ids;
    }

    /**
     * The card ids on a board whose `pr_number` matches (DL-029) — the dependabot
     * idempotency key. Collection for the same N:1 reason as {@see correlateDl}.
     *
     * @return list<int>
     */
    public function correlatePr(int $boardId, int $prNumber): array
    {
        if ($this->correlation === 'ref') {
            return $this->findCardsByRef($boardId, 'github_pr', (string) $prNumber);
        }

        $ids = [];
        foreach ($this->correlationCards($boardId) as $card) {
            $pr = $card['payload']['pr_number'] ?? null;
            if (is_numeric($pr) && (int) $pr === $prNumber && isset($card['id']) && is_numeric($card['id'])) {
                $ids[] = (int) $card['id'];
            }
        }

        return $ids;
    }

    /**
     * Card ids correlated to a `(system, ref)` via the kanban by-ref lookup
     * (DL-147/148): `GET /boards/{b}/tasks/by-ref.json` — server canonicalizes
     * the ref and returns the live cards as a collection (N:1). Indexed, O(1),
     * no board scan/paging. Pure: a genuine no-match is an empty list, not an error.
     *
     * @return list<int>
     */
    public function findCardsByRef(int $boardId, string $system, string $ref): array
    {
        $data = $this->http()->get("/boards/{$boardId}/tasks/by-ref.json", [
            'system' => $system,
            'ref' => $ref,
        ])->throw()->json('data');

        $ids = [];
        foreach (is_array($data) ? $data : [] as $card) {
            if (is_array($card) && isset($card['id']) && is_numeric($card['id'])) {
                $ids[] = (int) $card['id'];
            }
        }

        return $ids;
    }

    /**
     * Cheap board-visibility probe for `bridge:check` (DL-029): a single
     * `limit=1` search — answers "can this token see the board, and how big is
     * it?" without the full correlation read, independent of the correlation
     * mode. A blind/non-member token gets a 200 with no rows (the DL-026 signal);
     * an HTTP error throws.
     *
     * Prefers the DL-146 pagination `meta.total` (exact size). Against a
     * pre-DL-146 kanban (no `meta`) it falls back to the returned row count for
     * the 0-vs-nonzero blind-token signal only — `exact:false`, size unknown —
     * so a healthy older board is NOT misreported as a blind token.
     *
     * @return array{total: int, exact: bool}
     */
    public function visibility(int $boardId): array
    {
        $body = $this->http()->get('/tasks/search.json', ['q' => "board_id={$boardId}", 'limit' => 1])->throw()->json();
        $meta = is_array($body) && is_array($body['meta'] ?? null) ? $body['meta'] : null;
        if ($meta !== null && isset($meta['total']) && is_numeric($meta['total'])) {
            return ['total' => (int) $meta['total'], 'exact' => true];
        }

        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];

        return ['total' => count($data), 'exact' => false];
    }

    /**
     * Whether the kanban instance exposes the `by-ref` lookup (DL-031). Since
     * `ref` is the default correlation mode, a kanban that predates by-ref
     * (< v0.17.2) would 404 EVERY correlation silently — `bridge:check` calls
     * this to catch that before traffic. by-ref returns 200 `{data:[]}` on a
     * no-match (never 404), so a 404 means the ROUTE isn't registered. Any other
     * non-2xx is a real error and re-throws.
     */
    public function byRefAvailable(int $boardId): bool
    {
        $resp = $this->http()->get("/boards/{$boardId}/tasks/by-ref.json", ['system' => 'dl', 'ref' => '0']);
        if ($resp->status() === 404) {
            return false;   // route not registered → kanban predates by-ref
        }
        $resp->throw();

        return true;
    }

    /**
     * The board's cards for correlation, WITH the degraded-state guards (DL-026/028).
     *
     * `null` from a finder otherwise conflates "N cards, none matched" (a genuine
     * no-op) with "0 cards" — which means the token's user lost board membership
     * (or board_id/instance is wrong): kanban answers 200 + empty data, so
     * `->throw()` never fires and EVERY correlation silently no-ops (or, on the
     * dependabot create path, duplicates a card). Make those two non-erroring
     * degradations LOUD here, at the single read both finders share — never as a
     * 5xx (that would retry-storm a genuinely-blind board). A genuine no-match
     * (N>0, none matched) logs nothing. Truncation past the MAX_PAGES ceiling is
     * the same class of silent loss and is warned the same way.
     *
     * @return list<array<string, mixed>>
     */
    private function correlationCards(int $boardId): array
    {
        $read = $this->readBoard($boardId);
        if ($read->cards === []) {
            Log::warning('writeback correlation: board read returned 0 cards — the writeback token\'s user is likely not a member of this board (or board_id/instance is wrong); every card-move correlation will silently no-op until fixed', ['board_id' => $boardId]);
        } elseif ($read->truncated) {
            Log::warning('writeback correlation: board read hit the '.self::MAX_PAGES.'-page safety ceiling ('.(self::MAX_PAGES * self::SEARCH_LIMIT).' cards) — any cards beyond it are invisible to correlation', ['board_id' => $boardId, 'ceiling' => self::MAX_PAGES * self::SEARCH_LIMIT]);
        }

        return $read->cards;
    }

    /**
     * Read a board's cards via the task-search endpoint (server-side board_id
     * filter), paging until a short page (DL-028). The search response is a bare
     * `{data:[...]}` with no total/meta, so the stop condition is "page until a
     * page returns fewer than SEARCH_LIMIT rows"; a hard MAX_PAGES ceiling bounds
     * a pathological/non-paging upstream. Pure: no logging.
     *
     * Both the short-page break and the `$truncated` flag are decided on the RAW
     * batch length (rows kanban returned), NOT the array-filtered/merged count —
     * a non-array row would otherwise desync the decision (a missed-truncation
     * false negative, the DL-026 silent-loss class). The break MUST stay
     * `< SEARCH_LIMIT` (continue while `>=`): an `=== SEARCH_LIMIT` test would
     * loop forever against an upstream that ever returned an over-full page. The
     * truncation flag assumes kanban honors `page` (it does — server-side
     * `forPage` over a total `id`-desc order, so pages don't skip/dup).
     */
    private function readBoard(int $boardId): BoardRead
    {
        $cards = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $batch = $this->http()->get('/tasks/search.json', ['q' => "board_id={$boardId}", 'limit' => self::SEARCH_LIMIT, 'page' => $page])->throw()->json('data');
            $rows = is_array($batch) ? $batch : [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $cards[] = $row;
                }
            }
            if (count($rows) < self::SEARCH_LIMIT) {
                return new BoardRead($cards, false);   // short/empty page ⇒ fully read
            }
        }

        // Ran all MAX_PAGES pages and every one came back full ⇒ the board is at
        // or beyond the ceiling and cards past it were not read.
        return new BoardRead($cards, true);
    }

    /**
     * Create a card on a board at a stage; returns the new card id. An optional
     * $swimlaneId places it in a lane (DL-027) — omitted from the POST entirely
     * when null (the board then assigns its default lane, today's behavior).
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $tags
     */
    public function createCard(int $boardId, int $stageId, string $name, array $payload, array $tags, ?int $swimlaneId = null): int
    {
        $task = [
            'board_id' => $boardId,
            'workflow_stage_id' => $stageId,
            'name' => $name,
            'payload' => $payload,
            'tags' => $tags,
        ];
        if ($swimlaneId !== null) {
            $task['swimlane_id'] = $swimlaneId;
        }
        $data = $this->http()->post('/tasks.json', ['task' => $task])->throw()->json('data');
        if (! is_array($data) || ! isset($data['id']) || ! is_numeric($data['id'])) {
            throw new \RuntimeException('kanban createCard: response carried no task id');
        }

        return (int) $data['id'];
    }

    /**
     * The swimlane ids defined on a board — for the bridge:check validation that
     * a mapping's `swimlane_id` (DL-027) exists on its board. Uses the lightweight
     * preload endpoint (carries swimlanes, NOT every task) — never the task-heavy
     * GET /boards/{id}.json.
     *
     * @return list<int>
     */
    public function boardSwimlaneIds(int $boardId): array
    {
        $swimlanes = $this->http()->get("/boards/{$boardId}/preload.json")->throw()->json('data.swimlanes');
        $ids = [];
        foreach (is_array($swimlanes) ? $swimlanes : [] as $s) {
            if (is_array($s) && isset($s['id']) && is_numeric($s['id'])) {
                $ids[] = (int) $s['id'];
            }
        }

        return $ids;
    }

    private static function digits(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }
}
