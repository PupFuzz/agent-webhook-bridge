<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Create-or-move a kanban card for a DEPENDABOT pull request — the writeback's
 * second reaction. Dependabot PRs carry no DL and have no pre-existing card, so
 * GitHubPrCardMoveClassifier emits this target (keyed by PR NUMBER) instead of a
 * move, and only when the repo's mapping opts in (`create_dependabot_cards`).
 *
 * Lifecycle, idempotent on `payload.pr_number`:
 *  - outcome closed_unmerged, card exists → ARCHIVE it (DL-161). Dependabot
 *    routinely closes its own PRs (superseded bump / manual close); a move would
 *    only shuffle the card to a column and let it accumulate, so we retire it.
 *    Archive needs no stage mapping. Idempotent: an archived card is excluded
 *    from correlation, so a redelivered close finds nothing and no-ops.
 *  - outcome closed_unmerged, no card → skip (never tracked → nothing to retire).
 *  - card exists, other outcome → move it to the outcome's stage (no-op if there).
 *  - no card, outcome opened / merged / merged_to_main → create it at that stage.
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → log + no-op)
 * split as the move handler (DL-020). New cards are tagged `dependencies` +
 * `triaged` so the routine churn doesn't flood the untriaged sweep.
 */
final class KanbanDependabotCardHandler implements DurableReaction, Handler
{
    /**
     * The board custom-field keys this handler's create payload sets. Single
     * source of truth: the create call below builds exactly these keys, and
     * bridge:check (#2949) reads this to verify the target board registers them
     * (an unregistered key 422s the create and is silently swallowed — DL-020).
     *
     * @var list<string>
     */
    public const CREATE_PAYLOAD_KEYS = ['pr_number', 'pr_url', 'origin'];

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $outcome = $p['outcome'] ?? null;
        $prNumber = $p['pr_number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_string($outcome) || $outcome === '' || ! is_numeric($prNumber)) {
            Log::warning('kanban_dependabot_card: malformed payload (repo/outcome/pr_number); ignoring', ['payload' => $p]);

            return;
        }
        $prNumber = (int) $prNumber;
        $title = is_string($p['pr_title'] ?? null) && $p['pr_title'] !== '' ? $p['pr_title'] : "Dependabot PR #{$prNumber}";
        $url = is_string($p['pr_url'] ?? null) ? $p['pr_url'] : '';

        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        if ($writeback === null) {
            Log::warning('kanban_dependabot_card: writeback not configured; ignoring', ['repo' => $repo, 'pr' => $prNumber]);

            return;
        }
        $mapping = $writeback->mappingFor($repo);
        if ($mapping === null || ! $mapping->createDependabotCards) {
            // Opt-out / unmapped: permanent refusal — log + no-op (never 5xx-retry a config gap).
            Log::info('kanban_dependabot_card: repo not mapped or opt-out; ignoring', ['repo' => $repo, 'pr' => $prNumber]);

            return;
        }
        $client = WritebackClientFactory::make();   // throws (→ 5xx) on a missing/insecure token
        try {
            // correlatePr keys on the bare PR NUMBER — kanban's `github_pr` by-ref
            // index is not repo-qualified (DL-029) — so on a board shared across repos
            // (DL-027, lane-per-repo) a same-numbered PR in ANOTHER repo collides.
            // Attribute each correlated card to its repo (via its stored pr_url) and
            // keep only THIS repo's: a co-hosted repo's card is a distinct PR, never
            // ours to move or archive.
            $cards = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber), $repo);

            // Closed-unmerged dependabot PR → RETIRE the card(s) (DL-161). Archive,
            // not move: routine dependabot churn shouldn't linger in any column, and
            // archiving needs no stage mapping. A repo+PR may map to >1 card (a create
            // race) — archive them all. Empty (never tracked) → nothing to do.
            if ($outcome === 'closed_unmerged') {
                foreach (array_keys($cards) as $cardId) {
                    if ($client->archiveCard($cardId)) {
                        Log::info('kanban_dependabot_card: archived (closed-unmerged)', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber]);
                    } else {
                        // 200 but not archived = wrong-verb / kanban contract change.
                        // Deterministic ⇒ permanent: log LOUD + no-op, never 5xx-storm it (DL-020 posture).
                        Log::error('kanban_dependabot_card: archive returned 200 but the card is not archived (archived_at null) — kanban _action:archive contract may have changed; NOT retrying', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber]);
                    }
                }

                return;
            }

            $stageId = $mapping->stageFor($outcome);
            if ($stageId === null) {
                Log::info('kanban_dependabot_card: no stage mapped for outcome; ignoring', ['repo' => $repo, 'outcome' => $outcome, 'pr' => $prNumber]);

                return;
            }
            if ($cards !== []) {
                // >1 card for one repo+PR is a create-race artifact (see collapseDuplicates):
                // retire the extras and move only the survivor. Self-heals duplicates minted
                // before this guard shipped, on the PR's next event.
                $survivor = $this->collapseDuplicates($client, $cards, $repo, $prNumber);
                if (($survivor['workflow_stage_id'] ?? null) !== $stageId) {
                    $client->moveCard((int) $survivor['id'], $stageId);
                    Log::info('kanban_dependabot_card: moved', ['card_id' => $survivor['id'], 'stage' => $stageId, 'outcome' => $outcome, 'pr' => $prNumber]);
                }

                return;
            }
            // Keyed by self::CREATE_PAYLOAD_KEYS so the create payload and the keys
            // bridge:check (#2949) verifies the board registers are ONE source of
            // truth: add a key to the constant without a value here and array_combine
            // throws (count mismatch) — they cannot silently drift.
            $payload = array_combine(self::CREATE_PAYLOAD_KEYS, [$prNumber, $url, 'dependabot']);
            $newId = $client->createCard($mapping->boardId, $stageId, $title, $payload, ['dependencies', 'triaged'], $mapping->swimlaneId);
            Log::info('kanban_dependabot_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $stageId, 'swimlane' => $mapping->swimlaneId, 'outcome' => $outcome, 'pr' => $prNumber]);

            // Close the create-or-move race. The correlate→create above is not atomic
            // across concurrent deliveries: two events for the same repo+PR (opened+
            // reopened, or a fresh-delivery-id re-emit) can both correlate empty and both
            // create (live: board-3 cards 2965+2968 for the same PR #289). Re-correlate,
            // filter to this repo (the card we just wrote is indexed synchronously at the
            // kanban TaskMutator chokepoint, so a racer's card is now visible too), and
            // collapse any duplicate. A re-read failure flows through the same transient/
            // permanent split below; the move-path guard self-heals it on the PR's next event.
            $live = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber), $repo);
            if (count($live) > 1) {
                $this->collapseDuplicates($client, $live, $repo, $prNumber);
            }
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if ($this->isPermanent($e)) {
                Log::warning('kanban_dependabot_card: kanban refused (4xx) — ignoring', ['repo' => $repo, 'pr' => $prNumber, 'status' => $e->response->status()]);

                return;
            }
            throw $e;
        }
    }

    /**
     * Fetch the correlated cards and keep only those belonging to $repo, as an
     * `id => card` map. Attribution is by the `github.com/<repo>/pull/` segment of
     * a card's stored `pr_url` (see {@see cardRepo}); a card whose repo can't be
     * read is dropped — never moved or archived on a guess. This is the cross-repo
     * guard: correlation is by bare PR number, so a board shared across repos can
     * surface a foreign repo's same-numbered PR (see the call sites).
     *
     * @param  list<int>  $cardIds
     * @return array<int, array<string, mixed>>
     */
    private function cardsForRepo(KanbanClient $client, array $cardIds, string $repo): array
    {
        $cards = [];
        foreach ($cardIds as $id) {
            $card = $client->getCard($id);
            if ($this->cardRepo($card) === $repo) {
                $cards[$id] = $card;
            }
        }

        return $cards;
    }

    /**
     * The `owner/repo` a dependabot card belongs to, parsed from its stored
     * `pr_url` (`https://github.com/<owner>/<repo>/pull/<n>`), or null when the
     * url is absent/unparseable.
     *
     * @param  array<string, mixed>  $card
     */
    private function cardRepo(array $card): ?string
    {
        $payload = $card['payload'] ?? null;
        $url = is_array($payload) ? ($payload['pr_url'] ?? null) : null;
        if (! is_string($url) || $url === '') {
            return null;
        }

        return preg_match('#github\.com/([^/]+/[^/]+)/pull/#', $url, $m) === 1 ? $m[1] : null;
    }

    /**
     * Reduce the cards for one repo+PR down to a single survivor, archiving the rest,
     * and return the survivor's card. The survivor is the LOWEST id — a deterministic
     * choice, so two racing workers that each observe the same set converge on the
     * same survivor and the same archive set (no flip-flop). Archiving is idempotent
     * (an archived card drops out of correlation, so a redelivery re-presents
     * nothing). Assumes a non-empty map (every caller has already guarded `!== []`).
     * The cards share an identical dependabot payload, so which one survives is
     * immaterial — only that exactly one does.
     *
     * @param  non-empty-array<int, array<string, mixed>>  $cards  id => card
     * @return array<string, mixed>
     */
    private function collapseDuplicates(KanbanClient $client, array $cards, string $repo, int $prNumber): array
    {
        ksort($cards);
        $survivorId = array_key_first($cards);
        foreach (array_keys($cards) as $id) {
            if ($id === $survivorId) {
                continue;
            }
            if ($client->archiveCard($id)) {
                Log::info('kanban_dependabot_card: archived duplicate card for the same repo+PR', ['card_id' => $id, 'survivor' => $survivorId, 'repo' => $repo, 'pr' => $prNumber]);
            } else {
                // 200 but not archived = wrong-verb / contract change — deterministic,
                // so log LOUD + leave it rather than 5xx-storm an unfixable event (DL-020).
                Log::error('kanban_dependabot_card: duplicate archive returned 200 but the card is not archived (archived_at null); NOT retrying', ['card_id' => $id, 'survivor' => $survivorId, 'repo' => $repo, 'pr' => $prNumber]);
            }
        }

        return $cards[$survivorId];
    }

    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
