<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\CardCollapse;
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
            // Repo-qualified correlation (DL-167) only on a SHARED board (DL-174):
            // there kanban's `source` dimension (kanban DL-163) returns only THIS
            // repo's cards in `ref` mode. On a 1:1 board the qualifier is omitted so
            // null-source refs (operator-stamped pr_number cards) still correlate.
            // cardsForRepo stays as the `scan`-mode guard and a belt-and-suspenders
            // confirm — it attributes each card by its pr_url and drops any
            // foreign-repo card a bare-number match surfaced.
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cards = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber, $sourceRepo), $repo);

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
            // create (live: board-3 cards 2965+2968 for the same PR #289). Re-correlate
            // (repo-qualified at source — the card we just wrote is indexed synchronously
            // at the kanban TaskMutator chokepoint, so a racer's card is now visible too)
            // and collapse any duplicate. A re-read failure flows through the same
            // transient/permanent split below; the move-path guard self-heals it next event.
            $live = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber, $sourceRepo), $repo);
            if (count($live) > 1) {
                $this->collapseDuplicates($client, $live, $repo, $prNumber);
            }
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if ($this->isPermanent($e)) {
                Log::warning('kanban_dependabot_card: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)', ['repo' => $repo, 'pr' => $prNumber] + RefusalContext::from($e));

                return;
            }
            throw $e;
        }
    }

    /**
     * Fetch the correlated cards and keep only those belonging to $repo, as an
     * `id => card` map. correlatePr is repo-qualified at the source in `ref` mode
     * (DL-167 → kanban `source`, DL-163), so this is a confirm there; in `scan`
     * mode it's the actual cross-repo guard. Attribution is by the
     * `github.com/<repo>/pull/` segment of a card's stored `pr_url`; a card whose
     * repo can't be read is dropped — never moved or archived on a guess.
     *
     * @param  list<int>  $cardIds
     * @return array<int, array<string, mixed>>
     */
    private function cardsForRepo(KanbanClient $client, array $cardIds, string $repo): array
    {
        $refs = new ExternalReferenceNormalizer;
        $wantRepo = $refs->canonicalizeSource($repo);   // canon-compare: GitHub owner/repo is case-insensitive
        $cards = [];
        foreach ($cardIds as $id) {
            $card = $client->getCard($id);
            if ($this->cardRepo($refs, $card) === $wantRepo) {
                $cards[$id] = $card;
            }
        }

        return $cards;
    }

    /**
     * The canonical `owner/repo` a dependabot card belongs to, parsed from its
     * stored `pr_url` (`https://github.com/<owner>/<repo>/pull/<n>`), or null when
     * the url is absent/unparseable. Canonicalized via the vendored normalizer so
     * attribution matches the kanban server's `source` semantics.
     *
     * @param  array<string, mixed>  $card
     */
    private function cardRepo(ExternalReferenceNormalizer $refs, array $card): ?string
    {
        $payload = $card['payload'] ?? null;
        $url = is_array($payload) ? ($payload['pr_url'] ?? null) : null;

        return is_string($url) ? $refs->repoFromGitHubUrl($url) : null;
    }

    /**
     * Reduce the cards for one repo+PR down to a single survivor, archiving the rest,
     * and return the survivor's card. Delegates the deterministic-tie-break kernel
     * (keep lowest id, archive rest) to the shared {@see CardCollapse} so the two
     * create-capable movers can never drift on which card wins (DL-198); the (repo,
     * PR) correlation stays here. Assumes a non-empty map (every caller has already
     * guarded `!== []`). The cards share an identical dependabot payload, so which one
     * survives is immaterial — only that exactly one does.
     *
     * @param  non-empty-array<int, array<string, mixed>>  $cards  id => card
     * @return array<string, mixed>
     */
    private function collapseDuplicates(KanbanClient $client, array $cards, string $repo, int $prNumber): array
    {
        return CardCollapse::toSurvivor($client, $cards, 'kanban_dependabot_card', ['repo' => $repo, 'pr' => $prNumber]);
    }

    private function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
    }
}
