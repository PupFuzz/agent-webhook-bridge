<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\WebhookOutageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\MoveCardFiveClassifier;
use Tests\Support\KanbanCardStub;
use Tests\Support\PreloadStub;
use Tests\Support\ScopeLookupStub;
use Tests\TestCase;

/**
 * card#10849 on the real surface: a writeback move kanban rate-limits (429) reaches the route,
 * the dispatcher and the exception handler as a TRANSIENT failure — the receiver answers 5xx so
 * the upstream redelivers, the run is recorded and surfaced by `bridge:inbox` for as long as it
 * lasts, and the redelivery that gets through lands the move once. Before card#10849 the same 429
 * was swallowed as a permanent refusal: the delivery answered 200, kanban never redelivered, and
 * the card stayed where it was.
 */
class RateLimitedWritebackRedeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const ALERT_URL = 'http://127.0.0.1:9931/';

    private string $dir;

    private string $secret = 'ratelimit-scope-5-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/bridge-10849-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', $this->secret);
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        foreach (File::allFiles($this->dir) as $f) {
            chmod($f->getPathname(), 0o600);
        }
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".MoveCardFiveClassifier::class."'\n");

        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.config_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_rate_limited_move_5xxs_is_surfaced_while_it_lasts_and_lands_once_on_the_redelivery_that_gets_through(): void
    {
        $stub = new KanbanCardStub([5 => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'block_reason' => null, 'tags' => []]]);
        $stub->rateLimitedPatches = 2;
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])] + $stub->stub()
            + PreloadStub::stub(8, [49 => 3, 52 => 5]) + ScopeLookupStub::onMappedBoard(8));

        // kanban redelivers the SAME delivery; a failed dispatch is not a processed one.
        $this->deliver()->assertStatus(500);
        $this->deliver()->assertStatus(500);

        $this->assertSame(49, $stub->cards[5]['workflow_stage_id'], 'nothing moved while kanban was refusing');
        $this->assertSame(2, WebhookOutageRecord::read()['failing']['count'] ?? null);
        $this->assertStringContainsString('2 consecutive webhook 5xx', $this->inbox(), 'the run is surfaced while it lasts');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL));

        $this->deliver()->assertStatus(200);

        $this->assertSame(52, $stub->cards[5]['workflow_stage_id']);
        $this->assertSame([['workflow_stage_id' => 52]], $stub->appliedPatchesTo(5), 'one applied move across three deliveries');
        $this->assertNull(WebhookOutageRecord::read()['failing'] ?? null, 'the run ended');
    }

    private function deliver()
    {
        $body = (string) json_encode([
            'event' => 'task.moved',
            'board_id' => 5,
            'delivery_id' => 'ratelimit-10849',
            'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2],
        ]);

        return $this->call('POST', '/webhooks/kanban?b=5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
        ], $body);
    }

    private function inbox(): string
    {
        $this->assertSame(0, Artisan::call('bridge:inbox', ['--hook-format' => 'plain']));

        return trim(Artisan::output());
    }
}
