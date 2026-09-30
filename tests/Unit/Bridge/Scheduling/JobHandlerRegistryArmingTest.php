<?php

namespace Tests\Unit\Bridge\Scheduling;

use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\JobHandlerRegistry;
use Tests\TestCase;

/**
 * `JobHandlerRegistry::armedFromConfig()`'s one named exception to DL-325 (card#10849 / DL-440,
 * operator ruling 2026-09-29): `owed_write_retry` is armed by default, regardless of
 * `BRIDGE_JOBS_ARMED_MUTATORS`, unless its own kill switch is set. Every OTHER name in that list
 * still passes through unchanged — this is not a general default-on mechanism.
 */
class JobHandlerRegistryArmingTest extends TestCase
{
    public function test_owed_write_retry_is_armed_by_default_with_an_empty_list(): void
    {
        config(['bridge.jobs.armed_mutators' => '', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $this->assertContains(OwedWriteRetryJob::NAME, JobHandlerRegistry::armedFromConfig());
    }

    public function test_the_kill_switch_withholds_it(): void
    {
        config(['bridge.jobs.armed_mutators' => '', 'bridge.jobs.owed_write_retry_disabled' => true]);

        $this->assertNotContains(OwedWriteRetryJob::NAME, JobHandlerRegistry::armedFromConfig());
    }

    public function test_an_explicit_operator_listing_is_not_duplicated(): void
    {
        config(['bridge.jobs.armed_mutators' => 'owed_write_retry,some_other_job', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $armed = JobHandlerRegistry::armedFromConfig();
        $this->assertSame(1, count(array_keys($armed, OwedWriteRetryJob::NAME, true)));
        $this->assertContains('some_other_job', $armed);
    }

    public function test_every_other_name_in_the_list_is_unaffected_by_the_kill_switch(): void
    {
        config(['bridge.jobs.armed_mutators' => 'some_other_job', 'bridge.jobs.owed_write_retry_disabled' => true]);

        $armed = JobHandlerRegistry::armedFromConfig();
        $this->assertContains('some_other_job', $armed);
        $this->assertNotContains(OwedWriteRetryJob::NAME, $armed);
    }

    /**
     * The kill switch wins outright, even over an explicit (redundant, or left over from
     * before disabling) listing — a kill switch a stray list entry could silently override
     * would not be a kill switch.
     */
    public function test_the_kill_switch_wins_even_when_explicitly_listed_too(): void
    {
        config(['bridge.jobs.armed_mutators' => 'owed_write_retry', 'bridge.jobs.owed_write_retry_disabled' => true]);

        $this->assertNotContains(OwedWriteRetryJob::NAME, JobHandlerRegistry::armedFromConfig());
    }
}
