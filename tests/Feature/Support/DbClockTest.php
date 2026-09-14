<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\DbClock;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs on EVERY database the suite is pointed at: SQLite by default, and MariaDB in CI's
 * `phpunit-mariadb` matrix (the job sets `DB_CONNECTION=mysql`). The clock query differs per
 * driver, so a green run on one says nothing about the other.
 */
class DbClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_clock_that_stamps_received_at(): void
    {
        $event = WebhookEvent::create([
            'delivery_id' => 'dbclock-1', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.moved', 'payload' => ['a' => 1],
        ]);
        $received = (float) $event->fresh()->received_at->format('U.u');

        $now = (float) DbClock::now()->format('U.u');

        // The DB stamped the row before this read, on the same clock, so the age is a small
        // non-negative number. The 1 s floor is SQLite's `useCurrent()` resolution.
        $this->assertGreaterThanOrEqual(-1.0, $now - $received);
        $this->assertLessThan(10.0, $now - $received);
    }

    public function test_it_is_utc_and_on_this_test_host_agrees_with_php(): void
    {
        $this->assertSame('UTC', DbClock::now()->getTimezone()->getName());
        $this->assertLessThan(10.0, abs((float) DbClock::now()->format('U.u') - (float) Carbon::now()->format('U.u')));
    }

    public function test_the_query_matches_the_connection_driver(): void
    {
        // Pinned per driver: a query written for one engine is a syntax error on the other,
        // which is exactly how the MySQL spelling would have broken the SQLite default.
        $driver = DB::connection()->getDriverName();
        $this->assertContains($driver, ['sqlite', 'mysql', 'mariadb']);
        $this->assertNotNull(DbClock::now());
    }

    public function test_an_unknown_driver_is_refused_rather_than_guessed(): void
    {
        config(['database.connections.dbclock_pg' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'x']]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("no clock query for database driver 'pgsql'");

        DbClock::now('dbclock_pg');
    }
}
