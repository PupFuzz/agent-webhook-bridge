<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\WritebackOwedWritesTableCheck;
use App\Bridge\Support\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The owed-write table leg (card#10849 / DL-440). Every golden fixture install has the table,
 * so the golden suite pins only this leg's silence; the FAIL arm — the one an unmigrated
 * upgrade reaches — and the could-not-ask arm are measured here.
 */
class WritebackOwedWritesTableCheckTest extends TestCase
{
    use MaterializesChecks;
    use RefreshDatabase;

    public function test_a_present_table_says_nothing(): void
    {
        $this->assertSame([], $this->findingsOf(new WritebackOwedWritesTableCheck));
    }

    public function test_a_missing_table_fails_and_names_the_remedy(): void
    {
        $file = glob(database_path('migrations/*_create_writeback_owed_writes_table.php'));
        $this->assertCount(1, $file, 'the owed-write migration has been renamed or split');
        $migration = require $file[0];

        // Through the migration's own down()/up(): DDL commits RefreshDatabase's transaction on
        // MariaDB, so an unrestored drop would take the table from every later test.
        $migration->down();
        try {
            $findings = $this->findingsOf(new WritebackOwedWritesTableCheck);
        } finally {
            $migration->up();
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('writeback_owed_writes', $findings[0]->message);
        $this->assertStringContainsString('php artisan migrate', $findings[0]->message);
    }

    public function test_a_database_that_cannot_be_asked_is_unvalidated_not_missing(): void
    {
        $default = config('database.default');
        config([
            'database.default' => 'checktest',
            'database.connections.checktest' => ['driver' => 'sqlite', 'database' => '/nonexistent-directory-for-a-bridge-check-test/bridge.sqlite'],
        ]);
        try {
            $findings = $this->findingsOf(new WritebackOwedWritesTableCheck);
        } finally {
            // Back before teardown, which rolls back and audits the DEFAULT connection.
            config(['database.default' => $default]);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
    }
}
