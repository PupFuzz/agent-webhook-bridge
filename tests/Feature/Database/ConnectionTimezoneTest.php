<?php

namespace Tests\Feature\Database;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * card#8825 — the assertion that would have caught a connection whose clock is not the
 * application's clock.
 *
 * ⭐ THE WHOLE DEFECT IS ONE COMPARISON, and it is the one nothing made. `app.timezone` was UTC
 * while the MySQL session kept the server's `SYSTEM` zone, so PHP serialised every Eloquent
 * timestamp as a bare UTC literal and MySQL stored it as LOCAL time — `instant + host_offset`.
 * Nothing warned and no surface looked wrong, because the same wrong offset was applied again on
 * the way out. What breaks the symmetry is that ONE column in this schema is written by the DB
 * (`webhook_events.received_at`, a `->useCurrent()` default) and its neighbour `created_at` is
 * written by PHP, in the same INSERT, for the same instant. They can only disagree if one of
 * the two clocks is wrong, so their difference is a live measurement of the bug, and
 * `agreementSkewSeconds()` below is that measurement.
 *
 * ⚠ WHICH ENGINE EACH TEST ACTUALLY EXERCISES, because the answer is not the same for all of them
 * and the difference decides what a green run means:
 *  - `test_a_freshly_written_row_agrees_with_itself_and_with_the_true_instant` runs on WHATEVER
 *    driver the job configured — SQLite in the default suite, MariaDB in the `phpunit-mariadb`
 *    matrix. ⛔ ON SQLITE IT IS VACUOUS FOR THIS BUG: SQLite has no session time zone, stores
 *    the literal it is handed, and emits `CURRENT_TIMESTAMP` in UTC, so no skew is
 *    representable there. It is the MariaDB legs of the matrix that make it a measurement.
 *  - `test_the_connection_clock_is_pinned_to_the_application_clock` is the leg that reds if the
 *    `timezone` key is dropped from `config/database.php` — on ANY MySQL server, including a
 *    CI container whose own zone is already UTC, because it asserts the session was explicitly
 *    SET rather than merely happening to agree.
 *  - `test_the_agreement_measurement_detects_a_skewed_session` is the positive control (canon
 *    #9). It reproduces the exact production condition by skewing the session by hand and
 *    asserts the measurement above REPORTS it — so a green agreement assertion is evidence that
 *    the skew is absent rather than evidence that nothing can see it.
 *  - `test_the_correction_migration_repairs_a_skewed_row_exactly_once` uses that same technique to
 *    hand the one-time data correction a genuinely skewed row, on MySQL only. It is the only
 *    coverage the destructive half of this change has, and it asserts all three of its
 *    properties: that it repairs, that it leaves the DB-written column alone, and that a second
 *    application does not move the data again.
 */
class ConnectionTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** A correct pair is written by one INSERT, so it differs by microseconds. */
    private const AGREEMENT_TOLERANCE_S = 2;

    /** The session offset the control imposes, in seconds — Eastern Daylight Time, as measured on prod. */
    private const CONTROL_OFFSET_S = -14400;

    public function test_the_connection_clock_is_pinned_to_the_application_clock(): void
    {
        $this->requireMySql();

        $connection = DB::getDefaultConnection();
        $configured = config('database.connections.'.$connection.'.timezone');

        $this->assertNotNull(
            $configured,
            "config/database.php's `{$connection}` connection has no `timezone` key, so the session keeps the "
            .'SERVER\'s zone and every PHP-written timestamp is stored skewed by the host\'s UTC offset (card#8825).',
        );

        $this->assertSame(
            $configured,
            DB::selectOne('SELECT @@session.time_zone AS tz')?->tz,
            'the connection reports a session time zone the config did not ask for — Laravel issues `SET time_zone` '
            .'only when the `timezone` key is present, so this is the wiring, not the value.',
        );

        // The value is not enough on its own: it must be the same clock PHP writes in. Derived
        // from `app.timezone` rather than pinned to zero, so an install that moves both together
        // stays green and an install that moves one of them does not.
        $this->assertSame(
            now()->utcOffset() * 60,
            (int) DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_s')?->offset_s,
            'the database session and app.timezone are different clocks.',
        );
    }

    public function test_a_freshly_written_row_agrees_with_itself_and_with_the_true_instant(): void
    {
        $before = now();
        $event = $this->writeEvent('agreement');
        $after = now();

        $this->assertLessThanOrEqual(
            self::AGREEMENT_TOLERANCE_S,
            abs($this->agreementSkewSeconds($event)),
            'the DB-written received_at and the PHP-written created_at of ONE row disagree: the two clocks that '
            .'wrote them are not the same clock (card#8825).',
        );

        // ⛔ Agreement alone is not enough — two clocks wrong by the same amount agree. Pin both
        // to the instant PHP observed, which is the app's own definition of now.
        foreach (['received_at' => $event->received_at, 'created_at' => $event->created_at] as $name => $value) {
            $this->assertNotNull($value, "{$name} was not written");
            $this->assertGreaterThanOrEqual(
                $before->getTimestamp() - self::AGREEMENT_TOLERANCE_S,
                $value->getTimestamp(),
                "{$name} is behind the instant the row was written",
            );
            $this->assertLessThanOrEqual(
                $after->getTimestamp() + self::AGREEMENT_TOLERANCE_S,
                $value->getTimestamp(),
                "{$name} is ahead of the instant the row was written",
            );
        }
    }

    public function test_the_agreement_measurement_detects_a_skewed_session(): void
    {
        $this->requireMySql();

        // Read back from the SESSION rather than from config: this control has to restore what
        // the connection actually had, including on a checkout where the pin is absent — which
        // is exactly the run in which this test must still be readable.
        $pinned = (string) DB::selectOne('SELECT @@session.time_zone AS tz')?->tz;

        try {
            // Exactly the production condition: PHP still serialises UTC literals, the session
            // now reads them as EDT. Session variables are not transactional, so this does not
            // disturb RefreshDatabase's open transaction.
            DB::statement("SET SESSION time_zone = '-04:00'");
            $event = $this->writeEvent('control');
        } finally {
            DB::statement("SET SESSION time_zone = '".$pinned."'");
        }

        $event->refresh();

        $this->assertEqualsWithDelta(
            -self::CONTROL_OFFSET_S,
            $this->agreementSkewSeconds($event),
            self::AGREEMENT_TOLERANCE_S,
            'the agreement measurement did not report a session skewed by four hours, so a green result from it '
            .'says nothing about whether the skew is present.',
        );
    }

    /**
     * ⭐ The one-time correction is the DESTRUCTIVE half of card#8825 — applied twice it would
     * subtract the offset again — so its repair, its refusal to repeat, and its refusal to touch
     * the DB-written column are asserted rather than reasoned about. Same skewed-session
     * technique as the control above, which is what lets a MariaDB job reproduce a defect that
     * needs a non-UTC server.
     */
    public function test_the_correction_migration_repairs_a_skewed_row_exactly_once(): void
    {
        $this->requireMySql();

        $pinned = (string) DB::selectOne('SELECT @@session.time_zone AS tz')?->tz;

        try {
            DB::statement("SET SESSION time_zone = '-04:00'");
            $event = $this->writeEvent('migration');
        } finally {
            DB::statement("SET SESSION time_zone = '".$pinned."'");
        }

        $event->refresh();
        $this->assertEqualsWithDelta(
            -self::CONTROL_OFFSET_S,
            $this->agreementSkewSeconds($event),
            self::AGREEMENT_TOLERANCE_S,
            'precondition: the row this test hands the migration is not actually skewed.',
        );
        $receivedAt = $event->received_at->getTimestamp();

        $migration = require database_path('migrations/2026_09_05_000001_correct_php_written_timestamps_to_utc.php');
        $migration->up();

        $event->refresh();
        $this->assertLessThanOrEqual(
            self::AGREEMENT_TOLERANCE_S,
            abs($this->agreementSkewSeconds($event)),
            'the correction did not bring the PHP-written column onto the DB-written one.',
        );
        $this->assertSame(
            $receivedAt,
            $event->received_at->getTimestamp(),
            'the correction moved received_at — the ONE column in this schema that was never wrong.',
        );
        $repaired = $event->created_at->getTimestamp();

        // ⛔ The whole reason the gate is a witness rather than run-once bookkeeping. Asserted on
        // the DATA rather than on the pass's own report of what it did: the report is the thing
        // that would still be right if the write were wrong.
        $migration->up();

        $event->refresh();
        $this->assertSame(
            $repaired,
            $event->created_at->getTimestamp(),
            'a second application moved the data again — this is the destructive case the witness gate exists for.',
        );
        $this->assertSame(
            $receivedAt,
            $event->received_at->getTimestamp(),
            'a second application moved received_at.',
        );
    }

    /**
     * `created_at` (PHP-written) minus `received_at` (DB-written), in seconds. Both are read
     * back through the SAME session, so the difference is independent of what that session's
     * zone is — which is what makes it a measurement of the WRITERS rather than of the reader.
     */
    private function agreementSkewSeconds(WebhookEvent $event): int
    {
        return $event->created_at->getTimestamp() - $event->received_at->getTimestamp();
    }

    private function writeEvent(string $tag): WebhookEvent
    {
        $event = WebhookEvent::create([
            'delivery_id' => 'card8825-'.$tag.'-'.bin2hex(random_bytes(8)),
            'provider' => 'kanban',
            'scope_id' => '1',
            'event_type' => 'card_updated',
            'actor_id' => null,
            'payload' => ['card' => ['id' => 1]],
        ]);

        // received_at is a DB-side default, so it exists only after a read.
        $event->refresh();

        return $event;
    }

    private function requireMySql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('a session time zone is a MySQL/MariaDB concept; SQLite has none to pin.');
        }
    }
}
