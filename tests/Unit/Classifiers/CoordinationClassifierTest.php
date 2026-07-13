<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use Tests\TestCase;

class CoordinationClassifierTest extends TestCase
{
    private CoordinationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new CoordinationClassifier;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<mixed>  $classifierConfig
     */
    private function classify(string $eventType, array $payload, string $scopeId, string $me = 'me', array $classifierConfig = [], ?string $actorName = null)
    {
        $agent = AgentConfig::fromArray($me, [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => array_merge(['class' => CoordinationClassifier::class], $classifierConfig === [] ? [] : ['config' => $classifierConfig]),
        ]);

        // Shared identity ⇒ Actor.name is null (the whole point of re-attribution).
        return $this->classifier->classify(new ClassifyContext(
            $eventType, $payload, new Actor(id: '99', name: $actorName, isKnownAgent: false), 'github', $scopeId, $agent,
        ));
    }

    /** @param list<array{name:string}>|list<string> $labelNames */
    private function issue(int $number, array $labelNames, string $body = '', string $title = 'T'): array
    {
        $labels = array_map(fn ($n) => ['name' => $n], $labelNames);

        return ['issue' => ['number' => $number, 'title' => $title, 'body' => $body, 'labels' => $labels, 'html_url' => 'https://x/'.$number]];
    }

    // ---- coord-message family (default; back-compat) ----

    public function test_coord_message_to_me_surfaces_and_reattributes(): void
    {
        $r = $this->classify('issues.opened', $this->issue(4, ['to:me', 'from:other'], 'FROM: other'), 'org/coord');

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_issue', $r->intents[0]->kind);
        $this->assertCount(1, $r->targets);                        // paired channel_push
        $this->assertSame('other', $r->reattributedActor?->name);  // recovered from the from: label
    }

    public function test_coord_message_not_addressed_to_me_is_dropped(): void
    {
        $r = $this->classify('issues.opened', $this->issue(4, ['to:someone-else', 'from:other']), 'org/coord');

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
        $this->assertNull($r->reattributedActor);
    }

    public function test_distinct_account_is_not_reattributed(): void
    {
        // Actor.name non-null ⇒ dedicated account ⇒ pre-classify echo handles it.
        $r = $this->classify('issues.opened', $this->issue(4, ['to:me']), 'org/coord', actorName: 'aimla-pm');

        $this->assertCount(1, $r->intents);
        $this->assertNull($r->reattributedActor);
    }

    // ---- family gating ----

    public function test_impl_ci_family_off_by_default(): void
    {
        // A push with the DEFAULT families (coord-message only) ⇒ no reaction.
        $r = $this->classify('push', ['ref' => 'refs/heads/main', 'head_commit' => ['message' => 'x']], 'org/impl');

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    // ---- impl-ci-wake family (opt-in) ----

    /** @return array<mixed> */
    private function implConfig(array $extra = []): array
    {
        return ['families' => ['impl-ci-wake'], 'scope_author_map' => ['org/impl' => 'device']] + $extra;
    }

    public function test_push_to_release_branch_wakes_release_landed(): void
    {
        $r = $this->classify('push', [
            'ref' => 'refs/heads/main',
            'after' => 'abc1234',
            'head_commit' => ['message' => 'Merge #9', 'id' => 'abc1234', 'url' => 'https://c/1'],
            'commits' => [['id' => 'abc1234'], ['id' => 'def5678']],
        ], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_release_landed', $r->intents[0]->kind);
        $this->assertSame('abc1234', $r->intents[0]->subjectId);          // subjectId = landed commit sha (aimla's SHA-chain key)
        $this->assertSame('abc1234', $r->intents[0]->payload['head_sha']);
        $this->assertSame(2, $r->intents[0]->payload['commit_count']);
        $this->assertSame('device', $r->intents[0]->payload['from']);     // §1 scope-map on a label-less event
        $this->assertCount(1, $r->targets);
    }

    public function test_push_to_non_release_branch_does_not_wake(): void
    {
        $r = $this->classify('push', ['ref' => 'refs/heads/feature-x', 'head_commit' => ['message' => 'wip']], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
    }

    public function test_branch_delete_push_does_not_wake(): void
    {
        $r = $this->classify('push', ['ref' => 'refs/heads/main', 'deleted' => true], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
    }

    public function test_workflow_run_failure_wakes(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5, 'html_url' => 'https://r/5']], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_workflow_run_success_non_provenance_does_not_wake(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'CI', 'id' => 6]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
    }

    public function test_workflow_run_cancelled_is_benign_no_wake(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'cancelled', 'name' => 'CI', 'id' => 6]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
    }

    public function test_workflow_run_unknown_conclusion_wakes_fail_loud(): void
    {
        // A new/unknown GitHub conclusion is NOT in the benign set ⇒ wakes (fail-loud);
        // the pre-fix allow-set would have silently dropped it.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'action_required', 'name' => 'CI', 'id' => 6]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_workflow_run_provenance_success_wakes(): void
    {
        $r = $this->classify(
            'workflow_run.completed',
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'SLSA provenance', 'id' => 7]],
            'org/impl',
            classifierConfig: $this->implConfig(['provenance_patterns' => ['slsa']]),
        );

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_provenance_ok', $r->intents[0]->kind);
    }

