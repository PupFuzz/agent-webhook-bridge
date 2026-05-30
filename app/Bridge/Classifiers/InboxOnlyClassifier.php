<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;

/**
 * Canonical default classifier: surfaces kanban activity to the agent inbox
 * as Intents, with no automated reactions (no ReactionTargets).
 *
 * Lifecycle events (delete/archive/restore/unarchive) are NOT noise when
 * kanban is the source of truth — they're invalidation signals for any
 * agent-held state keyed on the subject_id, so each maps to a distinct kind.
 * Event types not handled here fall through to an empty result.
 */
class InboxOnlyClassifier implements Classifier
{
    /**
     * event_type => [display verb, intent kind] for kanban-board's TaskMutator
     * lifecycle events.
     *
     * @var array<string, array{string, string}>
     */
    private const LIFECYCLE = [
        'task.deleted' => ['deleted', 'card_removed'],
        'task.archived' => ['archived', 'card_archived'],
        'task.restored' => ['restored', 'card_restored'],
        'task.unarchived' => ['unarchived', 'card_unarchived'],
    ];

    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
    ): ClassifyResult {
        $intent = match (true) {
            $eventType === 'task.created' => $this->newCardIntent($payload, $actor, $provider),
            $eventType === 'task.moved' => $this->moveIntent($payload, $actor, $provider),
            $eventType === 'task.updated' => $this->contentEditIntent($payload, $actor, $provider),
            isset(self::LIFECYCLE[$eventType]) => $this->lifecycleIntent($eventType, $payload, $actor, $provider),
            default => null,
        };

        return $intent === null ? new ClassifyResult : new ClassifyResult(intents: [$intent]);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function newCardIntent(array $payload, Actor $actor, string $provider): Intent
    {
        $name = $this->scalar($this->task($payload)['name'] ?? null) ?: '<unnamed>';
        $subjectId = $this->scalar($payload['subject_id'] ?? null);

        return new Intent(
            kind: 'new_card',
            subjectId: $subjectId,
            provider: $provider,
            actor: $actor,
            summary: "new card by {$this->who($actor)}: {$name}",
            payload: ['name' => $name, 'board_id' => $payload['board_id'] ?? null],
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function moveIntent(array $payload, Actor $actor, string $provider): Intent
    {
        $task = $this->task($payload);
        $subjectId = $this->scalar($payload['subject_id'] ?? null);
        $fromStage = $task['from_stage_id'] ?? null;
        $toStage = $task['to_stage_id'] ?? null;

        return new Intent(
            kind: 'column_move',
            subjectId: $subjectId,
            provider: $provider,
            actor: $actor,
            summary: "{$this->who($actor)} moved card {$subjectId} from stage "
                .$this->scalar($fromStage).' → '.$this->scalar($toStage),
            payload: [
                'from_stage_id' => $fromStage,
                'to_stage_id' => $toStage,
                'from_swimlane_id' => $task['from_swimlane_id'] ?? null,
                'to_swimlane_id' => $task['to_swimlane_id'] ?? null,
                'index' => $task['index'] ?? null,
            ],
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function contentEditIntent(array $payload, Actor $actor, string $provider): Intent
    {
        $fields = $this->task($payload)['fields'] ?? [];
        $fields = is_array($fields) ? $fields : [];
        $keys = array_map(strval(...), array_keys($fields));
        sort($keys);
        $subjectId = $this->scalar($payload['subject_id'] ?? null);

        return new Intent(
            kind: 'content_edit',
            subjectId: $subjectId,
            provider: $provider,
            actor: $actor,
            summary: "{$this->who($actor)} edited card {$subjectId}: ".(implode(', ', $keys) ?: '?'),
            payload: ['changed_fields' => $keys, 'fields' => $fields],
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function lifecycleIntent(string $eventType, array $payload, Actor $actor, string $provider): Intent
    {
        [$verb, $kind] = self::LIFECYCLE[$eventType];
        $name = $this->scalar($this->task($payload)['name'] ?? null);
        $subjectId = $this->scalar($payload['subject_id'] ?? null);
        $suffix = $name !== '' ? " ('{$name}')" : '';

        return new Intent(
            kind: $kind,
            subjectId: $subjectId,
            provider: $provider,
            actor: $actor,
            summary: "{$verb} by {$this->who($actor)}: subject {$subjectId}{$suffix}",
            payload: ['board_id' => $payload['board_id'] ?? null, 'name' => $name !== '' ? $name : null],
        );
    }

    /**
     * The event-specific nested object (the webhook body's `payload` field).
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function task(array $payload): array
    {
        $task = $payload['payload'] ?? null;

        return is_array($task) ? $task : [];
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function who(Actor $actor): string
    {
        return $actor->name ?? $actor->id ?? '?';
    }
}
