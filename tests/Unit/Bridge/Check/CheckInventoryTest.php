<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\OptInCheck;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use Tests\TestCase;

/**
 * The DL-242 stage-8 accounting invariant: every registered check is accounted for on
 * every run that completes (`CheckRunner` does not catch, so a throwing check aborts
 * `bridge:check` before anything renders the account).
 *
 * THE TWO TESTS THIS FILE EXISTS FOR ARE THE POSITIVE CONTROLS the plan's § Verification
 * item 5 demands — *"register a deliberately non-emitting check and observe the runner
 * flag it, before trusting the exact-inventory invariant"*. Measurement at the start of
 * the stage widened that to two failure shapes, because the corpus showed the second one
 * is 13 of 37 checks on the baseline install and the first is 13 more (an earlier revision
 * said 15, a PRE-taxonomy count that folded in the 2 un-requested opt-in probes — which
 * are a THIRD shape, and the one `NotRequested` exists to keep out of this pair):
 *  - {@see test_a_registered_check_that_emits_nothing_is_recorded_not_lost()}
 *  - {@see test_a_registered_check_whose_slot_never_runs_is_recorded_as_not_run()}
 * Both were confirmed RED against a runner that only reported what it was told about,
 * before this class was trusted. A check that cannot fail is a decoration.
 *
 * WHY THE DIRECTION OF DERIVATION IS THE POINT, and is pinned here rather than left to a
 * docblock: `NotRun` is derived from the REGISTRATION LIST, so a slot whose invocation is
 * forgotten still produces a row. The rejected alternative — the caller declaring each
 * skip — would have made the mechanism's correctness depend on remembering to call it,
 * which is the same shape as the bug it closes.
 */
class CheckInventoryTest extends TestCase
{
    private function check(string $id, Finding ...$findings): Check
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

    private function optIn(string $id, bool $requested, Finding ...$findings): OptInCheck
    {
        return new class($id, $requested, $findings) implements OptInCheck
        {
            /** @param list<Finding> $findings */
            public function __construct(private string $id, private bool $requested, private array $findings) {}

            public function id(): string
            {
                return $this->id;
            }

            public function wasRequested(): bool
            {
                return $this->requested;
            }

            public function run(CheckContext $ctx): iterable
            {
                return $this->findings;
            }
        };
    }

