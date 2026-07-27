<?php

namespace Tests\Unit\Console;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

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
        // The throw is SYNTHETIC (no app is booted here, so the WebhookEvent query
        // dies on a null connection resolver) and deliberately so — what is under
        // test is the catch arm's rendering and counting, and the catch is
        // `Throwable`, so the DB hiccup it exists for reaches the identical arm.
        // Forcing a real DB failure would mean DDL inside RefreshDatabase, which
        // passes on SQLite and reds the MariaDB matrix.
        $advisory = new ReflectionMethod($this->command, 'checkEventFollowsConsumer');
        $advisory->invoke($this->command, ['5' => [
            ['agent' => 'prod-agent', 'class' => 'X', 'consumed' => ['push'], 'declared' => true],
        ]]);

        $out = $this->buffer->fetch();
        $this->assertStringContainsString('event-consumer: check skipped', $out);
        $this->assertStringNotContainsString("\033[", $out);
        $this->assertSame(1, $this->tally());
    }

    /**
     * The DL-225 ssh advisory's incomplete-setup predicate. POSITIVE membership:
     * the `!== 'ok'` proxy it replaced would sweep every severity added later, so
     * an agent would be told its ssh setup is incomplete on the strength of a check
     * nobody ran. Driven off `Severity::cases()` (card 5178) so a case added later
     * is asserted here too — the hand-written `'some-future-severity'` row this
     * replaces could only ever stand in for ONE such value.
     */
    public function test_only_warn_and_fail_mean_the_ssh_setup_is_incomplete(): void
    {
        $incomplete = new ReflectionMethod(CheckCommand::class, 'severityMeansSetupIncomplete');

        foreach (Severity::cases() as $case) {
            $this->assertSame(
                in_array($case, [Severity::Warn, Severity::Fail], true),
                $incomplete->invoke(null, $case),
                "severityMeansSetupIncomplete({$case->value})",
            );
        }
    }

    private function emit(Severity $severity, string $message): bool
    {
        $emit = new ReflectionMethod($this->command, 'emitFinding');

        /** @var bool $result */
        $result = $emit->invoke($this->command, 'agent x: ', new Finding($severity, $message));

        return $result;
    }

    private function tally(): int
    {
        /** @var int $count */
        $count = (new ReflectionProperty(CheckCommand::class, 'unvalidatedCount'))->getValue($this->command);

        return $count;
    }
}
