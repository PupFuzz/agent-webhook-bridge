<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckReport;
use App\Bridge\Check\CheckResult;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The DL-242 check-registry contract. Stage 1 wired the first three checks into
 * `CheckCommand`, so the registry is no longer exercised only from here — but the golden
 * fixtures measure OUTPUT, and these measure the runner's own properties, which output
 * cannot distinguish (two checks in one slot and one check yielding twice print the same
 * bytes).
 *
 * Each one pins a property a later stage would otherwise be free to break silently:
 * order (the byte-identical output contract), slot isolation (position), attribution
 * (the inventory), id uniqueness (one inventory namespace), and the deliberate absence
 * of exception isolation (a change of that is an operator-visible change).
 */
class CheckRunnerTest extends TestCase
{
    private function fixedCheck(string $id, Finding ...$findings): Check
    {
        return new class($id, $findings) implements Check
        {
            /** @param list<Finding> $findings */
            public function __construct(private string $id, private array $findings) {}

            public function id(): string
            {
                return $this->id;
            }

            public function run(CheckContext $ctx): iterable
            {
                return $this->findings;
            }
        };
    }

    private function fixedPerAgentCheck(string $id, string $message): PerAgentCheck
    {
        return new class($id, $message) implements PerAgentCheck
        {
            public function __construct(private string $id, private string $message) {}

            public function id(): string
            {
                return $this->id;
            }

            public function runFor(AgentConfig $config, CheckContext $ctx): iterable
            {
                return [Finding::ok($this->message)];
            }
        };
    }