    public function test_incomplete_workflow_run_does_not_wake(): void
    {
        $r = $this->classify('workflow_run.requested', ['workflow_run' => ['status' => 'in_progress', 'name' => 'CI', 'id' => 8]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
    }

    // ---- impl-ci-wake: impl_repos gate (R1, DL-189) ----

    public function test_impl_repos_gate_wakes_when_scope_in_list(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['impl_repos' => ['org/impl', 'org/other']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_impl_repos_gate_drops_when_scope_not_in_list(): void
    {
        // A wake-worthy event on a repo OUTSIDE the configured subset is gate-dropped
        // (e.g. a coord-repo push/CI event a PM doesn't want to self-wake on).
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/coord', classifierConfig: $this->implConfig(['impl_repos' => ['org/impl']]));

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_impl_repos_gate_is_case_insensitive(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'ORG/Impl', classifierConfig: $this->implConfig(['impl_repos' => ['org/impl']]));

        $this->assertCount(1, $r->intents);
    }

    public function test_impl_repos_empty_wakes_all_subscribed(): void
    {
        // Absent impl_repos ⇒ v0.50.0 back-compat: every subscribed repo wakes.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/anything', classifierConfig: $this->implConfig());

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    // ---- coord-message: drop_title_all_of noise filter (R1, DL-189) ----

    public function test_drop_title_all_of_drops_when_all_substrings_present(): void
    {
        $r = $this->classify(
            'issues.opened',
            $this->issue(9, ['to:me'], '', 'Rule E back-merge sync — paper-trail anchor for v0.50.0'),
            'org/coord',
            classifierConfig: ['drop_title_all_of' => [['Rule E back-merge sync', 'paper-trail anchor']]],
        );

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_drop_title_all_of_keeps_when_only_partial_match(): void
    {
        // Only one of the two substrings present ⇒ NOT a match ⇒ kept + surfaced.
        $r = $this->classify(
            'issues.opened',
            $this->issue(9, ['to:me'], '', 'Rule E back-merge sync completed'),
            'org/coord',
            classifierConfig: ['drop_title_all_of' => [['Rule E back-merge sync', 'paper-trail anchor']]],
        );

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_issue', $r->intents[0]->kind);
    }

    public function test_drop_title_all_of_is_case_insensitive(): void
    {
        $r = $this->classify(
            'issues.opened',
            $this->issue(9, ['to:me'], '', 'RULE E BACK-MERGE SYNC — PAPER-TRAIL ANCHOR'),
            'org/coord',
            classifierConfig: ['drop_title_all_of' => [['Rule E back-merge sync', 'paper-trail anchor']]],
        );

        $this->assertSame([], $r->intents);
    }

    public function test_drop_title_all_of_absent_keeps_subject(): void
    {
        // No config ⇒ back-compat: an ordinary addressed subject surfaces unchanged.
        $r = $this->classify('issues.opened', $this->issue(9, ['to:me'], '', 'Rule E back-merge sync — paper-trail anchor'), 'org/coord');

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_issue', $r->intents[0]->kind);
    }

    public function test_drop_title_all_of_suppresses_comments_on_a_matched_issue(): void
    {
        // Blast radius: for an issue_comment the title is the PARENT issue's, so a
        // matched drop-group suppresses even a to:me-addressed comment on that issue.
        $payload = [
            'issue' => ['number' => 9, 'title' => 'Rule E back-merge sync — paper-trail anchor', 'labels' => [['name' => 'to:me']]],
            'comment' => ['body' => 'TO: me', 'html_url' => 'https://x/9#c1', 'id' => 1, 'created_at' => '2026-07-12T00:00:00Z'],
        ];
        $r = $this->classify('issue_comment.created', $payload, 'org/coord', classifierConfig: ['drop_title_all_of' => [['Rule E back-merge sync', 'paper-trail anchor']]]);

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    // ---- kanban-triage family (opt-in; folded DL-168) ----

    /**
     * @param  array<mixed>  $card
     * @param  list<string>  $families
     */
    private function classifyKanbanCreated(array $card, array $families = ['kanban-triage'], bool $actorIsAgent = false): ClassifyResult
    {
        $agent = AgentConfig::fromArray('pm', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => $families]],
        ]);

        return $this->classifier->classify(new ClassifyContext(
            'task.created',
            ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Investigate flake'], 'card' => $card],
            new Actor(id: '7', name: 'human', isKnownAgent: $actorIsAgent),
            'kanban', '5', $agent,
        ));
    }

    public function test_kanban_triage_family_wakes_human_untriaged(): void
    {
        $r = $this->classifyKanbanCreated(['tags' => [], 'external_references' => []]);

        $this->assertCount(1, $r->intents);                                    // inbox new_card from the InboxOnly base
        $this->assertSame('new_card', $r->intents[0]->kind);
        $this->assertCount(1, $r->targets);
        $this->assertSame('channel_push', $r->targets[0]->handler);
        $this->assertSame($r->intents[0]->subjectId, $r->targets[0]->targetId); // silent-drop guard
        $this->assertSame($r->intents[0]->toArray(), $r->targets[0]->payload);
    }

    public function test_kanban_triage_family_suppresses_triaged_card(): void
    {
        $r = $this->classifyKanbanCreated(['tags' => ['triaged'], 'external_references' => []]);

        $this->assertCount(1, $r->intents);   // still inbox-staged
        $this->assertSame([], $r->targets);   // but no wake
    }

    public function test_kanban_triage_family_suppresses_agent_filed_card(): void
    {
        $r = $this->classifyKanbanCreated(['tags' => [], 'external_references' => []], actorIsAgent: true);

        $this->assertCount(1, $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_kanban_event_inbox_staged_but_no_wake_when_family_off(): void
    {
        // Default families (coord-message only): a human untriaged kanban card is
        // still inbox-staged by the InboxOnly base, but does NOT wake — the triage
        // wake is gated behind enabling the kanban-triage family.
        $r = $this->classifyKanbanCreated(['tags' => [], 'external_references' => []], families: ['coord-message']);

        $this->assertCount(1, $r->intents);
        $this->assertSame('new_card', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);
    }

    // ---- coord-message: wake_membership (Phase-2, DL-190) — narrow default flip ----

    public function test_wake_membership_narrow_default_drops_from_me_only(): void
    {
        // A thread I opened (from:me) with no to:me / to:all: under the NARROW default
        // it no longer live-wakes — from_me is now an opt-in.
        $r = $this->classify('issues.opened', $this->issue(9, ['from:me']), 'org/coord');

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_wake_membership_from_me_opt_in_surfaces_own_thread(): void
    {
        $r = $this->classify('issues.opened', $this->issue(9, ['from:me']), 'org/coord',
            classifierConfig: ['wake_membership' => ['to_me', 'to_all', 'from_me']]);

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_issue', $r->intents[0]->kind);
    }

    public function test_wake_membership_default_still_wakes_to_me_and_to_all(): void
    {
        $this->assertCount(1, $this->classify('issues.opened', $this->issue(9, ['to:me']), 'org/coord')->intents);
        $this->assertCount(1, $this->classify('issues.opened', $this->issue(9, ['to:all']), 'org/coord')->intents);
    }

    // ---- coord-message: coord_extra_actions (Phase-2, DL-190) — allow-list extension ----

    public function test_coord_extra_actions_surfaces_configured_action(): void
    {
        // pull_request.synchronize is NOT in the fail-safe default allow-list.
        $pr = ['pull_request' => ['number' => 5, 'title' => 'T', 'body' => '', 'labels' => [['name' => 'to:me']], 'html_url' => 'https://x/5']];
        $r = $this->classify('pull_request.synchronize', $pr, 'org/coord',
            classifierConfig: ['coord_extra_actions' => ['pull_request' => ['synchronize']]]);

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_pr', $r->intents[0]->kind);
    }

    public function test_coord_extra_actions_absent_drops_unlisted_action(): void
    {
        // Fail-safe: an unlisted action is dropped when not opted in.
        $pr = ['pull_request' => ['number' => 5, 'title' => 'T', 'labels' => [['name' => 'to:me']], 'html_url' => 'https://x/5']];
        $r = $this->classify('pull_request.synchronize', $pr, 'org/coord');

        $this->assertSame([], $r->intents);
    }

    // ---- impl-ci-wake: impl_non_wake_disposition (Phase-2, DL-190) ----

    public function test_drop_default_gate_drops_non_wake_event(): void
    {
        $r = $this->classify('workflow_run.completed',
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'CI', 'id' => 6]],
            'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_inbox_stage_stages_benign_workflow_run_without_wake(): void
    {
        $r = $this->classify('workflow_run.completed',
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'CI', 'id' => 6, 'html_url' => 'https://r/6']],
            'org/impl', classifierConfig: $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);   // inbox only, no channel_push
    }

    public function test_inbox_stage_stages_non_release_push_without_wake(): void
    {
        $r = $this->classify('push',
            ['ref' => 'refs/heads/feature-x', 'after' => 'abc123', 'head_commit' => ['message' => 'wip', 'url' => 'https://c/abc'], 'commits' => [['id' => 'abc123']]],
            'org/impl', classifierConfig: $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_push', $r->intents[0]->kind);
        $this->assertSame('abc123', $r->intents[0]->subjectId);
        $this->assertSame('feature-x', $r->intents[0]->payload['branch']);
        $this->assertSame([], $r->targets);
    }

    public function test_inbox_stage_skips_non_terminal_workflow_run(): void
    {
        $r = $this->classify('workflow_run.requested',
            ['workflow_run' => ['status' => 'in_progress', 'name' => 'CI', 'id' => 8]],
            'org/impl', classifierConfig: $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertSame([], $r->intents);
    }

    public function test_inbox_stage_skips_branch_delete_push(): void
    {
        $r = $this->classify('push', ['ref' => 'refs/heads/feature-x', 'deleted' => true],
            'org/impl', classifierConfig: $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertSame([], $r->intents);
    }

    public function test_inbox_stage_wake_worthy_still_wakes(): void
    {
        // A release-branch push is wake-worthy in BOTH dispositions.
        $r = $this->classify('push',
            ['ref' => 'refs/heads/main', 'after' => 'def456', 'head_commit' => ['message' => 'release', 'url' => 'https://c/def'], 'commits' => [['id' => 'def456']]],
            'org/impl', classifierConfig: $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_release_landed', $r->intents[0]->kind);
        $this->assertCount(1, $r->targets);   // wake preserved
    }

    // ---- impl-ci-wake: impl_wake_emit (Phase-2, DL-190) ----

    public function test_impl_wake_emit_none_suppresses_channel_push(): void
    {
        // Wake-worthy event still stages the Intent, but the classifier emits no
        // push — a channel whose route_intents owns waking isn't double-woken.
        $r = $this->classify('workflow_run.completed',
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5, 'html_url' => 'https://r/5']],
            'org/impl', classifierConfig: $this->implConfig(['impl_wake_emit' => 'none']));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);
    }

    public function test_sola_profile_inbox_stage_plus_wake_emit_none(): void
    {
        // The full sola impl profile: every terminal event stages an Intent, none
        // emits a classifier push (route_intents:true owns waking).
        $cfg = $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage', 'impl_wake_emit' => 'none']);

        $fail = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/impl', classifierConfig: $cfg);
        $this->assertSame('impl_ci_failed', $fail->intents[0]->kind);
        $this->assertSame([], $fail->targets);

        $benign = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'CI', 'id' => 6]], 'org/impl', classifierConfig: $cfg);
        $this->assertSame('impl_ci', $benign->intents[0]->kind);
        $this->assertSame([], $benign->targets);
    }

    // ---- impl-ci-wake: github_user_id-never-on-wake-identity invariant (Phase-2, DL-190) ----

    public function test_impl_wake_identity_is_author_agent_by_name_not_pusher_id(): void
    {
        // scope_author_map attributes to the agent by NAME; the wake payload `from`
        // is the agent (`device`), never the raw pusher github id (`99`) — so the
        // dispatcher echo gate can't drop the agent's own-repo landing.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertSame('device', $r->intents[0]->payload['from']);
    }
}