    /** @param list<Finding> $findings */
    private function perAgent(string $id, array $findings): PerAgentCheck
    {
        return new class($id, $findings) implements PerAgentCheck
        {
            /** @param list<Finding> $findings */
            public function __construct(private string $id, private array $findings) {}

            public function id(): string
            {
                return $this->id;
            }

            public function runFor(AgentConfig $config, CheckContext $ctx): iterable
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

    // ---- the two positive controls ----

    public function test_a_registered_check_that_emits_nothing_is_recorded_not_lost(): void
    {
        // POSITIVE CONTROL 1 (§ Verification item 5). The deliberately non-emitting check.
        // Before stage 8 this check contributed nothing to any report and nothing named
        // it, so dropping it from the registration list changed no output at all.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('emits.nothing'));
        $runner->run(CheckSlot::Install, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Silent, $inventory->dispositions['emits.nothing']);
        $this->assertSame(1, $inventory->registered());
        $this->assertSame(1, $inventory->ran());
    }

    public function test_a_registered_check_whose_slot_never_runs_is_recorded_as_not_run(): void
    {
        // POSITIVE CONTROL 2. The shape the corpus measured as 13 of 37 on the baseline
        // install, and the one `CheckRunner`'s docblock named before it was measured: "A
        // SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL DOWN". The slot is registered
        // and deliberately never invoked.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('ran.here', Finding::ok('hi')))
            ->register(CheckSlot::Writeback, $this->check('never.invoked', Finding::ok('unreachable')));
        $runner->run(CheckSlot::Install, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Reported, $inventory->dispositions['ran.here']);
        $this->assertSame(CheckDisposition::NotRun, $inventory->dispositions['never.invoked']);
        // And it is accounted for WITHOUT the caller having said anything about it — the
        // derivation direction that makes a forgotten call site impossible to lose.
        $this->assertSame(['never.invoked'], $inventory->unexplainedNotRun());
    }

    // ---- the accounting contract ----

    public function test_the_dispositions_always_sum_to_the_registered_total(): void
    {
        // The conservation property. It is what lets the operator-facing line be read as
        // an inventory rather than a selection: no disposition can absorb a check.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('spoke', Finding::warn('w')), $this->check('quiet'))
            ->register(CheckSlot::ProbeTools, $this->optIn('optin', requested: false))
            ->register(CheckSlot::Writeback, $this->check('skipped'));
        $runner->run(CheckSlot::Install, new CheckContext);
        $runner->run(CheckSlot::ProbeTools, new CheckContext);

        $inventory = $runner->inventory();
        $sum = $inventory->count(CheckDisposition::Reported)
            + $inventory->count(CheckDisposition::Silent)
            + $inventory->count(CheckDisposition::NotRequested)
            + $inventory->count(CheckDisposition::NotRun);

        $this->assertSame(4, $inventory->registered());
        $this->assertSame(4, $sum);
    }

    public function test_ran_counts_only_the_checks_that_actually_executed(): void
    {
        // The conservation property above cannot see this one: it sums all four
        // dispositions, so a `ran()` that absorbed `NotRequested` still totals the
        // registered count. `ran()` is what the operator line reads as "N ran", and the
        // two non-executing dispositions must stay out of it — neither looked at this
        // install. Mutation-proven at the golden corpus (a wrong `ran()` moves the line
        // in 34 fixtures); asserted here because this is where the contract is stated.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('spoke', Finding::warn('w')), $this->check('quiet'))
            ->register(CheckSlot::ProbeTools, $this->optIn('optin', requested: false))
            ->register(CheckSlot::Writeback, $this->check('skipped'));
        $runner->run(CheckSlot::Install, new CheckContext);
        $runner->run(CheckSlot::ProbeTools, new CheckContext);

        $this->assertSame(2, $runner->inventory()->ran());
    }

    public function test_an_unrequested_opt_in_check_is_not_confused_with_a_silent_one(): void
    {
        // The resolved opt-in-probe decision, as a property. `Silent` is a statement
        // ABOUT THE INSTALL ("looked, nothing to say"); `NotRequested` is a statement
        // about the INVOCATION and says nothing about the install. Collapsing them is
        // precisely what that decision refused.
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeTools, $this->optIn('probe.unasked', requested: false))
            ->register(CheckSlot::ProbeToolsSsh, $this->optIn('probe.asked', requested: true));
        $runner->run(CheckSlot::ProbeTools, new CheckContext);
        $runner->run(CheckSlot::ProbeToolsSsh, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::NotRequested, $inventory->dispositions['probe.unasked']);
        // REQUESTED and silent is NOT `NotRequested`: the operator asked, so the silence
        // is an answer about the install and belongs in the same bucket as any other
        // check that looked and found nothing.
        $this->assertSame(CheckDisposition::Silent, $inventory->dispositions['probe.asked']);
    }

    public function test_a_requested_opt_in_check_that_reports_is_reported(): void
    {
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeTools, $this->optIn('probe.asked', true, Finding::fail('nope')));
        $runner->run(CheckSlot::ProbeTools, new CheckContext);

        $this->assertSame(CheckDisposition::Reported, $runner->inventory()->dispositions['probe.asked']);
    }

