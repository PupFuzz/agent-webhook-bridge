<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Writeback\OwedWriteQueue;
use App\Models\AgentDispatch;
use App\Models\WritebackOwedWrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\HandlerRecorder;
use Tests\Fixtures\OwedWriteProbeClassifier;
use Tests\Fixtures\RecordingDurableHandler;
use Tests\Fixtures\RecordingHandler;
use Tests\Support\KanbanCardStub;
use Tests\Support\PreloadStub;
use Tests\Support\ScopeLookupStub;
use Tests\TestCase;

/**
 * card#10849 / DL-440 on the real surface: a durable writeback kanban rate-limits reaches the
 * route, the dispatcher and the owed-write queue. The delivery answers 200 with the write
 * recorded as OWED, every other target / push / agent still runs, and the bridge applies the
 * write itself — inline on the subject's next live event, or on the retry sweep (on by default) — in the order
 * live processing would have used.
 *
 * ⛔ WHAT IT REPLACES: `RateLimitedWritebackRedeliveryTest` (fbf99b3), which asserted the
 * opposite outcome — a 5xx and a redelivery. Every durable writeback handler is reached through
 * a GitHub classifier, and GitHub never redelivers (DL-183), so that outcome lost the write.
 */
class OwedWriteDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_URL = 'http://127.0.0.1:9931/';

    /** Stage id => board position: 48 backlog, 49 in progress, 51 in review, 52 shipped. */
    private const STAGES = [48 => 2, 49 => 3, 51 => 4, 52 => 5];

    private string $dir;

    private string $kanbanSecret = 'owed-scope-5-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    private string $githubSecret = 'owed-gh-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        HandlerRecorder::reset();
        $this->dir = sys_get_temp_dir().'/bridge-10849-owed-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', $this->kanbanSecret);
        File::put($this->dir.'/github/webhook-secret-scope-acme-corp%2Fwidget', $this->githubSecret);
        File::put($this->dir.'/kanban/writeback-token', 'wb-token'); // gitleaks:allow — test fixture
        foreach (File::allFiles($this->dir) as $f) {
            chmod($f->getPathname(), 0o600);
        }
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 51, 'merged' => 52, 'closed_unmerged' => 49]]],
        ]));

        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.config_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);

        $handlers = $this->app->make(HandlerRegistry::class);
        $handlers->register('test_durable_probe', new RecordingDurableHandler('durable-probe'));
        $handlers->register('test_push_probe', new RecordingHandler('push-probe'));
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        HandlerRecorder::reset();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // --- §10.2: dispatch isolation on the real GitHub route ---

    public function test_a_rate_limited_move_on_the_github_route_answers_200_owes_one_row_and_every_other_target_and_agent_runs(): void
    {
        $this->githubAgent('agent-a');
        $this->githubAgent('agent-b');
        $stub = $this->card(48);
        $stub->rateLimitedPatches = PHP_INT_MAX;
        $this->fake($stub);

        $this->postGithub(['with_probes' => true], 'gh-owed-1')->assertStatus(200);

        // ONE row for two agents emitting the same write: agent-b's insert was a no-op.
        $this->assertSame(1, WritebackOwedWrite::query()->count());
        $row = WritebackOwedWrite::query()->sole();
        $this->assertSame('kanban_move_card', $row->handler);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(429, $row->last_status);
        // agent-b drained the same subject, found its head not yet due, and sent nothing: one PATCH.
        $this->assertCount(1, $stub->patchesTo(5));
        $this->assertSame(48, $stub->cards[5]['workflow_stage_id']);

        // Each agent's OTHER durable target and its best-effort push still ran.
        $this->assertSame(['durable-probe', 'push-probe', 'durable-probe', 'push-probe'], HandlerRecorder::$calls);
        foreach (['agent-a', 'agent-b'] as $agent) {
            $dispatch = AgentDispatch::query()->where('agent_name', $agent)->sole();
            $this->assertNotNull($dispatch->processed_at, "{$agent} was dispatched to completion");
            $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $dispatch->outcome);
            $this->assertStringContainsString('owed write: kanban_move_card (5)', (string) $dispatch->error_message);
        }
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::ALERT_URL));
    }

    // --- §10.3: inline recovery on the subject's next live event ---

    public function test_the_next_live_event_for_the_subject_applies_the_owed_write_inline_with_no_scheduler(): void
    {
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $this->fake($stub);

        $this->deliverKanban('k-owed-1', ['outcome' => 'merged'])->assertStatus(200);
        $this->assertSame(1, WritebackOwedWrite::query()->count());
        $this->assertSame(48, $stub->cards[5]['workflow_stage_id']);

        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $this->deliverKanban('k-owed-2', ['outcome' => 'merged'])->assertStatus(200);

        $this->assertSame(0, WritebackOwedWrite::query()->count(), 'both rows were applied by the second delivery');
        $this->assertSame(52, $stub->cards[5]['workflow_stage_id']);
        $this->assertSame([['workflow_stage_id' => 52]], $stub->appliedPatchesTo(5), 'the owed move landed once; the second event found the card already there');
        $second = AgentDispatch::query()->whereHas('webhookEvent', fn ($q) => $q->where('delivery_id', 'k-owed-2'))->sole();
        $this->assertNull($second->error_message);
    }

    // --- §10.4: the retry sweep ---

    public function test_the_retry_sweep_applies_a_due_owed_write_once_and_its_next_pass_sends_nothing(): void
    {
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $this->fake($stub);
        $this->deliverKanban('k-sweep-1', ['outcome' => 'merged'])->assertStatus(200);
        $this->assertSame(1, WritebackOwedWrite::query()->count());

        [$scheduler] = $this->armedSweep();
        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $this->assertTrue($scheduler->pass(JobPassSource::Tick)->didRun());

        $this->assertSame(0, WritebackOwedWrite::query()->count());
        $this->assertSame([['workflow_stage_id' => 52]], $stub->appliedPatchesTo(5));

        $patches = count($stub->patchesTo(5));
        $this->travel(3600)->seconds();
        $this->assertTrue($scheduler->pass(JobPassSource::Tick)->didRun());
        $this->assertCount($patches, $stub->patchesTo(5), 'the next pass sent nothing');
    }

    // --- §10.5: FIFO — the M1/MF-B regression, direct ---

    public function test_a_later_event_for_the_same_card_queues_behind_the_owed_one_and_a_live_drain_applies_them_in_order(): void
    {
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $this->fake($stub);

        $this->deliverKanban('fifo-1', ['outcome' => 'opened'])->assertStatus(200);
        $this->deliverKanban('fifo-2', ['outcome' => 'closed_unmerged'])->assertStatus(200);

        // The later write did NOT jump the queue: nothing landed, both rows are owed.
        $this->assertSame([], $stub->appliedPatchesTo(5));
        $this->assertSame(2, WritebackOwedWrite::query()->count());

        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $this->deliverKanban('fifo-3', ['outcome' => 'closed_unmerged'])->assertStatus(200);

        $this->assertSame([['workflow_stage_id' => 51], ['workflow_stage_id' => 49]], $stub->appliedPatchesTo(5), 'opened, THEN closed_unmerged');
        $this->assertSame(49, $stub->cards[5]['workflow_stage_id']);
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    public function test_a_later_event_for_the_same_card_queues_behind_the_owed_one_and_the_sweep_applies_them_in_order(): void
    {
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $this->fake($stub);

        $this->deliverKanban('fifo-s-1', ['outcome' => 'opened'])->assertStatus(200);
        $this->deliverKanban('fifo-s-2', ['outcome' => 'closed_unmerged'])->assertStatus(200);
        $this->assertSame([], $stub->appliedPatchesTo(5));

        [$scheduler] = $this->armedSweep();
        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $scheduler->pass(JobPassSource::Tick);

        $this->assertSame([['workflow_stage_id' => 51], ['workflow_stage_id' => 49]], $stub->appliedPatchesTo(5), 'opened, THEN closed_unmerged');
        $this->assertSame(49, $stub->cards[5]['workflow_stage_id']);
    }

    // --- §10.12: the no-regression guard does not fail open on a rate limit ---

    public function test_a_queued_opened_retried_after_merged_landed_requeues_on_a_rate_limited_order_read_instead_of_moving_backward(): void
    {
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $preloadRefusals = 0;
        $this->fake($stub, preload: function () use (&$preloadRefusals) {
            if ($preloadRefusals > 0) {
                $preloadRefusals--;

                return Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']);
            }

            return PreloadStub::stub(8, self::STAGES)['*/boards/8/preload.json'];
        });

        // `opened` is rate-limited on its PATCH and queued. Its first retry is due only after
        // the backoff — by which time the PR has MERGED and the card is Shipped.
        $this->deliverKanban('regress-1', ['outcome' => 'opened'])->assertStatus(200);
        $this->assertSame([], $stub->appliedPatchesTo(5));
        $stub->cards[5]['workflow_stage_id'] = 52;

        // The retry's stage-order read is rate-limited: the move must be requeued, not allowed.
        $this->freshRequest();
        $preloadRefusals = 1;
        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $this->deliverKanban('regress-2', ['outcome' => 'merged'])->assertStatus(200);
        $this->assertSame(0, $preloadRefusals, 'the retry read the stage order and was refused');
        $this->assertSame(52, $stub->cards[5]['workflow_stage_id'], 'the stale opened did not drag the card backward');
        $this->assertSame([], $stub->appliedPatchesTo(5));
        $this->assertSame(2, WritebackOwedWrite::query()->orderBy('id')->first()?->attempts);

        // Once the order reads, the guard refuses the backward move and the queue drains clean.
        $this->freshRequest();
        $this->travel(OwedWriteQueue::BASE_BACKOFF_S * 2 + 1)->seconds();
        $this->deliverKanban('regress-3', ['outcome' => 'merged'])->assertStatus(200);
        $this->assertSame(52, $stub->cards[5]['workflow_stage_id']);
        $this->assertSame([], $stub->appliedPatchesTo(5), 'no backward move was ever applied');
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    // --- §10.13: a failed insert is the existing durability contract ---

    public function test_with_the_owed_write_table_missing_a_durable_delivery_5xxs_and_one_without_a_durable_target_does_not(): void
    {
        $this->kanbanAgent();
        $this->fake($this->card(48));
        $file = glob(database_path('migrations/*_create_writeback_owed_writes_table.php'));
        $this->assertCount(1, $file, 'the owed-write migration has been renamed or split');
        $migration = require $file[0];

        // ⚑ Through the migration's own down()/up(), never an open-coded drop: DDL commits
        // RefreshDatabase's transaction on MariaDB, so an unrestored drop takes the table from
        // every later test (the BridgeCommandsTest precedent).
        $migration->down();
        try {
            $durable = $this->deliverKanban('missing-1', ['outcome' => 'merged']);
            $pushOnly = $this->deliverKanban('missing-2', ['only_push' => true]);
        } finally {
            $migration->up();
        }

        $durable->assertStatus(500);
        $pushOnly->assertStatus(200);
    }

    // --- §10.14: two notes on one dispatch ---

    public function test_a_failed_push_note_and_an_owed_write_note_are_both_kept_on_the_dispatch(): void
    {
        $this->app->make(HandlerRegistry::class)->register('test_push_probe', new RecordingHandler('push-probe', throw: true));
        $this->kanbanAgent();
        $stub = $this->card(48);
        $stub->rateLimitedPatches = 1;
        $this->fake($stub);

        $this->deliverKanban('notes-1', ['with_probes' => true])->assertStatus(200);

        $message = (string) AgentDispatch::query()->sole()->error_message;
        $this->assertStringContainsString('push-probe failed', $message);
        $this->assertStringContainsString('owed write: kanban_move_card (5)', $message);
    }

    // --- §10.15: the leg that swallows its own failure keeps doing so (the DL-386 owner-tag clear it once shared this with was retired by DL-439) ---

    public function test_a_rate_limited_card_note_is_still_swallowed_and_alerted_and_the_move_is_not_owed(): void
    {
        $this->kanbanAgent();
        $stub = new KanbanCardStub([5 => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'block_reason' => null, 'tags' => [], 'payload' => ['pr_number' => 261]]]);
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5/comments.json' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '37']),
        ] + $stub->stub() + PreloadStub::stub(8, self::STAGES) + ScopeLookupStub::onMappedBoard(8));

        $this->deliverKanban('card-note-1', ['outcome' => 'merged', 'extra' => ['stamp_pr' => 262]])->assertStatus(200);

        $this->assertSame(0, WritebackOwedWrite::query()->count(), 'the rate limit never reached the queue');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tasks/5/comments.json'));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::ALERT_URL) && $r['reason'] === 'cardnote_send_failed');
    }

    // --- helpers ---

    /**
     * What a new FPM request has and one test's `$this->call()`s do not: a fresh move handler.
     * Its board stage-order memo is request-scoped by design (KanbanMoveCardHandler), and the
     * container singleton holding it outlives a request inside one test.
     */
    private function freshRequest(): void
    {
        $this->app->make(HandlerRegistry::class)->register('kanban_move_card', new KanbanMoveCardHandler);
    }

    private function card(int $stage): KanbanCardStub
    {
        return new KanbanCardStub([5 => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $stage, 'block_reason' => null, 'tags' => []]]);
    }

    /**
     * @param  (\Closure(Request): mixed)|null  $preload  answers the board's preload read INSTEAD of the ordinary stub
     */
    private function fake(KanbanCardStub $stub, ?\Closure $preload = null): void
    {
        $preloadStub = $preload === null ? PreloadStub::stub(8, self::STAGES) : ['*/boards/8/preload.json' => $preload];
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])] + $stub->stub() + $preloadStub + ScopeLookupStub::onMappedBoard(8));
    }

    private function kanbanAgent(): void
    {
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".OwedWriteProbeClassifier::class."'\n");
    }

    private function githubAgent(string $name): void
    {
        File::put($this->dir."/{$name}.yml", "subscriptions:\n  - provider: github\n    scopes: [acme-corp/widget]\n"
            ."classifier:\n  class: '".OwedWriteProbeClassifier::class."'\n");
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function deliverKanban(string $delivery, array $probe): TestResponse
    {
        $body = (string) json_encode([
            'event' => 'task.moved', 'board_id' => 5, 'delivery_id' => $delivery, 'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2], 'probe' => $probe,
        ]);

        return $this->call('POST', '/webhooks/kanban?b=5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->kanbanSecret),
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function postGithub(array $probe, string $delivery): TestResponse
    {
        $body = (string) json_encode([
            'action' => 'closed', 'repository' => ['full_name' => 'acme-corp/widget'],
            'sender' => ['id' => 990001, 'login' => 'someone'], 'probe' => $probe,
        ]);

        return $this->call('POST', '/webhooks/github?b=acme-corp/widget', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->githubSecret),
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
        ], $body);
    }

    /**
     * The sweep, armed the way it ships (nothing disarmed, DL-441), and
     * one instance declared. Built after the first delivery so the event gate's own pass during
     * that delivery cannot have advanced the instance's clock.
     *
     * @return array{0: JobScheduler}
     */
    private function armedSweep(): array
    {
        $handlers = new JobHandlerRegistry([], $this->app->make(StandupGate::class), $this->app->make(HandlerRegistry::class));
        (new JobRegistry($handlers))->insert(new JobSpec(
            name: 'writeback-owed-writes',
            handler: OwedWriteRetryJob::NAME,
            intervalS: 300,
            owner: 'test',
            docsRef: 'docs/writeback.md',
            justification: 'a subject that sees no further event has no arrival to retry it on',
        ));

        return [new JobScheduler($handlers)];
    }
}
