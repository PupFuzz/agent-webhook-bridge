<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\ClassifierConfig;
use App\Bridge\Support\RecipientAddressing;

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
 * Event families (config-gated via `classifier.config.families`; DEFAULT
 * `['coord-message']` so an install that doesn't opt in behaves EXACTLY as the
 * pre-#8 reference — back-compat):
 *   - `coord-message` — github issues/issue_comment/pull_request coordination
 *     messages, addressed per DL-022, shared-identity re-attributed.
 *   - `impl-ci-wake`  — github push→release-branch (release-landed) + workflow_run
 *     wake-worthy CI (failure-class conclusions, provenance-success). Surgical:
 *     hand-emits a channel_push ONLY for wake-worthy events, else inbox-only.
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
class CoordinationClassifier implements Classifier
{
    /** event_type prefix → the body-actions that represent a NEW coordination message worth waking on. */
    private const HANDLED = [
        'issues.' => ['opened', 'reopened'],
        'issue_comment.' => ['created'],
        'pull_request.' => ['opened', 'reopened', 'ready_for_review'],
    ];

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
        // Only GitHub events carry the coordination addressing / CI convention.
        if ($ctx->provider !== 'github') {
            return new ClassifyResult;
        }

        $cfg = $ctx->agent->classifierConfig;
        $families = $cfg->enabledFamilies !== [] ? $cfg->enabledFamilies : self::DEFAULT_FAMILIES;

        // Run each enabled family; merge results into one ClassifyResult. Families
        // handle disjoint event types (coord: issues/issue_comment/pull_request;
        // impl-ci: push/workflow_run), so at most one contributes per event — but
        // the merge is order-independent and additive regardless.
        $intents = [];
        $targets = [];
        $reattributed = null;
        foreach ($families as $family) {
            $result = match ($family) {
                'coord-message' => $this->coordMessageFamily($ctx, $cfg),
                'impl-ci-wake' => $this->implCiWakeFamily($ctx, $cfg),
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

    // =====================================================================
    // Family: coord-message (the pre-#8 CoordinationClassifier behavior + §1)
    // =====================================================================

    private function coordMessageFamily(ClassifyContext $ctx, ClassifierConfig $cfg): ?ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $agent = $ctx->agent;

        $subject = $this->subject($eventType, $payload);
        if ($subject === null) {
            return null; // unhandled / contentless coordination event
        }

        $labels = $this->labels($eventType, $payload);
        $me = $agent->agentName;

        // Recipient gate (DL-022): membership is label-authoritative (to:{me} /
        // to:all / from:{me}); a comment's TO: line NARROWS within membership.
        $forMe = in_array("to:{$me}", $labels, true)
            || in_array('to:all', $labels, true)
            || in_array("from:{$me}", $labels, true);
        if ($subject['kind'] === 'comment'
            && RecipientAddressing::addresses($subject['body'], $me) === false) {
            $forMe = false;
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
            targets: [ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            )],
            reattributedActor: $reattributed,
        );
    }

    // =====================================================================
    // Family: impl-ci-wake (§3 — AIMLA push-landing + sola workflow_run gating)
    // =====================================================================
    //
    // STRAWMAN pending aimla's field data (wake-conclusions + push-detection) — the
    // defaults below are the safe starting set; every knob is config. Surgical:
    // a wake-worthy event gets an Intent + channel_push; a non-wake impl event
    // returns null (staged to inbox by the dispatcher, never a live wake).

    private function implCiWakeFamily(ClassifyContext $ctx, ClassifierConfig $cfg): ?ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;

        $signal = null;   // [kind, summaryTail, url]
        if ($eventType === 'push') {
            $signal = $this->pushSignal($payload, $cfg);
        } elseif (str_starts_with($eventType, 'workflow_run.')) {
            $signal = $this->workflowRunSignal($payload, $cfg);
        }
        if ($signal === null) {
            return null; // non-wake impl event → inbox-only (dispatcher stages it)
        }

        $reattributed = $this->reattribute($ctx->actor, $ctx->scopeId, [], '', false, $cfg);
        $who = $this->displayName($reattributed, $ctx->actor);

        $intent = new Intent(
            kind: 'impl_'.$signal['kind'],
            subjectId: $signal['ref'],
            provider: $ctx->provider,
            actor: $ctx->actor,
            summary: sprintf('impl %s on %s (%s): %s', $signal['kind'], $ctx->scopeId, $who, $signal['tail']),
            payload: array_merge([
                'repo' => $ctx->scopeId,
                'event' => $eventType,
                'url' => $signal['url'],
                'from' => $who,
            ], $signal['extra']),
        );

        return new ClassifyResult(
            intents: [$intent],
            targets: [ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            )],
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
     * conclusion is not wake-worthy.
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
            return ['kind' => 'ci_failed', 'ref' => $runId, 'tail' => "{$name} → {$concl}", 'url' => $url, 'extra' => []];
        }

        // A benign conclusion — only a name-matched provenance SUCCESS is a (positive)
        // wake. Patterns are sola-specific config (e.g. SLSA / Auto-tag); absent ⇒
        // no provenance wake.
        if ($concl === 'success') {
            foreach ($cfg->strings('provenance_patterns') as $pattern) {
                if ($pattern !== '' && str_contains(strtolower($name), $pattern)) {
                    return ['kind' => 'provenance_ok', 'ref' => $runId, 'tail' => "{$name} → success", 'url' => $url, 'extra' => []];
                }
            }
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
    private function subject(string $eventType, array $payload): ?array
    {
        foreach (self::HANDLED as $prefix => $actions) {
            if (! str_starts_with($eventType, $prefix)) {
                continue;
            }
            $action = substr($eventType, strlen($prefix));
            if (! in_array($action, $actions, true)) {
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

    private function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137).'...' : $text;
    }
}
