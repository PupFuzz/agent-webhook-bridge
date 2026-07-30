<?php

namespace Tests\Unit\Console;

use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckInventory;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * WHICH CHANNEL each of `bridge:check`'s render decisions dispatches to (DL-248).
 *
 * WHY THE GOLDEN CORPUS CANNOT ANSWER THIS, and why believing it could was the defect this
 * file closes. `GoldenCapture` reads `Artisan::output()`, an UNDECORATED `BufferedOutput`:
 * the formatter strips `<warning>`/`<error>`/`<info>` to bare text, so `line()`, `warn()`,
 * `error()` and `info()` write BYTE-IDENTICAL output there. All 33 fixtures are therefore
 * blind to every channel choice, not merely to the rare ones — replacing the inventory
 * head's `$this->line(...)` with `$this->warn(...)` leaves all 33 goldens unchanged while
 * rendering a yellow warning across every healthy operator run. An earlier revision of this
 * stage recorded the residual as *"the `line` channel is exercised by all 33 goldens; the
 * `warn` channel by none"*, which was narrower than the truth in the direction that matters:
 * NEITHER is exercised.
 *
 * ONE FILE FOR BOTH DISPATCH SITES, because they are one shape and not two. The
 * severity→channel map in `emitFinding()` sits behind the same undecorated capture as the
 * inventory's `[channel, message]` dispatch loop, so a per-site fix would have left a
 * sibling of the just-closed gap one method away. The instrument is a DECORATED
 * `BufferedOutput`, where each channel carries its own ANSI attribute and a swap is visible.
 *
 * WHAT IS ASSERTED IS THE ATTRIBUTE, NOT THE COLOUR NAME. The tests below pin that the
 * channels are DISTINCT and that the plain ones carry no escape at all — a scheme change
 * upstream would move a colour, but a channel collapsing into another is what this file
 * exists to red.
 *
 * The methods under test are private; reflection is deliberate. Reaching them through a
 * command run puts the undecorated capture back in the path, which is the blindness itself.
 */
class CheckOutputChannelTest extends TestCase
{
    /** Symfony's `<info>`, `<warning>` and `<error>` attributes under a decorated formatter. */
    private const GREEN = "\033[32m";

    private const YELLOW = "\033[33m";

    private const ON_RED = "\033[37;41m";

    /**
     * A command whose output is a DECORATED buffer, plus the buffer to read back.
     *
     * @return array{0: CheckCommand, 1: BufferedOutput}
     */
    private function decorated(): array
    {
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $command = new CheckCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return [$command, $buffer];
    }

    /**
     * The lines `emitInventory()` writes for this inventory, as rendered.
     *
     * @return list<string>
     */
    private function emitted(CheckInventory $inventory): array
    {
        [$command, $buffer] = $this->decorated();
        (new ReflectionMethod(CheckCommand::class, 'emitInventory'))->invoke($command, $inventory);

        return explode("\n", rtrim($buffer->fetch(), "\n"));
    }

    /** The rendering of one finding, and what `emitFinding()` returned for it. */
    private function rendered(Severity $severity): string
    {
        [$command, $buffer] = $this->decorated();
        (new ReflectionMethod(CheckCommand::class, 'emitFinding'))
            ->invoke($command, new Finding($severity, 'the message'));

        return rtrim($buffer->fetch(), "\n");
    }

    /**
     * @param  array<string, string>  $reasons
     */
    private function inventory(int $reported, int $notRun, array $reasons = []): CheckInventory
    {
        $dispositions = [];
        for ($i = 0; $i < $reported; $i++) {
            $dispositions["reported-{$i}"] = CheckDisposition::Reported;
        }
        for ($i = 0; $i < $notRun; $i++) {
            $dispositions["not-run-{$i}"] = CheckDisposition::NotRun;
        }

        return new CheckInventory($dispositions, $reasons);
    }

    // ---- the inventory's dispatch loop ----

    public function test_the_inventory_head_reaches_the_operator_undecorated(): void
    {
        // The arm no golden file can see. The head prints on every run, so a `line` that
        // became a `warn` would paint a yellow "something is wrong" across every healthy
        // install — and the corpus would stay byte-identical through the change.
        $lines = $this->emitted($this->inventory(reported: 3, notRun: 0));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('checks: 3 registered', $lines[0]);
        $this->assertStringNotContainsString("\033[", $lines[0], 'the head is plain — it reports, it does not alarm');
    }

    public function test_the_internal_defect_disclosure_reaches_the_operator_as_a_warning(): void
    {
        // The other arm, and the one no INSTALL can reach: every conditional slot in
        // `handle()` records a not-run reason by design, so `unexplainedNotRun()` is empty
        // on every real run and this dispatch is unreachable outside a unit. It is the one
        // line of the inventory the operator is asked to act on, so it must not arrive
        // looking like the head.
        $lines = $this->emitted($this->inventory(reported: 1, notRun: 1));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('bridge:check internal:', $lines[1]);
        $this->assertStringStartsWith(self::YELLOW, $lines[1]);
    }

    public function test_the_two_inventory_channels_are_distinguishable_from_each_other(): void
    {
        // The property the two tests above rest on, asserted rather than assumed: if the
        // channels ever rendered alike, both would still pass against a dispatch loop that
        // had collapsed to a single arm.
        $lines = $this->emitted($this->inventory(reported: 1, notRun: 1));

        $this->assertNotSame(
            $this->attributeOf($lines[0]),
            $this->attributeOf($lines[1]),
            'a head and a disclosure that render alike make this whole file a decoration',
        );
    }

    // ---- the severity → channel map ----

    public function test_each_severity_renders_on_its_own_channel(): void
    {
        // THE SIBLING GAP, closed with the same instrument. `emitFinding()`'s RETURN arm is
        // witnessed by exit codes and its `unvalidated` arm by the tally line two fixtures
        // render — but `fail`, `warn` and `ok` differ only in ANSI attributes the corpus
        // discards, so before this test `Severity::Fail => $this->info(...)` printed every
        // failure in green with the full suite green behind it.
        $this->assertStringStartsWith(self::ON_RED, $this->rendered(Severity::Fail));
        $this->assertStringStartsWith(self::YELLOW, $this->rendered(Severity::Warn));
        $this->assertStringStartsWith(self::GREEN, $this->rendered(Severity::Ok));
    }

    public function test_unvalidated_renders_plain_because_neither_green_nor_yellow_is_honest(): void
    {
        // Card 5170's decision, pinned where it can red: green would read as certified by a
        // check that never ran, and yellow would nag a documented-correct population (a
        // multi-host install is TOLD to leave `channel.server_path` unset) with no action
        // available to silence it.
        $rendered = $this->rendered(Severity::Unvalidated);

        $this->assertSame('the message', $rendered);
        $this->assertStringNotContainsString("\033[", $rendered);
    }

    /** The leading ANSI attribute of a rendered line, or `''` when it carries none. */
    private function attributeOf(string $line): string
    {
        return preg_match("/^\033\[[0-9;]*m/", $line, $m) === 1 ? $m[0] : '';
    }
}
