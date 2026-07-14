<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Classifiers\KanbanTriageClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierConfig;
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
    private function classify(string $eventType, array $payload, string $scopeId, string $me = 'me', array $classifierConfig = [], ?string $actorName = null, bool $routeIntents = false)
    {
        $raw = [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => array_merge(['class' => CoordinationClassifier::class], $classifierConfig === [] ? [] : ['config' => $classifierConfig]),
        ];
        if ($routeIntents) {
            // route_intents:true requires a socket/url; the dispatcher then routes
            // every staged intent, so wakePush() suppresses each family's hand-emit.
            $raw['channel'] = ['socket' => '/tmp/test-coord-channel.sock', 'route_intents' => true];
        }
        $agent = AgentConfig::fromArray($me, $raw);

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

    // ---- consumedEventTypes (card#4183 / DL-196) ----

    /** @param array<mixed> $config */
    private function config(array $config = []): ClassifierConfig
    {
        return ClassifierConfig::fromClassifierSection($config === [] ? [] : ['config' => $config]);
    }

    public function test_consumed_event_types_default_families_are_coord_message(): void
    {
        // Unset families ⇒ the coord-message default, derived from HANDLED.
        $events = $this->classifier->consumedEventTypes($this->config());

        sort($events);
        $this->assertSame(['issue_comment', 'issues', 'pull_request'], $events);
    }

    public function test_consumed_event_types_impl_ci_wake_subset(): void
    {
        $events = $this->classifier->consumedEventTypes($this->config(['families' => ['impl-ci-wake']]));

        sort($events);
        $this->assertSame(['push', 'workflow_run'], $events);
    }

    public function test_consumed_event_types_union_over_enabled_families(): void
    {
        // Both families enabled ⇒ the DEDUPED union of their top-level event types.
        $events = $this->classifier->consumedEventTypes($this->config(['families' => ['coord-message', 'impl-ci-wake']]));

        sort($events);
        $this->assertSame(['issue_comment', 'issues', 'pull_request', 'push', 'workflow_run'], $events);
    }

    public function test_consumed_event_types_kanban_triage_has_no_github_events(): void
    {
        // kanban-triage is a kanban-provider family — it consumes NO github event type.
        $this->assertSame([], $this->classifier->consumedEventTypes($this->config(['families' => ['kanban-triage']])));
    }

    public function test_consumed_event_types_triage_shim_default_has_no_github_events(): void
    {
        // The KanbanTriageClassifier shim defaults to [kanban-triage] → no github events.
        $this->assertSame([], (new KanbanTriageClassifier)->consumedEventTypes($this->config()));
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

    // ---- impl-ci-wake: ci_failure_workflow_patterns filter (DL-197) ----

    public function test_ci_failure_filter_empty_wakes_any_failure(): void
    {
        // Absent filter ⇒ any workflow's failure wakes (back-compat, byte-identical).
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Retention sweep', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig());

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_matched_name_wakes(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Protocol integrity', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_unmatched_name_does_not_wake(): void
    {
        // A non-watched workflow's failure is filtered out → non-wake, default drop.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Retention sweep', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_ci_failure_filter_unmatched_name_inbox_staged_when_disposition_set(): void
    {
        // A filtered-out failure is a NON-wake run: with inbox_stage it stages like any
        // benign run (impl_ci, no channel_push) — conclusion-agnostic staging.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Retention sweep', 'id' => 5, 'html_url' => 'https://r/5']], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity'], 'impl_non_wake_disposition' => 'inbox_stage']));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);
    }

    public function test_ci_failure_filter_matched_name_unknown_conclusion_still_wakes_fail_loud(): void
    {
        // The filter narrows WHICH workflows, never WHICH conclusions: a matched
        // workflow with an unknown conclusion still wakes fail-loud.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'stale', 'name' => 'Protocol integrity', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_substring_matches_ci_of_ci(): void
    {
        // `protocol integrity` substring-matches `Protocol integrity tests` too — both
        // are oversight gates; substring is the desired behavior (sola/aimla intent).
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Protocol integrity tests', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_is_case_insensitive(): void
    {
        // Config `Protocol integrity` (mixed case) is lowercased at parse; matching
        // lowercases the name → matches regardless of case.
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Protocol integrity', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['Protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_empty_name_wakes_fail_loud(): void
    {
        // A failure with NO workflow name can't be matched against the allow-list →
        // it is never filtered, and wakes fail-loud (preserves pre-filter behavior).
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_multi_pattern_second_match_wakes(): void
    {
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'Protocol integrity', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['alpha', 'protocol integrity']]));

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
    }

    public function test_ci_failure_filter_does_not_affect_provenance_success(): void
    {
        // The failure filter is applied ONLY in the failure branch — the provenance
        // SUCCESS path is orthogonal. Filter set, provenance_patterns empty, a matched
        // name concluding success ⇒ NO wake (the filter is never consulted here).
        $r = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'Protocol integrity', 'id' => 5]], 'org/impl', classifierConfig: $this->implConfig(['ci_failure_workflow_patterns' => ['protocol integrity']]));

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
    private function classifyKanbanCreated(array $card, array $families = ['kanban-triage'], bool $actorIsAgent = false, bool $routeIntents = false): ClassifyResult
    {
        $raw = [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => $families]],
        ];
        if ($routeIntents) {
            $raw['channel'] = ['socket' => '/tmp/test-triage-channel.sock', 'route_intents' => true];
        }
        $agent = AgentConfig::fromArray('pm', $raw);

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

    public function test_kanban_triage_suppresses_hand_emit_on_route_intents_true(): void
    {
        // DL-191: on a route_intents channel the triage push is suppressed — the base
        // new_card intent still routes (single wake), so no double-wake and no miss.
        $r = $this->classifyKanbanCreated(['tags' => [], 'external_references' => []], routeIntents: true);

        $this->assertCount(1, $r->intents);                 // base new_card intent routes
        $this->assertSame('new_card', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);                 // no hand-emit
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

    // ---- coord-message: comment_to (DL-192) — directed body-TO:<self> GRANTS on opt-in ----

    /**
     * An issue_comment payload: the parent issue carries the thread labels, the
     * comment carries the body TO: line the grant/narrow reads.
     *
     * @param  list<string>  $labelNames
     * @return array<string, mixed>
     */
    private function comment(int $number, array $labelNames, string $body): array
    {
        $labels = array_map(static fn (string $n): array => ['name' => $n], $labelNames);

        return [
            'issue' => ['number' => $number, 'title' => 'T', 'labels' => $labels],
            'comment' => ['body' => $body, 'html_url' => 'https://x/'.$number.'#c1', 'id' => 1, 'created_at' => '2026-07-12T00:00:00Z'],
        ];
    }

    public function test_comment_to_grants_a_directed_comment_on_an_unaddressed_thread(): void
    {
        // The cross-thread pull-in: a comment "TO: me" on a thread labelled to:other
        // (I neither opened nor was labelled on) GRANTS when comment_to is opted in.
        $r = $this->classify('issue_comment.created', $this->comment(9, ['from:other', 'to:other'], 'TO: me'),
            'org/coord', classifierConfig: ['wake_membership' => ['to_me', 'to_all', 'comment_to']]);

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_comment', $r->intents[0]->kind);
    }

    public function test_comment_to_off_by_default_does_not_grant(): void
    {
        // The SAME pull-in comment WITHOUT comment_to → no grant (default byte-identical).
        $r = $this->classify('issue_comment.created', $this->comment(9, ['from:other', 'to:other'], 'TO: me'), 'org/coord');

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_comment_to_narrow_still_denies_a_comment_addressed_elsewhere(): void
    {
        // Narrow is UNCONDITIONAL: even with comment_to set AND a to:me label that would
        // otherwise wake, a comment "TO: other" denies membership → no wake.
        $r = $this->classify('issue_comment.created', $this->comment(9, ['to:me'], 'TO: other'),
            'org/coord', classifierConfig: ['wake_membership' => ['to_me', 'to_all', 'comment_to']]);

        $this->assertSame([], $r->intents);
    }

    public function test_comment_to_null_body_falls_back_to_labels(): void
    {
        // A comment with NO TO: line falls back to label membership (comment_to or not):
        // a to:me label wakes; an unaddressed thread does not.
        $cfg = ['wake_membership' => ['to_me', 'to_all', 'comment_to']];
        $this->assertCount(1, $this->classify('issue_comment.created', $this->comment(9, ['to:me'], 'no directive'), 'org/coord', classifierConfig: $cfg)->intents);
        $this->assertSame([], $this->classify('issue_comment.created', $this->comment(9, ['to:other'], 'no directive'), 'org/coord', classifierConfig: $cfg)->intents);
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

    // ---- wakePush: route_intents suppresses every family's hand-emit (DL-191) ----

    public function test_coord_message_hand_emits_on_route_intents_false(): void
    {
        // Baseline: a route_intents:false channel keeps the surgical hand-emit (the
        // aimla/kanban-solo model) — byte-identical to pre-DL-191.
        $r = $this->classify('issues.opened', $this->issue(4, ['to:me', 'from:other'], 'FROM: other'), 'org/coord');

        $this->assertCount(1, $r->intents);
        $this->assertCount(1, $r->targets);
    }

    public function test_coord_message_suppresses_hand_emit_on_route_intents_true(): void
    {
        // F-A regression (sola's route_intents:true profile): the intent is still
        // staged (route_intents routes it → single wake), but the classifier emits
        // NO channel_push, so the dispatcher's routed push isn't a double-wake.
        $r = $this->classify('issues.opened', $this->issue(4, ['to:me', 'from:other'], 'FROM: other'), 'org/coord', routeIntents: true);

        $this->assertCount(1, $r->intents);
        $this->assertSame('coord_issue', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);
    }

    public function test_coord_message_narrow_gate_still_drops_non_member_on_route_intents(): void
    {
        // The wake_membership gate returns null (no intent) BEFORE the emit decision,
        // so route_intents cannot widen the wake past the narrow gate — a non-member
        // stages no intent to route.
        $r = $this->classify('issues.opened', $this->issue(4, ['to:someone-else', 'from:other']), 'org/coord', routeIntents: true);

        $this->assertSame([], $r->intents);
        $this->assertSame([], $r->targets);
    }

    public function test_impl_wake_suppresses_hand_emit_on_route_intents_true(): void
    {
        // Wake-worthy impl event on a route_intents channel: Intent staged, no push.
        $r = $this->classify('workflow_run.completed',
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5, 'html_url' => 'https://r/5']],
            'org/impl', classifierConfig: $this->implConfig(), routeIntents: true);

        $this->assertCount(1, $r->intents);
        $this->assertSame('impl_ci_failed', $r->intents[0]->kind);
        $this->assertSame([], $r->targets);
    }

    public function test_sola_profile_inbox_stage_on_route_intents_true(): void
    {
        // The full sola impl profile: inbox_stage + route_intents:true. Every terminal
        // event stages an Intent, none emits a classifier push (routing owns waking).
        $cfg = $this->implConfig(['impl_non_wake_disposition' => 'inbox_stage']);

        $fail = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'name' => 'CI', 'id' => 5]], 'org/impl', classifierConfig: $cfg, routeIntents: true);
        $this->assertSame('impl_ci_failed', $fail->intents[0]->kind);
        $this->assertSame([], $fail->targets);

        $benign = $this->classify('workflow_run.completed', ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'name' => 'CI', 'id' => 6]], 'org/impl', classifierConfig: $cfg, routeIntents: true);
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
