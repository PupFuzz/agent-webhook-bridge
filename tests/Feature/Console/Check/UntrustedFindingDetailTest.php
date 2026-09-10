<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\AgentScopeCoverage;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckInventory;
use App\Bridge\Check\CheckJsonRenderer;
use App\Bridge\Check\Checks\ChannelTransportCheck;
use App\Bridge\Check\EventConsumers\EventConsumerReconciliation;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * THE TWO HALVES OF THE UNTRUSTED-DETAIL DECISION, asserted against each other (card#9121,
 * DL-366): the TERMINAL render escapes and caps a span a check declared it did not author,
 * and `--format=json` carries that same span BYTE FOR BYTE.
 *
 * ⭐ WHY BOTH IN ONE CLASS. They are not two properties, they are one decision with a cost
 * on each side, and a suite that asserted them apart would let either drift into the other's
 * file without anything reading the pair. The JSON document is a WRITE CONTRACT that machine
 * consumers already parse, so sanitising at construction would change their bytes with no
 * schema bump to warn them; the terminal is where a control sequence actually does harm. So
 * the escape lives in the renderer, and the raw bytes stay in the document — deliberately,
 * with the consequence written down rather than glossed.
 *
 * ⚠ EVERY ASSERTION HERE IS DRIVEN BY THE REAL CHECK OVER A REAL PLANTED MARKER, never by a
 * hand-built `Finding`. What is under test includes whether the CALL SITE declares its span
 * at all, and a synthetic finding would assert the renderer over a declaration this suite
 * wrote itself.
 */
class UntrustedFindingDetailTest extends TestCase
{
    use MaterializesChecks;

    /** An ANSI erase-display plus a forged second line — what a planted marker would carry. */
    private const PAYLOAD = "\x1b[2J\x1b[1;1Hagent prod-agent: channel socket live";

    private string $dir;

