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
    /** Endpoint max for /tasks/search.json; a board with more live cards needs paging (DL-026). */
    public const SEARCH_LIMIT = 200;

    public function __construct(
        private string $baseUrl,
        private string $token,
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
     * Find the card on a board whose `dl_number` custom field matches a DL token
     * (correlation, DL-021). Matches on the numeric part so "DL-9" / "9" / "dl-9"
     * compare equal. Returns the card id, or null when no card matches (a PR with
     * no tracked card → the writeback gracefully no-ops). Reads the board via the
     * task-search endpoint and filters client-side, so it doesn't depend on the
     * search DSL's custom-field syntax.
     */
    public function findCardByDlNumber(int $boardId, string $dl): ?int
    {
        $want = self::digits($dl);
        if ($want === '') {
            return null;
        }
        foreach ($this->correlationCards($boardId) as $card) {
            $cardDl = $card['payload']['dl_number'] ?? null;
            // Compare on the numeric value (int) so "DL-42" / "42" / "042" all
            // match. Skip a non-scalar dl_number (defensive — payload is free-form).
            if (is_scalar($cardDl) && self::digits((string) $cardDl) !== '' && (int) self::digits((string) $cardDl) === (int) $want
                && isset($card['id']) && is_numeric($card['id'])) {
                return (int) $card['id'];
            }
        }

        return null;
    }

    /**
     * The id of the card on a board whose `payload.pr_number` matches, or null.
     * The idempotency key for PR-origin cards (dependabot) that carry no DL.
     */
    public function findCardByPrNumber(int $boardId, int $prNumber): ?int
    {
        foreach ($this->correlationCards($boardId) as $card) {
            $pr = $card['payload']['pr_number'] ?? null;
            if (is_numeric($pr) && (int) $pr === $prNumber && isset($card['id']) && is_numeric($card['id'])) {
                return (int) $card['id'];
            }
        }

        return null;
    }

    /** Number of cards the writeback token can see on a board — the bridge:check visibility probe (DL-026). */
    public function boardCardCount(int $boardId): int
    {
        return count($this->searchBoardCards($boardId));
    }

    /**
     * The board's cards for correlation, WITH the degraded-state guard (DL-026).
     *
     * `null` from a finder otherwise conflates "N cards, none matched" (a genuine
     * no-op) with "0 cards" — which means the token's user lost board membership
     * (or board_id/instance is wrong): kanban answers 200 + empty data, so
     * `->throw()` never fires and EVERY correlation silently no-ops (or, on the
     * dependabot create path, duplicates a card). Make those two non-erroring
     * degradations LOUD here, at the single read both finders share — never as a
     * 5xx (that would retry-storm a genuinely-blind board). A genuine no-match
     * (N>0, none matched) logs nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function correlationCards(int $boardId): array
    {
        $cards = $this->searchBoardCards($boardId);
        if ($cards === []) {
            Log::warning('writeback correlation: board read returned 0 cards — the writeback token\'s user is likely not a member of this board (or board_id/instance is wrong); every card-move correlation will silently no-op until fixed', ['board_id' => $boardId]);
        } elseif (count($cards) >= self::SEARCH_LIMIT) {
            Log::warning('writeback correlation: board read hit the '.self::SEARCH_LIMIT.'-card cap — cards beyond the cap are invisible to correlation; paging is needed', ['board_id' => $boardId, 'cap' => self::SEARCH_LIMIT]);
        }

        return $cards;
    }

    /**
     * Read a board's cards via the task-search endpoint (server-side board_id
     * filter), filtered to array rows. Pure: no logging — `correlationCards`
     * owns the degraded-state warnings, and `bridge:check` prints its own lines.
     *
     * @return list<array<string, mixed>>
     */
    private function searchBoardCards(int $boardId): array
    {
        $cards = $this->http()->get('/tasks/search.json', ['q' => "board_id={$boardId}", 'limit' => self::SEARCH_LIMIT])->throw()->json('data');

        return array_values(array_filter(is_array($cards) ? $cards : [], 'is_array'));
    }

    /**
     * Create a card on a board at a stage; returns the new card id.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $tags
     */
    public function createCard(int $boardId, int $stageId, string $name, array $payload, array $tags): int
    {
        $data = $this->http()->post('/tasks.json', ['task' => [
            'board_id' => $boardId,
            'workflow_stage_id' => $stageId,
            'name' => $name,
            'payload' => $payload,
            'tags' => $tags,
        ]])->throw()->json('data');
        if (! is_array($data) || ! isset($data['id']) || ! is_numeric($data['id'])) {
            throw new \RuntimeException('kanban createCard: response carried no task id');
        }

        return (int) $data['id'];
    }

    private static function digits(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }
}
