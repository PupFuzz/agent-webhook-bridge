<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Scheduling\TickState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * DEATH IS THE ALARM (card#8425 / DL-325) — and the alarm's own correctness conditions.
 *
 * The tick is the single point of failure for every periodic job on an install that adopted
 * it, and the failure mode is SILENCE: `bridge:prune` sat unscheduled for ~45 days across
 * three installs because nothing announced its absence. So the record exists — and every
 * distinction the ruling on staleness requires is pinned here, because an alarm that cannot
 * tell *"I have no measurement"* from *"the measurement is bad"* pages the wrong installs
 * and gets muted.
 *
 *  - THE HORIZON IS THE INSTALL'S OWN DECLARATION, never a fleet-wide constant.
 *  - AN ABSENT RECORD IS UNMEASURED, NOT STALE — the third state, in both directions.
 *  - NO DECLARED HORIZON ⇒ NO VERDICT, and never a destructive one.
 */
class TickFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['bridge.jobs.tick_expected_every' => null]);
    }

    public function test_an_install_that_never_ticked_is_unmeasured_and_not_stale(): void
    {
        $posture = TickRecord::posture();

        $this->assertSame(TickState::Unmeasured, $posture->state);
        $this->assertFalse($posture->adopted);
        $this->assertFalse($posture->failsAssertion(), 'an install that never adopted the tick is not failing by not ticking');
        $this->assertStringContainsString('not adopted', $posture->summary());
    }

    public function test_a_declared_install_that_has_never_been_seen_is_unmeasured_and_says_so(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);

        $posture = TickRecord::posture();

        // LOUD, but honest about which fact it holds: never observed, not dead.
        $this->assertSame(TickState::Unmeasured, $posture->state);
        $this->assertTrue($posture->failsAssertion());
        $this->assertStringContainsString('NEVER OBSERVED', $posture->summary());
        $this->assertStringContainsString('UNMEASURED, not dead', $posture->summary());
    }

    public function test_a_tick_with_no_declared_horizon_gets_an_age_and_no_verdict(): void
    {
        TickRecord::stamp();

        $posture = TickRecord::posture();

        $this->assertSame(TickState::Undeclared, $posture->state);
        $this->assertNotNull($posture->ageS);
        $this->assertNull($posture->expectedEveryS);
        $this->assertFalse($posture->failsAssertion(), 'no declaration, no verdict — inventing a default constant is exactly what this refuses');
    }

    public function test_a_declared_tick_inside_its_own_horizon_is_fresh(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();

        $posture = TickRecord::posture();

        $this->assertSame(TickState::Fresh, $posture->state);
        $this->assertFalse($posture->failsAssertion());
    }

    public function test_a_declared_tick_that_blew_its_own_horizon_is_stale(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();

        // Two horizons plus the jitter grace. The number comes from the install's own
        // declaration, so no fleet constant is being tuned here.
        $this->travel(1500)->seconds();

        $posture = TickRecord::posture();
        $this->assertSame(TickState::Stale, $posture->state);
        $this->assertTrue($posture->failsAssertion());
        $this->assertStringContainsString('STALE', $posture->summary());
    }

    /**
     * ⚑ THE CONTROL FOR THE STALENESS THRESHOLD. An install that declares a LONG horizon is
     * not paged for meeting it — which is the whole argument against a fleet-wide constant:
     * the same age is healthy on one install and evidence on another, and only the install
     * knows which.
     */
    public function test_the_same_age_is_fresh_or_stale_according_to_the_installs_own_declaration(): void
    {
        TickRecord::stamp();
        $this->travel(1500)->seconds();

        config(['bridge.jobs.tick_expected_every' => 600]);
        $this->assertSame(TickState::Stale, TickRecord::posture()->state);

        config(['bridge.jobs.tick_expected_every' => 3600]);
        $this->assertSame(TickState::Fresh, TickRecord::posture()->state);
    }

    public function test_a_flushed_cache_degrades_to_unmeasured_and_never_to_fresh(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);
        TickRecord::stamp();
        $this->assertSame(TickState::Fresh, TickRecord::posture()->state);

        Cache::flush();

        // The failure direction that matters: a lost stamp is loud on a declared install,
        // never a false green.
        $this->assertSame(TickState::Unmeasured, TickRecord::posture()->state);
        $this->assertTrue(TickRecord::posture()->failsAssertion());
    }

    public function test_an_unreadable_stamp_is_unmeasured_rather_than_a_dated_guess(): void
    {
        Cache::put(TickRecord::KEY, 'not-a-timestamp', 600);

        $this->assertNull(TickRecord::lastAt());
        $this->assertSame(TickState::Unmeasured, TickRecord::posture()->state);
    }

    public function test_the_tick_command_stamps_the_record(): void
    {
        $this->assertNull(TickRecord::lastAt(), 'the premise');

        $this->artisan('bridge:tick')->assertExitCode(0);

        $this->assertNotNull(TickRecord::lastAt());
    }
}
