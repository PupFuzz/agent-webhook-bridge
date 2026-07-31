<?php

namespace Tests\Unit\Console;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Checks\EventFollowsConsumerCheck;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\EventConsumers\EventConsumerReconciliation;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\SshTransportProbe;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Support\FindingFactories;

/**
 * The severity→exit contract of `bridge:check`'s finding renderer (card 5170).
 *
 * `emitFinding` is private and reached by reflection deliberately: what is under
 * test IS its internal severity→exit mapping, and asserting it through an observed
 * process exit code would only pin the severities that happen to be emitted today —
 * exactly the property that broke when a fourth severity was added.
 */
class CheckCommandSeverityContractTest extends TestCase
{
    use FindingFactories;

    private CheckCommand $command;

    private BufferedOutput $buffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CheckCommand;
        // Decorated: the rendering assertions below distinguish a styled (green
        // `info` / yellow `warn`) line from a plain one, which is only observable
        // when styling is emitted.
        $this->buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
    }

    /**
     * The WHOLE vocabulary, derived from the enum rather than listed (card 5178).
     * Before `Severity` existed this provider carried a hand-written
     * `'some-future-severity'` row standing in for "a severity added later"; that
     * value is now unrepresentable, so the row is REPLACED, not dropped — iterating
     * `Severity::cases()` covers every future case automatically, which is strictly
     * more than the one hypothetical string it could pin before.
     *
     * @return iterable<string, array{Severity, bool}>
     */
    public static function severities(): iterable
    {
        foreach (Severity::cases() as $case) {
            yield $case->value => [$case, $case !== Severity::Fail];
        }
    }

    #[DataProvider('severities')]
    public function test_emit_finding_returns_false_if_and_only_if_the_severity_is_fail(Severity $severity, bool $expected): void
    {
        // This is what preserves bridge:check's exit codes BY CONSTRUCTION across
        // any widening of the vocabulary: only the `fail` arm flips the caller's $ok.
        // A fifth case mapped to `false` in emitFinding's return match REDs here.
        $this->assertSame($expected, $this->emit($severity, 'a message'));
    }

    /**
     * The exhaustiveness itself, asserted as an executing property rather than left
     * to phpstan alone: every case the enum declares must reach a handled arm. A
     * case added to `Severity` without updating either `match` in `emitFinding`
     * raises `\UnhandledMatchError` here — the guard that survives even if the
     * renderer ever leaves the analysed paths.
     */
    public function test_every_declared_severity_is_handled_by_the_renderer(): void
    {
        foreach (Severity::cases() as $case) {
            $this->emit($case, "rendered {$case->value}");
            // Handled AND emitted: an arm that swallowed the finding would leave
            // nothing on the buffer, which no exhaustiveness check would catch.
            $this->assertStringContainsString("rendered {$case->value}", $this->buffer->fetch());
        }
    }

    public function test_an_unvalidated_finding_renders_plain_never_as_certified_green(): void
    {
        // Green reads as "validated clean" — the exact misreading card 5170 fixes.
        // Yellow was rejected too: an install told by docs/multi-host.md to leave
        // channel.server_path unset would be nagged forever with no way to silence it.
        $this->emit(Severity::Unvalidated, 'channel.server_path not declared — snapshot not validated');
        $plain = $this->buffer->fetch();

        $this->assertStringContainsString('snapshot not validated', $plain);
        $this->assertStringNotContainsString("\033[", $plain);

        // Positive control: the same renderer DOES style an `ok`, so the assertion
        // above is measuring the severity arm and not an un-styled output object.
        $this->emit(Severity::Ok, 'channel snapshot holds every file');
        $this->assertStringContainsString("\033[", $this->buffer->fetch());
    }

    public function test_only_an_unvalidated_finding_is_tallied(): void
    {
        // Every case EXCEPT the tallied one, derived from the enum so a severity
        // added later is asserted not to be tallied without editing this list.
        foreach (array_filter(Severity::cases(), fn (Severity $s) => $s !== Severity::Unvalidated) as $severity) {
            $this->emit($severity, 'a message');
        }
        $this->assertSame(0, $this->tally());

        $this->emit(Severity::Unvalidated, 'a message');
        $this->emit(Severity::Unvalidated, 'another message');
        $this->assertSame(2, $this->tally());
    }

    public function test_a_skipped_event_consumer_advisory_is_unvalidated_not_green(): void
    {
        // The DL-196 advisory's fail-soft catch reported a check that DID NOT RUN
        // with info() — green, exit 0, invisible to the tally: this card's own
        // defect shape, a second time, in the same command.
        //
        // Driven through the REGISTERED check and the report renderer since DL-242
        // stage 7a, rather than by reflecting on the advisory method it replaced. That
        // keeps the property end-to-end: the check yields the finding, `emitReport`
        // renders it, and the tally counts it — a chain the check's own unit test
        // (which asserts the yielded Finding) cannot close on its own.
        //
        // THE FAILURE IS NOW HANDED IN AS A VALUE (DL-249 stage 9), where it used to be
        // a SYNTHETIC THROW out of the check's own query (no app booted ⇒ the
        // WebhookEvent read died on a null connection resolver). The derivation moved to
        // `EventConsumerReconciler`, so the check no longer queries and that throw can no
        // longer happen here — it would leave this test asserting an advisory the check
        // had no way to emit. The property is unchanged and so is every assertion below;
        // only the way the skipped state is reached moved with the code. The catch arm
        // itself is asserted at its new address, with a discriminating control, by
        // `EventConsumerReconcilerTest` — so nothing is left proven only by inference.
        $ctx = new CheckContext;
        $ctx->githubScopeConsumers = ['5' => [
            ['agent' => 'prod-agent', 'class' => 'X', 'consumed' => ['push'], 'declared' => true],
        ]];
        $ctx->eventConsumers = new EventConsumerReconciliation(error: 'db hiccup');
        $report = (new CheckRunner)
            ->register(CheckSlot::EventConsumer, new EventFollowsConsumerCheck)
            ->run(CheckSlot::EventConsumer, $ctx);
        $emit = new ReflectionMethod($this->command, 'emitReport');

        // `unvalidated` never flips the exit — asserted here, at the command, because
        // that is where the exit contract lives.
        $this->assertTrue($emit->invoke($this->command, $report));

        $out = $this->buffer->fetch();
        $this->assertStringContainsString('event-consumer: check skipped', $out);
        $this->assertStringNotContainsString("\033[", $out);
        $this->assertSame(1, $this->tally());
    }

    /**
     * The DL-225 ssh advisory's incomplete-setup predicate. POSITIVE membership, driven off
     * `Severity::cases()` (card 5178) so a case added later is asserted here too.
     *
     * ⚠ THIS ASSERTS THE MAP, AND THE MAP IS NOT THE HAZARD. DL-238(g) recorded the
     * canon-#3 watch-item that the `!== 'ok'` proxy this replaced would sweep in any
     * severity added later. DL-251 moves `unvalidated` to `true` — which LOOKS like the
     * proxy's behaviour and is not the same thing: it is a decision made about the two
     * findings this predicate's only caller can actually see (`SshPinnedLineCheck`'s), both
     * of which the sweep re-assigned from `warn`. What is NOT fixed is that
     * `unvalidated` now carries two opposite meanings on this path — "could not read
     * authorized_keys" (setup IS incomplete) and, for any future structurally-unmeasurable
     * finding, the opposite — with the SAME enum value. No severity assertion can separate
     * them, so the guard below is a TRIPWIRE for the emitted set MOVING, not a proof that
     * the map is right for whatever moved into it. DL-238(g) is NARROWED, not closed; the
     * root-cause option (have `probePinnedLine()` report the fact rather than have the
     * advisory infer it from a severity) is named in DL-251 and is out of stage-10 scope.
     */
    public function test_the_incomplete_setup_predicate_excludes_only_ok(): void
    {
        $incomplete = new ReflectionMethod(CheckCommand::class, 'severityMeansSetupIncomplete');

        foreach (Severity::cases() as $case) {
            $this->assertSame(
                $case !== Severity::Ok,
                $incomplete->invoke(null, $case),
                "severityMeansSetupIncomplete({$case->value})",
            );
        }
    }

    /**
     * THE TRIPWIRE the docblock above describes, and the reason the predicate's widening is
     * not simply the rejected `!== 'ok'` proxy wearing a `match`: it pins the severity set
     * `SshPinnedLineCheck` can actually EMIT, so a leg added to that check — or an existing
     * one re-assigned again — reds here and forces someone to decide whether the DL-225
     * advisory should fire for it.
     *
     * WHAT IT DOES NOT DO, stated because a guard that is trusted for more than it does is
     * worse than none: it cannot tell anyone the RIGHT severity for a new leg, it says
     * nothing about whether the advisory's reading of `unvalidated` is correct, and it
     * constrains no other check.
     */
    public function test_the_pinned_line_leg_emits_only_the_severities_the_advisory_was_decided_for(): void
    {
        // Derived from the METHOD'S SOURCE, not from a fixture corpus: a behavioural union
        // over the shapes some test happens to construct is blind to an arm no shape
        // reaches, which is the failure mode this whole program exists to remove. Every
        // `Finding::` construction in `probePinnedLine()` is inline in it, so the slice is
        // the complete set.
        $method = new ReflectionMethod(SshTransportProbe::class, 'probePinnedLine');
        $file = $method->getFileName();
        $this->assertIsString($file);
        $lines = array_slice(
            (array) file($file),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );

        preg_match_all('/Finding::(ok|warn|unvalidated|fail)\(/', implode('', $lines), $m);
        $emitted = array_values(array_unique($m[1]));
        sort($emitted);

        // Non-vacuous: an empty match would satisfy any assertSame against an empty list.
        $this->assertNotEmpty($emitted, 'the source scan found no Finding factory calls — it has broken');
        $this->assertSame(
            ['fail', 'ok', 'unvalidated'],
            $emitted,
            'the severity set `SshPinnedLineCheck` can emit has MOVED — decide, per severity, whether the DL-225 flipped-default advisory should treat it as an incomplete ssh setup, then update this pin and `severityMeansSetupIncomplete()` together',
        );
    }

    private function emit(Severity $severity, string $message): bool
    {
        $emit = new ReflectionMethod($this->command, 'emitFinding');

        // One argument since DL-242 stage 1: the line prefix used to be the caller's
        // and is now folded into the message by the Check that yields it. The property
        // under test is unchanged — which severities flip the exit — so the prefix is
        // kept here, in the message, rather than dropped.
        /** @var bool $result */
        $result = $emit->invoke($this->command, self::findingOf($severity, 'agent x: '.$message));

        return $result;
    }

    private function tally(): int
    {
        /** @var int $count */
        $count = (new ReflectionProperty(CheckCommand::class, 'unvalidatedCount'))->getValue($this->command);

        return $count;
    }
}
