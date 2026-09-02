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
use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
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
 *  - outcome `renamed` (DL-328, the upstream retitle) → restamp the card NAME and move
 *    nothing, and only on a card whose name is still byte-identical to the title the
 *    bridge stamped there ({@see restampNames} owns that test and why it is the only
 *    safe one). No card → nothing to restamp.
 *  - card exists, other outcome → move it to the outcome's stage (no-op if there).
 *  - no card, outcome opened / merged / merged_to_main → create it at that stage.
 *  - a PINNED card is refused on every write here that touches its STAGE or its
 *    LIFECYCLE — the closed-unmerged ARCHIVE and the collapse survivor's MOVE
 *    ({@see refusedAsPinned}, DL-335), and since DL-340 the create-race collapse's
 *    DUPLICATE ARCHIVE, which is refused one layer down in {@see CardCollapse} and so
 *    is not visible in this file at all. A create is unreachable from a pin: there is
 *    no card yet to carry one. ⚠ The DL-328 restamp is NOT among them, and that is the
 *    scope of the rule rather than an omission: the pin governs the card's STAGE
 *    (DL-178's own annotation), widened by exactly the archive (DL-335) — read those
 *    two entries, and DL-340's Bounds for the open question, before reading the gap as
 *    a defect. So a pinned card whose name the bridge still owns is restamped.
 *
 * DURABLE, with the same transient(5xx → retry) / permanent(4xx → alert + log + no-op)
 * split as the move handler (DL-020/DL-285). New cards are tagged `dependencies` +
 * `triaged` so the routine churn doesn't flood the untriaged sweep, plus an
 * opt-in rendered `id:` provenance tag (#75) when the mapping sets
 * `card_id_tag_template`, so a tag-keyed Shipped→Released promoter can find
 * them — absent ⇒ no tag (back-compat).
 *
 * Its permanent refusals are keyed by the PULL REQUEST, not by a card, so the alert
 * carries the PR number in the body's `issue_number` — GitHub numbers issues and PRs in
 * one space, so DL-285 gave the body one field rather than two.
 */
final class KanbanDependabotCardHandler implements DurableReaction, Handler
{
    /**
     * The synthetic `outcome` this handler's alerts carry. The event's own `outcome`
     * (opened / merged / closed_unmerged) is deliberately NOT used: it would split one
     * repo's dedup marker per PR state and re-alert the same misconfiguration on each.
     */
    private const ALERT_OUTCOME = 'dependabot_card';

    /**
     * The board custom-field keys this handler's create payload sets. Single
     * source of truth: the create call below builds exactly these keys, and
     * bridge:check (#2949) reads this to verify the target board registers them
     * (an unregistered key 422s the create and is silently swallowed — DL-020).
     *
     * @var list<string>
     */
    public const CREATE_PAYLOAD_KEYS = ['pr_number', 'pr_url', 'origin'];

    /**
     * The MOVE-LESS outcome of a retitled PR (DL-328) — a handler-internal outcome with no
     * stage of its own, exactly like the move handler's `reopened` (DL-195), so
     * `WritebackConfig::OUTCOMES` (the operator-configurable stage keys) is unchanged and a
     * `writeback.json` naming it still fails validation. It is a CONSTANT rather than a
     * literal in two files because it is the seam between the classifier that emits the
     * target and the handler that reads it: a typo on either side would degrade to the
     * "no stage mapped for outcome" no-op, which is silent.
     */
    public const RENAMED_OUTCOME = 'renamed';

    private WritebackAlertNotifier $alerts;

    public function __construct(?WritebackAlertNotifier $alerts = null)
    {
        $this->alerts = $alerts ?? new WritebackAlertNotifier;
    }

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $p = $target->payload;
        $repo = $p['repo'] ?? null;
        $outcome = $p['outcome'] ?? null;
        $prNumber = $p['pr_number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_string($outcome) || $outcome === '' || ! is_numeric($prNumber)) {
            // Deterministic classifier bug — permanent. The repo/PR that would key the
            // alert are part of what is malformed, so each degrades rather than
            // suppressing the signal.
            $this->alerts->warnAndNotify(
                'kanban_dependabot_card: malformed payload (repo/outcome/pr_number); ignoring',
                ['payload' => $p],
                is_string($repo) ? $repo : '', self::ALERT_OUTCOME, null, 'dependabot_card_payload_invalid',
                is_numeric($prNumber) ? (int) $prNumber : null,
            );

            return;
        }
        $prNumber = (int) $prNumber;
        $title = is_string($p['pr_title'] ?? null) && $p['pr_title'] !== '' ? $p['pr_title'] : "Dependabot PR #{$prNumber}";
        $url = is_string($p['pr_url'] ?? null) ? $p['pr_url'] : '';

        $writeback = WritebackConfig::loadDefault();
        if ($writeback === null) {
            // Degrades to log-only (docs/writeback.md, *Branch-#3 degradation*); the call
            // is kept so this arm cannot drift out of the paired primitive.
            $this->alerts->warnAndNotify(
                'kanban_dependabot_card: writeback not configured; ignoring',
                ['repo' => $repo, 'pr' => $prNumber],
                $repo, self::ALERT_OUTCOME, null, 'writeback_not_configured', $prNumber,
            );

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
            $cards = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber, $sourceRepo), $repo, $mapping, $prNumber);

            // Closed-unmerged dependabot PR → RETIRE the card(s) (DL-161). Archive,
            // not move: routine dependabot churn shouldn't linger in any column, and
            // archiving needs no stage mapping. A repo+PR may map to >1 card (a create
            // race) — archive them all. Empty (never tracked) → nothing to do.
            if ($outcome === 'closed_unmerged') {
                foreach ($cards as $cardId => $card) {
                    if ($this->refusedAsPinned($card, $cardId, $repo, $prNumber, $mapping, 'archive')) {
                        continue;
                    }
                    if ($client->archiveCard($cardId)) {
                        // ⭐ A GROUP-B write (card#7211): this id came out of a board-scoped
                        // SEARCH, so unlike the token-path arms the card's board here is not
                        // implied by anything upstream. `cardsForRepo` re-checks it (DL-298),
                        // and that gate is not a substitute for this record — a gate emits
                        // evidence only when it REFUSES. Recording BOTH boards is what makes a
                        // landed cross-board write distinguishable from a correct one after the
                        // fact (card#7212).
                        Log::info('kanban_dependabot_card: archived (closed-unmerged)', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + MappedBoardGuard::boardContext($card, $mapping));
                    } else {
                        // 200 but not archived = wrong-verb / kanban contract change.
                        // Deterministic ⇒ permanent: log LOUD + no-op, never 5xx-storm it (DL-020 posture).
                        Log::error('kanban_dependabot_card: archive returned 200 but the card is not archived (archived_at null) — kanban _action:archive contract may have changed; NOT retrying', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + MappedBoardGuard::boardContext($card, $mapping));
                    }
                }

                return;
            }

            // Upstream RETITLE (DL-328) → restamp the name, move nothing. Placed after the
            // correlation (it writes to the same card set every other arm does) and before
            // the stage lookup, which has no entry for this outcome and would no-op it.
            if ($outcome === self::RENAMED_OUTCOME) {
                $this->restampNames($client, $cards, $p['name_from'] ?? null, $p['pr_title'] ?? null, $mapping, $repo, $prNumber);

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
                $survivor = $this->collapseDuplicates($client, $cards, $mapping, $repo, $prNumber);
                if (($survivor['workflow_stage_id'] ?? null) !== $stageId) {
                    if ($this->refusedAsPinned($survivor, (int) $survivor['id'], $repo, $prNumber, $mapping, 'move')) {
                        return;
                    }
                    $client->moveCard((int) $survivor['id'], $stageId);
                    // Group-B, as the archive arm above (card#7211/card#7212): the survivor was
                    // resolved by search, not by a token, so its own board is recorded here.
                    Log::info('kanban_dependabot_card: moved', ['card_id' => $survivor['id'], 'stage' => $stageId, 'outcome' => $outcome, 'pr' => $prNumber] + MappedBoardGuard::boardContext($survivor, $mapping));
                }

                return;
            }
            // Keyed by self::CREATE_PAYLOAD_KEYS so the create payload and the keys
            // bridge:check (#2949) verifies the board registers are ONE source of
            // truth: add a key to the constant without a value here and array_combine
            // throws (count mismatch) — they cannot silently drift.
            $payload = array_combine(self::CREATE_PAYLOAD_KEYS, [$prNumber, $url, 'dependabot']);
            $tags = ['dependencies', 'triaged'];
            if ($mapping->cardIdTagTemplate !== null) {
                // The CONFIGURED spelling (card#7124 review), for the same reason the
                // promote leg's token probe uses it: `{repo}` renders into a persisted
                // `id:` tag an external tag-keyed reader correlates on, and a tag is text
                // the operator declared — not a spelling the payload happened to carry.
                // Until DL-293 the two were equal on every reachable path, so this keeps
                // the tag byte-identical wherever the two files agree and uses the
                // operator's own spelling where they do not.
                array_unshift($tags, $this->renderIdTag($mapping->cardIdTagTemplate, $prNumber, $writeback->configuredRepoFor($repo) ?? $repo));
            }
            $newId = $client->createCard($mapping->boardId, $stageId, $title, $payload, $tags, $mapping->swimlaneId);
            Log::info('kanban_dependabot_card: created', ['card_id' => $newId, 'board' => $mapping->boardId, 'stage' => $stageId, 'swimlane' => $mapping->swimlaneId, 'outcome' => $outcome, 'pr' => $prNumber]);

            // Close the create-or-move race. The correlate→create above is not atomic
            // across concurrent deliveries: two events for the same repo+PR (opened+
            // reopened, or a fresh-delivery-id re-emit) can both correlate empty and both
            // create (live: board-3 cards 2965+2968 for the same PR #289). Re-correlate
            // (repo-qualified at source — the card we just wrote is indexed synchronously
            // at the kanban TaskMutator chokepoint, so a racer's card is now visible too)
            // and collapse any duplicate. A re-read failure flows through the same
            // transient/permanent split below; the move-path guard self-heals it next event.
            $live = $this->cardsForRepo($client, $client->correlatePr($mapping->boardId, $prNumber, $sourceRepo), $repo, $mapping, $prNumber);
            if (count($live) > 1) {
                $this->collapseDuplicates($client, $live, $mapping, $repo, $prNumber);
            }
        } catch (RequestException $e) {
            // A kanban 4xx is permanent (alert + log + no-op); a 5xx / timeout is transient (throw → redelivery retries).
            if (RefusalContext::isPermanent($e)) {
                // FLAT reason: this one catch spans the correlation READS, the archive /
                // move / create WRITES and the collapse, so a status-split write reason
                // would be wrong-but-specific on a refused read.
                $this->alerts->warnAndNotify(
                    'kanban_dependabot_card: kanban refused (4xx) — ignoring (see `body` for the reason kanban gave)',
                    ['repo' => $repo, 'pr' => $prNumber] + RefusalContext::from($e),
                    $repo, self::ALERT_OUTCOME, null, 'dependabot_card_4xx', $prNumber,
                );

                return;
            }
            throw $e;
        }
    }

    /**
     * The pinned-card opt-out for the two writes DL-335 covers (card#8454) — the
     * closed-unmerged archive and the collapse survivor's move: true when the card is
     * PINNED and the caller must skip the write it was about to make. ⚠ It is NOT this
     * handler's whole pin story: the collapse's duplicate archive is refused inside
     * {@see CardCollapse} (DL-340), and the DL-328 name restamp is deliberately outside
     * the predicate — see the class docblock's lifecycle list for both.
     *
     * DL-178's predicate is a property of the CARD, not of the mover, and until this shipped
     * the dependabot handler was the one event-path mover that never consulted it — so a
     * closed-unmerged dependabot PR RETIRED a card a human had parked (`block_reason` /
     * `no-automove`) while `bridge:reconcile` and the release-promote sweep both skipped it.
     * That is card#8289's asymmetry one handler over, and worse in kind: an archive is the
     * hardest write here to notice and the only one that takes the card off the board.
     *
     * ⛔ TWO BOUNDS, both deliberate. (1) There is NO override — the DL-194 unpark and the
     * DL-195 revive are `started`/`reopened` outcomes of the move handler and have no
     * counterpart on a dependabot PR, so a pinned card is refused on every outcome this
     * handler can act on. (2) The `(repo, outcome, reason)` alert dedup collapses BOTH arms
     * into one marker, because this handler's `outcome` is the synthetic ALERT_OUTCOME: one
     * repo's first pinned dependabot card signals, and later ones reach the durable
     * `Log::warning` only. That is the same trade the constant already makes for every other
     * arm here, and the log line — which `warnAndNotify` always writes — is the per-card record.
     *
     * ⭐ SINCE card#8523 THE CONSULT AND ITS RECORD ARE ONE PRIMITIVE ({@see PinGuard::refuses}),
     * the same pairing `MappedBoardGuard` owns for the board rule: this method is now the
     * handler's arm-specific CONTEXT (the PR number, the board pair) and nothing else. What
     * it stops is a sixth writer minting a seventh spelling of the refusal.
     *
     * @param  array<string, mixed>  $card
     */
    private function refusedAsPinned(array $card, int $cardId, string $repo, int $prNumber, WritebackMapping $mapping, string $write): bool
    {
        return PinGuard::refuses(
            $this->alerts, $card, 'kanban_dependabot_card', $write, $cardId, $repo, self::ALERT_OUTCOME,
            ['pr' => $prNumber] + MappedBoardGuard::boardContext($card, $mapping),
            $prNumber,
        );
    }

    /**
     * Restamp the card name of a retitled dependabot PR (DL-328) — the FIRST name write the
     * bridge made after birth (DL-341 added the coord-card twin,
     * {@see KanbanCoordCardHandler::restampNames}, so "the only one" is no longer true and
     * is not restated here), and the answer to a card asserting a version that never
     * shipped (dependabot retitles its PR in place when it retargets a bump).
     *
     * ⭐ THE OWNERSHIP TEST IS BYTE-EQUALITY WITH WHAT THE BRIDGE ITSELF STAMPED, and it is
     * the reason this is not the naive "always overwrite the name from the upstream title"
     * fix, which must not be built: the classifier delivers the upstream title on every
     * subsequent event, so an unconditional write would silently destroy a deliberate human
     * rename on the next webhook — trading a stale machine name for a destroyed human one.
     * `$nameFrom` is GitHub's `changes.title.from`: the title as it stood before this edit,
     * which is exactly the string the bridge stamped when it minted the card. A card whose
     * name still equals it byte for byte has been touched by nobody since; a card whose name
     * differs by so much as a space has an author other than this bridge and is LEFT ALONE.
     * No heuristic reads the name's shape, no monotonic assumption is made about the version
     * (the measured drift runs BOTH ways — 7.0.2→6.0.3 as readily as 29.1.1→30.0.0), and the
     * head ref is never consulted: it is frozen at branch creation while the diff is
     * retargeted, so it names the bump the PR no longer carries.
     *
     * ⚠ ITS BOUND, disclosed rather than papered over: the evidence lives on the RENAME
     * EVENT. A retitle the bridge never received (it was down, or the repo predates this
     * leg) leaves a card whose name matches no `from` string anyone can produce later, and a
     * merge event carries the new title but no witness for the old one — so this leg CANNOT
     * repair it, and deliberately does not try. Already-wrong cards are a separate one-off
     * backfill (card#8377's scope), not a data migration folded into a behaviour change.
     *
     * A malformed rename payload is a deterministic CLASSIFIER bug — permanent, so it
     * alerts + no-ops rather than throwing, exactly like the entry-point validation. It is
     * checked HERE and not only at the emit site because a handler is a published extension
     * point (docs/customization.md): another classifier can emit this target, and the failure
     * this refuses is a card renamed to the WRONG string, which no later event corrects.
     *
     * ⚑ ON >1 CORRELATED CARD THIS RESTAMPS EVERY MATCHING ONE, and does NOT collapse:
     * a create-race duplicate carries the same bridge-stamped name as its twin, so restamping
     * only a survivor would leave the other asserting the version that never shipped — the
     * exact defect this leg exists to remove — while the collapse itself belongs to the
     * move path, which is where a duplicate is actually retired (DL-198). The ownership test
     * is applied per card, so a race pair one of whose cards a human renamed still writes to
     * the other alone.
     *
     * @param  array<int, array<string, mixed>>  $cards  id => card, already board- and repo-gated
     */
    private function restampNames(KanbanClient $client, array $cards, mixed $nameFrom, mixed $title, WritebackMapping $mapping, string $repo, int $prNumber): void
    {
        if (! is_string($nameFrom) || $nameFrom === '' || ! is_string($title) || $title === '' || $title === $nameFrom) {
            $this->alerts->warnAndNotify(
                'kanban_dependabot_card: malformed rename payload (name_from/pr_title); no name written',
                ['repo' => $repo, 'pr' => $prNumber],
                $repo, self::ALERT_OUTCOME, null, 'dependabot_card_rename_payload_invalid', $prNumber,
            );

            return;
        }
        foreach ($cards as $cardId => $card) {
            $name = $card['name'] ?? null;
            if (! is_string($name) || $name !== $nameFrom) {
                // ⛔ THE RECORD STATES THE FACT AND NAMES NO AUTHOR, because this branch has
                // more than one innocent history and cannot tell them apart. A human (or
                // another writer) renaming the card is the case the gate exists for — but a
                // GitHub REDELIVERY of this same edit reaches here too, as does a retried
                // partial restamp, and in both of those the name it found is the one THIS
                // bridge wrote a moment ago. Accusing a writer would be wrong-but-specific on
                // the redelivery path (DL-314's shape), so the text reports only what was
                // compared. Info, not warn: on every one of those histories the no-op is the
                // designed outcome, not a failure.
                Log::info('kanban_dependabot_card: card name is not `changes.title.from`; not restamped', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + MappedBoardGuard::boardContext($card, $mapping));

                continue;
            }
            $client->patchCard($cardId, ['name' => $title]);
            // Group-B (card#7211/card#7212): the card came out of a board-scoped SEARCH, so
            // its own board is recorded beside the write that landed on it.
            Log::info('kanban_dependabot_card: restamped name from the upstream retitle', ['card_id' => $cardId, 'repo' => $repo, 'pr' => $prNumber] + MappedBoardGuard::boardContext($card, $mapping));
        }
    }

    /**
     * Fetch the correlated cards and keep only those on the mapped BOARD and belonging
     * to $repo, as an `id => card` map — the one place every write this handler makes
     * draws its card set from, which is why both gates live here rather than at each
     * write. The class docblock's lifecycle list owns WHICH writes those are; an
     * enumeration here would be a second copy of it, and was already one arm short.
     *
     * The BOARD gate (DL-298, card#7211) re-tests the row kanban actually handed back
     * against the mapped board, through the same `MappedBoardGuard` the token-path
     * handlers use — never a second copy of the compare. It refuses nothing today
     * (`correlatePr` is board-scoped by the by-ref URL PATH in `ref` mode and by the
     * `q=board_id=<b>` board read in `scan` mode, and that scoping is measured to be
     * honoured), and that is the design intent: the scope becomes a property of the
     * RESULT, so a call-construction change cannot silently widen it. It costs no extra
     * request — the row is already read here for the repo attribution below.
     *
     * The REPO gate: correlatePr is repo-qualified at the source in `ref` mode
     * (DL-167 → kanban `source`, DL-163), so this is a confirm there; in `scan`
     * mode it's the actual cross-repo guard. Attribution is by the
     * `github.com/<repo>/pull/` segment of a card's stored `pr_url`; a card whose
     * repo can't be read is dropped — never moved or archived on a guess.
     *
     * @param  list<int>  $cardIds
     * @return array<int, array<string, mixed>>
     */
    private function cardsForRepo(KanbanClient $client, array $cardIds, string $repo, WritebackMapping $mapping, int $prNumber): array
    {
        $refs = new ExternalReferenceNormalizer;
        $wantRepo = $refs->canonicalizeSource($repo);   // canon-compare: GitHub owner/repo is case-insensitive
        $cards = [];
        foreach ($cardIds as $id) {
            $card = $client->getCard($id);
            // The board gate runs BEFORE the repo gate, and the order is the point: a
            // foreign-board card that ALSO fails the repo test would be dropped silently,
            // and the board is the one boundary whose breach must never be a quiet drop.
            if (MappedBoardGuard::refuses($this->alerts, $card, $mapping, 'kanban_dependabot_card', $id, $repo, self::ALERT_OUTCOME, $prNumber)) {
                continue;
            }
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
    private function collapseDuplicates(KanbanClient $client, array $cards, WritebackMapping $mapping, string $repo, int $prNumber): array
    {
        return CardCollapse::toSurvivor($client, $cards, 'kanban_dependabot_card', $repo, ['repo' => $repo, 'pr' => $prNumber], $mapping);
    }

    /**
     * Render a mapping's id-tag template for a dependabot card (#75). Placeholders:
     * {n}/{pr_number} = PR number, {repo} = repo NAME (last path segment). Unknown
     * placeholders are left literal — the tenant's template is verified against its own
     * id:-keyed reader at activation, not here.
     */
    private function renderIdTag(string $template, int $prNumber, string $repo): string
    {
        $repoName = basename($repo);

        return strtr($template, ['{n}' => (string) $prNumber, '{pr_number}' => (string) $prNumber, '{repo}' => $repoName]);
    }
}
