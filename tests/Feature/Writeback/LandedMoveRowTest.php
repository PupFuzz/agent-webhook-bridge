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
 * card#11223, the landing row of a move whose stamp is rate-limited. The real `KanbanMoveCardHandler`
 * runs through the real owed-write queue; kanban lands the move and then rate-limits the stamp. The
 * landing is logged the moment the move succeeds, so it is recorded once although the write stays
 * owed and its retries find the card already at the stage. The rows carry the delivery's
 * `webhook_event_id` from the scope. That id names a DELIVERY, not a card or a write, and a
 * give-up's `op: move` means "not confirmed", so this test does not decide whether a move landed
 * from it.
 */
class LandedMoveRowTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-11223-landed-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
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

    public function test_the_retries_and_the_give_up_add_no_landing(): void
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
        $this->assertSame((int) $event->id, $gaveUp[0]['webhook_event_id'], 'the give-up carries the delivery from the scope, as the landing does');
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

        return WebhookEvent::query()->create(['delivery_id' => 'landed-1', 'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => []]);
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
        $seen = [];
        foreach (['info', 'warning', 'error'] as $level) {
            try {
                Log::shouldHaveReceived($level)->withArgs(function (string $message, array $context = []) use ($catalogId, &$seen): bool {
                    if (($context['catalog_id'] ?? null) === $catalogId) {
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
