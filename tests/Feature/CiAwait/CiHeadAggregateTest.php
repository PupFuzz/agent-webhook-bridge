<?php

namespace Tests\Feature\CiAwait;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\ClientUpdate\CallerReport;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\DispatchOutcome;
use App\Models\CiAwait;
use App\Models\CiHeadRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CallingSeatSeal;
use Tests\TestCase;

/**
 * The per-head aggregate `ci_settled` end to end (card#11667): `workflow_run` deliveries through
 * the real webhook route, the real `CoordinationClassifier` `impl-ci-wake` family, and the
 * dispatcher — with the seat's channel faked and every other request refused
 * (`Tests\TestCase` runs `Http::preventStrayRequests()`), so a GitHub read would fail the test.
 */
class CiHeadAggregateTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'octo/widgets';

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const HOOK_SECRET = 'ci-head-hook-secret'; // gitleaks:allow — test fixture

    private const PORT = 8711;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ci-head-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::ensureDirectoryExists($this->dir.'/github');
        foreach (['github/token' => 'gh-read-token', 'github/webhook-secret-scope-octo%2Fwidgets' => self::HOOK_SECRET] as $file => $value) {
            File::put($this->dir.'/'.$file, $value);   // gitleaks:allow — test fixture
            chmod($this->dir.'/'.$file, 0o600);
        }
        $this->writeSeat();
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.inbox_layout' => 'shared',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.jobs.enabled' => false,
            'bridge.ci_await.ttl' => 21600,
        ]);
        Http::fake(['127.0.0.1:*' => Http::response('ok', 200)]);
        Carbon::setTestNow('2026-10-10T10:00:00.000Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_head_with_every_run_green_gets_exactly_one_aggregate_and_no_per_run_success(): void
    {
        $this->requestAll([1 => 'CI', 2 => 'Lint', 3 => 'Docs']);

        $this->complete(1, 'CI', 'success');
        $this->complete(2, 'Lint', 'success');
        $this->assertSame([], $this->kinds(), 'nothing is sent while a run is still open');
        $this->complete(3, 'Docs', 'skipped');

        $this->assertSame(['ci_settled'], $this->kinds());
        $this->assertSame(['ci_settled'], $this->pushedKinds());
        $settled = $this->inbox()[0];
        $this->assertSame('green', $settled['payload']['runs_verdict']);
        $this->assertSame(17, $settled['payload']['pr']);
        $this->assertSame(['CI', 'Lint', 'Docs'], array_column($settled['payload']['runs'], 'workflow'));
        $this->assertSame(['success', 'success', 'skipped'], array_column($settled['payload']['runs'], 'conclusion'));
        $this->assertSame('ci:'.self::REPO.'@'.self::SHA, $settled['subject_id']);
        $this->assertStringContainsString('GREEN', $settled['summary']);
        $this->assertStringContainsString('ci-read stays the authoritative verdict', $settled['summary']);
        $this->assertNoGitHubRead();
    }

    public function test_a_failed_run_still_wakes_at_once_and_the_aggregate_is_red(): void
    {
        $this->requestAll([1 => 'CI', 2 => 'Lint']);

        $this->complete(1, 'CI', 'failure');

        $this->assertSame(['impl_ci_failed'], $this->kinds(), 'the failure is delivered on its own run, before the head settles');
        $this->assertSame(['impl_ci_failed'], $this->pushedKinds());

        $this->complete(2, 'Lint', 'success');

        $this->assertSame(['impl_ci_failed', 'ci_settled'], $this->kinds());
        $this->assertSame('red', $this->inbox()[1]['payload']['runs_verdict']);
        $this->assertStringContainsString('RED — CI → failure', $this->inbox()[1]['summary']);
    }

    public function test_a_head_whose_last_run_fails_settles_red(): void
    {
        $this->requestAll([1 => 'CI', 2 => 'Lint']);

        $this->complete(1, 'CI', 'success');
        $this->complete(2, 'Lint', 'timed_out');

        $this->assertSame(['impl_ci_failed', 'ci_settled'], $this->kinds());
        $this->assertSame('red', $this->lastSettled()['payload']['runs_verdict']);
    }

    public function test_a_cancelled_run_superseded_by_a_newer_run_of_its_workflow_does_not_turn_the_head_red(): void
    {
        $this->deliver('requested', 1, 'CI', null, runNumber: 1);
        $this->deliver('requested', 2, 'Lint', null, workflowId: 2002);
        $this->deliver('requested', 3, 'CI', null, runNumber: 2);
        $this->complete(1, 'CI', 'cancelled', runNumber: 1);
        $this->complete(2, 'Lint', 'success', workflowId: 2002);
        $this->assertNotContains('ci_settled', $this->kinds(), 'the newer CI run decides, and it is still open');

        $this->complete(3, 'CI', 'success', runNumber: 2);

        $settled = $this->lastSettled();
        $this->assertSame('green', $settled['payload']['runs_verdict']);
        $this->assertSame([true, false, false], array_column($settled['payload']['runs'], 'superseded'));
        $this->assertSame(1, count(array_keys($this->kinds(), 'ci_settled')));
    }

    public function test_an_unsuperseded_cancelled_run_makes_the_aggregate_red(): void
    {
        $this->requestAll([1 => 'CI', 2 => 'Lint']);

        $this->complete(1, 'CI', 'cancelled');
        $this->complete(2, 'Lint', 'success');

        $this->assertSame(['impl_ci', 'ci_settled'], $this->kinds(), 'a cancelled run is not green, so it keeps its own impl_ci');
        $settled = $this->lastSettled();
        $this->assertSame('red', $settled['payload']['runs_verdict']);
        $this->assertStringContainsString('CI → cancelled', $settled['summary']);
    }

    public function test_a_run_that_starts_after_the_aggregate_settles_the_head_again(): void
    {
        $this->requestAll([1 => 'CI']);
        $this->complete(1, 'CI', 'success');
        $this->assertSame(['ci_settled'], $this->kinds());

        // An `on: workflow_run` follow-on on the same head, created after the first settle.
        $this->deliver('requested', 9, 'Deploy preview', null, workflowId: 9009);
        $this->assertSame(['ci_settled'], $this->kinds(), 'an open run is not a settled head');
        $this->complete(9, 'Deploy preview', 'success', workflowId: 9009);

        $this->assertSame(['ci_settled', 'ci_settled'], $this->kinds());
        $this->assertSame(['CI', 'Deploy preview'], array_column($this->lastSettled()['payload']['runs'], 'workflow'));
    }

    public function test_the_same_settled_state_is_never_sent_twice(): void
    {
        $this->deliver('requested', 1, 'CI', null, runNumber: 1);
        $this->deliver('requested', 3, 'CI', null, runNumber: 2);
        $this->complete(3, 'CI', 'success', runNumber: 2);
        $this->assertSame(['ci_settled'], $this->kinds(), 'the newer run decides; the older one is superseded');

        // The superseded run finishes after the head settled: another completed delivery for the
        // head, and the same deciding runs — the same state. (A byte-identical redelivery never
        // gets this far: its delivery id is the body's hash, deduped before dispatch.)
        $this->complete(1, 'CI', 'cancelled', runNumber: 1);
        $this->deliver('requested', 3, 'CI', null, runNumber: 2);

        $this->assertSame(['ci_settled', 'impl_ci'], $this->kinds(), 'the cancelled run keeps its own impl_ci; no second ci_settled');
        $this->assertSame('completed', CiHeadRun::query()->where('run_id', 3)->value('status'), 'a late requested never moves the run backwards');
    }

    public function test_a_re_run_that_turns_green_settles_the_head_again(): void
    {
        $this->requestAll([1 => 'CI']);
        $this->complete(1, 'CI', 'failure');
        $this->deliver('requested', 1, 'CI', null, attempt: 2);
        $this->complete(1, 'CI', 'success', attempt: 2);

        $this->assertSame(['impl_ci_failed', 'ci_settled', 'ci_settled'], $this->kinds());
        $this->assertSame(['red', 'green'], array_map(static fn (array $l): string => $l['payload']['runs_verdict'], array_values(array_filter($this->inbox(), static fn (array $l): bool => $l['kind'] === 'ci_settled'))));
    }

    public function test_per_run_delivery_keeps_the_old_behaviour_and_sends_no_aggregate(): void
    {
        $this->writeSeat(delivery: 'per_run');
        $this->requestAll([1 => 'CI', 2 => 'Lint']);

        $this->complete(1, 'CI', 'success');
        $this->complete(2, 'Lint', 'success');

        $this->assertSame(['impl_ci', 'impl_ci'], $this->kinds());
        $this->assertSame(['impl_ci', 'impl_ci'], $this->pushedKinds());
    }

    public function test_an_agent_that_drops_non_wake_ci_gets_no_aggregate_either(): void
    {
        $this->writeSeat(disposition: null);
        $this->requestAll([1 => 'CI']);

        $this->complete(1, 'CI', 'success');

        $this->assertSame([], $this->kinds(), 'the aggregate goes only where per-run impl_ci went');
    }

    public function test_without_route_intents_the_aggregate_is_staged_and_not_pushed_like_impl_ci(): void
    {
        $this->writeSeat(routeIntents: false);
        $this->requestAll([1 => 'CI']);

        $this->complete(1, 'CI', 'success');

        $this->assertSame(['ci_settled'], $this->kinds());
        $this->assertSame([], $this->pushedKinds());
    }

    public function test_a_ci_await_on_the_head_is_answered_by_the_aggregate_once(): void
    {
        CiAwait::query()->create(['agent' => 'seat-a', 'repo' => self::REPO, 'repo_name' => self::REPO, 'head_sha' => self::SHA, 'pr' => 17, 'expires_at' => Carbon::now()->addHour()]);
        // The await's own read on run 1's completion (ci_await's unchanged behaviour): run 2 is open.
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response($this->listOf([[1, 'CI', 'completed', 'success'], [2, 'Lint', 'in_progress', null]]))]);
        $this->requestAll([1 => 'CI', 2 => 'Lint']);

        $this->complete(1, 'CI', 'success');
        $this->complete(2, 'Lint', 'success');

        $this->assertSame(['ci_settled'], $this->kinds());
        $this->assertSame(['ci_settled'], $this->pushedKinds());
        $this->assertSame(0, CiAwait::query()->count(), 'the aggregate claimed the await');
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.github.com')), 'the settling delivery reads nothing: the aggregate claimed the await before the await path could look');
    }

    public function test_a_ci_await_registered_after_the_aggregate_is_answered_settled_without_a_second_event(): void
    {
        $this->requestAll([1 => 'CI']);
        $this->complete(1, 'CI', 'success');
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response($this->listOf([[1, 'CI', 'completed', 'success']]))]);

        $out = $this->callTool('ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);

        $this->assertTrue($out->ok, json_encode($out->body()) ?: '');
        $this->assertSame('settled', $out->body()['result']['state']);
        $this->assertSame(['ci_settled'], $this->kinds());
        $this->assertSame(0, CiAwait::query()->count());
    }

    public function test_a_ci_await_that_settled_first_is_not_followed_by_the_aggregate_for_the_same_state(): void
    {
        $this->requestAll([1 => 'CI', 2 => 'Lint']);
        $this->complete(1, 'CI', 'success');
        // The list is ahead of the webhook: it already shows run 2 finished.
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response($this->listOf([[1, 'CI', 'completed', 'success'], [2, 'Lint', 'completed', 'success']]))]);
        $out = $this->callTool('ci_await', ['repo' => self::REPO, 'head_sha' => self::SHA]);
        $this->assertSame('settled', $out->body()['result']['state']);
        $this->assertSame(['ci_settled'], $this->kinds());

        $this->complete(2, 'Lint', 'success');

        $this->assertSame(['ci_settled'], $this->kinds(), 'the delivery that settles the tracked runs finds the state already sent');
    }

    // ---- helpers -------------------------------------------------------------------------

    private function writeSeat(?string $disposition = 'inbox_stage', bool $routeIntents = true, ?string $delivery = null): void
    {
        File::put($this->dir.'/seat-a.yml', "subscriptions:\n  - provider: github\n    scopes: [".self::REPO."]\n"
            ."classifier:\n  class: '".CoordinationClassifier::class."'\n  config:\n    families: ['impl-ci-wake']\n"
            .($disposition === null ? '' : "    impl_non_wake_disposition: {$disposition}\n")
            .($delivery === null ? '' : "    impl_ci_delivery: {$delivery}\n")
            ."channel:\n  url: http://127.0.0.1:".self::PORT."/\n  route_intents: ".($routeIntents ? 'true' : 'false')."\n");
    }

    /** @param  array<int, string>  $runs  run id => workflow name */
    private function requestAll(array $runs): void
    {
        foreach ($runs as $id => $name) {
            $this->deliver('requested', $id, $name, null);
        }
    }

    private function complete(int $runId, string $name, string $conclusion, ?int $runNumber = null, ?int $workflowId = null, int $attempt = 1): void
    {
        $this->deliver('completed', $runId, $name, $conclusion, $runNumber, $workflowId, $attempt);
    }

    /** A run's workflow id defaults to one per name, so two names are two workflows. */
    private function deliver(string $action, int $runId, string $name, ?string $conclusion, ?int $runNumber = null, ?int $workflowId = null, int $attempt = 1): TestResponse
    {
        $body = (string) json_encode([
            'action' => $action,
            'workflow_run' => [
                'id' => $runId, 'name' => $name, 'workflow_id' => $workflowId ?? crc32($name), 'run_number' => $runNumber ?? $runId,
                'head_sha' => self::SHA, 'status' => $action, 'conclusion' => $conclusion, 'run_attempt' => $attempt, 'event' => 'pull_request',
                'html_url' => "https://github.com/octo/widgets/actions/runs/{$runId}",
                'pull_requests' => [['number' => 17]],
            ],
            'repository' => ['full_name' => self::REPO],
            'sender' => ['id' => 4242],
        ]);

        return $this->call('POST', '/webhooks/github?b='.self::REPO, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::HOOK_SECRET),
            'HTTP_X_GITHUB_DELIVERY' => uniqid('d', true),
            'HTTP_X_GITHUB_EVENT' => 'workflow_run',
        ], $body)->assertOk();
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: ?string}>  $runs  [id, name, status, conclusion]
     * @return array<string, mixed>
     */
    private function listOf(array $runs): array
    {
        $list = [];
        foreach ($runs as [$id, $name, $status, $conclusion]) {
            $list[] = ['id' => $id, 'name' => $name, 'workflow_id' => crc32($name), 'run_number' => $id, 'head_sha' => self::SHA, 'status' => $status, 'conclusion' => $conclusion, 'event' => 'pull_request', 'run_attempt' => 1, 'html_url' => "https://github.com/octo/widgets/actions/runs/{$id}"];
        }

        return ['total_count' => count($list), 'workflow_runs' => $list];
    }

    private function callTool(string $tool, array $args): DispatchOutcome
    {
        CallingSeatSeal::forANewServingProcess();
        $cfg = new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );

        return (new BoardToolDispatcher(new BoardToolsRegistry))->dispatch($tool, $args, $cfg, 'seat-a', CallProvenance::NotSshd, null, CallerReport::unreported());
    }

    /** @return list<array<string, mixed>> */
    private function inbox(): array
    {
        $path = $this->dir.'/state/inbox.jsonl';

        return is_file($path) ? array_map(fn (string $l): array => (array) json_decode($l, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return array_column($this->inbox(), 'kind');
    }

    /** @return array<string, mixed> */
    private function lastSettled(): array
    {
        $settled = array_values(array_filter($this->inbox(), static fn (array $l): bool => $l['kind'] === 'ci_settled'));
        $this->assertNotSame([], $settled, 'no ci_settled was staged');

        return $settled[count($settled) - 1];
    }

    /** @return list<string> */
    private function pushedKinds(): array
    {
        return array_values(array_map(
            static fn (array $pair): string => json_decode($pair[0]->body(), true)['intent']['kind'],
            Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:'.self::PORT))->all(),
        ));
    }

    private function assertNoGitHubRead(): void
    {
        $this->assertCount(0, Http::recorded(fn (Request $r): bool => str_contains($r->url(), 'api.github.com')));
    }
}
