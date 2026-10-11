<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\CiAwaitsCheck;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Support\Severity;
use App\Models\CiAwait;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `ci_await.awaits` leg (card#11200 / DL-452). Every golden fixture install has the table and
 * no awaits, so the golden suite pins only this leg's silence; every arm that speaks is measured here.
 */
class CiAwaitsCheckTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    private const REPO = 'octo/widgets';

    public function test_no_awaits_says_nothing(): void
    {
        $this->assertSame([], $this->findingsOf(new CiAwaitsCheck));
    }

    public function test_a_healthy_await_says_nothing(): void
    {
        $this->await();
        $this->recordWorkflowRun();
        app(JobRegistry::class)->insert(CiAwaitSweepJob::spec());

        $this->assertSame([], $this->findingsOf(new CiAwaitsCheck));
    }

    public function test_an_await_with_no_clock_to_expire_it_warns_naming_the_command(): void
    {
        $this->await();
        $this->recordWorkflowRun();

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('bridge:jobs add '.CiAwaitSweepJob::INSTANCE, $findings[0]->message);
    }

    public function test_a_failed_read_warns_with_its_error(): void
    {
        $this->await(['last_error' => 'GitHub answered HTTP 403 to the workflow-run read (rate limited)', 'last_read_at' => now()]);
        $this->recordWorkflowRun();
        app(JobRegistry::class)->insert(CiAwaitSweepJob::spec());

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('(rate limited)', $findings[0]->message);
    }

    public function test_an_await_whose_event_could_not_be_written_warns_naming_the_agent(): void
    {
        $this->await(['emit_failed_at' => now()]);
        $this->recordWorkflowRun();
        app(JobRegistry::class)->insert(CiAwaitSweepJob::spec());

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('inbox of agent `seat-a`', $findings[0]->message);
    }

    public function test_a_sweep_read_cap_the_bridge_refuses_fails_naming_the_key(): void
    {
        config(['bridge.ci_await.sweep_reads' => 0]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('BRIDGE_CI_AWAIT_SWEEP_READS', $findings[0]->message);
    }

    public function test_an_overdue_default_the_bridge_refuses_fails_naming_the_key(): void
    {
        config(['bridge.ci_await.overdue_default' => 30]);

        $fails = array_values(array_filter($this->findingsOf(new CiAwaitsCheck), static fn ($f): bool => $f->severity === Severity::Fail && str_contains($f->message, 'BRIDGE_CI_AWAIT_OVERDUE_DEFAULT')));

        $this->assertCount(1, $fails);
    }

    public function test_an_awaited_repo_with_no_stored_workflow_run_warns_and_says_what_that_does_not_prove(): void
    {
        $this->await();
        app(JobRegistry::class)->insert(CiAwaitSweepJob::spec());

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString(self::REPO, $findings[0]->message);
        $this->assertStringContainsString('not proof', $findings[0]->message);
    }

    public function test_a_ttl_the_bridge_refuses_fails_naming_the_value(): void
    {
        config(['bridge.ci_await.ttl' => '6h']);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("'6h'", $findings[0]->message);
    }

    public function test_each_ci_await_setting_the_bridge_refuses_fails_naming_its_key(): void
    {
        config(['bridge.ci_await.max_per_seat' => 0, 'bridge.ci_await.read_cooldown' => 'soon']);

        $messages = array_map(fn ($f): string => $f->message, $this->findingsOf(new CiAwaitsCheck));

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('BRIDGE_CI_AWAIT_MAX_PER_SEAT is 0', $messages[0]);
        $this->assertStringContainsString("BRIDGE_CI_AWAIT_READ_COOLDOWN is 'soon'", $messages[1]);
    }

    public function test_a_missing_table_warns_and_names_the_remedy(): void
    {
        $file = glob(database_path('migrations/*_create_ci_awaits_table.php'));
        $this->assertCount(1, $file, 'the ci_awaits migration has been renamed or split');
        $migration = require $file[0];

        // Through the migration's own down()/up(): DDL commits RefreshDatabase's transaction on
        // MariaDB, so an unrestored drop would take the table from every later test.
        $migration->down();
        try {
            $findings = $this->findingsOf(new CiAwaitsCheck);
        } finally {
            $migration->up();
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('php artisan migrate', $findings[0]->message);
    }

    // ---- can each received repo be read (card#11600) --------------------------------------

    public function test_a_received_repo_github_answers_is_ok(): void
    {
        $this->installServingCiTools();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['total_count' => 0, 'workflow_runs' => []])]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString(self::REPO, $findings[0]->message);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'per_page=1'));
    }

    public function test_a_received_repo_the_token_cannot_see_fails_naming_the_source_file_and_remedy(): void
    {
        $dir = $this->installServingCiTools();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'Not Found'], 404)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('HTTP 404', $findings[0]->message);
        $this->assertStringContainsString(self::REPO, $findings[0]->message);
        $this->assertStringContainsString('token source: the single GitHub token file, file '.$dir.'/github/token', $findings[0]->message);
        $this->assertStringContainsString('[git-credential-map]', $findings[0]->message);
        $this->assertStringNotContainsString('gh-check-token', $findings[0]->message);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function unconfirmedStatuses(): array
    {
        return [
            'a header-less 403' => [403, 'Resource not accessible by integration'],
            'a header-less secondary-limit 403' => [403, 'You have exceeded a secondary rate limit.'],
            'a 401' => [401, 'Bad credentials'],
        ];
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_a_single_401_or_header_less_403_is_unvalidated_not_a_fail(int $status, string $message): void
    {
        $this->installServingCiTools();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => $message], $status)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString("HTTP {$status}", $findings[0]->message);
        $this->assertStringNotContainsString($message, $findings[0]->message);
    }

    public function test_a_single_401_or_403_says_a_confirming_read_is_needed(): void
    {
        $this->installServingCiTools();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'Forbidden'], 403)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertStringContainsString('confirming read', $findings[0]->message);
    }

    public function test_a_received_repo_with_no_read_token_fails(): void
    {
        $dir = $this->installServingCiTools();
        unlink($dir.'/github/token');
        Http::fake();

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('no GitHub read token', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_a_received_repo_whose_read_answered_5xx_is_unvalidated_not_a_pass(): void
    {
        $this->installServingCiTools();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'boom'], 502)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('NOT measured', $findings[0]->message);
    }

    public function test_no_agent_served_the_ci_tools_asks_github_nothing(): void
    {
        $this->installServingCiTools(served: false);
        Http::fake();

        $this->assertSame([], $this->findingsOf(new CiAwaitsCheck));
        Http::assertNothingSent();
    }

    // ---- a repo declared to have no CI (card#11696) ----------------------------------------

    /**
     * The coordination-repo shape measured on prod: received for its comments, no Actions, and
     * GitHub answers 404 to its runs. Declared, it is neither read nor a FAIL.
     */
    public function test_a_received_repo_declared_to_have_no_ci_is_ok_and_not_read(): void
    {
        $this->installServingCiTools();
        config(['bridge.ci_await.no_ci_repos' => ['Octo/Widgets']]);
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'Not Found'], 404)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString(self::REPO.' is declared to have no CI', $findings[0]->message);
        $this->assertStringContainsString('repo_not_ci', $findings[0]->message);
        Http::assertNothingSent();
    }

    /** The declaration exempts the repo it names, and no other: a CI repo the token cannot read still FAILs. */
    public function test_a_ci_repo_the_token_cannot_see_still_fails_beside_a_declared_one(): void
    {
        $dir = $this->installServingCiTools();
        File::put($dir.'/seat-a.yml', "subscriptions:\n  - provider: github\n    scopes: [".self::REPO.", octo/roundtable]\nboard_tools:\n  enabled: true\n  transport: ssh\n");
        config(['bridge.ci_await.no_ci_repos' => ['octo/roundtable']]);
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'Not Found'], 404)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('cannot read '.self::REPO, $findings[0]->message);
        $this->assertSame(Severity::Ok, $findings[1]->severity);
        $this->assertStringContainsString('octo/roundtable is declared to have no CI', $findings[1]->message);
        Http::assertSentCount(1);
    }

    public function test_a_declared_repo_that_has_delivered_a_workflow_run_warns(): void
    {
        $this->installServingCiTools();
        config(['bridge.ci_await.no_ci_repos' => [self::REPO]]);
        $this->recordWorkflowRun();
        Http::fake(['api.github.com/repos/octo/widgets/actions/runs*' => Http::response(['message' => 'Not Found'], 404)]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('holds a workflow_run delivery from it', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_a_no_ci_repo_entry_that_is_not_owner_name_fails_naming_it(): void
    {
        config(['bridge.ci_await.no_ci_repos' => ['octo/widgets/extra']]);

        $findings = $this->findingsOf(new CiAwaitsCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("BRIDGE_CI_AWAIT_NO_CI_REPOS lists 'octo/widgets/extra'", $findings[0]->message);
    }

    /** One agent subscribed to REPO, with a placed single token file; returns the install dir. */
    private function installServingCiTools(bool $served = true): string
    {
        $dir = sys_get_temp_dir().'/ci-await-check-'.uniqid();
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/github');
        File::put($dir.'/github/token', 'gh-check-token'); // gitleaks:allow — test fixture
        chmod($dir.'/github/token', 0o600);
        File::put($dir.'/seat-a.yml', "subscriptions:\n  - provider: github\n    scopes: [".self::REPO."]\n"
            .($served ? "board_tools:\n  enabled: true\n  transport: ssh\n" : ''));
        config(['bridge.config_dir' => $dir, 'bridge.secret_dir' => $dir]);

        return $dir;
    }

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $extra */
    private function await(array $extra = []): void
    {
        CiAwait::query()->create($extra + ['agent' => 'seat-a', 'repo' => self::REPO, 'repo_name' => self::REPO, 'head_sha' => str_repeat('a', 40), 'expires_at' => now()->addHour()]);
    }

    private function recordWorkflowRun(): void
    {
        WebhookEvent::query()->create(['delivery_id' => 'ci-await-check-1', 'provider' => 'github', 'scope_id' => self::REPO, 'event_type' => 'workflow_run.completed', 'payload' => []]);
    }
}
