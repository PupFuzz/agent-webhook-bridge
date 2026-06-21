<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\KanbanTriageClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Support\AgentConfig;
use PHPUnit\Framework\TestCase;

/**
 * DL-168 / #3010 — wake the triage owner on a human-filed, untriaged card_created,
 * filtering at classify time on the DL-164 `card` snapshot (no API call).
 */
class KanbanTriageClassifierTest extends TestCase
{
    private KanbanTriageClassifier $classifier;

    private AgentConfig $agent;

    protected function setUp(): void
    {
        $this->classifier = new KanbanTriageClassifier;
        $this->agent = AgentConfig::fromArray('pm-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function classify(array $payload, bool $actorIsAgent = false, string $eventType = 'task.created')
    {
        return $this->classifier->classify(new ClassifyContext(
            $eventType, $payload, new Actor(id: '7', name: 'human', isKnownAgent: $actorIsAgent), 'kanban', '5', $this->agent,
        ));
    }

    private function created(array $card): array
    {
        return ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Investigate flake'], 'card' => $card];
    }

    public function test_human_filed_untriaged_card_wakes_the_triage_owner(): void
    {
        $result = $this->classify($this->created(['tags' => [], 'external_references' => []]));

        $this->assertCount(1, $result->intents);
        $this->assertCount(1, $result->targets);
        $target = $result->targets[0];
        $this->assertSame('channel_push', $target->handler);
        $this->assertSame($result->intents[0]->subjectId, $target->targetId);   // silent-drop guard
        $this->assertSame($result->intents[0]->toArray(), $target->payload);
    }

    public function test_agent_filed_card_does_not_wake_no_self_wake(): void
    {
        // Actor is a known agent (bridge/dependabot/agent-created) → inbox only, no push.
        $result = $this->classify($this->created(['tags' => [], 'external_references' => []]), actorIsAgent: true);

        $this->assertCount(1, $result->intents);
        $this->assertSame([], $result->targets);
    }

    public function test_triaged_card_does_not_wake(): void
    {
        $result = $this->classify($this->created(['tags' => ['triaged'], 'external_references' => []]));
        $this->assertSame([], $result->targets);
    }

    public function test_card_with_id_pr_tag_does_not_wake(): void
    {
        $result = $this->classify($this->created(['tags' => ['id:pr:204'], 'external_references' => []]));
        $this->assertSame([], $result->targets);
    }

    public function test_card_with_a_dl_ref_does_not_wake(): void
    {
        $result = $this->classify($this->created(['tags' => [], 'external_references' => [['system' => 'dl', 'source' => null, 'ref' => '5']]]));
        $this->assertSame([], $result->targets);
    }

    public function test_a_github_pr_ref_alone_still_wakes(): void
    {
        // A bare github_pr ref is not "triaged" — only a dl ref / triaged tag / id:pr tag suppresses.
        $result = $this->classify($this->created(['tags' => [], 'external_references' => [['system' => 'github_pr', 'source' => null, 'ref' => '9']]]));
        $this->assertCount(1, $result->targets);
    }

    public function test_missing_card_snapshot_reads_as_untriaged_and_wakes(): void
    {
        // Pre-DL-164 kanban: no `card` key → reads untriaged → wakes (SessionStart
        // snapshot is the durable backstop, so an over-wake is at worst minor noise).
        $result = $this->classify(['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'x']]);
        $this->assertCount(1, $result->targets);
    }

    public function test_non_created_event_is_inbox_only(): void
    {
        $result = $this->classify(['subject_id' => 7, 'payload' => ['from_stage_id' => 1, 'to_stage_id' => 2]], eventType: 'task.moved');
        $this->assertCount(1, $result->intents);
        $this->assertSame([], $result->targets);   // moves don't wake the triage owner
    }
}
