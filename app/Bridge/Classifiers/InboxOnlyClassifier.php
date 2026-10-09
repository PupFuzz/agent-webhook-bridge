<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\HandlerRegistry;

/**
 * Canonical default classifier: surfaces kanban activity to the agent inbox
 * as Intents, with no automated reactions (no ReactionTargets).
 *
 * Lifecycle events (delete/archive/restore/unarchive) are NOT noise when
 * kanban is the source of truth — they're invalidation signals for any
 * agent-held state keyed on the subject_id, so each maps to a distinct kind.
 * Event types not handled here fall through to an empty result.
 *
 * `comment.created` is the one ADDRESSED kanban event (card#11581 / DL-467): it
 * becomes a `card_comment` intent for the agent whose seat the card is ASSIGNED
 * to, and for no other agent — see {@see cardComment()}.
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

    public const CARD_COMMENT_KIND = 'card_comment';

    /** The dropped reason when the delivery carries no usable card/comment snapshot. */
    public const CARD_COMMENT_SNAPSHOT_ABSENT = 'card_comment unroutable: snapshot absent (kanban sent no card.assigned_user_id or comment block — a kanban that predates them, or a kanban webhook replay)';

    public const CARD_COMMENT_UNASSIGNED = 'card_comment: card is unassigned';

    public const CARD_COMMENT_NOT_ASSIGNEE = 'card_comment: card is not assigned to this agent\'s kanban user';

    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        if ($ctx->eventType === 'comment.created') {
            return $this->cardComment($ctx);
        }

        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $provider = $ctx->provider;

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
     * A board comment on a card, routed to the card's ASSIGNEE only (card#11581 / DL-467).
     *
     * The assignee is read from the `card.assigned_user_id` snapshot kanban puts on the
     * delivery, and matched against this agent's kanban user id through the delivery's
     * {@see AgentRegistry} — the coord-roster mapping (DL-450) the echo gate and
     * attribution already use. Every other agent records a dropped reason naming why.
     *
     * The assignee's OWN kanban user never reaches here: the dispatcher's pre-classify echo
     * gate seeds that id from the roster (DL-450). The writeback identity, which authors
     * every board-tools write, is kept out ONLY while it is a global echo id (DL-009/019),
     * i.e. while writeback.json `identity_id` is set. Without it, a seat's own
     * `board_comment_card` on its own card, and the comment `board_take_card` leaves on a
     * takeover, DO arrive here and wake that seat with its own words: a loop for a seat
     * that answers a card comment with `board_comment_card`. No author check is repeated
     * here, because that check is the echo gate's.
     *
     * ⛔ ABSENT IS NOT UNASSIGNED. A kanban that predates the snapshot, and a kanban webhook
     * REPLAY (which rebuilds the envelope without these blocks), send no `card` /
     * `comment` — and then nothing says who the card belongs to. That is unroutable, not
     * "nobody's": no intent for any agent, and the ledger row says so. A null
     * `assigned_user_id` that IS present is the card being unassigned.
     */
    private function cardComment(ClassifyContext $ctx): ClassifyResult
    {
        $payload = $ctx->payload;
        $card = $payload['card'] ?? null;
        $comment = $payload['comment'] ?? null;
        if (! is_array($card) || ! array_key_exists('assigned_user_id', $card) || ! is_array($comment)) {
            return new ClassifyResult(dropReason: self::CARD_COMMENT_SNAPSHOT_ABSENT);
        }

        $assignee = $card['assigned_user_id'];
        if ($assignee === null) {
            return new ClassifyResult(dropReason: self::CARD_COMMENT_UNASSIGNED);
        }

        $agents = $ctx->agents ?? AgentRegistry::fromAgentConfigs([$ctx->agent]);
        if ($assignee !== $agents->kanbanUserIdOf($ctx->agent->agentName)) {
            return new ClassifyResult(dropReason: self::CARD_COMMENT_NOT_ASSIGNEE);
        }

        $cardId = $this->scalar($payload['subject_id'] ?? null);
        $author = $this->scalar($comment['user_name'] ?? null);
        $body = $this->scalar($comment['content'] ?? null);

        return new ClassifyResult(intents: [new Intent(
            kind: self::CARD_COMMENT_KIND,
            subjectId: $cardId,
            provider: $ctx->provider,
            actor: $ctx->actor,
            summary: "comment on card {$cardId} by {$this->oneLine($author)}: ".$this->oneLine($body),
            payload: [
                'card_id' => $payload['subject_id'] ?? null,
                'board_id' => $payload['board_id'] ?? null,
                'comment_id' => $comment['id'] ?? null,
                'author_name' => $author,
                'body' => $body,
            ],
        )]);
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
     * The single guarded wake-emit point shared by every reaction-emitting subclass
     * (DL-191). Emit a surgical `channel_push` for the intent — UNLESS the serving
     * channel has `route_intents:true`, where the dispatcher already routes every
     * staged intent to the channel (DL-006) and a hand-emit would double-wake (the
     * routed push carries the same `$intent->toArray()` payload, so suppressing here
     * loses no wake): hand-emit ⟺ `route_intents:false`. The base's own `classify()`
     * emits no reactions; this is machinery its wake-emitting subclasses (EventDriven,
     * Coordination) share so the guard lives in exactly one place (card #4494).
     *
     * @return list<ReactionTarget>
     */
    protected function wakePush(Intent $intent, ClassifyContext $ctx): array
    {
        if ($ctx->agent->channel->routeIntents) {
            return [];
        }

        return [ReactionTarget::make(
            handler: HandlerRegistry::CHANNEL_PUSH,
            targetId: $intent->subjectId,
            debounceSeconds: 0,
            payload: $intent->toArray(),
        )];
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

    /**
     * Whitespace-collapsed and cut to a summary-line length. `/u` makes `\s` match Unicode
     * line breaks (U+2028, U+0085) as well; it would return null on invalid UTF-8, which
     * cannot arrive here because every caller's text comes out of `json_decode`.
     */
    protected function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137).'...' : $text;
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
