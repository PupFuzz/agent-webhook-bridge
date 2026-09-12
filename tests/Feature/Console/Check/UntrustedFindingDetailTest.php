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
use App\Bridge\Support\UntrustedText;
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
 * BOTH SURFACES OF THE UNTRUSTED-DETAIL DECISION, asserted against each other (card#9121,
 * DL-366, card#9200): the check escapes the foreign detail AT THE INTERPOLATION, so the
 * TERMINAL line and the `--format=json` document carry the SAME escaped bytes.
 *
 * ⛔ THIS CLASS PREVIOUSLY ASSERTED THE OPPOSITE, and the reversal is the point (card#9200).
 * Its earlier thesis was that `findings[].message` is a WRITE CONTRACT machine consumers
 * already parse, so the escape had to live in the terminal renderer and the raw bytes had to
 * stay in the document. ⛔ `docs/check-json-contract.md` §2 falsifies that premise in bold —
 * *"`message` strings are NOT part of the contract"*, reworded before and to be reworded
 * again, with `CheckJsonContractTest` deliberately not pinning them — and its own table lists
 * a reworded `message` as *"not a contract change at all"*. An escape is a rewording.
 * ⭐ THE PREMISE WAS LOAD-BEARING, which is why this file changes rather than just its prose:
 * escaping at a SINK forces every producer to declare which span of its own sentence is
 * foreign, which makes the escape OPT-IN, which makes an omission invisible. That is the
 * defect card#9200 records. Escaping at the interpolation removes the declaration, and the
 * document stops handing a machine consumer an erase-line as a bonus.
 *
 * ⚠ EVERY ASSERTION HERE IS DRIVEN BY THE REAL CHECK OVER A REAL PLANTED MARKER, never by a
 * hand-built `Finding`. What is under test includes whether the CALL SITE escapes at all, and
 * a synthetic finding would assert a property this suite had written in itself.
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

    public function test_the_terminal_line_carries_the_foreign_detail_escaped(): void
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

    public function test_the_same_bytes_pass_through_when_no_producer_escaped_them(): void
    {
        // ⭐ THE DISCRIMINATING CONTROL for the test above. An identical payload interpolated
        // into a finding by a producer that escaped NOTHING still reaches the terminal raw —
        // so the assertion above is measuring the ESCAPE and not some property of the buffer,
        // the severity arm, or the output style. ⛔ `Finding` must never start escaping to
        // make this go away: it holds a sentence the producer already composed, the bridge's
        // own prose runs past `UntrustedText::MAX_CHARS`, and a whole-message pass would
        // truncate sentences this install wrote and vouches for.
        //
        // ⚠ It is also this change's own honest bound, stated as an executing fact rather
        // than as prose: THE INGRESS DOOR IS NOT CLOSED. A new client, `Process` run or
        // foreign-file read that returns a bare `string`, interpolated without a call to
        // `App\Bridge\Support\UntrustedText`, gets no protection, and nothing can see that
        // omission. What the move to the producer buys is a reduction — from every line that
        // prints a foreign value to the handful of classes that READ one — never elimination.
        // Where the value is ALSO matched on and so cannot be escaped at its producer,
        // `App\Bridge\Support\ForeignText` closes it by construction instead, and
        // `ForeignTextTest` owns that half.
        $buffer = $this->emit(Finding::warn('agent prod-agent: '.self::PAYLOAD));

        $this->assertStringContainsString("\x1b[2J", $buffer);
    }

    public function test_a_foreign_detail_past_the_cap_is_truncated_on_both_surfaces(): void
    {
        $long = str_repeat('E', 500);
        $finding = $this->plantedMarkerFinding($long);

        // `SOURCE CHARS`, not `CHARS`: the figure is the SPAN's own size and the label says
        // so. Escaping is the identity on this payload, so this leg cannot tell the two
        // figures apart — `UntrustedTextTest` owns the one that can.
        $this->assertStringContainsString('[TRUNCATED, 500 SOURCE CHARS]', $this->emit($finding));
        // ⛔ AND THE CAP IS ON THE FINDING TOO, which is the half that moved (card#9200): the
        // escape is the producer's, so there is no second, unbounded copy of the detail left
        // for `--format=json` to carry. An operator and a machine consumer read the same
        // 200-character bound, and neither is handed the other 300 characters.
        $this->assertStringNotContainsString("({$long})", $finding->message);
        $this->assertStringContainsString('[TRUNCATED, 500 SOURCE CHARS]', $finding->message);
    }

    /**
     * ⛔ THE JSON DOCUMENT CARRIES THE ESCAPED FORM, AND THE RAW CONTROL BYTES ARE GONE FROM
     * IT — the reversal card#9200 makes, asserted as bytes rather than argued.
     *
     * Anchored on a LITERAL composition rather than on the finding object, so an escape that
     * crept OUT of the producer (or a second one that crept into the JSON renderer) reds here
     * instead of moving both sides of an `assertSame` together. The expected span is DERIVED
     * by calling the escape rather than typed out, because a hand-written `\x1B[2J` in a test
     * is a second implementation of the rule. The tail is read from the check's own constant,
     * so this asserts the document and not a third copy of that sentence.
     *
     * ⚠ `schema` DOES NOT MOVE, and the reason is §2's table, not an absence of change: a
     * reworded `message` is listed there as *"not a contract change at all"*. The adjacent
     * test asserts the version as an executing fact.
     */
    public function test_the_json_document_carries_the_escaped_span_and_not_the_raw_bytes(): void
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
            "agent prod-agent: channel bind-FAILURE marker at {$marker} (".UntrustedText::forOperator(self::PAYLOAD).')'.$tail,
            $document['findings_outside_registry'][0]['message'],
        );
        // Spelled out separately, because the composed literal above is easy to read past:
        // NO raw control byte is in the document any more, and the escaped form IS.
        $this->assertStringNotContainsString("\x1b", $document['findings_outside_registry'][0]['message']);
        $this->assertStringContainsString('\x1B[2J', $document['findings_outside_registry'][0]['message']);
    }

    public function test_the_encoded_json_has_no_control_byte_left_to_escape_and_keeps_the_schema(): void
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

        // ⛔ JSON'S OWN `\u001b` ESCAPE IS THE ONE THIS FILE USED TO ASSERT WAS PRESENT, and
        // it was never protection: a `\u001b` sequence decodes straight back to the ESC byte
        // for any consumer that parses the document and prints the string. There is now no
        // control byte left in the message for JSON to encode, so it is absent — which is the
        // difference between a document that is parseable and one that is safe to print.
        $this->assertStringNotContainsString('\\u001b', $encoded);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        // ⚠ `schema` STAYS 1 on §2's reworded-`message` row, which that table calls "not a
        // contract change at all" — not because nothing changed. No key is added or removed,
        // no type moves, and no severity is invented.
        $this->assertSame(1, $decoded['schema'], 'a reworded message must not move the schema version');
        $this->assertStringNotContainsString("\x1b", $decoded['findings_outside_registry'][0]['message']);
        $this->assertStringContainsString('\x1B[2J', $decoded['findings_outside_registry'][0]['message']);
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
