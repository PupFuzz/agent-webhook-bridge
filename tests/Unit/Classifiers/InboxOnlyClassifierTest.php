<?php

namespace Tests\Unit\Classifiers;

use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Dispatch\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InboxOnlyClassifierTest extends TestCase
{
    private InboxOnlyClassifier $classifier;

    private Actor $actor;

    protected function setUp(): void
    {
        $this->classifier = new InboxOnlyClassifier;
        $this->actor = new Actor(id: '137', name: 'alice');
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function classify(string $eventType, array $payload)
    {
        return $this->classifier->classify($eventType, $payload, $this->actor, 'kanban', '5');
    }

    public function test_task_created_yields_new_card_intent(): void
    {
        $result = $this->classify('task.created', [
            'subject_id' => 42,
            'board_id' => 5,
            'payload' => ['name' => 'Ship it'],
        ]);

        $this->assertCount(1, $result->intents);
        $this->assertSame([], $result->targets);
        $intent = $result->intents[0];
        $this->assertSame('new_card', $intent->kind);
        $this->assertSame('42', $intent->subjectId);
        $this->assertSame('kanban', $intent->provider);
        $this->assertStringContainsString('new card by alice: Ship it', $intent->summary);
        $this->assertSame('Ship it', $intent->payload['name']);
    }

    public function test_new_card_falls_back_to_unnamed(): void
    {
        $result = $this->classify('task.created', ['subject_id' => 1, 'payload' => []]);

        $this->assertSame('<unnamed>', $result->intents[0]->payload['name']);
    }

    public function test_task_moved_yields_column_move_intent(): void
    {
        $result = $this->classify('task.moved', [
            'subject_id' => 7,
            'payload' => ['from_stage_id' => 1, 'to_stage_id' => 2, 'index' => 3],
        ]);

        $intent = $result->intents[0];
        $this->assertSame('column_move', $intent->kind);
        $this->assertSame(1, $intent->payload['from_stage_id']);
        $this->assertSame(2, $intent->payload['to_stage_id']);
        $this->assertStringContainsString('from stage 1 → 2', $intent->summary);
    }

    public function test_task_updated_yields_sorted_changed_fields(): void
    {
        $result = $this->classify('task.updated', [
            'subject_id' => 9,
            'payload' => ['fields' => ['title' => ['old' => 'a', 'new' => 'b'], 'color' => ['old' => 1, 'new' => 2]]],
        ]);

        $intent = $result->intents[0];
        $this->assertSame('content_edit', $intent->kind);
        $this->assertSame(['color', 'title'], $intent->payload['changed_fields']);   // sorted
        $this->assertStringContainsString('color, title', $intent->summary);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function lifecycleCases(): array
    {
        return [
            'deleted' => ['task.deleted', 'card_removed'],
            'archived' => ['task.archived', 'card_archived'],
            'restored' => ['task.restored', 'card_restored'],
            'unarchived' => ['task.unarchived', 'card_unarchived'],
        ];
    }

    #[DataProvider('lifecycleCases')]
    public function test_lifecycle_events_map_to_distinct_kinds(string $eventType, string $expectedKind): void
    {
        $result = $this->classify($eventType, ['subject_id' => 11, 'board_id' => 5, 'payload' => null]);

        $this->assertCount(1, $result->intents);
        $this->assertSame($expectedKind, $result->intents[0]->kind);
        $this->assertSame('11', $result->intents[0]->subjectId);
    }

    public function test_unhandled_event_yields_empty_result(): void
    {
        $result = $this->classify('comment.created', ['subject_id' => 1]);

        $this->assertSame([], $result->intents);
        $this->assertSame([], $result->targets);
    }

    public function test_never_emits_reaction_targets(): void
    {
        foreach (['task.created', 'task.moved', 'task.updated', 'task.deleted'] as $event) {
            $result = $this->classify($event, ['subject_id' => 1, 'payload' => ['name' => 'x']]);
            $this->assertSame([], $result->targets, "event {$event} should emit no targets");
        }
    }
}
