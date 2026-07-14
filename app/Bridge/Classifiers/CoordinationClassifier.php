<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\ClassifierConfig;
use App\Bridge\Support\RecipientAddressing;
use App\Bridge\Writeback\WritebackConfig;

/**
 * Coordination + impl/CI classifier for the Claude Code Coordination Framework —
 * the config-driven, config-gated superset (roundtable #8): one reference
 * classifier that both a project's coordination repo AND a cross-project channel
 * AND (opt-in) an impl code repo route through, parameterized by
 * `classifier.config` ({@see ClassifierConfig}) so no install forks the bridge.
 *
 * NON-FINAL by design: a project ships a THIN subclass carrying only its
 * specifics (repo→agent maps go in config; a custom handler like sola's
 * `registry_append` is the only reason to subclass). The mechanics live here.
 *
 * Extends {@see InboxOnlyClassifier}: `parent::classify()` provides the base
 * inbox-staging for kanban `task.*` events (a GitHub event yields an empty base —
 * InboxOnly handles no GitHub event type), and the config-gated families below
 * layer reactions on top. This is the roundtable-#8 unification: ONE reference
 * classifier for a coord repo, a cross-project channel, an impl code repo, AND a
 * kanban triage board, selected by which families a project enables.
 *
 * Event families (config-gated via `classifier.config.families`; DEFAULT
 * `['coord-message']` so an install that doesn't opt in behaves EXACTLY as the
 * pre-#8 reference — back-compat):
 *   - `coord-message` — github issues/issue_comment/pull_request coordination
 *     messages, addressed per DL-022, shared-identity re-attributed. Optional
 *     `drop_title_all_of` drops a subject whose title contains every substring of
 *     any configured group (bookkeeping-title noise, e.g. a back-merge anchor).
 *   - `impl-ci-wake`  — github push→release-branch (release-landed) + workflow_run
 *     wake-worthy CI (failure-class conclusions, provenance-success). DEFAULT
 *     surgical: hand-emits a channel_push ONLY for wake-worthy events, else
 *     gate-dropped. Config: `impl_repos` gates to a repo subset (empty ⇒ all
 *     subscribed); `impl_non_wake_disposition: inbox_stage` keeps a broad CI/push
 *     inbox (no channel_push) instead of gate-dropping non-wake events.
 *
 * WAKE-EMIT INVARIANT (DL-191): every family hand-emits its `channel_push` through
 * {@see wakePush()}, which suppresses the push on a `route_intents:true` channel —
 * there the dispatcher routes every staged intent (DL-006), so a hand-emit would
 * double-wake. So: hand-emit ⟺ `route_intents:false`; when the channel routes, the
 * routed push (byte-identical payload) is the single wake.
 *
 *   - `coord_extra_actions` (coord-message) extends the fail-safe action allow-list
 *     per prefix; `wake_membership` (default `[to_me, to_all]`) selects which label
 *     classes grant coord-message live-wake (`from_me` = opt-in; `comment_to` =
 *     opt-in body-`TO:<self>` grant for cross-thread pull-ins, DL-192).
 *   - `kanban-triage` — kanban `task.created` for a HUMAN-filed, UNTRIAGED card
 *     (DL-168): pairs the inbox `new_card` Intent with a channel_push to the
 *     triage owner. Folded in from the retired standalone KanbanTriageClassifier
 *     (now a thin back-compat shim that just enables this family).
 *   - `coord-card-create` — github `issues.opened/reopened` on the coord repo
 *     whose title carries a recognized `[PREFIX]` (DL-198): emits ONE
 *     `kanban_coord_card` writeback target (no intent, no wake) so a tracking card
 *     is created in real time. Runs board-level (independent of the coord-message
 *     recipient gate); own gate is prefix-recognized AND the repo's
 *     `writeback.json` mapping has `create_coord_cards`. Opt-in (not a default).
 *
 * SCOPE-AGNOSTIC: never branches on `$ctx->scopeId` for routing — it keys on the
 * addressing labels + `$agent->agentName` — so the same instance serves a coord
 * repo and the cross-project channel unchanged.
 *
 * Shared-identity (DL-002): every agent posts under ONE github account, so
 * `Actor.name` is null; the author is recovered (`reattribute()`) from, in order,
 * the `scope_author_map` (a single-author repo — resolves LABEL-LESS impl events),
 * the `from:<agent>` label, then the body `FROM:` line, and returned as
 * `reattributedActor` so the dispatcher suppresses an agent's own writes
 * post-classify (enrichment, never a filter).
 *
 * Stateless: `$agent`/config are read as call-locals (one cached instance serves
 * every agent in the per-agent dispatch loop — never stash on the instance).
 */
class CoordinationClassifier extends InboxOnlyClassifier implements DeclaresConsumedEvents, EmitsWritebackReactions
{
    /** event_type prefix → the body-actions that represent a NEW coordination message worth waking on. */
    private const HANDLED = [
        'issues.' => ['opened', 'reopened'],
        'issue_comment.' => ['created'],
        'pull_request.' => ['opened', 'reopened', 'ready_for_review'],
    ];

