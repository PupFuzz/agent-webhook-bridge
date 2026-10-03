<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\CiAwaitsCheck;
use App\Bridge\Scheduling\Handlers\CiAwaitSweepJob;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Support\Severity;
use App\Models\CiAwait;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
