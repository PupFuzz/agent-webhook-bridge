<?php

namespace Tests\Feature\Webhook;

use App\Bridge\Support\WebhookOutageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PDO;
use Tests\Support\SkipsAsRoot;
use Tests\TestCase;

/**
 * The receiver's webhook 5xx record and its `bridge:inbox` surface, driven through the real
 * route, middleware and exception handler with the database genuinely unreachable
 * (card#10158).
 */
class WebhookOutageRecordTest extends TestCase
{
    use RefreshDatabase;
    use SkipsAsRoot;

    private const SENTINEL_HOST = 'sentinel-dbhost-10158.invalid';

    private const SENTINEL_USER = 'sentinel_dbuser_10158';

    private const SENTINEL_PASSWORD = 'sentinel-dbpass-10158'; // gitleaks:allow — fake credential, never a real one

    private string $dir;

    private string $secret = 'outage-scope-5-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    private string $originalDefault;

    private int $deliveries = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = (string) config('database.default');
        $this->dir = sys_get_temp_dir().'/bridge-outage-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', $this->secret);
        File::put($this->dir.'/github/webhook-secret-scope-acme-corp%2Fwidget', 'gh-secret');
        foreach (File::allFiles($this->dir) as $f) {
            chmod($f->getPathname(), 0o600);
        }

        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.config_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
        ]);
    }

    protected function tearDown(): void
    {
        // RefreshDatabase rolls back on the DEFAULT connection, so it must be the migrated one
        // again before parent::tearDown() runs.
        $this->databaseUp();
        @chmod($this->dir.'/state', 0o700);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_db_down_delivery_500s_writes_the_record_and_the_inbox_prints_it_with_the_db_still_down(): void
    {
        Carbon::setTestNow('2026-09-14T14:01:54Z');
        $this->databaseDown();

        $this->deliver()->assertStatus(500);

        $record = WebhookOutageRecord::read();
        $this->assertSame([
            'since' => '2026-09-14T14:01:54Z',
            'last_at' => '2026-09-14T14:01:54Z',
            'count' => 1,
            'last_status' => 500,
        ], $record['failing'] ?? null);

        // Still down: the inbox reads files only.
        $out = $this->inbox();
        $this->assertStringContainsString('WARNING: 1 consecutive webhook 5xx since 2026-09-14T14:01:54Z', $out);
        $this->assertStringContainsString('(last: HTTP 500 at 2026-09-14T14:01:54Z)', $out);

        // A run in progress is not consumed: it prints on every invocation until it ends.
        $this->assertStringContainsString('consecutive webhook 5xx', $this->inbox());
    }

    public function test_consecutive_5xx_are_counted_from_the_first_failure(): void
    {
        $this->databaseDown();

        Carbon::setTestNow('2026-09-14T14:01:54Z');
        $this->deliver()->assertStatus(500);
        Carbon::setTestNow('2026-09-15T09:00:00Z');
        $this->deliver()->assertStatus(500);
        Carbon::setTestNow('2026-09-17T00:20:00Z');
        $this->deliver()->assertStatus(500);

        $this->assertSame([
            'since' => '2026-09-14T14:01:54Z',
            'last_at' => '2026-09-17T00:20:00Z',
            'count' => 3,
            'last_status' => 500,
        ], WebhookOutageRecord::read()['failing'] ?? null);
        $this->assertStringContainsString('3 consecutive webhook 5xx since 2026-09-14T14:01:54Z', $this->inbox());
    }

    public function test_a_2xx_ends_the_run_and_each_consumer_is_shown_the_recovery_once(): void
    {
        $this->databaseDown();
        Carbon::setTestNow('2026-09-14T14:01:54Z');
        $this->deliver()->assertStatus(500);
        Carbon::setTestNow('2026-09-17T00:20:00Z');
        $this->deliver()->assertStatus(500);

        $this->databaseUp();
        Carbon::setTestNow('2026-09-17T00:21:00Z');
        $this->deliver()->assertStatus(200);

        $record = WebhookOutageRecord::read();
        $this->assertNotNull($record);
        $this->assertNull($record['failing']);
        $this->assertSame([
            'since' => '2026-09-14T14:01:54Z',
            'last_failure_at' => '2026-09-17T00:20:00Z',
            'count' => 2,
            'last_status' => 500,
            'recovered_at' => '2026-09-17T00:21:00Z',
        ], $record['recovered'] ?? null);

        // A peek never consumes it.
        $this->assertStringContainsString('recovered at', $this->inbox(['--no-cursor-advance' => true]));

        $out = $this->inbox();
        $this->assertStringContainsString('Webhook deliveries recovered at 2026-09-17T00:21:00Z after 2 consecutive 5xx', $out);
        $this->assertStringContainsString('(2026-09-14T14:01:54Z to 2026-09-17T00:20:00Z, last HTTP 500)', $out);
        $this->assertStringContainsString('`php artisan bridge:reconcile --fix`', $out);
        // The remedy names what it does not recover rather than claiming full recovery.
        $this->assertStringContainsString('only a `dl_number` is skipped', $out);
        $this->assertStringContainsString('a merge with no closing reference', $out);
        $this->assertStringNotContainsString('consecutive webhook 5xx since', $out);

        $this->assertSame('', $this->inbox(), 'the recovery is shown to a consumer ONCE');

        // Another seat on the same install has its own cursor, so it still sees it — once.
        $this->assertStringContainsString('recovered at', $this->inbox(['--agent' => 'pm']));
        $this->assertSame('', $this->inbox(['--agent' => 'pm']));
    }

    public function test_a_new_runs_warning_does_not_re_show_a_recovery_this_consumer_already_saw(): void
    {
        // The two notice classes share one cursor file. Pruning it by "this notice's keys
        // only" would make the first warning of run B drop the mark recording that run A's
        // recovery was already shown — and the consumer would be handed a stale remedy as
        // news, in the same output that says deliveries are failing right now.
        $this->databaseDown();
        Carbon::setTestNow('2026-09-14T14:01:54Z');
        $this->deliver()->assertStatus(500);
        $this->databaseUp();
        Carbon::setTestNow('2026-09-14T15:00:00Z');
        $this->deliver()->assertStatus(200);

        $this->assertStringContainsString('recovered at', $this->inbox(), 'control: the recovery is shown once');
        $this->assertSame('', $this->inbox(), 'control: and consumed');

        // A SECOND outage begins. Its warning is due; run A's recovery is not.
        $this->databaseDown();
        Carbon::setTestNow('2026-09-14T16:00:00Z');
        $this->deliver()->assertStatus(500);

        // ⛔ TWO invocations, and the SECOND is the one that measures: the recovery mark is
        // read before the warning mark is written, so a prune that drops it leaves this call's
        // own output clean and re-shows the recovery on the NEXT one.
        $first = $this->inbox();
        $this->assertStringContainsString('1 consecutive webhook 5xx since 2026-09-14T16:00:00Z', $first);
        $this->assertStringNotContainsString('recovered at', $first);

        $second = $this->inbox();
        $this->assertStringContainsString('1 consecutive webhook 5xx since 2026-09-14T16:00:00Z', $second);
        $this->assertStringNotContainsString('recovered at', $second, 'the warning write must not drop the recovery mark');
    }

    public function test_a_recovery_older_than_the_notice_window_is_not_shown(): void
    {
        $this->databaseDown();
        Carbon::setTestNow('2026-09-14T14:01:54Z');
        $this->deliver()->assertStatus(500);
        $this->databaseUp();
        $this->deliver()->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-09-14T14:01:54Z')->addSeconds(WebhookOutageRecord::NOTICE_WINDOW_SECONDS));
        $this->assertStringContainsString('recovered at', $this->inbox(['--no-cursor-advance' => true]), 'control: inside the window');

        Carbon::setTestNow(Carbon::parse('2026-09-14T14:01:54Z')->addSeconds(WebhookOutageRecord::NOTICE_WINDOW_SECONDS + 1));
        $this->assertSame('', $this->inbox());
    }

    public function test_no_db_credential_reaches_the_record_or_the_inbox_output(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message;
        });

        $this->databaseDown(sentinel: true);
        $this->deliver()->assertStatus(500);

        // Known positive: the failure this delivery hit DID carry the sentinel host, so its
        // absence below is a measurement, not a fixture that had nothing to leak.
        $this->assertStringContainsString(self::SENTINEL_HOST, implode("\n", $logged));

        $file = (string) File::get(WebhookOutageRecord::path());
        $out = $this->inbox();
        $this->assertStringContainsString('consecutive webhook 5xx', $out);
        foreach ([self::SENTINEL_HOST, self::SENTINEL_USER, self::SENTINEL_PASSWORD, 'SQLSTATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $file);
            $this->assertStringNotContainsString($secret, $out);
        }
    }

    public function test_a_record_write_failure_leaves_the_response_unchanged(): void
    {
        $this->skipAsRoot();
        $this->databaseDown();

        $baseline = $this->deliver();
        $baseline->assertStatus(500);
        File::delete(WebhookOutageRecord::path());

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message;
        });
        chmod($this->dir.'/state', 0o500);   // the record can no longer be written

        $broken = $this->deliver();

        $this->assertSame($baseline->getStatusCode(), $broken->getStatusCode());
        $this->assertSame($baseline->headers->get('Content-Type'), $broken->headers->get('Content-Type'));
        $this->assertFileDoesNotExist(WebhookOutageRecord::path());
        $this->assertContains('bridge: could not update the webhook 5xx record', $logged);
    }

    public function test_a_non_webhook_route_5xx_is_not_counted(): void
    {
        Route::post('/not-a-webhook', fn () => abort(500));

        $this->call('POST', '/not-a-webhook')->assertStatus(500);
        $this->assertFileDoesNotExist(WebhookOutageRecord::path());

        // Presence witness: the recorder is live in this test, so the absence above is not vacuous.
        $this->databaseDown();
        $this->deliver()->assertStatus(500);
        $this->assertSame(1, WebhookOutageRecord::read()['failing']['count'] ?? null);
    }

    public function test_a_4xx_and_a_ping_neither_count_nor_end_a_run(): void
    {
        $this->databaseDown();
        $this->deliver()->assertStatus(500);
        $before = WebhookOutageRecord::read();

        $body = $this->kanbanBody();
        $this->post('/webhooks/kanban?b=5', [], [])->assertStatus(401);
        $this->postWebhook('/webhooks/kanban?b=5', $body, ['X-Kanban-Signature' => 'sha256=bad'])->assertStatus(401);

        // A ping is a 2xx that never reaches the database: it proves nothing about recovery.
        $ping = (string) json_encode(['zen' => 'Design for failure.']);
        $this->postWebhook('/webhooks/github?b=acme-corp/widget', $ping, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $ping, 'gh-secret'),
            'X-GitHub-Delivery' => 'gh-ping-10158',
            'X-GitHub-Event' => 'ping',
        ])->assertStatus(200)->assertSee('pong');

        $this->assertSame($before, WebhookOutageRecord::read());
    }

    public function test_an_unreadable_record_is_said_and_does_not_hide_the_intents(): void
    {
        $this->skipAsRoot();
        $this->databaseDown();
        $this->deliver()->assertStatus(500);
        File::put($this->dir.'/state/inbox.jsonl', json_encode(['id' => 'e1', 'kind' => 'new_card', 'summary' => 'card 42'])."\n");
        chmod(WebhookOutageRecord::path(), 0o000);

        $out = $this->inbox();

        $this->assertStringContainsString('webhook delivery health is UNKNOWN', $out);
        $this->assertStringContainsString('card 42', $out);
    }

    public function test_concurrent_failures_lose_no_increment(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run concurrent writers');
        }
        $workers = 8;
        $each = 40;

        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    for ($i = 0; $i < $each; $i++) {
                        WebhookOutageRecord::observe(500, false);
                    }
                } finally {
                    // SIGKILL, never exit() or a return: a child that unwinds would go on to
                    // run the rest of the suite as a second runner. A throw shows up as a
                    // short count below.
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $this->assertSame($workers * $each, WebhookOutageRecord::read()['failing']['count'] ?? null);
    }

    private function databaseDown(bool $sentinel = false): void
    {
        config([
            'database.connections.bridge_down' => $sentinel
                ? [
                    'driver' => 'mysql',
                    'host' => self::SENTINEL_HOST,
                    'port' => 3306,
                    'database' => 'sentinel_db_10158',
                    'username' => self::SENTINEL_USER,
                    'password' => self::SENTINEL_PASSWORD,
                    'options' => [PDO::ATTR_TIMEOUT => 2],
                ]
                : ['driver' => 'sqlite', 'database' => '/nonexistent-directory-10158/bridge.sqlite', 'prefix' => ''],
            'database.default' => 'bridge_down',
        ]);
        DB::purge('bridge_down');
    }

    private function databaseUp(): void
    {
        config(['database.default' => $this->originalDefault]);
        DB::purge('bridge_down');
    }

    private function deliver()
    {
        $body = $this->kanbanBody(['delivery_id' => 'outage-'.(++$this->deliveries)]);

        return $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function kanbanBody(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'event' => 'task.moved',
            'board_id' => 5,
            'delivery_id' => 'outage-0',
            'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2],
        ], $overrides));
    }

    /** @param  array<string, string>  $headers */
    private function postWebhook(string $uri, string $body, array $headers)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    /** @param  array<string, mixed>  $options */
    private function inbox(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('bridge:inbox', ['--hook-format' => 'plain', ...$options]));

        return trim(Artisan::output());
    }
}
