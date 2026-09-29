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
use Tests\Fixtures\HandlerRecorder;
use Tests\Fixtures\RecordingDurableHandler;
use Tests\Support\KanbanCardStub;
use Tests\TestCase;

/**
 * card#10849 / DL-440 § 3b through the owed-write queue: a handler with its own candidate loop
 * (the release promote scan) that meets a rate limit mid-scan becomes ONE owed row for the whole
 * target, a different subject drained beside it is unaffected, and the retry re-scans and applies
 * what is still eligible — each card moved once.
 */
class OwedWriteLoopHandlerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        HandlerRecorder::reset();
        $this->dir = sys_get_temp_dir().'/bridge-10849-loop-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token'); // gitleaks:allow — test fixture
        File::put($this->dir.'/github/token', 'ghp_read'); // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        chmod($this->dir.'/github/token', 0o600);
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53], 'promote_on_release' => true]],
        ]));
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: github\n    scopes: [owner/repo]\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        HandlerRecorder::reset();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_github_rate_limit_mid_scan_owes_one_row_spares_another_subject_and_the_retry_rescans(): void
    {
        $cards = [
            5 => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'block_reason' => null, 'tags' => [], 'payload' => ['pr_number' => 100, 'pr_url' => 'https://github.com/owner/repo/pull/100']],
            7 => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 52, 'block_reason' => null, 'tags' => [], 'payload' => ['pr_number' => 103, 'pr_url' => 'https://github.com/owner/repo/pull/103']],
        ];
        $stub = new KanbanCardStub($cards);
        $githubRefusals = 1;
        Http::fake([
            '*/tasks/search.json*' => fn () => Http::response(['data' => array_values(array_filter($stub->cards, static fn (array $c): bool => $c['workflow_stage_id'] === 52)), 'links' => ['next' => null]]),
            'https://api.github.com/repos/owner/repo/pulls/*' => function (Request $r) use (&$githubRefusals) {
                if ($githubRefusals > 0) {
                    $githubRefusals--;

                    return Http::response(['message' => 'API rate limit exceeded'], 429, ['Retry-After' => '60']);
                }

                return Http::response(['merged' => true, 'merge_commit_sha' => 'SHA'.basename($r->url()), 'state' => 'closed', 'base' => ['ref' => 'dev']]);
            },
            'https://api.github.com/repos/owner/repo/compare/*' => Http::response(['status' => 'ahead']),
        ] + $stub->stub());

        $handlers = new HandlerRegistry;
        $handlers->register('test_durable_probe', new RecordingDurableHandler('other-subject'));
        $queue = new OwedWriteQueue($handlers, new SubscriptionRegistry($this->dir));
        $promote = OwedWriteQueue::subjectKey('github', 'owner/repo', 'kanban_promote_released', 'owner/repo');
        $other = OwedWriteQueue::subjectKey('github', 'owner/repo', 'test_durable_probe', 'probe');

        $event = $this->event();
        $queue->enqueue($promote, ReactionTarget::make('kanban_promote_released', 'owner/repo', payload: ['repo' => 'owner/repo']), $this->agent(), $event);
        $queue->drain($promote);
        $queue->enqueue($other, ReactionTarget::make('test_durable_probe', 'probe'), $this->agent(), $event);
        $queue->drain($other);

        // ONE owed row for the whole scan; the scan stopped at the first GitHub refusal.
        $this->assertSame(1, WritebackOwedWrite::query()->count());
        $this->assertSame('kanban_promote_released', WritebackOwedWrite::query()->sole()->handler);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/pulls/')));
        $this->assertSame([], $stub->patchesTo(5));
        $this->assertSame([], $stub->patchesTo(7));
        // A different subject, drained right after, was not held behind it.
        $this->assertSame(['other-subject'], HandlerRecorder::$calls);

        $this->travel(OwedWriteQueue::BASE_BACKOFF_S + 1)->seconds();
        $queue->drain($promote);

        $this->assertSame([['workflow_stage_id' => 53]], $stub->appliedPatchesTo(5));
        $this->assertSame([['workflow_stage_id' => 53]], $stub->appliedPatchesTo(7));
        $this->assertSame(0, WritebackOwedWrite::query()->count());
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'delivery_id' => 'loop-'.uniqid(), 'provider' => 'github', 'scope_id' => 'owner/repo',
            'event_type' => 'pull_request', 'payload' => [],
        ]);
    }

    private function agent(): AgentConfig
    {
        return AgentConfig::load('prod-agent', $this->dir);
    }
}
