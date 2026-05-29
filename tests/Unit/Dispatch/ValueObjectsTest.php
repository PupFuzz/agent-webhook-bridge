<?php

namespace Tests\Unit\Dispatch;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use PHPUnit\Framework\TestCase;

class ValueObjectsTest extends TestCase
{
    public function test_intent_to_array_is_the_canonical_wire_shape(): void
    {
        $actor = new Actor(id: '137', name: 'prod-agent', isKnownAgent: true, rawEnvelope: ['user_id' => 137]);
        $intent = new Intent(
            kind: 'new_card',
            subjectId: '42',
            provider: 'kanban',
            actor: $actor,
            summary: 'New card by prod-agent',
            payload: ['name' => 'Hello'],
        );

        $this->assertSame([
            'kind' => 'new_card',
            'subject_id' => '42',
            'provider' => 'kanban',
            'actor' => ['id' => '137', 'name' => 'prod-agent', 'is_known_agent' => true],
            'summary' => 'New card by prod-agent',
            'payload' => ['name' => 'Hello'],
        ], $intent->toArray());
    }

    public function test_to_array_omits_raw_envelope(): void
    {
        $actor = new Actor(id: '1', rawEnvelope: ['secret' => 'should-not-leak']);
        $intent = new Intent('k', 's', 'kanban', $actor, 'sum');

        $this->assertArrayNotHasKey('raw_envelope', $intent->toArray()['actor']);
    }

    public function test_reaction_target_make_defaults_debounce_key_to_target_id(): void
    {
        $t = ReactionTarget::make('channel_push', 'board:5');

        $this->assertSame('channel_push', $t->handler);
        $this->assertSame('board:5', $t->targetId);
        $this->assertSame('board:5', $t->debounceKey);
        $this->assertNull($t->debounceSeconds);
        $this->assertSame([], $t->payload);
    }

    public function test_reaction_target_make_honours_explicit_debounce_key(): void
    {
        $t = ReactionTarget::make('log_intent', 'task:1234', debounceKey: 'task:1234:content', debounceSeconds: 30);

        $this->assertSame('task:1234:content', $t->debounceKey);
        $this->assertSame(30, $t->debounceSeconds);
    }

    public function test_classify_result_defaults_are_empty(): void
    {
        $r = new ClassifyResult;

        $this->assertSame([], $r->targets);
        $this->assertSame([], $r->intents);
    }
}
