<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Scheduling\JobSpecException;
use App\Bridge\Standup\StandupGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * The INSTANCE half of the governance split (card#8425 / DL-325): what the runtime
 * insert/remove API accepts, and — the part that matters — what it REFUSES.
 *
 * ⭐ EVERY REFUSAL HERE IS SEEN TO FIRE. A validating constructor whose guards are never
 * exercised is decoration: the directive's requirement was that an unknown handler be a LOUD
 * refusal and never a silent skip, and "loud" is only a property you can demonstrate.
 */
class JobRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(array $armed = []): JobRegistry
    {
        $handlers = new JobHandlerRegistry($armed, $this->app->make(StandupGate::class));
        $handlers->register(new RecordingJobHandler);
        $handlers->register(new RecordingJobHandler('mutating_job', JobCapability::MutatesState));

        return new JobRegistry($handlers);
    }

    private function spec(array $overrides = []): JobSpec
    {
        return new JobSpec(
            name: $overrides['name'] ?? 'a-job',
            handler: $overrides['handler'] ?? 'recording_job',
            intervalS: $overrides['intervalS'] ?? 600,
            owner: $overrides['owner'] ?? 'suite',
            docsRef: $overrides['docsRef'] ?? 'docs/periodic-jobs.md',
            justification: $overrides['justification'] ?? 'no arrival on this install can create or gate this work',
            enabled: $overrides['enabled'] ?? true,
        );
    }

    public function test_an_instance_is_inserted_enumerated_and_removed_at_runtime(): void
    {
        $registry = $this->registry();

        $registry->insert($this->spec());
        $this->assertSame(['a-job'], $registry->all()->pluck('name')->all());

        $this->assertTrue($registry->remove('a-job'));
        $this->assertSame([], $registry->all()->pluck('name')->all());
        $this->assertFalse($registry->remove('a-job'), 'removing what is not there is not an error, but it is not a removal either');
    }

    public function test_insert_is_an_upsert_by_name(): void
    {
        $registry = $this->registry();

        $registry->insert($this->spec());
        $registry->insert($this->spec(['intervalS' => 900]));

        $this->assertCount(1, $registry->all(), 'a caller re-declaring its job on every boot must converge on one row');
        $this->assertSame(900, (int) $registry->find('a-job')?->interval_s);
    }

    /**
     * ⛔ A RE-DECLARE MUST NOT UNDO AN OPERATOR'S `disable`. Insert is an upsert a caller
     * re-runs — the shape this registry was built for is a subsystem declaring its job on
     * every boot — so writing the spec's `enabled` on the UPDATE path would silently revert
     * `bridge:jobs disable` at the next boot, with nothing said anywhere and the switch's own
     * docblock promising the opposite. A declaration says what a job IS; whether this install
     * currently wants it running is {@see JobRegistry::setEnabled()}'s to say.
     */
    public function test_a_re_declare_does_not_re_enable_a_job_an_operator_disabled(): void
    {
        $registry = $this->registry();
        $registry->insert($this->spec());
        $registry->setEnabled('a-job', false);

        $registry->insert($this->spec(['intervalS' => 900]));

        $this->assertFalse((bool) $registry->find('a-job')?->enabled, 'a boot-time re-declare reverted an operator act');

        // The control: everything that is NOT the operator's switch still converges on the
        // re-declare, which is the whole point of the upsert. Without this leg the test above
        // would pass for an insert that had stopped updating anything at all.
        $this->assertSame(900, (int) $registry->find('a-job')?->interval_s);
    }

    /** The other side of the carve-out: on CREATE the spec's own value is what is stored. */
    public function test_an_instance_declared_disabled_is_created_disabled(): void
    {
        $registry = $this->registry();
        $registry->insert($this->spec(['enabled' => false]));

        $this->assertFalse((bool) $registry->find('a-job')?->enabled);
    }

    public function test_a_disabled_instance_stays_enumerable(): void
    {
        $registry = $this->registry();
        $registry->insert($this->spec());

        $this->assertTrue($registry->setEnabled('a-job', false));

        // The audit surface must show a job somebody switched OFF: deleting it to silence it
        // destroys the record of the decision.
        $this->assertCount(1, $registry->all());
        $this->assertFalse((bool) $registry->find('a-job')?->enabled);
    }

    public function test_an_unknown_handler_is_refused_at_insert_and_names_what_does_exist(): void
    {
        $this->expectException(JobSpecException::class);
        $this->expectExceptionMessageMatches('/no handler named \'nope\' exists in this build.*Registered: /s');

        $this->registry()->insert($this->spec(['handler' => 'nope']));
    }

    public function test_a_state_mutating_handler_is_refused_until_this_install_arms_it(): void
    {
        try {
            $this->registry()->insert($this->spec(['handler' => 'mutating_job']));
            $this->fail('an unarmed state-mutating handler must be refused');
        } catch (JobSpecException $e) {
            $this->assertStringContainsString('BRIDGE_JOBS_ARMED_MUTATORS', $e->getMessage());
        }

        // The SAME insert, on an install whose operator armed it. Without this leg the test
        // above would pass for a registry that refuses every mutating handler forever.
        $armed = $this->registry(['mutating_job']);
        $armed->insert($this->spec(['handler' => 'mutating_job']));
        $this->assertSame('mutating_job', $armed->find('a-job')?->handler);
    }

    /**
     * ⭐ The operator's anti-proliferation rule, mechanised. A missing justification is a
     * refused insert — not a warning, not a default, and not an approval gate: the friction
     * is one sentence and nobody is consulted.
     */
    public function test_an_instance_with_no_justification_is_refused(): void
    {
        foreach (['', '   ', '-', 'n/a', 'because'] as $blank) {
            try {
                $this->registry()->insert($this->spec(['justification' => $blank]));
                $this->fail("a justification of '{$blank}' must be refused");
            } catch (JobSpecException $e) {
                $this->assertStringContainsString('A periodic job is the LAST resort', $e->getMessage());
            }
        }

        // And a real sentence is accepted — the control that stops the guard reading as
        // "the registry refuses everything".
        $this->registry()->insert($this->spec(['justification' => 'the upstream emits no webhook here, so there is no arrival to gate on']));
        $this->assertCount(1, $this->registry()->all());
    }

    public function test_the_justification_reaches_the_enumeration(): void
    {
        $registry = $this->registry();
        $registry->insert($this->spec(['justification' => 'no arrival on this install can create or gate this work']));

        $this->assertSame(
            'no arrival on this install can create or gate this work',
            $registry->find('a-job')?->justification,
            'the audit surface must answer WHY a job is periodic, not only what runs',
        );
    }

    public function test_a_cadence_no_ingress_can_deliver_is_refused(): void
    {
        foreach ([1, 59, 2678401] as $interval) {
            try {
                $this->registry()->insert($this->spec(['intervalS' => $interval]));
                $this->fail("an interval of {$interval}s must be refused");
            } catch (JobSpecException $e) {
                $this->assertStringContainsString('interval', $e->getMessage());
            }
        }

        $this->registry()->insert($this->spec(['intervalS' => 60]));
        $this->assertCount(1, $this->registry()->all(), 'the floor itself must be accepted');
    }

    public function test_a_name_that_is_not_a_handle_is_refused(): void
    {
        $this->expectException(JobSpecException::class);

        $this->registry()->insert($this->spec(['name' => 'Not A Handle']));
    }

    public function test_an_owner_and_a_docs_ref_are_required(): void
    {
        foreach ([['owner' => ' '], ['docsRef' => '']] as $missing) {
            try {
                $this->registry()->insert($this->spec($missing));
                $this->fail('an instance missing '.array_key_first($missing).' must be refused');
            } catch (JobSpecException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
