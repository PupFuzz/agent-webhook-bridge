<?php

namespace Tests\Feature\CiAwait;

use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobPassSource;
use App\Models\CiAwait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cooldown race, reproduced in one process by interleaving through the HTTP fakes (review round
 * 3): a seat whose registration read was SKIPPED must be woken by the sweep whatever the claim
 * interleaving, because no concurrent read is obliged to find it. ⚠ The interleave below is the one
 * that stranded seat C against the claim-then-reload ordering (it is red there); the ordering is gone,
 * so on the current code C no longer registers mid-read and the test asserts only the END STATE —
 * no skipped registration is left unread — which is the guarantee. The marker itself is held by the
 * CiAwaitTest controls, which interleave on the current structure.
 */
class CiAwaitReloadRaceTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'octo/widgets';

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ci-await-repro-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'gh-read-token');
        chmod($this->dir.'/github/token', 0o600);
        File::put($this->dir.'/tok', 't');
        chmod($this->dir.'/tok', 0o600);
        foreach (['seat-a' => 8701, 'seat-b' => 8702, 'seat-b2' => 8703, 'seat-c' => 8704] as $agent => $port) {
            File::put($this->dir."/{$agent}.yml", "subscriptions:\n  - provider: github\n    scopes: [".self::REPO."]\n"
                ."channel:\n  url: http://127.0.0.1:{$port}/\n  auth:\n    token_path: {$this->dir}/tok\n");
        }
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state',
            'bridge.inbox_layout' => 'shared', 'bridge.jobs.enabled' => false, 'bridge.ci_await.ttl' => 21600]);
        Carbon::setTestNow('2026-10-03T10:00:00.000Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function runs(string $status): array
    {
        return ['total_count' => 1, 'workflow_runs' => [['id' => 1, 'name' => 'CI', 'status' => $status, 'conclusion' => $status === 'completed' ? 'success' : null, 'event' => 'pull_request', 'run_attempt' => 1, 'html_url' => 'u']]];
    }

    public function test_a_registration_cooled_by_a_row_first_seen_mid_read_is_settled_by_the_sweep(): void
    {
        $svc = $this->app->make(CiAwaitService::class);
        $svc->store('seat-a', self::REPO, self::SHA, null, 21600);
        CiAwait::query()->update(['last_read_at' => Carbon::now()->subSeconds(120)]);  // stale: A gives no cooldown
        $n = 0;
        $answers = [];
        Http::fake([
            'api.github.com/repos/octo/widgets/actions/runs*' => function () use (&$n, &$answers, $svc) {
                $n++;
                if ($n === 1) {   // X: the delivery's read, preload [A]
                    $svc->store('seat-b', self::REPO, self::SHA, null, 21600);
                    $answers['b'] = $svc->evaluateRegistration('seat-b', self::REPO, self::SHA, null, 60);

                    return Http::response($this->runs('completed'));
                }
                if ($n === 2) {   // Z: seat-b's own read, preload [A,B]
                    $svc->store('seat-b2', self::REPO, self::SHA, null, 21600);
                    $answers['b2'] = $svc->evaluateRegistration('seat-b2', self::REPO, self::SHA, null, 60);

                    return Http::response($this->runs('in_progress'));
                }

                return Http::response($this->runs('in_progress'));   // Z2: seat-b2's own read
            },
            '127.0.0.1:8702*' => function () use (&$answers, $svc) {   // seat-b's push: after the reload query, before B2's claim
                $svc->store('seat-c', self::REPO, self::SHA, null, 21600);
                $answers['c'] = $svc->evaluateRegistration('seat-c', self::REPO, self::SHA, null, 60);

                return Http::response('ok');
            },
            '127.0.0.1:*' => Http::response('ok'),
        ]);

        $svc->onWorkflowRunCompleted(self::REPO, self::SHA);

        Http::fake([
            'api.github.com/repos/octo/widgets/actions/runs*' => Http::response($this->runs('completed')),
            '127.0.0.1:*' => Http::response('ok'),
        ]);
        $this->app->make(CiAwaitSweepJob::class)->run(new JobContext(CiAwaitSweepJob::INSTANCE, [], null, 300, JobPassSource::Tick));

        // Seats B and B2 each read for themselves and saw CI running; they wait for the next delivery,
        // as any answered registration does. What must never remain is a SKIPPED registration.
        $this->assertSame([], CiAwait::query()->where('read_deferred', true)->pluck('agent')->all(), 'a skipped registration is left that nothing will read again');
        $this->assertNotContains('seat-c', CiAwait::query()->pluck('agent')->all());
    }
}
