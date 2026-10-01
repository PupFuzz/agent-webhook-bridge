<?php

namespace Tests\Unit\Bridge\Scheduling;

use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRefusal;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\HandlerRegistry;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * Every state-mutating job handler ships ARMED (card#10918 / DL-441, amending DL-325): the
 * operator's per-handler kill switch is `BRIDGE_JOBS_DISARMED_MUTATORS`, and `owed_write_retry`
 * keeps its own named switch, `BRIDGE_OWED_WRITE_RETRY_DISABLED` (card#10849 / DL-440).
 */
class JobHandlerRegistryArmingTest extends TestCase
{
    private function registryFromConfig(): JobHandlerRegistry
    {
        $registry = new JobHandlerRegistry(
            JobHandlerRegistry::disarmedFromConfig(),
            $this->app->make(StandupGate::class),
            $this->app->make(HandlerRegistry::class),
        );
        $registry->register(new RecordingJobHandler('custom_mutator', JobCapability::MutatesState));

        return $registry;
    }

    public function test_a_state_mutating_handler_is_armed_with_nothing_configured(): void
    {
        config(['bridge.jobs.disarmed_mutators' => '', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $registry = $this->registryFromConfig();

        $this->assertInstanceOf(RecordingJobHandler::class, $registry->runnable('custom_mutator'));
        $this->assertInstanceOf(OwedWriteRetryJob::class, $registry->runnable(OwedWriteRetryJob::NAME));
        $this->assertSame([], JobHandlerRegistry::disarmedFromConfig());
    }

    public function test_the_disarm_list_is_the_per_handler_kill_switch(): void
    {
        config(['bridge.jobs.disarmed_mutators' => 'custom_mutator', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $registry = $this->registryFromConfig();

        $refusal = $registry->runnable('custom_mutator');
        $this->assertInstanceOf(JobRefusal::class, $refusal);
        $this->assertSame(JobRefusal::DISARMED_MUTATOR, $refusal->reason);
        $this->assertStringContainsString('BRIDGE_JOBS_DISARMED_MUTATORS', $refusal->message);
        // A kill switch for one handler leaves every other one armed.
        $this->assertInstanceOf(OwedWriteRetryJob::class, $registry->runnable(OwedWriteRetryJob::NAME));
        $this->assertSame('BRIDGE_JOBS_DISARMED_MUTATORS', JobHandlerRegistry::disarmedBy('custom_mutator'));
        $this->assertNull(JobHandlerRegistry::disarmedBy(OwedWriteRetryJob::NAME));
    }

    public function test_the_owed_write_retry_kill_switch_disarms_only_that_handler(): void
    {
        config(['bridge.jobs.disarmed_mutators' => '', 'bridge.jobs.owed_write_retry_disabled' => true]);

        $registry = $this->registryFromConfig();

        $this->assertSame([OwedWriteRetryJob::NAME], JobHandlerRegistry::disarmedFromConfig());
        $this->assertInstanceOf(JobRefusal::class, $registry->runnable(OwedWriteRetryJob::NAME));
        $this->assertInstanceOf(RecordingJobHandler::class, $registry->runnable('custom_mutator'));
        $this->assertSame('BRIDGE_OWED_WRITE_RETRY_DISABLED=true', JobHandlerRegistry::disarmedBy(OwedWriteRetryJob::NAME));
    }

    public function test_owed_write_retry_can_also_be_disarmed_through_the_general_list(): void
    {
        config(['bridge.jobs.disarmed_mutators' => 'owed_write_retry', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $this->assertInstanceOf(JobRefusal::class, $this->registryFromConfig()->runnable(OwedWriteRetryJob::NAME));
        $this->assertSame('BRIDGE_JOBS_DISARMED_MUTATORS', JobHandlerRegistry::disarmedBy(OwedWriteRetryJob::NAME));
    }

    public function test_both_switches_naming_it_do_not_list_it_twice(): void
    {
        config(['bridge.jobs.disarmed_mutators' => 'owed_write_retry', 'bridge.jobs.owed_write_retry_disabled' => true]);

        $this->assertSame([OwedWriteRetryJob::NAME], JobHandlerRegistry::disarmedFromConfig());
    }

    public function test_a_read_and_alert_handler_named_in_the_list_is_not_disarmed(): void
    {
        config(['bridge.jobs.disarmed_mutators' => 'reader', 'bridge.jobs.owed_write_retry_disabled' => false]);

        $registry = $this->registryFromConfig();
        $registry->register(new RecordingJobHandler('reader', JobCapability::ReadAndAlert));

        // The list governs mutators only; a read-and-alert job is stopped by disabling its instance.
        $this->assertInstanceOf(RecordingJobHandler::class, $registry->runnable('reader'));
    }

    public function test_the_retired_armed_list_arms_and_disarms_nothing(): void
    {
        config([
            'bridge.jobs.armed_mutators' => 'owed_write_retry',
            'bridge.jobs.disarmed_mutators' => '',
            'bridge.jobs.owed_write_retry_disabled' => false,
        ]);

        $this->assertSame([], JobHandlerRegistry::disarmedFromConfig());
        $this->assertInstanceOf(RecordingJobHandler::class, $this->registryFromConfig()->runnable('custom_mutator'));
    }
}