    /**
     * The top-level GitHub event types the `impl-ci-wake` family consumes (§3):
     * a `push` (release-landed) and a `workflow_run.<action>` (CI). This is the
     * SINGLE source of truth {@see consumedEventTypes} unions for that family — it
     * mirrors the two dispatch guards in {@see implCiWakeFamily} (`$eventType ===
     * 'push'` / `str_starts_with($eventType, 'workflow_run.')`); keep them in sync.
     * (coord-message derives its types from {@see HANDLED} instead, so it can't
     * drift at all.)
     */
    private const IMPL_CI_WAKE_EVENT_TYPES = ['push', 'workflow_run'];

    /** Families run when `classifier.config.families` is unset — the pre-#8 behavior. */
    private const DEFAULT_FAMILIES = ['coord-message'];

    /**
     * workflow_run conclusions treated as BENIGN (no wake) by default; override via
     * `classifier.config.benign_conclusions`. The gate is fail-LOUD: a completed run
     * wakes UNLESS its conclusion is in this set — so a NEW GitHub conclusion type
     * never silently escapes CI oversight (a missed failure is worse than a spurious
     * wake). `success` is benign here for the CI-failure signal, but still wakes as a
     * provenance cue when its workflow NAME matches `provenance_patterns`.
     */
    private const DEFAULT_BENIGN_CONCLUSIONS = ['success', 'cancelled', 'skipped', 'neutral'];

    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        // InboxOnly base: kanban `task.*` events stage to the agent inbox; a GitHub
        // event yields an empty base (InboxOnly handles no GitHub event type). The
        // config-gated families below layer reactions onto this base.
        $base = parent::classify($ctx);

        $cfg = $ctx->agent->classifierConfig;
        $families = $cfg->enabledFamilies !== [] ? $cfg->enabledFamilies : $this->defaultFamilies();

        // Run each enabled family; merge results onto the inbox base. Families are
        // provider- and event-scoped (coord/impl-ci: github; kanban-triage: kanban),
        // so at most one contributes per event — but the merge is order-independent
        // and additive regardless. Each github family self-guards its provider (the
        // class now serves both github and kanban events).
        $intents = $base->intents;
        $targets = $base->targets;
        $reattributed = $base->reattributedActor;
        foreach ($families as $family) {
            $result = match ($family) {
                'coord-message' => $this->coordMessageFamily($ctx, $cfg),
                'impl-ci-wake' => $this->implCiWakeFamily($ctx, $cfg),
                'kanban-triage' => $this->kanbanTriageFamily($ctx, $base),
                'coord-card-create' => $this->coordCardCreateFamily($ctx),
                default => null,   // unknown family: ignore (forward-compat; bridge:check can warn)
            };
            if ($result === null) {
                continue;
            }
            $intents = [...$intents, ...$result->intents];
            $targets = [...$targets, ...$result->targets];
            $reattributed ??= $result->reattributedActor;
        }

