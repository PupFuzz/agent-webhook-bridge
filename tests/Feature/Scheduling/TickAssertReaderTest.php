<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\TickAssertRecord;
use App\Bridge\Scheduling\TickPosture;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Scheduling\TickState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * THE ALARM'S OWN READER, and the grace its verdict prints (card#8425 / DL-351, rt#341).
 *
 * ⛔ A DECLARED HORIZON WITH NO READER IS A DEAD ALARM THAT READS AS COVERAGE. Everything in
 * {@see TickFreshnessTest} is about a verdict being CORRECT; none of it establishes that
 * anything ever asks for the verdict. An install can declare
 * `BRIDGE_JOBS_TICK_EXPECTED_EVERY`, wire no consumer of `--assert-tick`, and resolve `stale`
 * perfectly for nobody — the same shape as a fault marker with a write site and no read site
 * (DL-345), with the ends swapped.
 *
 * ⭐ AND THE STALE VERDICT PRINTS THE SLACK IT APPLIED. The jitter grace is a judgement, and a
 * judgement a reader cannot see is one nobody re-examines. The test for it below asserts the
 * property that matters rather than the digits: the number the message PRINTS is the number
 * the verdict APPLIES, checked at the boundary on both sides. A hand-typed figure in the
 * sentence passes a substring assertion and fails that one.
 */
class TickAssertReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['bridge.jobs.tick_expected_every' => null]);
    }

    public function test_an_install_that_never_asserted_holds_no_record(): void
    {
        $this->assertNull(TickAssertRecord::lastAt());
        $this->assertNull(TickAssertRecord::ageS());
    }

    public function test_the_assert_entry_point_is_what_records_the_read(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);

        // The control: enumerating the registry is not asserting on it. A hook that never
        // passes the flag has not read the alarm, and must not be recorded as having done so.
        $this->artisan('bridge:jobs')->assertExitCode(0);
        $this->assertNull(TickAssertRecord::lastAt(), 'a plain listing is not an assertion');

        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(1);
        $this->assertNotNull(TickAssertRecord::lastAt());
    }

    /**
     * ⚑ THE RECORD IS OF THE ASK, NOT OF THE ANSWER. The run above exits 1 (a declared tick
     * never observed) and still records: an install whose hook fires and reds is a WATCHED
     * install, and a record written only on success would go quiet exactly while the alarm
     * was working.
     */
    public function test_an_assertion_that_fails_still_records_that_something_asked(): void
    {
        // ⛔ FREEZE FIRST, AND THE ASSERTION BELOW IS WHY. `ageS()` is an integer difference
        // of unix timestamps, so its value depends on whether a SECOND BOUNDARY falls between
        // the write and the read — not on how long the test took. Two microseconds either
        // side of :44.000000 read as 1; 999 milliseconds inside one second read as 0. Left
        // unfrozen this asserted the wall clock's phase, and it went red on `dev` at
        // `cc99a70` having passed on the byte-identical tree at `e1eab8c`.
        //
        // The clock is frozen rather than the assertion widened: `assertLessThanOrEqual(1, …)`
        // would go green for a record written a second late, which is the defect this test
        // exists to catch.
        $this->freezeTime();

        config(['bridge.jobs.tick_expected_every' => 600]);

        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(1);

        $this->assertSame(0, TickAssertRecord::ageS());
    }

    /**
     * ⭐ THE TWO ABSENCES STAY APART. *No record at all* and *a record from a while ago* are
     * different states with different remedies, and the second must not decay into the first
     * — which is why the record does not expire. A TTL would put NOTHING HAS EVER ASKED in
     * front of an operator whose hook ran last month.
     */
    public function test_a_long_ago_assertion_is_still_an_assertion_and_never_reads_as_never(): void
    {
        // Frozen BEFORE the write, for the reason the previous test states: the gap between an
        // unfrozen `stamp()` and the `travel()` below is real elapsed time, so a second
        // boundary crossing inside it made the exact assertion read `90 * 86400 + 1`. Same
        // defect as the test above, found by auditing the file for the shape rather than by
        // waiting for it to fire.
        $this->freezeTime();

        TickAssertRecord::stamp();

        $this->travel(90 * 86400)->seconds();

        $this->assertNotNull(TickAssertRecord::lastAt(), 'the record must not expire into "never asked"');
        $this->assertSame(90 * 86400, TickAssertRecord::ageS());
    }

    /**
     * ⚠ THE FAILURE DIRECTION. A cleared or unreachable store reads as NO RECORD, which the
     * preflight reports as a warn — loud, and never as a false claim that somebody is
     * watching.
     */
    public function test_a_cleared_store_reads_as_no_record_and_never_as_watched(): void
    {
        TickAssertRecord::stamp();
        $this->assertNotNull(TickAssertRecord::lastAt(), 'the premise');

        Cache::flush();

        $this->assertNull(TickAssertRecord::lastAt());
    }

    public function test_an_unreadable_stamp_is_no_record_rather_than_a_dated_guess(): void
    {
        Cache::put(TickAssertRecord::KEY, ['not', 'a', 'timestamp']);

        $this->assertNull(TickAssertRecord::lastAt());
    }

    /**
     * ⭐ THE PRINTED GRACE IS THE APPLIED GRACE, proved at the boundary rather than by
     * matching digits (rt#341, sola-pm's ask). The message is read for its two figures, and
     * those figures are then used as the threshold: one second inside is `fresh`, one second
     * outside is `stale`. A sentence carrying a hand-typed number — the drift this ask exists
     * to prevent — reds here the moment the constant behind the verdict moves.
     *
     * Two different horizons, because a grace that ignored the interval would still pass a
     * single-horizon check.
     */
    public function test_the_stale_message_prints_the_threshold_the_verdict_actually_applies(): void
    {
        foreach ([600, 3600] as $expected) {
            $summary = TickPosture::resolve(now()->subSeconds($expected * 10), $expected)->summary();

            $this->assertSame(1, preg_match('/allows (\d+)s of jitter grace/', $summary, $graceMatch), $summary);
            $this->assertSame(1, preg_match('/a tick older than (\d+)s reads as stale/', $summary, $staleMatch), $summary);

            $grace = (int) $graceMatch[1];
            $threshold = (int) $staleMatch[1];

            $this->assertSame($expected + $grace, $threshold, 'the printed threshold must be the horizon plus the printed grace');
            $this->assertGreaterThan($expected, $grace, 'the grace includes one whole extra interval');

            // The property, not the digits: the printed threshold IS the applied threshold.
            $this->assertSame(
                TickState::Fresh,
                TickPosture::resolve(now()->subSeconds($threshold), $expected)->state,
                "a tick exactly {$threshold}s old must still be fresh — the message says so",
            );
            $this->assertSame(
                TickState::Stale,
                TickPosture::resolve(now()->subSeconds($threshold + 1), $expected)->state,
                "a tick {$threshold}s + 1 old must be stale — the message says so",
            );
        }
    }

    /** The grace is only claimed where a verdict is claimed. */
    public function test_no_grace_is_printed_where_no_staleness_verdict_is_made(): void
    {
        TickRecord::stamp();

        foreach ([null, 600] as $expected) {
            config(['bridge.jobs.tick_expected_every' => $expected]);
            $this->assertStringNotContainsString('jitter grace', TickRecord::posture()->summary());
        }
    }
}
