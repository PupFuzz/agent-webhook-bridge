<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentConfig;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IntentLogTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/intentlog-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function event(): WebhookEvent
    {
        $event = WebhookEvent::create([
            'delivery_id' => 'evt-1',
            'provider' => 'kanban',
            'scope_id' => '5',
            'event_type' => 'task.created',
            'actor_id' => '137',
            'payload' => [],
        ]);

        return $event->refresh();
    }

    private function agent(): AgentConfig
    {
        return AgentConfig::fromArray('prod-agent', [
            'identity' => ['self' => 'prod-agent'],
            'api' => ['kanban' => ['base_url' => 'https://k.example.com', 'token_path' => '/t']],
            'receiver' => ['base_url' => 'https://b.example.com/webhooks'],
            'subscriptions' => [],
        ]);
    }

    private function intent(string $subjectId): Intent
    {
        return new Intent('new_card', $subjectId, 'kanban', new Actor(id: '137'), "card {$subjectId}");
    }

    private function inboxLines(): array
    {
        return $this->linesAt($this->dir.'/state/inbox.jsonl');
    }

    private function perAgentLines(string $agent = 'prod-agent'): array
    {
        return $this->linesAt($this->dir.'/state/inbox-'.$agent.'.jsonl');
    }

    private function linesAt(string $path): array
    {
        return array_values(array_filter(array_map(
            fn ($l) => json_decode($l, true),
            File::exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [],
        )));
    }

    public function test_stage_writes_line_with_stable_id_and_intent_fields(): void
    {
        $log = new IntentLog;
        $log->stage($this->agent(), $this->event(), $this->intent('42'), 0);

        $lines = $this->inboxLines();
        $this->assertCount(1, $lines);
        $this->assertSame('evt-1:prod-agent:0', $lines[0]['id']);
        $this->assertSame('new_card', $lines[0]['kind']);
        $this->assertSame('42', $lines[0]['subject_id']);
        $this->assertArrayHasKey('ts', $lines[0]);
    }

    public function test_restage_appends_with_stable_id_and_ts_deduped_on_read(): void
    {
        // DL-012: the writer no longer scans the file before appending (that was
        // O(file) on the hot path). A partial-staging redelivery re-appends the
        // same id with the SAME stable ts; bridge:inbox collapses it on read
        // (see InboxCommandTest). Full redelivery never reaches here — the
        // processed_at gate skips the agent.
        $log = new IntentLog;
        $event = $this->event();
        $agent = $this->agent();

        $log->stage($agent, $event, $this->intent('42'), 0);
        $tsFirst = $this->inboxLines()[0]['ts'];

        $log->stage($agent, $event, $this->intent('42'), 0);

        $lines = $this->inboxLines();
        $this->assertCount(2, $lines);                          // append-only writer
        $this->assertSame($lines[0]['id'], $lines[1]['id']);    // same stable id → read-side dedup collapses it
        $this->assertSame($tsFirst, $lines[0]['ts']);
        $this->assertSame($tsFirst, $lines[1]['ts']);           // stable ts across re-stage
    }

    public function test_distinct_indexes_get_distinct_ids(): void
    {
        $log = new IntentLog;
        $event = $this->event();
        $agent = $this->agent();

        $log->stage($agent, $event, $this->intent('42'), 0);
        $log->stage($agent, $event, $this->intent('42'), 1);   // same subject+kind, different index

        $lines = $this->inboxLines();
        $this->assertCount(2, $lines);
        $this->assertSame(['evt-1:prod-agent:0', 'evt-1:prod-agent:1'], array_column($lines, 'id'));
    }

    public function test_line_carries_serving_agent(): void
    {
        (new IntentLog)->stage($this->agent(), $this->event(), $this->intent('42'), 0);

        $lines = $this->inboxLines();
        $this->assertSame('prod-agent', $lines[0]['agent']);   // serving agent, distinct from actor (author)
    }

    public function test_per_agent_layout_writes_only_the_per_agent_file(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);

        (new IntentLog)->stage($this->agent(), $this->event(), $this->intent('42'), 0);

        $this->assertSame([], $this->inboxLines());                  // shared file NOT written
        $perAgent = $this->perAgentLines();
        $this->assertCount(1, $perAgent);
        $this->assertSame('evt-1:prod-agent:0', $perAgent[0]['id']);
        $this->assertSame('prod-agent', $perAgent[0]['agent']);
    }

    public function test_both_layout_writes_shared_and_per_agent(): void
    {
        config(['bridge.inbox_layout' => 'both']);

        (new IntentLog)->stage($this->agent(), $this->event(), $this->intent('42'), 0);

        $this->assertCount(1, $this->inboxLines());
        $this->assertCount(1, $this->perAgentLines());
        $this->assertSame($this->inboxLines()[0]['id'], $this->perAgentLines()[0]['id']);
    }

    public function test_per_agent_file_restage_appends_same_id(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);
        $event = $this->event();
        $agent = $this->agent();

        (new IntentLog)->stage($agent, $event, $this->intent('42'), 0);
        (new IntentLog)->stage($agent, $event, $this->intent('42'), 0);   // partial-staging redelivery

        // Append-only writer (DL-012); the duplicate id is collapsed by bridge:inbox on read.
        $lines = $this->perAgentLines();
        $this->assertCount(2, $lines);
        $this->assertSame($lines[0]['id'], $lines[1]['id']);
    }
}
