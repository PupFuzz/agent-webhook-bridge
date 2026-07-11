<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
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
}
