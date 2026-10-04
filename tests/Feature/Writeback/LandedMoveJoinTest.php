<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\OwedWriteQueue;
use App\Models\WebhookEvent;
use App\Models\WritebackOwedWrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\PreloadStub;
use Tests\Support\ScopeLookupStub;
use Tests\TestCase;

/**
 * card#11223, the join between a landed move and its give-up. The real `KanbanMoveCardHandler`
 * runs through the real owed-write queue; kanban lands the move and then rate-limits the stamp.
 * A give-up row's `op` is the handler's declared op (`move`) whether or not the move landed, so
 * the landing is answered by a `move_card.moved` row carrying the SAME `webhook_event_id` as the
 * give-up. Each leg was seen red against the handler that logged the landing after the stamp.
 */
class LandedMoveJoinTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-11223-join-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52, 'opened' => 50], 'create_dependabot_cards' => true]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_landed_move_whose_stamp_is_rate_limited_logs_exactly_one_landing_carrying_the_delivery(): void
    {
        $event = $this->landThenRateLimitTheStamp();

        $queue = $this->queue();
        $subject = OwedWriteQueue::subjectKey('kanban', '5', 'kanban_move_card', '5');
        $queue->enqueue($subject, $this->target(), $this->agent(), $event);
        $queue->drain($subject);

        $landings = $this->logged('move_card.moved');
        $this->assertCount(1, $landings, 'the move landed, so exactly one landing row, though the write is still owed');
        $this->assertSame((int) $event->id, $landings[0]['webhook_event_id']);
        $this->assertSame('move', $landings[0]['op']);
        $this->assertSame(1, WritebackOwedWrite::query()->count(), 'the stamp stays owed');
    }

    public function test_the_failed_stamp_is_a_stamp_row_for_the_same_delivery(): void
    {
        $event = $this->landThenRateLimitTheStamp();

        $queue = $this->queue();
        $subject = OwedWriteQueue::subjectKey('kanban', '5', 'kanban_move_card', '5');
        $queue->enqueue($subject, $this->target(), $this->agent(), $event);
        $queue->drain($subject);

        $failures = $this->logged('move_card.stamp_transient_failure');
        $this->assertCount(1, $failures);
        $this->assertSame('stamp', $failures[0]['op']);
        $this->assertSame((int) $event->id, $failures[0]['webhook_event_id']);
        $this->assertSame(429, $failures[0]['status']);
    }

    public function test_the_retries_and_the_give_up_add_no_landing_and_the_give_up_joins_the_one_there_is(): void
    {
        $event = $this->landThenRateLimitTheStamp();

        $queue = $this->queue();
        $subject = OwedWriteQueue::subjectKey('kanban', '5', 'kanban_move_card', '5');
        $queue->enqueue($subject, $this->target(), $this->agent(), $event);
        $queue->drain($subject);
        for ($retry = 1; $retry < OwedWriteQueue::MAX_ATTEMPTS; $retry++) {
            WritebackOwedWrite::query()->update(['not_before' => null]);
            $queue->drain($subject);
        }

        $this->assertSame(0, WritebackOwedWrite::query()->count(), 'the write was given up');
        $this->assertCount(1, $this->logged('move_card.moved'), 'five attempts and a give-up are still one landing');
        $this->assertCount(OwedWriteQueue::MAX_ATTEMPTS, $this->logged('move_card.stamp_transient_failure'), 'each attempt that failed the stamp says so');
        $gaveUp = $this->logged('owed_write.gave_up');
        $this->assertCount(1, $gaveUp);
        $this->assertSame('move', $gaveUp[0]['op'], 'the give-up carries the handler\'s declared op whether or not the move landed');
        $this->assertSame($this->logged('move_card.moved')[0]['webhook_event_id'], $gaveUp[0]['webhook_event_id'], 'the landing and the give-up join on the delivery');
    }

    public function test_a_card_already_at_the_stage_whose_stamp_is_rate_limited_gives_up_with_an_outcome_to_join(): void
    {
        $event = $this->stubCard(stage: 52, stampStatus: 429);

        $this->giveUp('kanban_move_card', $this->target(), $event);

        $this->assertCount(0, $this->logged('move_card.moved'), 'the bridge moved nothing: the card was already there');
        $this->assertSame(['move_card.already_at_stage'], $this->outcomeIdsFor($event));
        $this->assertFalse($this->abandoned($event), 'a give-up for a card already at its stage is not an abandoned move');
    }

    public function test_a_pinned_card_whose_stamp_is_rate_limited_gives_up_as_declined_not_abandoned(): void
    {
        $event = $this->stubCard(stage: 49, stampStatus: 429, blockReason: 'human hold');

        $this->giveUp('kanban_move_card', $this->target(), $event);

        $this->assertCount(0, $this->logged('move_card.moved'));
        $this->assertSame(['pin_guard.pinned'], $this->outcomeIdsFor($event));
        $this->assertSame((int) $event->id, $this->logged('pin_guard.pinned')[0]['webhook_event_id'], 'the pin-decline row carries the delivery from the scope');
        $this->assertFalse($this->abandoned($event));
    }

    public function test_a_dependabot_card_created_and_then_rate_limited_on_the_re_read_gives_up_with_its_creation_to_join(): void
    {
        $event = $this->dependabotCreateThenRateLimitTheReread();

        $this->giveUp('kanban_dependabot_card', ReactionTarget::make('kanban_dependabot_card', 'pr-42', payload: [
            'repo' => 'owner/repo', 'outcome' => 'opened', 'pr_number' => 42,
            'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/owner/repo/pull/42',
        ]), $event);

        $this->assertSame(['dependabot_card.created'], $this->outcomeIdsFor($event));
        $this->assertSame('move', $this->logged('owed_write.gave_up')[0]['op'], 'the give-up says move although the card was created');
        $this->assertFalse($this->abandoned($event));
    }

    public function test_the_control_a_move_that_never_landed_is_abandoned(): void
    {
        $event = $this->stubCard(stage: 49, stampStatus: 200, moveStatus: 429);

        $this->giveUp('kanban_move_card', $this->target(), $event);

        $this->assertSame([], $this->outcomeIdsFor($event), 'kanban refused every move, so nothing landed and nothing was declined');
        $this->assertTrue($this->abandoned($event), 'the predicate discriminates: with no outcome row the give-up IS an abandoned move');
    }

    public function test_every_outcome_kind_is_a_kind_the_catalog_declares_and_the_join_reads_them_from_there(): void
    {
        $catalog = $this->catalog();
        $this->assertNotSame([], $this->outcomeCatalogIds());
        foreach ($catalog['outcome_kinds'] as $kind) {
            $this->assertArrayHasKey($kind, $catalog['kinds']);
        }
    }

    /**
     * One card, `$stage`, its stamp answered with $stampStatus and its move with $moveStatus. A move
     * that is answered 200 lands (the card then reads back at the target stage).
     */
    private function stubCard(int $stage, int $stampStatus, ?string $blockReason = null, int $moveStatus = 200): WebhookEvent
    {
        $moved = false;
        Http::fake([
            '*/tasks/5.json' => function (Request $request) use (&$moved, $stage, $stampStatus, $blockReason, $moveStatus) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $moved ? 52 : $stage, 'block_reason' => $blockReason, 'tags' => [], 'payload' => []]]);
                }
                if (array_key_exists('workflow_stage_id', $request->data())) {
                    if ($moveStatus !== 200) {
                        return Http::response(['message' => 'Too Many Attempts.'], $moveStatus);
                    }
                    $moved = true;

                    return Http::response(['data' => ['id' => 5]]);
                }

                return $stampStatus === 200 ? Http::response(['data' => ['id' => 5]]) : Http::response(['message' => 'Too Many Attempts.'], $stampStatus);
            },
        ] + PreloadStub::stub(8, [48 => 2, 49 => 3, 51 => 4, 52 => 5]) + ScopeLookupStub::onMappedBoard(8));
        Log::spy();

        return WebhookEvent::query()->create(['delivery_id' => 'join-'.uniqid(), 'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => []]);
    }

    /** The board has no card for the PR at first; after the create, every read of it is rate-limited. */
    private function dependabotCreateThenRateLimitTheReread(): WebhookEvent
    {
        $reads = 0;
        Http::fake([
            '*/boards/8/custom_fields.json' => Http::response(['data' => [
                ['key' => 'pr_number', 'type' => 'number', 'options' => null],
                ['key' => 'pr_url', 'type' => 'url', 'options' => null],
                ['key' => 'origin', 'type' => 'string', 'options' => null],
            ]]),
            '*/tasks/search.json*' => function () use (&$reads) {
                return ++$reads === 1 ? Http::response(['data' => []]) : Http::response(['message' => 'Too Many Attempts.'], 429);
            },
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ] + ScopeLookupStub::onMappedBoard(8));
        Log::spy();

        return WebhookEvent::query()->create(['delivery_id' => 'join-'.uniqid(), 'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'pull_request.opened', 'payload' => []]);
    }

    /** Owe the write and drain it until the queue gives it up. */
    private function giveUp(string $handler, ReactionTarget $target, WebhookEvent $event): void
    {
        $queue = $this->queue();
        $subject = OwedWriteQueue::subjectKey('kanban', '5', $handler, $target->debounceKey);
        $queue->enqueue($subject, $target, $this->agent(), $event);
        $queue->drain($subject);
        for ($retry = 1; $retry < OwedWriteQueue::MAX_ATTEMPTS && WritebackOwedWrite::query()->count() > 0; $retry++) {
            WritebackOwedWrite::query()->update(['not_before' => null]);
            $queue->drain($subject);
        }
        $this->assertSame(0, WritebackOwedWrite::query()->count(), 'the write was given up');
        $this->assertCount(1, $this->logged('owed_write.gave_up'));
    }

    /**
     * The join rule `docs/writeback.md` states: a give-up is an abandoned move only if NO row of an
     * outcome kind carries its `webhook_event_id`.
     */
    private function abandoned(WebhookEvent $event): bool
    {
        return $this->outcomeIdsFor($event) === [];
    }

    /**
     * The distinct catalog ids of the outcome-kind rows logged for the delivery.
     *
     * @return list<string>
     */
    private function outcomeIdsFor(WebhookEvent $event): array
    {
        $outcome = $this->outcomeCatalogIds();
        $ids = [];
        foreach ($this->loggedRows() as $row) {
            if (($row['webhook_event_id'] ?? null) === (int) $event->id && in_array($row['catalog_id'], $outcome, true)) {
                $ids[$row['catalog_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return list<string> */
    private function outcomeCatalogIds(): array
    {
        $catalog = $this->catalog();

        return array_values(array_map(
            fn (array $e): string => $e['id'],
            array_filter($catalog['entries'], fn (array $e): bool => in_array($e['kind'], $catalog['outcome_kinds'], true) && ! isset($e['retired_since'])),
        ));
    }

    /** @return array{outcome_kinds: list<string>, kinds: array<string, string>, entries: list<array{id: string, kind: string}>} */
    private function catalog(): array
    {
        /** @var array{outcome_kinds: list<string>, kinds: array<string, string>, entries: list<array{id: string, kind: string}>} */
        return json_decode((string) file_get_contents(base_path('docs/board-mover-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** kanban lands the move (the card reads back at the target stage once PATCHed), and answers every stamp with a 429. */
    private function landThenRateLimitTheStamp(): WebhookEvent
    {
        $moved = false;
        Http::fake([
            '*/tasks/5.json' => function (Request $request) use (&$moved) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $moved ? 52 : 49, 'block_reason' => null, 'tags' => [], 'payload' => []]]);
                }
                if (array_key_exists('workflow_stage_id', $request->data())) {
                    $moved = true;

                    return Http::response(['data' => ['id' => 5]]);
                }

                return Http::response(['message' => 'Too Many Attempts.'], 429);
            },
        ] + PreloadStub::stub(8, [48 => 2, 49 => 3, 51 => 4, 52 => 5]) + ScopeLookupStub::onMappedBoard(8));
        Log::spy();

        return WebhookEvent::query()->create(['delivery_id' => 'join-1', 'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => []]);
    }

    private function target(): ReactionTarget
    {
        return ReactionTarget::make('kanban_move_card', '5', payload: ['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'merged', 'stamp_dl' => 'DL-42']);
    }

    /**
     * The context of every row logged with $catalogId, at any level.
     *
     * @return list<array<string, mixed>>
     */
    private function logged(string $catalogId): array
    {
        return array_values(array_filter($this->loggedRows(), fn (array $row): bool => $row['catalog_id'] === $catalogId));
    }

    /**
     * The context of every catalogued row logged, at any level.
     *
     * @return list<array<string, mixed>>
     */
    private function loggedRows(): array
    {
        $seen = [];
        foreach (['info', 'warning', 'error'] as $level) {
            try {
                Log::shouldHaveReceived($level)->withArgs(function (string $message, array $context = []) use (&$seen): bool {
                    if (is_string($context['catalog_id'] ?? null)) {
                        $seen[] = $context;
                    }

                    return true;
                });
            } catch (\Throwable) {
                // no call at this level
            }
        }

        return $seen;
    }

    private function queue(): OwedWriteQueue
    {
        return new OwedWriteQueue(new HandlerRegistry, new SubscriptionRegistry($this->dir));
    }

    private function agent(): AgentConfig
    {
        return AgentConfig::load('prod-agent', $this->dir);
    }
}
