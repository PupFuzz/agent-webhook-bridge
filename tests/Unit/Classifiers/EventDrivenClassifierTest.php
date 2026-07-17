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
    private function classify(string $eventType, array $payload, bool $routeIntents = false)
    {
        $agent = $this->agent;
        if ($routeIntents) {
            // route_intents:true requires a socket/url; the dispatcher then routes
            // every staged intent, so the hand-emit must suppress to avoid a double-wake.
            $agent = AgentConfig::fromArray('test-agent', [
                'identity' => ['kanban_user_id' => 1],
                'subscriptions' => [],
                'channel' => ['socket' => '/tmp/test-eventdriven-channel.sock', 'route_intents' => true],
            ]);
        }

        return $this->classifier->classify(new ClassifyContext($eventType, $payload, new Actor(id: '1', name: 'alice'), 'kanban', '5', $agent));
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

    public function test_suppresses_hand_emit_on_route_intents_true(): void
    {
        // DL-191: on a route_intents:true channel the dispatcher already routes every
        // staged intent to the channel, so the classifier must NOT hand-emit its own
        // channel_push — the two survive coalescing under distinct debounceKeys and
        // double-wake the handler (card #4494). The Intent is still staged (route_intents
        // routes it → single wake).
        $result = $this->classify('task.created', ['subject_id' => 42, 'payload' => ['name' => 'Ship it']], routeIntents: true);

        $this->assertCount(1, $result->intents);
        $this->assertSame('new_card', $result->intents[0]->kind);
        $this->assertSame([], $result->targets);
    }

    public function test_multiple_intents_each_hand_emit_on_route_intents_false(): void
    {
        // Behavior-preserving guard: on a plain (route_intents:false) channel every
        // intent still pairs with exactly one channel_push, byte-identical to pre-fix.
        $result = $this->classify('task.moved', ['subject_id' => 7, 'payload' => ['from_stage_id' => 1, 'to_stage_id' => 2]]);

        $this->assertCount(1, $result->intents);
        $this->assertCount(1, $result->targets);
        $this->assertSame('channel_push', $result->targets[0]->handler);
        $this->assertSame($result->intents[0]->subjectId, $result->targets[0]->targetId);
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
