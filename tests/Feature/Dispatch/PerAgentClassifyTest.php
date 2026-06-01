<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\RecipientAwareClassifier;
use Tests\TestCase;

/**
 * Acceptance test for the per-agent classify() param (FR: serving agent →
 * classify). Proves both halves: (i) the dispatcher passes EACH subscribed
 * agent its own config through the shared cached classifier instance (no leak),
 * and (ii) a classifier can therefore filter by recipient — only the addressed
 * agent stages an intent.
 */
class PerAgentClassifyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        RecipientAwareClassifier::reset();
        $this->dir = sys_get_temp_dir().'/peragent-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(string $name): void
    {
        File::put($this->dir."/{$name}.yml",
            "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".RecipientAwareClassifier::class."'\n");
    }

    private function dispatcher(): DispatchService
    {
        $subs = new SubscriptionRegistry($this->dir);

        return new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function inboxLines(): array
    {
        $path = $this->dir.'/state/inbox.jsonl';
        if (! File::exists($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $rows[] = json_decode($line, true);
        }

        return $rows;
    }

    public function test_each_serving_agent_is_passed_its_own_config_and_classifier_can_filter_by_recipient(): void
    {
        $this->writeAgent('agent-a');
        $this->writeAgent('agent-b');

        // One event addressed only to agent-b, delivered to both subscribers.
        $dto = new EventDto(deliveryId: 'evt-1', scopeId: '5', eventType: 'task.created', actorId: '999');
        $this->dispatcher()->dispatch('kanban', '5', $dto, ['subject_id' => 7, 'to' => 'agent-b']);

        // (i) classify ran once per agent, each seeing its OWN agentName — proves
        // the shared cached instance received distinct $agent per call (no leak).
        $this->assertEqualsCanonicalizing(['agent-a', 'agent-b'], RecipientAwareClassifier::$seenAgents);

        // (ii) only the addressed agent staged an intent; the other filtered.
        $lines = $this->inboxLines();
        $this->assertCount(1, $lines);
        $this->assertSame('agent-b', $lines[0]['agent']);
        $this->assertSame('addressed', $lines[0]['kind']);
    }

    public function test_broadcast_to_all_reaches_every_agent(): void
    {
        $this->writeAgent('agent-a');
        $this->writeAgent('agent-b');

        $dto = new EventDto(deliveryId: 'evt-2', scopeId: '5', eventType: 'task.created', actorId: '999');
        $this->dispatcher()->dispatch('kanban', '5', $dto, ['subject_id' => 7, 'to' => 'all']);

        $agents = array_map(fn (array $l): string => $l['agent'], $this->inboxLines());
        $this->assertEqualsCanonicalizing(['agent-a', 'agent-b'], $agents);
    }
}
