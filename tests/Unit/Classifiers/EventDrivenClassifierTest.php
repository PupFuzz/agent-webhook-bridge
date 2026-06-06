<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\EventDrivenClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Support\AgentConfig;
use PHPUnit\Framework\TestCase;

class EventDrivenClassifierTest extends TestCase
{
    private EventDrivenClassifier $classifier;

    private AgentConfig $agent;

    protected function setUp(): void
    {
        $this->classifier = new EventDrivenClassifier;
        $this->agent = AgentConfig::fromArray('test-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function classify(string $eventType, array $payload)
    {
        return $this->classifier->classify(new ClassifyContext($eventType, $payload, new Actor(id: '1', name: 'alice'), 'kanban', '5', $this->agent));
    }

    public function test_pairs_each_intent_with_a_channel_push_target(): void
    {
        $result = $this->classify('task.created', ['subject_id' => 42, 'payload' => ['name' => 'Ship it']]);

        $this->assertCount(1, $result->intents);
        $this->assertCount(1, $result->targets);

        $target = $result->targets[0];
        $intent = $result->intents[0];

        $this->assertSame('channel_push', $target->handler);
        // target_id matches the intent subject_id → silent-drop guard never warns.
        $this->assertSame($intent->subjectId, $target->targetId);
        $this->assertSame(0, $target->debounceSeconds);
        // The channel_push payload carries the intent's canonical wire shape.
        $this->assertSame($intent->toArray(), $target->payload);
    }

    public function test_no_intents_means_no_targets(): void
    {
        $result = $this->classify('comment.created', ['subject_id' => 1]);

        $this->assertSame([], $result->intents);
        $this->assertSame([], $result->targets);
    }

    public function test_preserves_inbox_only_intent_semantics(): void
    {
        $result = $this->classify('task.moved', ['subject_id' => 7, 'payload' => ['from_stage_id' => 1, 'to_stage_id' => 2]]);

        $this->assertSame('column_move', $result->intents[0]->kind);
        $this->assertSame('7', $result->targets[0]->targetId);
    }
}
