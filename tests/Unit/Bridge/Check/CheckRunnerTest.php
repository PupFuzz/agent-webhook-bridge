<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckReport;
use App\Bridge\Check\CheckResult;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The DL-242 check-registry contract. Stage 0 ships the scaffolding with an EMPTY
 * registry, so these tests are the only thing exercising it — they are what keeps it
 * from being a decoration until stage 1 lands the first migrated check.
 *
 * Each one pins a property a later stage would otherwise be free to break silently:
 * order (the byte-identical output contract), attribution (the inventory), id
 * uniqueness (one inventory namespace), and the deliberate absence of exception
 * isolation (a change of that is an operator-visible change).
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
            ->register($this->fixedCheck('retention.posture', Finding::ok('retention: on')))
            ->run(new CheckContext);

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
                $this->fixedCheck('first', Finding::ok('a'), Finding::warn('b')),
                $this->fixedCheck('second', Finding::ok('c')),
            )
            ->run(new CheckContext);

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

        $report = (new CheckRunner)->register($check)->run(new CheckContext);

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

        $report = (new CheckRunner)->registerPerAgent($check)->runForAgent($this->agent('prod-agent'), new CheckContext);

        $this->assertSame('prod-agent', $report->results[0]->agent);
        $this->assertSame('agent config ok: prod-agent', $report->findings()[0]->message);
    }

    public function test_global_and_per_agent_registries_do_not_run_each_other(): void
    {
        $runner = (new CheckRunner)
            ->register($this->fixedCheck('global', Finding::ok('global ran')))
            ->registerPerAgent(new class implements PerAgentCheck
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
        $this->assertSame(['global ran'], array_map(fn (Finding $f) => $f->message, $runner->run(new CheckContext)->findings()));
        $this->assertSame(['per-agent ran'], array_map(fn (Finding $f) => $f->message, $runner->runForAgent($this->agent('a'), new CheckContext)->findings()));
    }

    public function test_a_duplicate_id_is_refused_across_both_registries(): void
    {
        $runner = (new CheckRunner)->register($this->fixedCheck('writeback.source_coverage', Finding::ok('x')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate check id: writeback.source_coverage');

        $runner->registerPerAgent(new class implements PerAgentCheck
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

        (new CheckRunner)->register($this->fixedCheck('', Finding::ok('x')));
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

        (new CheckRunner)->register($check)->run(new CheckContext);
    }

    public function test_an_empty_registry_reports_nothing(): void
    {
        // Stage 0's shipped state. An empty report must render as no output at all —
        // that is what makes "operator-visible change: NONE" true by construction.
        $report = (new CheckRunner)->run(new CheckContext);

        $this->assertSame([], $report->results);
        $this->assertSame([], $report->findings());
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
