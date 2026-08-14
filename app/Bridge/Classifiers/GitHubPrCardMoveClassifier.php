<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClassifierConfig;
use App\Bridge\Support\DlTokenGrammar;
use App\Bridge\Writeback\CardTokenVerdict;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\Log;

/**
 * Correlation classifier for the GitHub-PR → card-move writeback (FR #2016 /
 * DL-021). Deterministic up to one kanban read: it turns a `pull_request` event
 * into a `kanban_move_card` target by (a) deriving the move OUTCOME from
 * GitHub-CONTROLLED fields (the action + `pull_request.merged` + `base.ref`),
 * never the PR title; (b) correlating the card by the `DL-NNN` token in the PR
 * title or head branch against the mapped board's `dl_number`, OR the native-id
 * card token (FR-7). Which spellings either token accepts is
 * {@see DlTokenGrammar}'s and {@see CardTokenGrammar}'s to say. Which
 * board+stage the move targets is decided by the durable handler from operator
 * config — this classifier only supplies which card + outcome.
 *
 * Token resolution is try-in-order-with-fallback (framework #112), keyed on the
 * OUTCOME of a token not its presence: a `DL-NNN` that resolves wins — UNLESS a
 * co-present explicit `card#` names a DIFFERENT card than the DL resolved to, which
 * is a CONFLICT (DL-218 / card#4811 incident): a descriptive or foreign DL mention
 * in a title must not silently hijack the move to the DL's card and drop the intended
 * card#, so the explicit card# is treated as authoritative — warn loudly, then move +
 * stamp the card# card. A co-present card# that AGREES with the DL is redundant (DL
 * wins, nothing dropped). A `DL-NNN` that resolves to no card falls through to a
 * present `card#`; a token present but resolving to nothing is warned loudly (a
 * decision-logged-but-unstamped card — never a silent no-op).
 *
 * A card token that is PRESENT BUT UNREADABLE — a card-shaped spelling this grammar
 * rejects, `card_4811` — is its own state and not "absent" (card#6027 / DL-287): read
 * as absent it switched the conflict guard off for exactly the subject it was written
 * for. Unless it recovers to one of the cards the DL already resolved to (redundant,
 * nothing dropped), the move is REFUSED — the flag rides the target and
 * {@see KanbanMoveCardHandler} refuses it, so the refusal alerts like every other
 * permanent one. The recovered id may refuse or warn, never SELECT.
 *
 * The card# token itself has TWO surfaces with different authority — the head branch
 * (this install's own artifact) outranks the PR title (prose), and a title-only token
 * is passed to the handler marked UNCORROBORATED for a card-side corroboration gate.
 * {@see cardTokenResolution} owns that rule (card#5287 / DL-270).
 *
 * Emits NO intents (the writeback is machine-only, "no agent in the loop"). A PR
 * with no parseable card reference, or a repo with no `writeback.json` mapping →
 * empty result (graceful no-op).
 */
class GitHubPrCardMoveClassifier implements Classifier, DeclaresConsumedEvents, EmitsWritebackReactions
{
    /**
     * The top-level GitHub event types this classifier consumes (card#4183 /
     * DL-196): a `pull_request.<action>` (the move lifecycle) and a `push` (the
     * DL-160 branch-create `started` trigger). Config-independent. Pure map — the
     * HARD CONTRACT on {@see DeclaresConsumedEvents}.
     *
     * @return list<string>
     */
    public function consumedEventTypes(ClassifierConfig $cfg): array
    {
        return ['pull_request', 'push'];
    }

    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        // The move/overlay/create classification (many early returns per action + token
        // path). The promote-on-release scan target is appended ONCE below rather than
        // threaded into each of those returns — a single choke point can't grow a
        // dropped-target sibling hole (canon #7), and the scan is independent of the
        // release PR's own card token anyway.
        $result = $this->classifyInner($ctx);

        $promote = $this->promoteOnReleaseTargets($ctx);
        if ($promote === []) {
            return $result;
        }