    private function agent(string $name): AgentConfig
    {
        return AgentConfig::fromArray($name, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ]);
    }

    public function test_it_reports_findings_attributed_to_the_check_that_produced_them(): void
    {
        $report = (new CheckRunner)
            ->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('retention.posture', Finding::ok('retention: on')))
            ->run(CheckSlot::ProbeToolsSsh, new CheckContext);

        $this->assertCount(1, $report->results);
        $this->assertSame('retention.posture', $report->results[0]->id);
        $this->assertNull($report->results[0]->agent);
        $this->assertSame('retention: on', $report->findings()[0]->message);
    }

    public function test_it_preserves_registration_order_and_yield_order(): void
    {
        // Order is the byte-identical output contract stages 0-7 hold: each stage
        // registers its migrated unit at the SAME ordinal position the inline code
        // held. A runner free to reorder would break that on the first renderer.
        $report = (new CheckRunner)
            ->register(
                CheckSlot::ProbeToolsSsh,
                $this->fixedCheck('first', Finding::ok('a'), Finding::warn('b')),
                $this->fixedCheck('second', Finding::ok('c')),
            )
            ->run(CheckSlot::ProbeToolsSsh, new CheckContext);

        $this->assertSame(
            ['a', 'b', 'c'],
            array_map(fn (Finding $f) => $f->message, $report->findings()),
        );
        $this->assertSame(['first', 'second'], array_map(fn (CheckResult $r) => $r->id, $report->results));
    }

    public function test_a_generator_check_is_materialized_as_a_list(): void
    {
        // `iterable` admits a Generator, and the ≥1-finding contract invites yielding.
        // A generator with string keys would corrupt a naive iterator_to_array into a
        // non-list; assert the list shape the report's `list<Finding>` promises.
        $check = new class implements Check
        {
            public function id(): string
            {
                return 'generated';
            }

            public function run(CheckContext $ctx): iterable
            {
                yield 'keyed' => Finding::warn('one');
                yield 'also-keyed' => Finding::warn('two');
            }
        };

        $report = (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $check)->run(CheckSlot::ProbeToolsSsh, new CheckContext);

        $this->assertSame([0, 1], array_keys($report->results[0]->findings));
        $this->assertSame(['one', 'two'], array_map(fn (Finding $f) => $f->message, $report->findings()));
    }

    public function test_per_agent_checks_run_for_the_named_agent_and_carry_the_attribution(): void
    {
        $check = new class implements PerAgentCheck
        {
            public function id(): string
            {
                return 'agent.config';
            }

            public function runFor(AgentConfig $config, CheckContext $ctx): iterable
            {
                return [Finding::ok("agent config ok: {$config->agentName}")];
            }
        };

        $report = (new CheckRunner)->registerPerAgent(CheckSlot::AgentConfig, $check)->runForAgent(CheckSlot::AgentConfig, $this->agent('prod-agent'), new CheckContext);

        $this->assertSame('prod-agent', $report->results[0]->agent);
        $this->assertSame('agent config ok: prod-agent', $report->findings()[0]->message);
    }

    public function test_global_and_per_agent_registries_do_not_run_each_other(): void
    {
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('global', Finding::ok('global ran')))
            ->registerPerAgent(CheckSlot::ProbeToolsSsh, new class implements PerAgentCheck
            {
                public function id(): string
                {
                    return 'per-agent';
                }

                public function runFor(AgentConfig $config, CheckContext $ctx): iterable
                {
                    return [Finding::ok('per-agent ran')];
                }
            });

        // Constraint (b): a per-agent check must fire INSIDE the config iteration, not
        // once globally — hoisting it would reorder output.
        $this->assertSame(['global ran'], array_map(fn (Finding $f) => $f->message, $runner->run(CheckSlot::ProbeToolsSsh, new CheckContext)->findings()));
        $this->assertSame(['per-agent ran'], array_map(fn (Finding $f) => $f->message, $runner->runForAgent(CheckSlot::ProbeToolsSsh, $this->agent('a'), new CheckContext)->findings()));
    }

    public function test_a_duplicate_id_is_refused_across_both_registries(): void
    {
        $runner = (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('writeback.source_coverage', Finding::ok('x')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate check id: writeback.source_coverage');

        $runner->registerPerAgent(CheckSlot::AgentConfig, new class implements PerAgentCheck
        {
            public function id(): string
            {
                return 'writeback.source_coverage';
            }

            public function runFor(AgentConfig $config, CheckContext $ctx): iterable
            {
                return [];
            }
        });
    }

    public function test_an_empty_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('check id must not be empty');

        (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('', Finding::ok('x')));
    }

    public function test_a_throwing_check_is_not_isolated(): void
    {
        // Deliberate: today an unwrapped inline leg that throws aborts bridge:check.
        // Catching here would turn those aborts into warns — an operator-visible
        // change, and not a side effect this refactor gets to make. Fail-soft legs
        // keep their own try/catch when they migrate.
        $check = new class implements Check
        {
            public function id(): string
            {
                return 'explodes';
            }

            public function run(CheckContext $ctx): iterable
            {
                throw new RuntimeException('boom');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $check)->run(CheckSlot::ProbeToolsSsh, new CheckContext);
    }

    public function test_an_empty_registry_reports_nothing(): void
    {
        // Stage 0's shipped state. An empty report must render as no output at all —
        // that is what makes "operator-visible change: NONE" true by construction.
        $report = (new CheckRunner)->run(CheckSlot::ProbeToolsSsh, new CheckContext);

        $this->assertSame([], $report->results);
        $this->assertSame([], $report->findings());
    }

    public function test_a_slot_runs_only_its_own_checks(): void
    {
        // The property stage 1 added the slot FOR. `CheckCommand` has two per-agent
        // iterations at different output positions; without slot isolation, running the
        // ssh loop would also re-run the channel-snapshot check for each ssh agent —
        // duplicated lines at the wrong position, which is precisely the byte-identical
        // contract breaking.
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('in-probe-slot', Finding::ok('probe')))
            ->register(CheckSlot::BoardToolsSsh, $this->fixedCheck('in-board-slot', Finding::ok('board')));

        $this->assertSame(['probe'], array_map(fn (Finding $f) => $f->message, $runner->run(CheckSlot::ProbeToolsSsh, new CheckContext)->findings()));
        $this->assertSame(['board'], array_map(fn (Finding $f) => $f->message, $runner->run(CheckSlot::BoardToolsSsh, new CheckContext)->findings()));
    }

    public function test_a_per_agent_slot_runs_only_its_own_checks(): void
    {
        $runner = (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, $this->fixedPerAgentCheck('config-loop', 'from the config loop'))
            ->registerPerAgent(CheckSlot::BoardToolsSsh, $this->fixedPerAgentCheck('ssh-loop', 'from the ssh loop'));

        $agent = $this->agent('prod-agent');

        $this->assertSame(['from the config loop'], array_map(fn (Finding $f) => $f->message, $runner->runForAgent(CheckSlot::AgentConfig, $agent, new CheckContext)->findings()));
        $this->assertSame(['from the ssh loop'], array_map(fn (Finding $f) => $f->message, $runner->runForAgent(CheckSlot::BoardToolsSsh, $agent, new CheckContext)->findings()));
    }

    public function test_a_duplicate_id_is_refused_across_slots(): void
    {
        // The inventory is ONE namespace regardless of where a check runs — a slot is a
        // position, not a scope for names. Two checks sharing an id would silently merge
        // into one inventory row at stage 8.
        $runner = (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('board_tools.ssh', Finding::ok('x')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate check id: board_tools.ssh');

        $runner->register(CheckSlot::BoardToolsSsh, $this->fixedCheck('board_tools.ssh', Finding::ok('y')));
    }

    public function test_a_slot_with_nothing_registered_reports_nothing(): void
    {
        // The normal mid-migration state: a slot exists for a position whose unit has
        // not migrated yet. It must render as no output, not as an error.
        $runner = (new CheckRunner)->register(CheckSlot::ProbeToolsSsh, $this->fixedCheck('only', Finding::ok('x')));

        $this->assertSame([], $runner->run(CheckSlot::BoardToolsSsh, new CheckContext)->results);
        $this->assertSame([], $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('a'), new CheckContext)->results);
    }

    public function test_the_report_flattens_severities_without_interpreting_them(): void
    {
        // The runner reports; it does not decide. The exit contract stays
        // CheckCommand::emitFinding()'s one `fail` arm.
        $report = new CheckReport([
            new CheckResult('a', [Finding::fail('f'), Finding::unvalidated('u')]),
        ]);

        $this->assertSame(
            [Severity::Fail, Severity::Unvalidated],
            array_map(fn (Finding $f) => $f->severity, $report->findings()),
        );
    }
}