    public function test_a_per_agent_check_reporting_for_any_agent_is_reported_for_the_run(): void
    {
        // Id-level granularity is the plan's accepted cost ("Stage 8's inventory keys on
        // the check id"). Reporting for ANY agent is the strongest thing true of the
        // check this run, so a later silent agent must not downgrade it — asserted in the
        // order that would catch the downgrade (report first, silence second).
        $runner = (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, new class implements PerAgentCheck
            {
                private int $calls = 0;

                public function id(): string
                {
                    return 'per.agent';
                }

                public function runFor(AgentConfig $config, CheckContext $ctx): iterable
                {
                    return ++$this->calls === 1 ? [Finding::warn('first agent')] : [];
                }
            });
        $ctx = new CheckContext;
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('a'), $ctx);
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('b'), $ctx);

        $this->assertSame(CheckDisposition::Reported, $runner->inventory()->dispositions['per.agent']);
    }

    public function test_a_silent_first_agent_is_upgraded_by_a_later_reporting_one(): void
    {
        // The same property in the opposite order, because `??=` versus `=` is exactly
        // where this would silently go wrong.
        $runner = (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, new class implements PerAgentCheck
            {
                private int $calls = 0;

                public function id(): string
                {
                    return 'per.agent';
                }

                public function runFor(AgentConfig $config, CheckContext $ctx): iterable
                {
                    return ++$this->calls === 1 ? [] : [Finding::warn('second agent')];
                }
            });
        $ctx = new CheckContext;
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('a'), $ctx);
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('b'), $ctx);

        $this->assertSame(CheckDisposition::Reported, $runner->inventory()->dispositions['per.agent']);
    }

    // ---- the reason channel ----

    public function test_a_noted_reason_explains_a_not_run_check_and_leaves_it_counted(): void
    {
        $runner = (new CheckRunner)
            ->register(CheckSlot::Writeback, $this->check('wb.one'), $this->check('wb.two'));
        $runner->noteNotRun(CheckSlot::Writeback, 'no writeback.json');

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::NotRun, $inventory->dispositions['wb.one']);
        $this->assertSame(CheckDisposition::NotRun, $inventory->dispositions['wb.two']);
        $this->assertSame([], $inventory->unexplainedNotRun());
        // ONE reason for two checks: the operator needs the cause once, not per check.
        $this->assertSame(['no writeback.json'], $inventory->notRunReasons());
    }

    public function test_the_outermost_reason_wins(): void
    {
        // The first envelope to close is the true cause; a narrower one evaluated later
        // must not overwrite it.
        $runner = (new CheckRunner)
            ->register(CheckSlot::WritebackProbe, $this->check('wb.probe'));
        $runner->noteNotRun(CheckSlot::WritebackProbe, 'no writeback.json');
        $runner->noteNotRun(CheckSlot::WritebackProbe, 'no mappings');

        $this->assertSame(['no writeback.json'], $runner->inventory()->notRunReasons());
    }

    public function test_a_reason_noted_for_a_check_that_did_run_is_inert(): void
    {
        // Load-bearing: `CheckCommand` notes the per-agent slots' reasons AFTER the loop,
        // unconditionally, precisely because this is inert. If a stray reason could
        // manufacture a not-run row, that call site would be reporting fiction.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('did.run', Finding::ok('fine')));
        $runner->run(CheckSlot::Install, new CheckContext);
        $runner->noteNotRun(CheckSlot::Install, 'this reason must not appear');

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Reported, $inventory->dispositions['did.run']);
        $this->assertSame([], $inventory->notRunReasons());
        $this->assertSame(0, $inventory->count(CheckDisposition::NotRun));
    }

    public function test_registered_ids_are_reported_in_registration_order_across_both_registries(): void
    {
        // The order the operator-facing inventory and stage 9's json both walk.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('one'))
            ->registerPerAgent(CheckSlot::AgentConfig, $this->perAgent('two', []))
            ->register(CheckSlot::Writeback, $this->check('three'));

        $this->assertSame(['one', 'two', 'three'], $runner->registeredIds());
        $this->assertSame(['one', 'two', 'three'], array_keys($runner->inventory()->dispositions));
    }

    // ---- the declared-silence accounting (card#5596) ----

    public function test_a_silent_check_that_declared_its_silence_is_not_reported_as_undeclared(): void
    {
        // The NEGATIVE arm, written first because it is what makes the positive arm below
        // mean something: a runner that never populated the list at all would pass the
        // positive arm's inverse and every other test in this file.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->declaredSilent('declared.quiet'));
        $runner->run(CheckSlot::Install, new CheckContext);

        $inventory = $runner->inventory();

        // Still SILENT — declaredness rides ALONGSIDE the disposition rather than becoming
        // a fifth one, which is what keeps `--format=json`'s four values and the exhaustive
        // `match` untouched.
        $this->assertSame(CheckDisposition::Silent, $inventory->dispositions['declared.quiet']);
        $this->assertSame([], $inventory->undeclaredSilent());
    }

    public function test_a_silent_check_that_declared_nothing_is_recorded_as_undeclared(): void
    {
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('undeclared.quiet'));
        $runner->run(CheckSlot::Install, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Silent, $inventory->dispositions['undeclared.quiet']);
        $this->assertSame(['undeclared.quiet'], $inventory->undeclaredSilent());
    }

    public function test_a_per_agent_check_undeclared_silent_for_one_agent_is_recorded_even_though_it_reported_for_another(): void
    {
        // THE PROPERTY NO EXISTING TEST SHAPE COVERS, and the reason `undeclaredSilent` is
        // recorded per EXECUTION rather than folded out of `$dispositions` like every other
        // accessor on this class. Reporting for ANY agent is the strongest thing true of a
        // per-agent check, so this run ends `Reported` — and a set derived by filtering
        // `disposition === Silent` (the shape `unexplainedNotRun()` uses) would therefore
        // hide precisely the execution that went unjudged. Recording per execution CLOSES
        // the per-agent granularity bound here instead of disclosing it.
        $runner = (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, $this->perAgentSpeakingOnlyFor('agent.selective', 'loud-agent'));
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('loud-agent'), new CheckContext);
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('quiet-agent'), new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Reported, $inventory->dispositions['agent.selective']);
        $this->assertSame(['agent.selective'], $inventory->undeclaredSilent());
    }

    public function test_an_id_with_several_undeclared_silent_executions_is_listed_once(): void
    {
        // The list names the CHECK, not the executions — it is read as "these ids have a
        // path nobody judged", and the same id repeated per agent would report the size of
        // the roster rather than the size of the defect.
        $runner = (new CheckRunner)
            ->registerPerAgent(CheckSlot::AgentConfig, $this->perAgent('agent.mute', []));
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('a'), new CheckContext);
        $runner->runForAgent(CheckSlot::AgentConfig, $this->agent('b'), new CheckContext);

        $this->assertSame(['agent.mute'], $runner->inventory()->undeclaredSilent());
    }

    public function test_an_unrequested_opt_in_check_owes_no_declaration(): void
    {
        // "The operator did not ask" is ALREADY a declared state, arrived at through the
        // interface built for it. Demanding a second declaration would put `Silence` into
        // the not-requested vocabulary the opt-in decision deliberately kept it out of, and
        // would list every un-probed install's opt-in checks as internal defects.
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeTools, $this->optIn('optin.unasked', requested: false));
        $runner->run(CheckSlot::ProbeTools, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::NotRequested, $inventory->dispositions['optin.unasked']);
        $this->assertSame([], $inventory->undeclaredSilent());
    }

    public function test_a_requested_opt_in_check_that_says_nothing_still_owes_a_declaration(): void
    {
        // The other half of the arm above, and the one that keeps `OptInCheck` from being
        // a blanket exemption: once the operator DID ask, an empty yield is an ordinary
        // silence and carries the ordinary obligation.
        $runner = (new CheckRunner)
            ->register(CheckSlot::ProbeTools, $this->optIn('optin.asked', requested: true));
        $runner->run(CheckSlot::ProbeTools, new CheckContext);

        $inventory = $runner->inventory();

        $this->assertSame(CheckDisposition::Silent, $inventory->dispositions['optin.asked']);
        $this->assertSame(['optin.asked'], $inventory->undeclaredSilent());
    }

    public function test_the_undeclared_list_follows_registration_order(): void
    {
        // Same walk as `dispositions` — the list is rendered inline in one operator-facing
        // sentence, and a set-ordered one would churn that line between runs.
        $runner = (new CheckRunner)
            ->register(CheckSlot::Install, $this->check('first.quiet'), $this->declaredSilent('middle.declared'), $this->check('last.quiet'));
        $runner->run(CheckSlot::Install, new CheckContext);

        $this->assertSame(['first.quiet', 'last.quiet'], $runner->inventory()->undeclaredSilent());
    }

    private function declaredSilent(string $id): Check
    {
        return new class($id) implements Check
        {
            public function __construct(private string $id) {}

            public function id(): string
            {
                return $this->id;
            }

            public function run(CheckContext $ctx): iterable
            {
                yield Silence::because('nothing applicable was configured on this install');
            }
        };
    }

    private function perAgentSpeakingOnlyFor(string $id, string $agentName): PerAgentCheck
    {
        return new class($id, $agentName) implements PerAgentCheck
        {
            public function __construct(private string $id, private string $agentName) {}

            public function id(): string
            {
                return $this->id;
            }

            public function runFor(AgentConfig $config, CheckContext $ctx): iterable
            {
                if ($config->agentName === $this->agentName) {
                    yield Finding::warn("something to say about {$config->agentName}");
                }

                // Deliberately no trailing declaration: this fixture IS the undeclared
                // fall-through the mechanism exists to catch.
            }
        };
    }
}