        return new ClassifyResult(
            targets: array_merge($result->targets, $promote),
            intents: $result->intents,
            reattributedActor: $result->reattributedActor,
        );
    }

    /**
     * The DL-207 promote-on-release scan target — emitted on a release-PR merge into main
     * (a `merged_to_main` outcome) for a `promote_on_release` mapping, INDEPENDENT of
     * whether the release PR itself carries a card token. One `kanban_promote_released`
     * target per event; the durable handler does the board-wide Shipped→Released scan.
     * Reuses {@see outcome()} for the merged_to_main derivation (the same GitHub-controlled
     * fields the move path keys on) so the trigger can't drift from the move outcome.
     *
     * @return list<ReactionTarget>
     */
    private function promoteOnReleaseTargets(ClassifyContext $ctx): array
    {
        if ($ctx->provider !== 'github' || ! str_starts_with($ctx->eventType, 'pull_request.')) {
            return [];
        }
        if ($this->outcome($ctx->eventType, $ctx->payload) !== 'merged_to_main') {
            return [];
        }
        $repo = $ctx->scopeId;
        $writeback = WritebackConfig::loadDefault();
        $mapping = $writeback?->mappingFor($repo);
        if ($mapping === null || ! $mapping->promoteOnRelease) {
            return [];
        }

        return [ReactionTarget::make('kanban_promote_released', $repo, payload: ['repo' => $repo])];
    }

    private function classifyInner(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $provider = $ctx->provider;
        $scopeId = $ctx->scopeId;

        if ($provider !== 'github') {
            return new ClassifyResult;
        }

        // Branch-create push → `started` (DL-160). A `push` that CREATED a branch
        // whose ref carries a DL-NNN means work has begun on that card; promote it
        // to In Progress. Separate from the pull_request lifecycle below — the PR
        // path stays byte-identical.
        if ($eventType === 'push') {
            return $this->classifyPush($payload, $scopeId);
        }

        if (! str_starts_with($eventType, 'pull_request.')) {
            return new ClassifyResult;
        }

        $outcome = $this->outcome($eventType, $payload);
        // Draft → block_reason OVERLAY trigger (DL-193), orthogonal to the move
        // outcome: converted_to_draft / opened-as-draft → 'set'; ready_for_review →
        // 'clear'; anything else → null. The pure-overlay actions carry no move
        // outcome (converted_to_draft/ready_for_review), so both being null is the
        // "we act on this event neither way" no-op.
        $overlayAction = $this->draftOverlayAction($eventType, $payload);
        if ($outcome === null && $overlayAction === null) {
            return new ClassifyResult;   // an action we don't act on (edited, synchronize, …)
        }

        $repo = $scopeId;   // GitHubAdapter sets scope_id = repository.full_name
        $writeback = WritebackConfig::loadDefault();
        $mapping = $writeback?->mappingFor($repo);
        if ($mapping === null) {
            return new ClassifyResult;   // repo not configured for writeback
        }

        // Dependabot PRs carry no DL and have no pre-existing card. When opted in
        // (per-mapping `create_dependabot_cards`), emit a create-or-move target
        // keyed by PR NUMBER — the durable handler creates the card on open and
        // moves it on close. Detected by dependabot's own branch namespace.
        // Gated on a real MOVE outcome so a null-outcome overlay action (which was
        // previously unreachable here — it early-returned above) can't fall in.
        if ($outcome !== null && $mapping->createDependabotCards && $this->isDependabot($payload)) {
            $prNumber = $this->prNumber($payload);
            if ($prNumber === null) {
                return new ClassifyResult;
            }
            $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

            return new ClassifyResult(targets: [
                ReactionTarget::make('kanban_dependabot_card', "pr-{$prNumber}", payload: [
                    'repo' => $repo,
                    'outcome' => $outcome,
                    'pr_number' => $prNumber,
                    'pr_title' => is_string($pr['title'] ?? null) ? $pr['title'] : "Dependabot PR #{$prNumber}",
                    'pr_url' => is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '',
                ]),
            ]);
        }

        // Draft-overlay targets (block_reason set/clear, DL-193) — opt-in per mapping,
        // reusing the SAME DL/card# correlation as the move path. Overlay ONLY: it adds
        // a `kanban_block_reason` target, never a stage move. When the mapping doesn't
        // opt in (or the action isn't a draft trigger) this is [] ⇒ every move return
        // below is byte-identical to today. On an opened-as-draft PR the overlay set
        // target is emitted IN ADDITION to the existing `opened` move target.
        $overlayTargets = ($overlayAction !== null && $mapping->draftOverlay)
            ? $this->blockReasonTargets($payload, $repo, $overlayAction, $mapping, $writeback)
            : [];

        // converted_to_draft / ready_for_review carry no move outcome → overlay only
        // (empty when not opted in, so byte-identical to the previous null-outcome no-op).
        if ($outcome === null) {
            return new ClassifyResult(targets: $overlayTargets);
        }

        // Won't-Do-revival (DL-195): a `reopened` action normally collapses to the `opened`
        // outcome (above). When the mapping opts in, emit a DISTINCT `reopened` MOVE outcome
        // so the durable handler applies the revival carve-out (revive a card parked in the
        // `closed_unmerged` abandon stage back to the `opened` stage — the backward move the
        // DL-163 guard otherwise refuses). Computed HERE, after the dependabot branch, so the
        // dependabot path — cards that ARCHIVE on close (DL-161), never park in
        // `closed_unmerged` — keeps `opened` and never enters revival; and after $mapping is
        // resolved (it isn't in scope in outcome()). Absent revive_on_reopen ⇒ $moveOutcome ===
        // $outcome ⇒ byte-identical. `reopened` is a handler-internal outcome with no config
        // stage of its own (it reuses `stages.opened`), so it is NOT in WritebackConfig::OUTCOMES.
        $moveOutcome = ($eventType === 'pull_request.reopened' && $mapping->reviveOnReopen)
            ? 'reopened'
            : $outcome;

        $dl = $this->dlToken($payload);
        $cardResolution = $this->cardTokenResolution($payload);
        $cardToken = $cardResolution['token'];
        if ($dl === null && $cardToken === null) {
            $this->warnTokenNearMiss($this->titleAndHead($payload), 'PR title/head');

            return new ClassifyResult(targets: $overlayTargets);   // no card-first token in the PR → move no-op (overlay may also be empty)
        }

        // The title-vs-branch card-token CONFLICT (card#5287): the head branch is this
        // install's own artifact and wins; a disagreeing title token is a foreign or
        // descriptive citation and is REFUSED — loudly, never silently picked. Logged
        // HERE rather than inside the resolver because the draft-overlay path resolves
        // the same two tokens on the same event and would double-log it (the same split
        // DL-218 made for its own conflict diagnostics).
        $foreignTitleToken = $cardResolution['foreignTitleToken'];
        if ($foreignTitleToken !== null) {
            // Deliberately does NOT claim which card ends up moving: a resolving DL can
            // still win below (DL-148 makes that one-to-many), and only the token
            // ruling is decided at this point. What is always true here is the ruling.
            Log::warning("kanban_move_card: PR title names card#{$foreignTitleToken} but the head branch names card#{$cardToken} — the branch ref is this install's own artifact and is AUTHORITATIVE; the title token is treated as a foreign/descriptive citation and refused (title-vs-branch conflict, card#5287). The card token in force is card#{$cardToken}");
        }

        // A DL that resolved to a DIFFERENT card than a co-present card# (the DL-218
        // conflict) is FOREIGN to the card# card — it must not be stamped onto it, or
        // the move-hijack simply re-emerges as a correlation-poison (card#4811 would
        // thereafter answer to the foreign DL). Recorded here, excluded at stamp time.
        $conflictDl = null;

        // FR-7 try-in-order-with-fallback (framework #112): resolve on the OUTCOME
        // of a token, not its mere presence. (1) DL resolves → use it. (2) DL
        // unresolved → fall through to a present card#. (4) a token was present but
        // nothing resolved → a high-value miss, warn loudly. The board-scope guard
        // for the card# fallback lives in KanbanMoveCardHandler (it already gates
        // DL and card# moves identically), so the classifier stays classify-time-
        // read-free on that path.
        if ($dl !== null) {
            // Correlation read (deterministic key → card id(s)). A transient failure
            // here is a classify error (treatment A: recorded, ack 200, bridge:replay).
            // A DL/PR can track MULTIPLE cards (bundled PR — DL-148), so move them ALL:
            // one target per card, each with the card id as its distinct target_id so
            // they don't coalesce (DL-003).
            // Repo-qualified (DL-167) ONLY where ambiguity exists (DL-174): on a board
            // SHARED by several repo mappings, the event's repo is sent as kanban's
            // `source` dimension (DL-163) so a colliding DL number (DL-027) resolves to
            // THIS repo's card only. On a 1:1 board the strict `source` filter would
            // exclude cards whose derived refs carry no source (operator-stamped
            // dl_number cards) while protecting nothing — so it is omitted.
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            if ($cardIds !== []) {
                // A co-present card# is a CONFLICT only when it names a card the DL
                // did NOT resolve to (DL-218 / card#4811 incident): a descriptive or
                // foreign DL mention in a title — e.g. "Static guard against DL-219
                // re-introduction (card#4811)", where DL-219 resolves to a DIFFERENT
                // card — must not silently hijack the move to the DL's card and drop
                // the intended card#. The explicit card# is the specific, intentional
                // token, so on a conflict it is authoritative: warn LOUDLY and fall
                // through to the card# path below (which builds the move AND stamps
                // refs). When the card# agrees with the DL (or no card token is present
                // in any spelling) the DL wins, byte-identically to before; the third
                // state — present but UNREADABLE — is DL-287's, immediately below.
                $verdict = $this->cardTokenVerdict($cardResolution, $cardIds);
                if ($verdict === CardTokenVerdict::NearMissRefusal) {
                    // DL-287: a card-SHAPED token that does not parse, naming a card
                    // the DL did not resolve to. We cannot fall through to it (it is
                    // not a token — selecting on it would make every rejected spelling
                    // a correlation channel), and we must not let the DL win either:
                    // that is the card#4811 hijack with the guard switched off. So the
                    // move is REFUSED — carried to the durable handler as a flag rather
                    // than dropped here, because a permanent refusal has to emit a live
                    // signal (DL-274/DL-285) and that primitive is the handler's. No
                    // stamp refs ride it: a refused move writes nothing at all.
                    Log::warning('kanban_move_card: PR carries '.$dl.' (resolves to card(s) '.implode(',', $cardIds).') AND a card-SHAPED token that does not parse ('
                        .CardTokenGrammar::describe()."), which appears to name card#{$cardResolution['nearMissId']} — a DIFFERENT card. The {$dl} move onto card(s) "
                        .implode(',', $cardIds).' is REFUSED so a near-miss spelling cannot hijack it (near-miss card token, DL-287): '.$this->titleAndHead($payload));

                    return new ClassifyResult(targets: array_merge(
                        $this->moveTargets($cardIds, $repo, $moveOutcome, cardTokenNearMiss: true),
                        $overlayTargets,
                    ));
                }
                if ($verdict !== CardTokenVerdict::CardTokenAuthoritative) {
                    // (1)/(3) DL resolved → it wins. A co-present card# that names the
                    // SAME card is redundant, logged for the ledger (nothing dropped).
                    if ($cardToken !== null) {
                        Log::info("kanban_move_card: PR carries both {$dl} and card#{$cardToken} — DL wins (FR-7 precedence); the card# names the same card, so nothing is dropped");
                    } elseif ($verdict === CardTokenVerdict::NearMissRedundant) {
                        // The same ledger line for the unreadable spelling of it: it
                        // recovers to a card the DL already resolved to, so nothing is
                        // dropped and there is nothing to refuse. This is the leg that
                        // separates "the guard can see the shape" from "the guard
                        // refuses the shape" — the latter would reject ordinary prose.
                        Log::info("kanban_move_card: PR carries {$dl} and a card-SHAPED token that does not parse ("
                            .CardTokenGrammar::describe()."), but it appears to name card#{$cardResolution['nearMissId']} — the SAME card the DL resolved to, so nothing is dropped and the DL wins (near-miss card token, DL-287)");
                    }

                    // The DL-resolved card(s) also carry the PR provenance refs, with
                    // the resolved DL EXCLUDED — the card already carries its
                    // `dl_number` (that is HOW it resolved), so re-stamping it delivers
                    // nothing and could poison; only `pr_number`/`pr_url` are stamped
                    // (card#4852), add-if-missing at the handler. Gated on the SOLE-DL
                    // shape: a title carrying 2+ DLs is bundled/descriptive (release-
                    // shaped), so its own PR refs are foreign to this card — without
                    // this gate, add-if-missing would be the only poison guard. The
                    // explicit-card# path below stamps regardless (its card# token
                    // asserts "this PR tracks this card"); no such assertion exists here.
                    $stampRefs = DlTokenGrammar::sole($this->titleAndHead($payload)) !== null
                        ? $this->stampRefs($this->titleAndHead($payload), $this->prNumber($payload), $this->prUrl($payload), $dl)
                        : [];

                    return new ClassifyResult(targets: array_merge($this->moveTargets($cardIds, $repo, $moveOutcome, $stampRefs), $overlayTargets));
                }
                Log::warning('kanban_move_card: PR carries '.$dl.' (resolves to card(s) '.implode(',', $cardIds).") AND a DIFFERENT card#{$cardToken} — treating the explicit card# as authoritative (foreign-DL-mention guard, DL-218); moving card#{$cardToken}, not the DL card(s)");
                $conflictDl = $dl;   // foreign to the card# card → do not stamp it
                // fall through to the card# path below (do NOT return the DL targets)
            } elseif ($cardToken === null) {
                // (4) DL present but nothing resolved and no card# fallback → a
                // high-value miss (a decision-logged-but-unstamped card is the live
                // footgun this fallback exists for). Warn loudly; never silent no-op.
                Log::warning("kanban_move_card: PR carries {$dl} but no card tracks it and no card# fallback token is present — no move (FR-7 high-value miss)");
                // …and if the "no card# fallback token" was actually an UNREADABLE one,
                // say so (DL-287). The near-miss line's own "no move" clause is TRUE
                // about this subject — nothing moves here — which is precisely the
                // DL-234(e) condition that keeps the probe behind the both-null guard
                // elsewhere. The probe is per-stem, so the DL that just parsed above
                // cannot draw a line claiming it did not.
                $this->warnTokenNearMiss($this->titleAndHead($payload), 'PR title/head');

                return new ClassifyResult(targets: $overlayTargets);
            } else {
                // (2) DL unresolved → fall through to the present card#.
                Log::info("kanban_move_card: {$dl} resolved to no card — falling through to card#{$cardToken} (FR-7 try-in-order)");
            }
        }

        // card#<task-id> (FR-7): direct native-id selection — reached when DL is
        // absent, or present-but-unresolved with a card# fallback. Board+stage stay
        // operator config.
        //
        // What the durable handler REFUSES, stated as the code delivers it rather than
        // as one guarantee (card#5287 — the sentence that stood here claimed only the
        // board guard, and an auditor who read it stopped there): a card the handler
        // CANNOT READ is refused by the 4xx arm, which returns BEFORE the board guard
        // ever reads `board_id` — 403 (a card that exists and is another tenant's) and
        // 404 are split there so the operator gets the right hypothesis (card#5288); a
        // card it CAN read that is not on the mapped board is refused by the
        // belongs-to-mapped-board guard; and a card reached by an UNCORROBORATED
        // title-only token is refused unless the card tracks no PR yet or already
        // tracks this one. On the same mapped board none of the first two fire, which
        // is why the third exists. The no-regression guards then apply exactly as for a
        // DL move.
        //
        // Carries stamp refs (FR #3866): the card selected by native id is the one that
        // strands unstamped for release-promote correlation. (The DL-win path above
        // also stamps its PR refs — card#4852 — just never the `dl_number` the card
        // already carries.) On a title-vs-branch conflict the sole-DL stamp derives
        // from the head ref ALONE: we have just established that this title is about
        // another card, so a DL sitting in it is foreign to the branch's card too —
        // the DL-218 `$conflictDl` exclusion applied to the SURFACE rather than to one
        // already-resolved token.
        $stampText = $foreignTitleToken !== null ? $this->prHead($payload) : $this->titleAndHead($payload);

        return new ClassifyResult(targets: array_merge([
            ReactionTarget::make('kanban_move_card', (string) $cardToken, payload: array_merge(
                [
                    'card_id' => $cardToken,
                    'repo' => $repo,
                    'outcome' => $moveOutcome,
                ],
                $cardResolution['uncorroborated'] ? ['card_token_uncorroborated' => true] : [],
                $this->stampRefs($stampText, $this->prNumber($payload), $this->prUrl($payload), $conflictDl),
            )),
        ], $overlayTargets));
    }

    /**
     * The card-token predicate, shared by all three token-resolution sites (the move
     * path, the branch-create `started` push, and the draft overlay — canon #5): what
     * a co-present `card#` says about a DL that has RESOLVED.
     *
     * THE DL-218 HALF. A resolving DL and a co-present explicit `card#` CONFLICT when
     * the `card#` names a card the DL did NOT resolve to. A descriptive/foreign DL
     * mention in a title or branch ref must not silently hijack the move/overlay to the
     * DL's card and drop the intended `card#`; on a conflict the explicit `card#` is
     * authoritative.
     *
     * THE DL-287 HALF, and why the token is THREE-state. This predicate used to take a
     * `?int` token, so a card-SHAPED token the grammar rejects (`card_4811`) arrived as
     * null and was read as "no card token" — the guard switched itself off for exactly
     * the subject that needed it, and the DL's card moved and was stamped with a PR
     * that never touched it (card#6027). An UNREADABLE token is now its own state,
     * carrying the id {@see CardTokenGrammar::nearMissCardId} recovers: naming one of
     * the DL's own cards is REDUNDANT (nothing is dropped — the DL still wins, exactly
     * as an agreeing parsed token does), and anything else is REFUSED.
     *
     * HARD BOUND: the recovered id may REFUSE or WARN, never SELECT. It is read here
     * ONLY as a member test against ids the DL resolved on its own; no branch returns
     * it, and no caller may move a card by it. The redundancy arm is deliberately the
     * narrow POSITIVE test, so anything the probe cannot pin to a resolved card lands
     * on the refusing side rather than the permissive one.
     *
     * @param  array{token: ?int, nearMissId: ?int}  $cardState
     * @param  list<int>  $cardIds
     */
    private function cardTokenVerdict(array $cardState, array $cardIds): CardTokenVerdict
    {
        if ($cardState['token'] !== null) {
            return in_array($cardState['token'], $cardIds, true)
                ? CardTokenVerdict::DlWins
                : CardTokenVerdict::CardTokenAuthoritative;
        }
        if ($cardState['nearMissId'] === null) {
            return CardTokenVerdict::DlWins;   // no card token, readable or not — the ordinary DL-only subject
        }

        return in_array($cardState['nearMissId'], $cardIds, true)
            ? CardTokenVerdict::NearMissRedundant
            : CardTokenVerdict::NearMissRefusal;
    }

    /**
     * The card-token STATE of one surface: the id the token names when it parses, else
     * the id a card-SHAPED spelling the grammar rejects appears to name (DL-287). The
     * near-miss id is read only where `parse()` returned null, which is what makes a
     * match a near-miss at all.
     *
     * @return array{token: ?int, nearMissId: ?int}
     */
    private function cardTokenState(string $text): array
    {
        $token = CardTokenGrammar::parse($text);

        return [
            'token' => $token,
            'nearMissId' => $token === null ? CardTokenGrammar::nearMissCardId($text) : null,
        ];
    }

    /**
     * Build one `kanban_move_card` target per resolved card id — each with the card
     * id as its distinct target_id so they don't coalesce (DL-003/DL-148). `$stampRefs`
     * (card#4852) is merged into every target's payload: the DL-win path passes the PR
     * provenance refs (`stamp_pr`/`stamp_pr_url`, never `stamp_dl`); the `started` push
     * path passes none, so `[]` keeps it byte-identical.
     *
     * `$cardTokenNearMiss` flags the target for the handler's DL-287 refusal — the move
     * this classifier will not authorize but must not silently drop, because the
     * refusal has to ALERT and that primitive is the handler's (DL-274/DL-285). It rides
     * every target of the event, since the DL resolved them as one set. A flagged target
     * is refused before anything is read or written, which is why it carries no stamp
     * refs and why no caller passes both.
     *
     * @param  list<int>  $cardIds
     * @param  array{stamp_dl?: string, stamp_pr?: int, stamp_pr_url?: string}  $stampRefs
     * @return list<ReactionTarget>
     */
    private function moveTargets(array $cardIds, string $repo, string $outcome, array $stampRefs = [], bool $cardTokenNearMiss = false): array
    {
        $targets = [];
        foreach ($cardIds as $cardId) {
            $targets[] = ReactionTarget::make('kanban_move_card', (string) $cardId, payload: array_merge([
                'card_id' => $cardId,
                'repo' => $repo,
                'outcome' => $outcome,
            ], $stampRefs, $cardTokenNearMiss ? ['card_token_near_miss' => true] : []));
        }

        return $targets;
    }

    /**
     * The draft → block_reason overlay ACTION for a pull_request event (DL-193), or
     * null when the action is not a draft trigger. Overlay only — no stage move:
     *  - `converted_to_draft`                        → 'set'   (add-if-missing marker)
     *  - `opened` / `reopened` with `draft === true` → 'set'   (a PR born a draft;
     *      `converted_to_draft` never fires for it — GitHub sends `opened` with the
     *      draft flag). The existing `opened` move still fires; overlay only adds the set.
     *  - `ready_for_review`                          → 'clear' (clear-if-ours)
     *
     * @param  array<mixed>  $payload
     */
    private function draftOverlayAction(string $eventType, array $payload): ?string
    {
        $action = substr($eventType, strlen('pull_request.'));
        if ($action === 'converted_to_draft') {
            return 'set';
        }
        if ($action === 'ready_for_review') {
            return 'clear';
        }
        if ($action === 'opened' || $action === 'reopened') {
            $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

            return ($pr['draft'] ?? null) === true ? 'set' : null;
        }

        return null;
    }

    /**
     * One `kanban_block_reason` overlay target per correlated card (DL-193) — the
     * card id is the distinct target_id so bundled-card targets don't coalesce
     * (DL-003/DL-148), and the payload carries the repo (for the handler's board
     * resolution) and the set/clear action. No card correlates → no target (empty
     * list), exactly like the move path's un-linked no-op.
     *
     * On the uncorroborated title-only residual it ALSO carries the move path's
     * `card_token_uncorroborated` flag plus this event's `pr_number` — the evidence
     * the writeback's shared `CardTokenCorroboration` predicate gates on (card#5953).
     * (Named, not `{@see}`-linked: an FQCN in a docblock becomes a real `use` under
     * pint, and this classifier constructs nothing in that namespace.) Both
     * keys ride together and only on that residual, so an ordinary corroborated overlay
     * payload is byte-identical to before. `pr_number` rather than the move path's
     * `stamp_pr`: this overlay never stamps anything, and `kanban_dependabot_card`
     * already spells a plain event PR number that way.
     *
     * @param  array<mixed>  $payload
     * @return list<ReactionTarget>
     */
    private function blockReasonTargets(array $payload, string $repo, string $action, WritebackMapping $mapping, WritebackConfig $writeback): array
    {
        $resolution = $this->correlatedCardIds($payload, $repo, $mapping, $writeback);
        $corroboration = $resolution['uncorroborated']
            ? ['card_token_uncorroborated' => true, 'pr_number' => $this->prNumber($payload)]
            : [];

        $targets = [];
        foreach ($resolution['ids'] as $cardId) {
            $targets[] = ReactionTarget::make('kanban_block_reason', (string) $cardId, payload: array_merge([
                'repo' => $repo,
                'action' => $action,
            ], $corroboration));
        }

        return $targets;
    }

    /**
     * The card ids a PR correlates to, for the draft overlay — the SAME DL→card /
     * card#-fallback resolution the move path uses (FR-7 try-in-order, incl. the
     * DL-218 conflict rule via {@see self::cardTokenVerdict()}): a DL that resolves wins (all
     * matching cards, one-to-many DL-148) UNLESS a co-present `card#` names a
     * DIFFERENT card — a conflict, where the explicit `card#` wins (the intended card
     * gets the overlay, not the foreign-DL card); an unresolved DL falls through to a
     * present `card#`; neither → empty. Deliberately does NOT log any FR-7 diagnostic
     * (precedence / conflict / high-value-miss / fallthrough) — on an opened-as-draft
     * PR the move path already emits them for the same tokens, so repeating them here
     * would double-log the identical event.
     *
     * A DL-287 near-miss REFUSAL is inherited here through the same predicate and with
     * the same silence: the overlay simply does not land on the DL's card (there is no
     * `card#` to fall through to — the token did not parse), and a pure-overlay action
     * carries no move outcome, so the move path never ran to say why. Naming it here
     * would be the double-log DL-218 split off, and the overlay is the lower-harm
     * surface (a marker, not a stage move) that ruling was made about.
     *
     * It shares {@see cardTokenResolution}, so the title-vs-branch rule (card#5287)
     * applies here too: an overlay never lands on a title's card when the branch names
     * a different one. Since card#5953 it also reports the uncorroborated TITLE-ONLY
     * residual, so {@see blockReasonTargets} can flag the target and the writeback's
     * shared `CardTokenCorroboration` predicate settle it at the handler on
     * the card's own evidence — the same mechanism the move path uses, not a second
     * one. Before that, the overlay inherited the RULE but not the GATE, and a
     * title-only `card#` could overlay a `block_reason` onto a card this PR had only
     * cited; the gate is a change to what the bridge accepts, so it was filed and
     * user-gated rather than widened alongside DL-270.
     *
     * `uncorroborated` describes the returned ids, so it is FALSE whenever a resolving
     * DL selected them: a DL picks its cards out of the board, never out of prose. It
     * is true only on the `card#` fallback with a prose-only token — mirroring the move
     * path, where {@see moveTargets} never carries the flag and only the card# return
     * does. It needs no "and a token was found" clause: {@see cardTokenResolution}
     * reports `uncorroborated` false whenever its `token` is null, and a null token
     * yields no ids for the flag to describe anyway.
     *
     * @param  array<mixed>  $payload
     * @return array{ids: list<int>, uncorroborated: bool}
     */
    private function correlatedCardIds(array $payload, string $repo, WritebackMapping $mapping, WritebackConfig $writeback): array
    {
        $dl = $this->dlToken($payload);
        $cardResolution = $this->cardTokenResolution($payload);
        $cardToken = $cardResolution['token'];
        if ($dl !== null) {
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            $verdict = $cardIds !== [] ? $this->cardTokenVerdict($cardResolution, $cardIds) : null;
            if ($verdict === CardTokenVerdict::DlWins || $verdict === CardTokenVerdict::NearMissRedundant) {
                return ['ids' => $cardIds, 'uncorroborated' => false];
            }
        }

        return [
            'ids' => $cardToken !== null ? [$cardToken] : [],
            'uncorroborated' => $cardResolution['uncorroborated'],
        ];
    }

    /**
     * Branch-create push → `started` move target(s) (DL-160). Fires ONCE on the
     * creation of a branch (`payload.created === true`) whose ref carries a
     * `DL-NNN` or a card token ({@see DlTokenGrammar} / {@see CardTokenGrammar}
     * own which spellings those are — this path runs the very same parses),
     * so it codifies "work has begun"
     * from the artifact (the branch), not from any agent. Uses `created === true`
     * so a subsequent push to the same branch is a no-op (the move would otherwise
     * re-fire on every push). The handler's promote-from guard
     * (`started_from_stages`) makes a re-create / force-push of an old branch a
     * no-op too. Correlates tokens→card exactly as the PR path (FR-7 try-in-order,
     * incl. the DL-218 conflict rule via {@see self::cardTokenVerdict()}): a branch ref that
     * carries a resolving `DL-NNN` AND an explicit `card#` for a DIFFERENT card is a
     * conflict — the explicit `card#` wins (warn loudly, move + stamp the `card#`, and
     * do NOT stamp the foreign DL); a ref whose card token is present but UNREADABLE
     * beside a resolving DL refuses the `started` move instead (DL-287), since nothing
     * here can say which card the branch meant. No target when: not a created-branch
     * push, a dependabot branch, no token in the ref, the repo is unmapped, or nothing
     * resolves.
     *
     * @param  array<mixed>  $payload
     */
    private function classifyPush(array $payload, string $scopeId): ClassifyResult
    {
        if (($payload['created'] ?? null) !== true) {
            return new ClassifyResult;   // not a branch creation → no-op (don't re-fire on every push)
        }
        $ref = is_string($payload['ref'] ?? null) ? $payload['ref'] : '';
        if (! str_starts_with($ref, 'refs/heads/')) {
            return new ClassifyResult;   // a tag or other ref, not a branch
        }
        $branch = substr($ref, strlen('refs/heads/'));
        if (str_starts_with($branch, 'dependabot/')) {
            return new ClassifyResult;   // dependabot branches carry no DL and track no card
        }

        $repo = $scopeId;
        $writeback = WritebackConfig::loadDefault();
        $mapping = $writeback?->mappingFor($repo);
        if ($mapping === null) {
            return new ClassifyResult;   // repo not configured for writeback
        }

        $dl = DlTokenGrammar::parse($branch);
        $cardState = $this->cardTokenState($branch);
        $cardToken = $cardState['token'];
        if ($dl === null && $cardToken === null) {
            $this->warnTokenNearMiss($branch, 'branch ref');

            return new ClassifyResult;   // no card-first token in the branch ref → un-linked, no-op
        }

        // A DL that resolved to a DIFFERENT card than a co-present card# (DL-218) is
        // FOREIGN to the card# card — excluded at stamp time, mirroring the move path.
        $conflictDl = null;

        // FR-7 try-in-order-with-fallback (framework #112): same resolution order as
        // the pull_request path — resolve on the OUTCOME of the DL, not its presence.
        if ($dl !== null) {
            // Repo-qualified (DL-167) only where ambiguity exists (DL-174) — same
            // shared-board conditional as the pull_request path above.
            $sourceRepo = $writeback->boardIsShared($mapping->boardId) ? $repo : null;
            $cardIds = WritebackClientFactory::make()->correlateDl($mapping->boardId, $dl, $sourceRepo);
            if ($cardIds !== []) {
                // DL-218 conflict (same rule + harm as the move path): a branch like
                // `card-4811-guard-DL-219` where DL-219 resolves elsewhere must not
                // hijack the `started` move to the DL's card. On a conflict the
                // explicit card# is authoritative — warn LOUDLY and fall through to the
                // card# path below. When the card# agrees (or is absent) the DL wins.
                $verdict = $this->cardTokenVerdict($cardState, $cardIds);
                if ($verdict === CardTokenVerdict::NearMissRefusal) {
                    // DL-287 on this surface: a branch like `card_4811-guard-DL-9`.
                    // Same rule, same harm class (a `started` promotion of a card this
                    // branch never named), same plumbing — the handler owns the refusal
                    // so it alerts.
                    Log::warning('kanban_move_card: branch carries '.$dl.' (resolves to card(s) '.implode(',', $cardIds).') AND a card-SHAPED token that does not parse ('
                        .CardTokenGrammar::describe()."), which appears to name card#{$cardState['nearMissId']} — a DIFFERENT card. The {$dl} move onto card(s) "
                        .implode(',', $cardIds).' is REFUSED so a near-miss spelling cannot hijack it (near-miss card token, DL-287): '.$branch);

                    return new ClassifyResult(targets: $this->moveTargets($cardIds, $repo, 'started', cardTokenNearMiss: true));
                }
                if ($verdict !== CardTokenVerdict::CardTokenAuthoritative) {
                    if ($cardToken !== null) {
                        Log::info("kanban_move_card: branch carries both {$dl} and card#{$cardToken} — DL wins (FR-7 precedence); the card# names the same card, so nothing is dropped");
                    } elseif ($verdict === CardTokenVerdict::NearMissRedundant) {
                        Log::info("kanban_move_card: branch carries {$dl} and a card-SHAPED token that does not parse ("
                            .CardTokenGrammar::describe()."), but it appears to name card#{$cardState['nearMissId']} — the SAME card the DL resolved to, so nothing is dropped and the DL wins (near-miss card token, DL-287)");
                    }

                    return new ClassifyResult(targets: $this->moveTargets($cardIds, $repo, 'started'));
                }
                Log::warning('kanban_move_card: branch carries '.$dl.' (resolves to card(s) '.implode(',', $cardIds).") AND a DIFFERENT card#{$cardToken} — treating the explicit card# as authoritative (foreign-DL-mention guard, DL-218); moving card#{$cardToken}, not the DL card(s)");
                $conflictDl = $dl;   // foreign to the card# card → do not stamp it
                // fall through to the card# path below (do NOT return the DL targets)
            } elseif ($cardToken === null) {
                Log::warning("kanban_move_card: branch carries {$dl} but no card tracks it and no card# fallback token is present — no move (FR-7 high-value miss)");
                // Nothing moves on this arm, so the near-miss line's "no move" clause
                // is true about this ref and the probe can honestly run (DL-287) —
                // the same honesty fix as the move path's arm.
                $this->warnTokenNearMiss($branch, 'branch ref');

                return new ClassifyResult;
            } else {
                Log::info("kanban_move_card: {$dl} resolved to no card — falling through to card#{$cardToken} (FR-7 try-in-order)");
            }
        }

        // card#<task-id> (FR-7): native-id selection — DL absent, or present-but-
        // unresolved with a card# fallback. The token came from the branch REF, which
        // this install's own tooling minted, so it is corroborated by construction and
        // carries no `card_token_uncorroborated` flag — the title/prose surface that
        // needs the handler's card-side gate (card#5287) does not exist on a push.
        // Handler-guarded on readability + board membership beyond that.
        // Carries stamp refs (FR #3866) off the branch ref (no PR on a push).
        return new ClassifyResult(targets: [
            ReactionTarget::make('kanban_move_card', (string) $cardToken, payload: array_merge([
                'card_id' => $cardToken,
                'repo' => $repo,
                'outcome' => 'started',
            ], $this->stampRefs($branch, null, null, $conflictDl))),
        ]);
    }

    /**
     * Move outcome from GitHub-controlled fields only.
     *
     * @param  array<mixed>  $payload
     */
    private function outcome(string $eventType, array $payload): ?string
    {
        $action = substr($eventType, strlen('pull_request.'));
        if ($action === 'opened' || $action === 'reopened') {
            return 'opened';
        }
        if ($action !== 'closed') {
            return null;
        }
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        if (($pr['merged'] ?? false) !== true) {
            return 'closed_unmerged';
        }
        $base = is_array($pr['base'] ?? null) ? ($pr['base']['ref'] ?? '') : '';

        // The merged→stage decision lives in PrOutcome so the reconciler
        // (bridge:reconcile, DL-183) derives the identical outcome from REST state.
        return PrOutcome::forMergedBase(is_string($base) ? $base : '');
    }

    /**
     * The FLATTENED correlation surface — the PR title plus the head branch ref, as
     * one string — used where the two surfaces genuinely carry equal weight: the
     * `DL-NNN` match ({@see dlToken}), the sole-DL stamp derivation
     * ({@see stampRefs}), and the near-miss probe. The card token deliberately does
     * NOT use it: flattening is exactly what let a title token outrank the branch's,
     * so {@see cardTokenResolution} reads {@see prTitle} and {@see prHead} separately.
     *
     * @param  array<mixed>  $payload
     */
    private function titleAndHead(array $payload): string
    {
        return $this->prTitle($payload).' '.$this->prHead($payload);
    }

    /**
     * The PR title — prose, and one of the two card-token surfaces
     * ({@see cardTokenResolution} says what authority it carries).
     *
     * @param  array<mixed>  $payload
     */
    private function prTitle(array $payload): string
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

        return is_string($pr['title'] ?? null) ? $pr['title'] : '';
    }

    /**
     * The head branch ref — this install's OWN artifact, and the authoritative
     * card-token surface ({@see cardTokenResolution}).
     *
     * @param  array<mixed>  $payload
     */
    private function prHead(array $payload): string
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];

        return is_array($pr['head'] ?? null) && is_string($pr['head']['ref'] ?? null) ? $pr['head']['ref'] : '';
    }

    /**
     * WHICH card this PR authorizes a write against, and how well corroborated that
     * claim is (card#5287 / DL-270). The card token (FR-7, framework v0.2.229) is the
     * native-kanban-task-id correlation channel for cards that carry no DL; the
     * accepted spellings are {@see CardTokenGrammar}'s, never re-listed here. What
     * this method owns is not the spelling but the AUTHORITY, because the token has
     * TWO surfaces and they do not carry the same weight:
     *
     *  - The HEAD BRANCH ref is minted by this install's own tooling
     *    (`board-card-start` → `card-<id>-slug`), so it is the authoritative referent
     *    for what the PR is work ON.
     *  - The TITLE is prose. A `card#NNNN` in it is as often a DESCRIPTIVE citation of
     *    another card — the normal consequence of agents citing each other's work —
     *    as it is a claim about this PR, and the two are lexically identical.
     *
     * Reading both as ONE string and taking `preg_match`'s single leftmost match let
     * the title (which sits first in the concatenation) silently OUTRANK the branch's
     * own correct token. That is the same hijack DL-218 closed one layer up for
     * DL-vs-`card#`, whose conflict predicate does not reach across the title/branch
     * boundary. So the DL-218 SHAPE is applied here (canon #5): the branch is
     * authoritative, and a DISAGREEING title token is foreign — refused and warned,
     * never silently picked.
     *
     * `uncorroborated` marks the residual the branch cannot vouch for: a title token
     * that the head ref does not back — neither as a full token nor as the card's bare
     * id ({@see refCorroborates}), so nothing this install minted agrees with the
     * prose. Corroboration is deliberately WIDER than selection: a bare id in the ref
     * can confirm a token but never produce one. The classifier
     * deliberately does NOT settle that case — it turns on whether the CARD already
     * answers to another PR, which only the durable handler's existing `getCard` read
     * can say — so the flag rides the payload and {@see KanbanMoveCardHandler} gates
     * on it. Costing no read the handler does not already make is precisely why
     * accept-if-the-card-tracks-no-other-PR was the approved residual answer rather
     * than refuse-every-title-only-token.
     *
     * `nearMissId` is the THIRD state (card#6027 / DL-287): no surface produced a
     * token, but one of them carries a card-SHAPED spelling this grammar rejects, and
     * this is the id it appears to name. It is reported ONLY from the both-null arm —
     * a token that parses on either surface settles the question, so a malformed
     * spelling sitting beside it changes nothing about WHICH card is named. The branch
     * is read first for the same reason it outranks the title everywhere else here.
     * What a caller may do with the id is bounded at
     * {@see self::cardTokenVerdict()}: refuse or warn, never select.
     *
     * @param  array<mixed>  $payload
     * @return array{token: ?int, uncorroborated: bool, foreignTitleToken: ?int, nearMissId: ?int}
     */
    private function cardTokenResolution(array $payload): array
    {
        $title = $this->cardTokenState($this->prTitle($payload));
        $head = $this->cardTokenState($this->prHead($payload));
        $titleToken = $title['token'];
        $headToken = $head['token'];

        if ($headToken !== null) {
            return [
                'token' => $headToken,
                'uncorroborated' => false,
                // Null when the title agrees or names nothing — only a DISAGREEING
                // title token is foreign.
                'foreignTitleToken' => $titleToken !== $headToken ? $titleToken : null,
                'nearMissId' => null,
            ];
        }

        if ($titleToken === null) {
            return [
                'token' => null,
                'uncorroborated' => false,
                'foreignTitleToken' => null,
                'nearMissId' => $head['nearMissId'] ?? $title['nearMissId'],
            ];
        }

        return [
            'token' => $titleToken,
            'uncorroborated' => ! $this->refCorroborates($this->prHead($payload), $titleToken),
            'foreignTitleToken' => null,
            'nearMissId' => null,
        ];
    }

    /**
     * Does the head ref carry this card's BARE id — `fix/5287-slug` for a title that
     * says `card#5287`? Corroboration only. A bare number can CONFIRM a token the
     * title already produced; it can never SELECT a card by itself, which is exactly
     * why {@see CardTokenGrammar} requires the `card` prefix (or an ordinary
     * `chore/2026-cleanup` would correlate). Keeping bare ids on the confirm side
     * preserves that and adds no correlation channel.
     *
     * This is load-bearing for the dominant branch convention, and measuring it is
     * what stopped this guard from breaking ordinary work (card#5287): real branches
     * here are overwhelmingly `<type>/<id>-slug` (`fix/5915-…`, `test/5233-…`), which
     * carries NO card token. Requiring a full token in the ref would have made almost
     * every PR uncorroborated, and the handler gate then refuses any SECOND PR against
     * a card that already tracks a first one — which is routine (three merged PRs here
     * track card 5698). The approved residual says "no BRANCH CORROBORATION", and a ref
     * naming the id plainly corroborates it.
     *
     * Bound, stated rather than discovered later: a numeric branch can accidentally
     * corroborate a low-id card (`chore/bump-1-2-3` vs `card#2`). That only ever
     * RELAXES a guard which did not exist at all before this change, and never widens
     * what can be selected — so it is accepted, not defended against (canon #6).
     */
    private function refCorroborates(string $ref, int $cardId): bool
    {
        preg_match_all('/\d+/', $ref, $matches);
        foreach ($matches[0] as $run) {
            if (ltrim($run, '0') === (string) $cardId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warn when text APPEARS to name a correlation token but the token doesn't
     * parse. An unparsed token is otherwise a silent no-op — the branch
     * publishes, the card never moves, nobody is told — exactly as high-value a
     * miss as an unresolvable DL (roundtable #48). Token-less text stays silent:
     * most branches/PRs legitimately carry neither token, and a bare space
     * before the id (`card 2`, `DL 239`) is deliberately NOT a near-miss — prose
     * like "supports card 2" in a PR title would warn.
     *
     * BOTH tokens are probed (card#5310). DL-234's argument — an unparsed token
     * is as high-value a miss as an unresolvable DL — was made about the DL
     * token BY NAME, and the DL half is the one that never got the fix: until
     * this, `DL_239` / `DL239` / `DLs-239` reached here and warned nothing. A
     * text can be a near-miss on both stems at once and then warns twice, once
     * per grammar; each line names its own accept-set, so a merged line would
     * have to pick one or restate both.
     *
     * WHICH SHAPES those are is each grammar's to say — both the accept-set the
     * warning renders and the probe that decides whether to warn at all
     * (DL-250). A hand-written accept-set here spent two releases telling
     * operators that glued `card123` does not correlate, months after DL-233
     * made it (card#5267); a hand-written probe here was silent on every plural
     * spelling.
     *
     * THE WHOLE-SUBJECT LIMIT (DL-234(e)) is the real gate, and it is a limit on
     * the CALL SITES, not on the both-null guard that used to be its only one: a
     * subject carrying a parsing `card#` beside a malformed `DL_239` moves its
     * card, so it is not in the silent-failure class this exists to catch — and
     * "no move" below would be a false statement about it. That limit STANDS;
     * what changed (card#5961) is that the cost it leaves — such a subject losing
     * its `dl_number` stamp — is no longer silent either. Naming a lost STAMP is a
     * different signal from naming a lost MOVE, so it is emitted where the stamp
     * is built ({@see stampRefs}) rather than by widening this guard.
     *
     * SINCE DL-287 the FR-7 no-move arms call this too — a DL that resolved to
     * nothing with no `card#` fallback moves nothing, so the limit is satisfied
     * there and the arm can finally say that the missing fallback was sitting in
     * the subject all along, misspelled. Those arms reach here with the DL PARSED,
     * which is why the per-stem parse check above is what makes the claim true
     * rather than the caller's guard.
     */
    private function warnTokenNearMiss(string $text, string $surface): void
    {
        // Each stem is probed only where ITS OWN grammar found nothing. At the
        // both-null call site that is implied by the guard, so this is the same
        // answer it always gave there; what it buys is the DL-287 call sites, where
        // ONE stem resolved to nothing and the other parsed — a probe that asked
        // regardless would tell an operator a token that parsed does not parse.
        $nearMisses = [];
        if (CardTokenGrammar::parse($text) === null && CardTokenGrammar::looksLikeCardToken($text)) {
            $nearMisses['a card'] = CardTokenGrammar::describe();
        }
        if (DlTokenGrammar::parse($text) === null && DlTokenGrammar::looksLikeDlToken($text)) {
            $nearMisses['a DL'] = DlTokenGrammar::describe();
        }

        foreach ($nearMisses as $what => $accepted) {
            Log::warning("kanban_move_card: {$surface} appears to name {$what} but the token does not parse ({$accepted}) — no move (FR-7 near-miss): {$text}");
        }
    }

    /**
     * The `DL-NNN` token from the PR title or head branch (the same convention
     * the board automation already uses), or null. The accepted spellings are
     * {@see DlTokenGrammar}'s, never re-listed here.
     *
     * @param  array<mixed>  $payload
     */
    private function dlToken(array $payload): ?string
    {
        return DlTokenGrammar::parse($this->titleAndHead($payload));
    }

    /**
     * The correlation refs to STAMP onto a moved card — the durable handler writes
     * them add-if-missing (FR #3866). Two callers: the `card#` fallback (which may
     * pass a resolving DL to stamp) and the DL-win path (which passes its resolved DL
     * as `$excludeDl` so the card is NOT re-stamped with the `dl_number` it already
     * carries — card#4852). Includes the DL token ONLY when EXACTLY ONE `DL-NNN`
     * appears in $text: a bundled / release-shaped PR carrying several DLs (or a
     * single FOREIGN, unresolved DL alongside a `card#` for a different card) must
     * never stamp one. `$excludeDl` is the same guard for the RESOLVED-foreign case
     * (DL-218) and for the DL-win case: a sole DL that is foreign to (or already on)
     * the target card is passed here to be dropped even though it is the lone match.
     * The PR number and URL are provenance included when present (this card's PR) —
     * add-if-missing at the handler protects an existing value, so a release PR whose
     * title merely names a feature card's DL cannot overwrite that card's own PR refs.
     *
     * THE LOST STAMP IS NOW NAMED (card#5961), and it is a different signal from
     * {@see warnTokenNearMiss}'s. That probe sits behind the both-null guard, so a
     * subject like `feat/card-3410-slug-DL_272` is silent there BY DECISION — this
     * event emits a move rather than no-opping, so the near-miss line's "no move"
     * clause would be false about it (DL-234(e)). What was ALSO silent is the
     * consequence at THIS site: `sole()` returns null on the malformed token and no
     * `stamp_dl` rides the target, so nothing carries a `dl_number` for the next
     * event to correlate by. The warning therefore belongs where the DL result is
     * CONSUMED.
     *
     * WHAT IT MAY CLAIM is bounded by where it is emitted — classify time. It says
     * a move is EMITTED, never that a card ends up moved: `KanbanMoveCardHandler`
     * can still refuse the target (the `getCard` 4xx arm, the
     * belongs-to-mapped-board guard, the uncorroborated-title-token gate, the
     * `started` no-regression guard). That is the same discipline the DL-148 note
     * above states for the conflict warning — what is always true HERE is what gets
     * said. The `dl_number` half needs no such hedge: the stamp is not in the
     * payload at all, so it cannot be written whatever the handler decides.
     *
     * The guard needs no "and a card token parsed" clause, which would defend
     * against an unreachable state (canon #6): both `card#`-path callers are reached
     * only with a non-null card token, and the DL-win caller passes a `$text` its
     * own non-null `$dl` was parsed out of — so `parse($text) === null` here IS the
     * card# path. A DL that parses but is not stamped — not SOLE (a bundled /
     * release-shaped subject), or excluded as `$excludeDl` on the DL-218 conflict
     * path — is deliberately NOT warned either: that DL is foreign to this card
     * by construction, not lost.
     *
     * @return array{stamp_dl?: string, stamp_pr?: int, stamp_pr_url?: string}
     */
    private function stampRefs(string $text, ?int $prNumber, ?string $prUrl = null, ?string $excludeDl = null): array
    {
        $refs = [];
        $sole = DlTokenGrammar::sole($text);
        if ($sole !== null && $sole !== $excludeDl) {
            $refs['stamp_dl'] = $sole;
        }
        if (DlTokenGrammar::parse($text) === null && DlTokenGrammar::looksLikeDlToken($text)) {
            Log::warning('kanban_move_card: a card token correlates, so this event moves a card rather than no-opping (the durable handler may still refuse it) — but the subject also carries a DL-shaped token that does not parse ('
                .DlTokenGrammar::describe().'), so no dl_number is stamped with the move (FR-7 lost DL stamp): '.$text);
        }
        if ($prNumber !== null) {
            $refs['stamp_pr'] = $prNumber;
        }
        if ($prUrl !== null && $prUrl !== '') {
            $refs['stamp_pr_url'] = $prUrl;
        }

        return $refs;
    }

    /**
     * A dependabot PR, detected by its own branch namespace (`dependabot/*`).
     *
     * @param  array<mixed>  $payload
     */
    private function isDependabot(array $payload): bool
    {
        return str_starts_with($this->prHead($payload), 'dependabot/');
    }

    /**
     * The PR number (the dependabot-card correlation key), or null.
     *
     * @param  array<mixed>  $payload
     */
    private function prNumber(array $payload): ?int
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $n = $pr['number'] ?? null;

        return is_numeric($n) ? (int) $n : null;
    }

    /**
     * The PR's `html_url` — the `pr_url` correlation ref (card#4852), which drives
     * kanban's multi-repo by-ref `source` derivation. Null when absent (e.g. a push,
     * which carries no PR).
     *
     * @param  array<mixed>  $payload
     */
    private function prUrl(array $payload): ?string
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $url = $pr['html_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
