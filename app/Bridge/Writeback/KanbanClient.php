<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin kanban-board writeback client (DL-009/019) — sibling to the
 * provisioning-scoped KanbanProvisionClient, but for the card-move WRITE path.
 * It authenticates with a DEDICATED least-privilege writeback token (its own
 * 0600 file, NOT the broad provisioning token) so a leak is bounded to card
 * moves. Exposes only the two writeback verbs; throws on non-2xx.
 */
final class KanbanClient
{
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
        // limit=200 is the endpoint max; the server-side board_id filter keeps the
        // result to one board, so 200 covers any realistic active board. (A board
        // with >200 live cards would need paging — out of scope for now.)
        $cards = $this->http()->get('/tasks/search.json', ['q' => "board_id={$boardId}", 'limit' => 200])->throw()->json('data');
        foreach (is_array($cards) ? $cards : [] as $card) {
            if (! is_array($card)) {
                continue;
            }
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
        $cards = $this->http()->get('/tasks/search.json', ['q' => "board_id={$boardId}", 'limit' => 200])->throw()->json('data');
        foreach (is_array($cards) ? $cards : [] as $card) {
            if (! is_array($card)) {
                continue;
            }
            $pr = $card['payload']['pr_number'] ?? null;
            if (is_numeric($pr) && (int) $pr === $prNumber && isset($card['id']) && is_numeric($card['id'])) {
                return (int) $card['id'];
            }
        }

        return null;
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