        return new ClassifyResult(intents: $intents, targets: $targets, reattributedActor: $reattributed);
    }

    /**
     * The families run when `classifier.config.families` is unset. The base default
     * is the pre-#8 coord-message-only behavior; a subclass overrides this to pin a
     * different default (e.g. the {@see KanbanTriageClassifier} shim → kanban-triage).
     *
     * @return list<string>
     */
    protected function defaultFamilies(): array
    {
        return self::DEFAULT_FAMILIES;
    }

    /**
     * The union of top-level GitHub event types over the ENABLED families
     * (card#4183 / DL-196) — the same `$cfg->enabledFamilies` gate `classify()`
     * applies, falling back to {@see defaultFamilies()}. Derived from the SAME
     * source of truth the classify pipeline uses so the two can't drift:
     * coord-message from {@see HANDLED}, impl-ci-wake from
     * {@see IMPL_CI_WAKE_EVENT_TYPES}, coord-card-create → `issues` (DL-198).
     * kanban-triage (and any unknown family) is a kanban-provider family that
     * consumes NO GitHub event type → contributes nothing. Pure `$cfg` → map, no
     * class-loading (the HARD CONTRACT on {@see DeclaresConsumedEvents}).
     *
     * @return list<string>
     */
    public function consumedEventTypes(ClassifierConfig $cfg): array
    {
        $families = $cfg->enabledFamilies !== [] ? $cfg->enabledFamilies : $this->defaultFamilies();

        $events = [];
        foreach ($families as $family) {
            $events = [...$events, ...match ($family) {
                'coord-message' => self::coordMessageEventTypes(),
                'impl-ci-wake' => self::IMPL_CI_WAKE_EVENT_TYPES,
                'coord-card-create' => ['issues'],   // acts on issues.opened/reopened (DL-198)
                default => [],   // kanban-triage (kanban provider) + unknown families: no github event type
            }];
        }

        return array_values(array_unique($events));
    }

    /**
     * The top-level GitHub event types the `coord-message` family consumes,
     * derived from {@see HANDLED} (`issues.`/`issue_comment.`/`pull_request.` →
     * `issues`/`issue_comment`/`pull_request`) so {@see consumedEventTypes} cannot
     * drift from the actual dispatch surface.
     *
     * @return list<string>
     */
    private static function coordMessageEventTypes(): array
    {
        return array_map(static fn (string $prefix): string => rtrim($prefix, '.'), array_keys(self::HANDLED));
    }

    /**
     * The single wake-emit point for every family (DL-191). Emit a surgical
     * `channel_push` for the intent — UNLESS the serving channel has
     * `route_intents:true`, where the dispatcher already routes every staged intent
     * to the channel (DL-006) and a hand-emit would double-wake. The routed push
     * carries the same `$intent->toArray()` payload, so suppressing here loses no
     * wake: hand-emit ⟺ `route_intents:false`.
     *
     * @return list<ReactionTarget>
     */
    private function wakePush(Intent $intent, ClassifyContext $ctx): array
    {
        if ($ctx->agent->channel->routeIntents) {
            return [];
        }

        return [ReactionTarget::make(
            handler: 'channel_push',
            targetId: $intent->subjectId,
            debounceSeconds: 0,
            payload: $intent->toArray(),
        )];
    }

    // =====================================================================
    // Family: coord-message (the pre-#8 CoordinationClassifier behavior + §1)
    // =====================================================================

    private function coordMessageFamily(ClassifyContext $ctx, ClassifierConfig $cfg): ?ClassifyResult
    {
        if ($ctx->provider !== 'github') {
            return null; // coordination addressing convention is GitHub-only
        }

        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $agent = $ctx->agent;

        $subject = $this->subject($eventType, $payload, $cfg);
        if ($subject === null) {
            return null; // unhandled / contentless coordination event
        }

        // Noise drop (config-gated): a subject whose title contains EVERY substring
        // of any `drop_title_all_of` group is bookkeeping churn (e.g. a back-merge
        // paper-trail anchor) — drop it for everyone before the recipient gate. Empty
        // config ⇒ no-op.
        if ($this->titleMatchesDropGroup($subject['title'], $cfg)) {
            return null;
        }

        $labels = $this->labels($eventType, $payload);
        $me = $agent->agentName;

        // Recipient gate (DL-022): membership is label-authoritative. Which label
        // classes grant live-wake membership is config-driven via `wake_membership`
        // — DEFAULT narrow `[to_me, to_all]` (over-wake is the failure mode we guard
        // against; a coordinator opening many threads would else wake on every reply
        // to them). `from_me` is the opt-in for waking on all activity on your own
        // threads.
        //
        // A comment's body TO: line is three-state (RecipientAddressing::addresses):
        // it always NARROWS (a comment addressed to someone else denies membership),
        // and — only with the `comment_to` opt-in (DL-192) — a comment addressed TO
        // the agent GRANTS membership even when the thread labels don't, closing the
        // cross-thread pull-in gap (a loop-in on a thread the agent neither opened nor
        // was labelled on). The narrow is unconditional; the grant is opt-in.
        $membership = $cfg->strings('wake_membership', ['to_me', 'to_all']);
        $forMe = (in_array('to_me', $membership, true) && in_array("to:{$me}", $labels, true))
            || (in_array('to_all', $membership, true) && in_array('to:all', $labels, true))
            || (in_array('from_me', $membership, true) && in_array("from:{$me}", $labels, true));
        if ($subject['kind'] === 'comment') {
            $addressed = RecipientAddressing::addresses($subject['body'], $me);
            if ($addressed === true && in_array('comment_to', $membership, true)) {
                $forMe = true;   // directed TO:<self> grants membership (opt-in)
            } elseif ($addressed === false) {
                $forMe = false;  // addressed to someone else — narrow, unconditional
            }
        }
        if (! $forMe) {
            return null;
        }

        // §1 shared-identity author recovery (scope-map primary, label/body fallback).
        $reattributed = $this->reattribute($actor, $ctx->scopeId, $labels, $subject['body'], $subject['kind'] === 'comment', $cfg);
        $who = $this->displayName($reattributed, $actor);

        if ($subject['kind'] === 'comment') {
            $to = RecipientAddressing::recipients($subject['body']);   // list<string>|null
        } else {
            $to = $this->addressees($labels);                          // list<string>
        }
        $toStr = is_array($to) ? implode(',', $to) : '';

        $intent = new Intent(
            kind: 'coord_'.$subject['kind'],
            subjectId: (string) $subject['number'],
            provider: $ctx->provider,
            actor: $reattributed ?? $actor,
            summary: sprintf(
                'coord %s #%s from %s%s: %s',
                $subject['kind'],
                $subject['number'],
                $who,
                $toStr !== '' ? " to {$toStr}" : '',
                $this->oneLine($subject['title'] !== '' ? $subject['title'] : $subject['body']),
            ),
            payload: [
                'repo' => $ctx->scopeId,
                'event' => $eventType,
                'url' => $subject['url'],
                'comment_id' => $subject['comment_id'] ?? null,
                'comment_created_at' => $subject['comment_created_at'] ?? null,
                'labels' => $labels,
                'from' => $who,
                'to' => $to,
            ],
        );

        return new ClassifyResult(
            intents: [$intent],
            targets: $this->wakePush($intent, $ctx),
            reattributedActor: $reattributed,
        );
    }

    // =====================================================================
    // Family: impl-ci-wake (§3 — AIMLA push-landing + sola workflow_run gating)
    // =====================================================================
    //
    // Field-validated (roundtable #8; aimla second-install). Surgical: a wake-worthy
    // event gets an Intent + channel_push; a non-wake impl event returns null and is
    // GATE-DROPPED by the dispatcher (no intent — the InboxOnly base is empty for a
    // GitHub event), never a live wake and never an inbox row.

    private function implCiWakeFamily(ClassifyContext $ctx, ClassifierConfig $cfg): ?ClassifyResult
    {
        if ($ctx->provider !== 'github') {
            return null; // push / workflow_run CI convention is GitHub-only
        }

        // impl_repos gate (optional): when non-empty, wake only for these scopes;
        // empty/absent ⇒ every subscribed repo (v0.50.0 back-compat). Keys on the
        // already-available scopeId — a PM scopes the wake to its impl-repo subset so
        // a coord-repo push/CI event doesn't self-wake.
        $implRepos = $cfg->strings('impl_repos');
        if ($implRepos !== [] && ! in_array(strtolower($ctx->scopeId), $implRepos, true)) {
            return null;
        }

        $eventType = $ctx->eventType;
        $payload = $ctx->payload;

        $signal = null;   // [kind, ref, tail, url, extra]
        if ($eventType === 'push') {
            $signal = $this->pushSignal($payload, $cfg);
        } elseif (str_starts_with($eventType, 'workflow_run.')) {
            $signal = $this->workflowRunSignal($payload, $cfg);
        }

        if ($signal !== null) {
            // Wake-worthy event → surgical channel_push (suppressed on a route_intents
            // channel by wakePush(), DL-191). The Intent is staged either way.
            $intent = $this->makeImplIntent($signal, $ctx, $cfg);

            return new ClassifyResult(intents: [$intent], targets: $this->wakePush($intent, $ctx));
        }

        // Non-wake terminal impl event. DEFAULT `drop` = gate-drop (lean inbox — no
        // intent). `impl_non_wake_disposition: inbox_stage` keeps a broad CI/push
        // SessionStart history: build a normal Intent with NO channel_push (a
        // broad-wake install routes it via the channel's route_intents; a surgical
        // install stays on `drop` and never reaches here).
        if ($cfg->string('impl_non_wake_disposition', 'drop') !== 'inbox_stage') {
            return null;
        }
        $staged = null;
        if ($eventType === 'push') {
            $staged = $this->pushInboxSignal($payload);
        } elseif (str_starts_with($eventType, 'workflow_run.')) {
            $staged = $this->workflowRunInboxSignal($payload);
        }
        if ($staged === null) {
            return null; // a branch-delete push / a non-terminal workflow_run is not inbox-worthy
        }

        return new ClassifyResult(intents: [$this->makeImplIntent($staged, $ctx, $cfg)], targets: []);
    }

    /**
     * Build the impl Intent shared by the wake path and the inbox_stage path. The
     * actor is re-attributed via the scope→author map (§1) — the wake identity is
     * the author AGENT (by name), NEVER the raw pusher github id. Invariant: a
     * wake-purposed agent config must not set `identity.github_user_id`, or the
     * dispatcher's pre-classify echo gate would drop the agent's own-repo landing.
     *
     * @param  array{kind:string,ref:string,tail:string,url:string,extra:array<string,mixed>}  $signal
     */
    private function makeImplIntent(array $signal, ClassifyContext $ctx, ClassifierConfig $cfg): Intent
    {
        $reattributed = $this->reattribute($ctx->actor, $ctx->scopeId, [], '', false, $cfg);
        $who = $this->displayName($reattributed, $ctx->actor);

        return new Intent(
            kind: 'impl_'.$signal['kind'],
            subjectId: $signal['ref'],
            provider: $ctx->provider,
            actor: $ctx->actor,
            summary: sprintf('impl %s on %s (%s): %s', $signal['kind'], $ctx->scopeId, $who, $signal['tail']),
            payload: array_merge([
                'repo' => $ctx->scopeId,
                'event' => $ctx->eventType,
                'url' => $signal['url'],
                'from' => $who,
            ], $signal['extra']),
        );
    }

    /**
     * A push to the release branch = release-landed (aimla's push-landing wake).
     * Non-release-branch pushes and branch-delete pushes are not wake-worthy. The
     * subjectId is the landed commit SHA (aimla's plane-5 SHA-chain keys on the
     * commit, not the branch name); `head_sha` + `commit_count` ride the payload.
     *
     * @param  array<mixed>  $payload
     * @return array{kind:string,ref:string,tail:string,url:string,extra:array<string,mixed>}|null
     */
    private function pushSignal(array $payload, ClassifierConfig $cfg): ?array
    {
        $ref = is_scalar($payload['ref'] ?? null) ? (string) $payload['ref'] : '';
        $releaseBranch = $cfg->string('release_branch', 'main');
        if ($ref !== "refs/heads/{$releaseBranch}") {
            return null;
        }
        // A branch DELETE arrives as a push with deleted=true — not a landing.
        if (($payload['deleted'] ?? false) === true) {
            return null;
        }
        $repo = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : [];
        $headSha = is_scalar($payload['after'] ?? null) ? (string) $payload['after']
            : (is_scalar($head['id'] ?? null) ? (string) $head['id'] : '');
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

        return [
            'kind' => 'release_landed',
            'ref' => $headSha !== '' ? $headSha : $releaseBranch,   // subjectId = the landed commit; branch as fallback
            'tail' => $this->oneLine(is_scalar($head['message'] ?? null) ? (string) $head['message'] : $releaseBranch),
            'url' => is_scalar($head['url'] ?? null) ? (string) $head['url']
                : (is_scalar($repo['html_url'] ?? null) ? (string) $repo['html_url'] : ''),
            'extra' => [
                'branch' => $releaseBranch,
                'head_sha' => $headSha,
                'commit_count' => count($commits),
            ],
        ];
    }

    /**
     * A completed workflow_run that wakes: any conclusion NOT in the benign set
     * (fail-loud CI-failure oversight), OR a name-matched provenance workflow that
     * SUCCEEDED (archival cue — sola's SLSA/Auto-tag). A benign, non-provenance
     * conclusion is not wake-worthy. The failure wake is optionally narrowed to a
     * workflow-NAME allow-list (`ci_failure_workflow_patterns`, DL-197) — empty ⇒ any
     * workflow; the narrowing is on workflow NAME only, never on conclusion.
     *
     * @param  array<mixed>  $payload
     * @return array{kind:string,ref:string,tail:string,url:string,extra:array<string,mixed>}|null
     */
    private function workflowRunSignal(array $payload, ClassifierConfig $cfg): ?array
    {
        $run = is_array($payload['workflow_run'] ?? null) ? $payload['workflow_run'] : [];
        if (($run['status'] ?? null) !== 'completed') {
            return null; // only terminal runs
        }
        $name = is_scalar($run['name'] ?? null) ? (string) $run['name'] : '';
        $concl = strtolower(is_scalar($run['conclusion'] ?? null) ? (string) $run['conclusion'] : '');
        $url = is_scalar($run['html_url'] ?? null) ? (string) $run['html_url'] : '';
        $runId = is_scalar($run['id'] ?? null) ? (string) $run['id'] : $name;

        // Fail-LOUD: wake on ANY conclusion not in the benign set — so a new/unknown
        // GitHub conclusion (e.g. a future `action_required`, `stale`) is surfaced,
        // never silently dropped (both sola + aimla gated the PR on this — an
        // allow-set fails silent on a conclusion it doesn't enumerate).
        $benign = $cfg->strings('benign_conclusions', self::DEFAULT_BENIGN_CONCLUSIONS);
        if (! in_array($concl, $benign, true)) {
            // Optional workflow-NAME filter on the FAILURE wake (DL-197). Empty
            // (default) ⇒ any workflow's failure wakes (byte-identical). Non-empty ⇒
            // only a name-matched workflow wakes — narrows WHICH workflows, never
            // WHICH conclusions (the fail-loud gate above is untouched: for a matched
            // workflow, EVERY non-benign conclusion still wakes). A run with NO name
            // ($name === '') is never filtered — it can't be matched against the
            // allow-list, so it wakes fail-loud, preserving the pre-filter behavior
            // for a malformed payload. A filtered-out failure becomes a NON-wake run
            // (drop / inbox_stage per `impl_non_wake_disposition`), same as a benign run.
            $failurePatterns = $cfg->strings('ci_failure_workflow_patterns');
            if ($failurePatterns !== [] && $name !== '' && ! $this->nameMatchesAnyPattern($name, $failurePatterns)) {
                return null;
            }

            return ['kind' => 'ci_failed', 'ref' => $runId, 'tail' => "{$name} → {$concl}", 'url' => $url, 'extra' => []];
        }

        // A benign conclusion — only a name-matched provenance SUCCESS is a (positive)
        // wake. Patterns are sola-specific config (e.g. SLSA / Auto-tag); absent ⇒
        // no provenance wake.
        if ($concl === 'success' && $this->nameMatchesAnyPattern($name, $cfg->strings('provenance_patterns'))) {
            return ['kind' => 'provenance_ok', 'ref' => $runId, 'tail' => "{$name} → success", 'url' => $url, 'extra' => []];
        }

        return null;
    }

    /**
     * Case-insensitive substring match of a workflow-run NAME against a pattern list.
     * Patterns come from {@see ClassifierConfig::strings} → already lowercased and
     * guaranteed non-empty (parseStringList rejects empty entries), so no per-entry
     * empty guard is needed. Empty list ⇒ false. One matcher, two callers: the
     * CI-failure name filter (DL-197) and the provenance-success cue.
     *
     * @param  list<string>  $patterns
     */
    private function nameMatchesAnyPattern(string $name, array $patterns): bool
    {
        $lower = strtolower($name);
        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A non-wake push staged for the broad inbox (`impl_non_wake_disposition:
     * inbox_stage`): any non-delete push on any branch. A branch-delete carries no
     * landed content, so it is not staged. A release-branch push never reaches here —
     * it is wake-worthy via {@see pushSignal}.
     *
     * @param  array<mixed>  $payload
     * @return array{kind:string,ref:string,tail:string,url:string,extra:array<string,mixed>}|null
     */
    private function pushInboxSignal(array $payload): ?array
    {
        if (($payload['deleted'] ?? false) === true) {
            return null;
        }
        $ref = is_scalar($payload['ref'] ?? null) ? (string) $payload['ref'] : '';
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
        $repo = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : [];
        $headSha = is_scalar($payload['after'] ?? null) ? (string) $payload['after']
            : (is_scalar($head['id'] ?? null) ? (string) $head['id'] : '');
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

        return [
            'kind' => 'push',
            'ref' => $headSha !== '' ? $headSha : ($branch !== '' ? $branch : 'push'),
            'tail' => $this->oneLine(is_scalar($head['message'] ?? null) ? (string) $head['message'] : $branch),
            'url' => is_scalar($head['url'] ?? null) ? (string) $head['url']
                : (is_scalar($repo['html_url'] ?? null) ? (string) $repo['html_url'] : ''),
            'extra' => [
                'branch' => $branch,
                'head_sha' => $headSha,
                'commit_count' => count($commits),
            ],
        ];
    }

    /**
     * A non-wake completed workflow_run staged for the broad inbox (inbox_stage).
     * Only TERMINAL (completed) runs are staged; a non-terminal run is filtered,
     * same as the wake path. Wake-worthy completed runs (failure / provenance success)
     * normally do not reach here — they are handled by {@see workflowRunSignal} — EXCEPT
     * a FAILURE whose workflow name is excluded by `ci_failure_workflow_patterns`
     * (DL-197): it becomes a non-wake run and is inbox-staged here like any benign run
     * (conclusion-agnostic — the `{name} → {concl}` tail carries whatever conclusion it has).
     *
     * @param  array<mixed>  $payload
     * @return array{kind:string,ref:string,tail:string,url:string,extra:array<string,mixed>}|null
     */
    private function workflowRunInboxSignal(array $payload): ?array
    {
        $run = is_array($payload['workflow_run'] ?? null) ? $payload['workflow_run'] : [];
        if (($run['status'] ?? null) !== 'completed') {
            return null;
        }
        $name = is_scalar($run['name'] ?? null) ? (string) $run['name'] : '';
        $concl = strtolower(is_scalar($run['conclusion'] ?? null) ? (string) $run['conclusion'] : '');
        $url = is_scalar($run['html_url'] ?? null) ? (string) $run['html_url'] : '';
        $runId = is_scalar($run['id'] ?? null) ? (string) $run['id'] : $name;

        return [
            'kind' => 'ci',
            'ref' => $runId,
            'tail' => $concl !== '' ? "{$name} → {$concl}" : $name,
            'url' => $url,
            'extra' => [],
        ];
    }

    // =====================================================================
    // Family: kanban-triage (DL-168 — wake the triage owner on a human-filed,
    // untriaged card; folded in from the retired standalone KanbanTriageClassifier)
    // =====================================================================

    /**
     * A card a HUMAN filed directly on the board that is still UNTRIAGED wakes the
     * serving (triage-owner) session via a channel_push — instead of waiting for the
     * SessionStart untriaged-snapshot at the next session. Everything else (agent-
     * filed, already-classified, non-`task.created`) stays inbox-only: the `new_card`
     * Intent the InboxOnly parent already produced in `$base`.
     *
     * The wake fires ONLY for a `task.created` that is:
     *   - human-filed — the actor is NOT a known agent, and
     *   - untriaged — no `triaged` tag, no `id:pr:*` tag, no `dl` external reference.
     *
     * No-self-wake — each automated creator is suppressed by a DIFFERENT mechanism,
     * NOT a single `isKnownAgent` check (which only covers registered agents):
     *   - the bridge's OWN dependabot-card creations carry `triaged` at create
     *     (DL-024) → dropped by the untriaged filter (the bridge's only card-CREATE
     *     path; the writeback move path only moves existing cards);
     *   - the dedicated writeback `identity_id` user's events are dropped PRE-classify
     *     by the dispatcher global-echo gate (`DispatchService`/`globalEchoIds`);
     *   - the poll adapter's auto-`triaged` backstops carry `triaged`.
     *
     * The filter reads the DL-164 `card` snapshot the `task.created` webhook carries,
     * so it runs at classify time with NO API call and NO read token. On a kanban that
     * predates the snapshot, `card` is absent → reads untriaged → wakes (SessionStart
     * snapshot is the durable backstop, so an over-wake is at worst minor noise, never
     * a miss).
     */
    private function kanbanTriageFamily(ClassifyContext $ctx, ClassifyResult $base): ?ClassifyResult
    {
        if ($ctx->provider !== 'kanban'
            || $ctx->eventType !== 'task.created'
            || $base->intents === []
            || $ctx->actor->isKnownAgent          // registered agents only; bridge/dependabot/writeback suppressed elsewhere (see docblock)
            || $this->isAlreadyClassified($ctx->payload)) {
            return null;
        }

        // targetId === the base new_card Intent's subjectId so the dispatcher's
        // silent-drop guard never warns; payload is that Intent's wire shape (the
        // handler sends {"intent": <toArray()>}); transport is the agent's cfg-default
        // channel — so the triage owner IS the recipient by configuration. Suppressed
        // on a route_intents channel (DL-191) — the base new_card intent routes there.
        return new ClassifyResult(
            targets: $this->wakePush($base->intents[0], $ctx),
        );
    }

    /**
     * Whether the new card is ALREADY classified — a `triaged` tag, an `id:pr:*` tag,
     * or a `dl` external reference — read from the DL-164 `card` snapshot on the
     * `task.created` webhook (no API call). Such a card is not a fresh human triage
     * item, so it must NOT wake the triage owner.
     *
     * @param  array<mixed>  $payload
     */
    private function isAlreadyClassified(array $payload): bool
    {
        $card = is_array($payload['card'] ?? null) ? $payload['card'] : [];

        $tags = is_array($card['tags'] ?? null) ? $card['tags'] : [];
        foreach ($tags as $tag) {
            if ($tag === 'triaged' || (is_string($tag) && str_starts_with($tag, 'id:pr:'))) {
                return true;
            }
        }

        $refs = is_array($card['external_references'] ?? null) ? $card['external_references'] : [];
        foreach ($refs as $ref) {
            if (is_array($ref) && ($ref['system'] ?? null) === 'dl') {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // Family: coord-card-create (DL-198 — real-time coordination issue → card)
    // =====================================================================
    //
    // A coordination issue opened/reopened with a recognized `[PREFIX]` title gets a
    // tracking card CREATED in real time (the bridge is the primary mover; the
    // periodic reconcile is the backstop that adopts it by the `id:<sid>` tag). Runs
    // INDEPENDENTLY of the coord-message recipient gate — it cards EVERY
    // recognized-prefix issue on the coord repo (board-level, not addressed-to-me);
    // its own gate is prefix-recognized AND the repo's `writeback.json` mapping has
    // `create_coord_cards`. Emits ONE writeback target, NO intent, NO wake.

    private function coordCardCreateFamily(ClassifyContext $ctx): ?ClassifyResult
    {
        if ($ctx->provider !== 'github') {
            return null; // coordination issues are GitHub-only
        }
        if ($ctx->eventType !== 'issues.opened' && $ctx->eventType !== 'issues.reopened') {
            return null; // opened + reopened only (a pre-ship issue backfills on its next reopen)
        }
        $issue = is_array($ctx->payload['issue'] ?? null) ? $ctx->payload['issue'] : null;
        if ($issue === null || ! is_numeric($issue['number'] ?? null)) {
            return null;
        }
        $num = (int) $issue['number'];
        $title = is_string($issue['title'] ?? null) ? $issue['title'] : '';

        $sid = $this->stableId($title, $num);
        if ($sid === null) {
            return null; // un-prefixed / PROPOSAL / unrecognized → not carded (definitional skip)
        }

        // Own gate: the repo must opt into coord-card creation. Loaded like the
        // PR-move classifier does — absent mapping / opt-out ⇒ byte-identical no-op.
        $configDir = (string) config('bridge.config_dir');
        $writeback = $configDir !== '' ? WritebackConfig::load($configDir) : null;
        $mapping = $writeback?->mappingFor($ctx->scopeId);
        if ($mapping === null || ! $mapping->createCoordCards) {
            return null;
        }

        $itype = strtolower(explode('-', $sid, 2)[0]);   // sid = "<PREFIX_UPPER>-<num>" → itype
        $url = is_string($issue['html_url'] ?? null) ? $issue['html_url'] : '';

        return new ClassifyResult(targets: [
            ReactionTarget::make('kanban_coord_card', "issue-{$num}", payload: [
                'repo' => $ctx->scopeId,
                'issue_number' => $num,
                'sid' => $sid,
                'itype' => $itype,
                'title' => $title,
                'issue_url' => $url,
            ]),
        ]);
    }

    /**
     * The card adoption key `sid` (DL-198) — byte-exact to the reconcile's Python
     * `_stable_id` recognized-prefix branch: an anchored, case-insensitive `[PREFIX]`
     * at the START of the trimmed title (`PREFIX ∈ {BRIEF, ANNOUNCE, QUERY, REVIEW,
     * TASK}`), emitting `"<PREFIX_UPPER>-<num>"` (un-padded). NO trailing boundary —
     * `[QUERY]x` matches → `QUERY-<num>` (the reconcile does too; a `(?=\s|$)` guard
     * would make PHP MORE restrictive and orphan a card the reconcile creates).
     * `trim()` mirrors Python's `.strip()`. Un-prefixed / PROPOSAL / unrecognized →
     * null → NOT carded (the create-set equals the reconcile's own-prefix set, so a
     * carded issue is always one the periodic pass backstops).
     */
    private function stableId(string $title, int $num): ?string
    {
        if (preg_match('/^\[(BRIEF|ANNOUNCE|QUERY|REVIEW|TASK)\]/i', trim($title), $m) === 1) {
            return strtoupper($m[1]).'-'.$num;
        }

        return null;
    }

    // =====================================================================
    // Shared mechanics
    // =====================================================================

    /**
     * Map the event family to a normalized subject, or null if not a handled
     * "new coordination message" event.
     *
     * @param  array<mixed>  $payload
     * @return array{kind:string,number:int|string,title:string,body:string,url:string,comment_id?:int|string|null,comment_created_at?:string|null}|null
     */
    private function subject(string $eventType, array $payload, ClassifierConfig $cfg): ?array
    {
        // Per-install allow-list extension: `coord_extra_actions: { pull_request:
        // [synchronize] }` surfaces additional actions beyond the fail-safe default.
        // An unlisted action still returns null — a new GitHub action never
        // auto-surfaces (allow-list, not deny-list).
        $extra = $cfg->stringListMap('coord_extra_actions');
        foreach (self::HANDLED as $prefix => $actions) {
            if (! str_starts_with($eventType, $prefix)) {
                continue;
            }
            $action = substr($eventType, strlen($prefix));
            $allowed = array_merge($actions, $extra[rtrim($prefix, '.')] ?? []);
            if (! in_array($action, $allowed, true)) {
                return null;
            }

            if ($prefix === 'issue_comment.') {
                $comment = $payload['comment'] ?? null;
                $issue = $payload['issue'] ?? null;
                if (! is_array($comment) || ! is_array($issue)) {
                    return null;
                }

                return [
                    'kind' => 'comment',
                    'number' => $issue['number'] ?? '',
                    'title' => (string) ($issue['title'] ?? ''),
                    'body' => (string) ($comment['body'] ?? ''),
                    'url' => (string) ($comment['html_url'] ?? ''),
                    'comment_id' => $comment['id'] ?? null,
                    'comment_created_at' => isset($comment['created_at']) ? (string) $comment['created_at'] : null,
                ];
            }

            $key = $prefix === 'pull_request.' ? 'pull_request' : 'issue';
            $obj = $payload[$key] ?? null;
            if (! is_array($obj)) {
                return null;
            }

            return [
                'kind' => $key === 'pull_request' ? 'pr' : 'issue',
                'number' => $obj['number'] ?? ($payload['number'] ?? ''),
                'title' => (string) ($obj['title'] ?? ''),
                'body' => (string) ($obj['body'] ?? ''),
                'url' => (string) ($obj['html_url'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * The parent issue's / PR's labels, lowercased.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function labels(string $eventType, array $payload): array
    {
        $container = str_starts_with($eventType, 'pull_request.')
            ? ($payload['pull_request'] ?? [])
            : ($payload['issue'] ?? []);
        $labels = is_array($container) ? ($container['labels'] ?? []) : [];

        $out = [];
        if (is_array($labels)) {
            foreach ($labels as $label) {
                $name = is_array($label) ? ($label['name'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $out[] = strtolower($name);
                }
            }
        }

        return $out;
    }

    /**
     * Recover the author for a shared-identity event (Actor.name === null), else
     * null (distinct account — pre-classify echo already handled it).
     *
     * §1 order: (a) `scope_author_map[scope]` — a single-author repo, so a
     * LABEL-LESS impl event still attributes; else (b) the `from:<agent>` label;
     * else (c) the body `FROM:` line. For a COMMENT the body FROM: wins over the
     * label (DL-034 — the from: label is the frozen thread-opener).
     *
     * @param  list<string>  $labels
     */
    private function reattribute(Actor $actor, string $scopeId, array $labels, string $body, bool $preferBody, ClassifierConfig $cfg): ?Actor
    {
        if ($actor->name !== null) {
            return null;
        }

        // (a) scope→author-agent map (single-author repo) — the §1 superset.
        $mapped = $cfg->scopeAuthorMap[strtolower($scopeId)] ?? null;

        $fromLabel = null;
        foreach ($labels as $label) {
            if (str_starts_with($label, 'from:')) {
                $fromLabel = substr($label, 5);
                break;
            }
        }
        $fromLabel = ($fromLabel === '') ? null : $fromLabel;

        $fromBody = preg_match('/^\s*FROM:\s*(\S+)/mi', $body, $m) === 1
            ? strtolower($m[1])
            : null;

        // scope-map is primary (resolves label-less impl events, and a
        // single-author repo has an unambiguous author); label/body only apply on
        // a multi-author repo (no map entry), where a comment prefers the body.
        $labelOrBody = $preferBody
            ? ($fromBody ?? $fromLabel)
            : ($fromLabel ?? $fromBody);
        $from = $mapped ?? $labelOrBody;

        if ($from === null || $from === '') {
            return null;
        }

        return new Actor(id: $actor->id, name: $from, isKnownAgent: true);
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function addressees(array $labels): array
    {
        $to = [];
        foreach ($labels as $label) {
            if (str_starts_with($label, 'to:')) {
                $to[] = substr($label, 3);
            }
        }

        return $to;
    }

    /**
     * The human display name for an event's author: the recovered (re-attributed)
     * name when a shared identity was resolved, else the actor's own name/id,
     * else '?'. Never null.
     */
    private function displayName(?Actor $reattributed, Actor $actor): string
    {
        if ($reattributed !== null && $reattributed->name !== null) {
            return $reattributed->name;
        }

        return $actor->name ?? $actor->id ?? '?';
    }

    /**
     * Whether a subject title matches any `drop_title_all_of` group — it contains
     * every (case-insensitive) substring of that group. Empty config ⇒ false (no
     * subject is dropped). Substrings are pre-lowercased by the accessor.
     */
    private function titleMatchesDropGroup(string $title, ClassifierConfig $cfg): bool
    {
        $haystack = strtolower($title);
        foreach ($cfg->stringGroups('drop_title_all_of') as $group) {
            $matchesAll = true;
            foreach ($group as $needle) {
                if (! str_contains($haystack, $needle)) {
                    $matchesAll = false;
                    break;
                }
            }
            if ($matchesAll) {
                return true;
            }
        }

        return false;
    }

    private function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137).'...' : $text;
    }
}