    private string|false $origXdg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/untrusted-detail-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/run');
        $this->origXdg = getenv('XDG_RUNTIME_DIR');
        putenv('XDG_RUNTIME_DIR='.$this->dir.'/run');
    }

    protected function tearDown(): void
    {
        $this->origXdg === false ? putenv('XDG_RUNTIME_DIR') : putenv('XDG_RUNTIME_DIR='.$this->origXdg);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_terminal_render_escapes_and_caps_a_declared_untrusted_span(): void
    {
        $finding = $this->plantedMarkerFinding();
        $buffer = $this->emit($finding);

        // ESCAPED, not stripped — the operator can see that the file held an escape.
        $this->assertStringContainsString('\x1B[2J\x1B[1;1H', $buffer);
        // And no ESC byte survives onto the line. The output object is UNDECORATED (see
        // emit()), so a raw ESC here could only have come from the payload.
        $this->assertStringNotContainsString("\x1b", $buffer);
        // The rest of the sentence — the bridge's own prose — is untouched, so this is
        // measuring the span and not a mangled line.
        $this->assertStringContainsString('channel bind-FAILURE marker at', $buffer);
        $this->assertStringContainsString('rm the marker once resolved.', $buffer);
    }

    public function test_the_same_bytes_pass_through_when_no_call_site_declared_them(): void
    {
        // ⭐ THE DISCRIMINATING CONTROL for the test above. An identical payload inside a
        // finding that declared NOTHING still reaches the terminal raw — so the assertion
        // above is measuring the DECLARATION path and not some property of the buffer, the
        // severity arm, or the output style.
        //
        // ⚠ It is also this change's own honest bound, stated as an executing fact rather
        // than as prose: a future call site that interpolates a foreign string into a plain
        // PROSE segment, rather than wrapping it in `App\Bridge\Support\Untrusted`, gets no
        // protection, and nothing can see that omission. Review of the call site is the
        // guard; this is not it. ⚑ What the positional design DID close is the span that IS
        // declared — it can no longer be missed, straddled or skipped by a renderer's
        // search, because there is no search (DL-366 Decision 8).
        $buffer = $this->emit(Finding::warn('agent prod-agent: '.self::PAYLOAD));

        $this->assertStringContainsString("\x1b[2J", $buffer);
    }

    public function test_a_declared_span_past_the_cap_is_truncated_on_the_terminal_only(): void
    {
        $long = str_repeat('E', 500);
        $finding = $this->plantedMarkerFinding($long);

        // `SOURCE CHARS`, not `CHARS`: the figure is the SPAN's own size and the label says
        // so. Escaping is the identity on this payload, so this leg cannot tell the two
        // figures apart — `UntrustedTextTest` owns the one that can.
        $this->assertStringContainsString('[TRUNCATED, 500 SOURCE CHARS]', $this->emit($finding));
        // The FINDING is untouched, which is what the JSON test below depends on.
        $this->assertStringContainsString("({$long})", $finding->message);
    }

    /**
     * ⛔ THE JSON DOCUMENT MUST BE BYTE-IDENTICAL TO WHAT IT WAS BEFORE THIS CHANGE.
     *
     * Anchored on a LITERAL composition rather than on the finding object, so a sanitiser
     * that crept into `Finding` (or into the JSON renderer) reds here instead of moving both
     * sides of an `assertSame` together. The tail is read from the check's own constant, not
     * re-typed, so this asserts the document and not a third copy of that sentence.
     */
    public function test_the_json_document_carries_the_declared_span_byte_for_byte(): void
    {
        $finding = $this->plantedMarkerFinding();
        $marker = $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED';
        $tail = (new ReflectionClass(ChannelTransportCheck::class))->getConstant('MARKER_TAIL');
        $this->assertIsString($tail);

        $document = (new CheckJsonRenderer)->document(
            true,
            [],
            new CheckInventory([]),
            [$finding],
            new EventConsumerReconciliation,
            new AgentScopeCoverage,
            [],
        );

        $this->assertSame(
            "agent prod-agent: channel bind-FAILURE marker at {$marker} (".self::PAYLOAD.')'.$tail,
            $document['findings_outside_registry'][0]['message'],
        );
        // Spelled out separately, because the composed literal above is easy to read past:
        // the raw control bytes ARE in the document, and that is the contract.
        $this->assertStringContainsString("\x1b[2J", $document['findings_outside_registry'][0]['message']);
        $this->assertStringNotContainsString('\x1B', $document['findings_outside_registry'][0]['message']);
    }

    public function test_the_encoded_json_escapes_the_control_bytes_as_json_and_keeps_the_schema(): void
    {
        $encoded = (new CheckJsonRenderer)->encode(
            true,
            [],
            new CheckInventory([]),
            [$this->plantedMarkerFinding()],
            new EventConsumerReconciliation,
            new AgentScopeCoverage,
            [],
        );

        // JSON's own escaping, which is what makes the document parseable — and is NOT this
        // change's escape: a backslash-u001b sequence decodes back to the ESC byte for any consumer.
        $this->assertStringContainsString('\\u001b[2J', $encoded);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['schema'], 'an added finding shape must not move the schema version');
        $this->assertStringContainsString("\x1b[2J", $decoded['findings_outside_registry'][0]['message']);
    }

    /**
     * THE REFUSAL IS EXIT-CODE-NEUTRAL, asserted at the command, because that is where the
     * exit contract lives — `emitFinding()` returns false ONLY on a `fail`, and a false is
     * the only thing that flips `bridge:check`'s verdict.
     *
     * The finding is the REAL one the check yields with `XDG_RUNTIME_DIR` unset, not a
     * hand-built `unvalidated`: what must not move the exit code is that leg's output, and a
     * synthetic stand-in would pass even if the leg had been written as a `fail`.
     */
    public function test_the_xdg_refusal_does_not_flip_the_exit_code_and_is_tallied(): void
    {
        putenv('XDG_RUNTIME_DIR');
        $findings = $this->httpFindings('http://127.0.0.1:8765/push');
        $refusal = $findings[0];
        $this->assertSame(Severity::Unvalidated, $refusal->severity);

        $command = new CheckCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $emit = new ReflectionMethod(CheckCommand::class, 'emitFinding');

        $this->assertTrue($emit->invoke($command, $refusal), 'the marker refusal must not flip the exit code');

        // Counted into the closing tally, so the run says out loud that a leg could not
        // answer its own question rather than passing silently.
        $tally = new ReflectionProperty(CheckCommand::class, 'unvalidatedCount');
        $this->assertSame(1, $tally->getValue($command));
    }

    // ---- plumbing ----

    /** The real HTTP marker finding, over a real planted marker under a real XDG dir. */
    private function plantedMarkerFinding(?string $payload = null): Finding
    {
        File::put(
            $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED',
            $payload ?? self::PAYLOAD,
        );

        return $this->httpFindings('http://127.0.0.1:8765/push')[0];
    }

    /** @return list<Finding> */
    private function httpFindings(string $url): array
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'channel' => ['url' => $url],
        ]);

        return $this->findingsOfFor(new ChannelTransportCheck($this->deadProbe()), $config, new CheckContext);
    }

    /**
     * UNDECORATED, deliberately: `warn()` on a decorated output emits real ANSI for the
     * yellow, which would make "no ESC byte reaches the line" unassertable. Styling is the
     * severity arm's property and `CheckCommandSeverityContractTest` owns it.
     */
    private function emit(Finding $finding): string
    {
        $buffer = new BufferedOutput;
        $command = new CheckCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        (new ReflectionMethod(CheckCommand::class, 'emitFinding'))->invoke($command, $finding);

        return $buffer->fetch();
    }

    private function deadProbe(): ChannelProbeEnvironment
    {
        return new class implements ChannelProbeEnvironment
        {
            /** @return array{connected: bool, error: string} */
            public function probe(string $dsn): array
            {
                return ['connected' => false, 'error' => ''];
            }
        };
    }
}
