<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackSourceCoverageCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\AssertsNoLiveControlByte;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * ⛔ A KANBAN CARD'S OWN FIELDS ARE FOREIGN TEXT, and `writeback.source_coverage` put three
 * of them on root's terminal raw (card#9121, DL-366 Decision 12).
 *
 * ⭐ WHY THIS IS A DIFFERENT PRODUCER FROM EVERY ONE DECISION 7 AND DECISION 11 FOUND, and
 * why both of those censuses were structurally unable to see it. Decision 7 swept the
 * producers of foreign text that arrives as a FILE or a PROBE RESULT; Decision 11 swept the
 * producers that relay an EXCEPTION MESSAGE. This arm is neither: it is the SUCCESS path of
 * a kanban read, and the bytes are ordinary row content —
 * {@see WritebackSourceCoverageCheck} interpolates a card's `id`, its `payload.dl_number`
 * and the `source` derived from its `payload.repo` into a plain-string finding, four lines
 * below a `catch` arm in the same method that DOES declare the same response body foreign.
 *
 * ⛔ THE ATTACKER IS ANYONE WHO CAN CREATE A CARD ON A MAPPED BOARD, and the leg is on by
 * default: `correlation=ref` is the default mode and this check runs on every `bridge:check`
 * of a `ref` install with a mapping.
 *
 * ⚠ `ExternalReferenceNormalizer::canonicalizeSource()` IS NOT A SHAPE REDUCTION and does
 * not bring these under Decision 7's ingest-reduction exemption — it trims, lower-cases and
 * cuts to 255 characters, and reduces NO byte class: every codepoint this escape exists to
 * stop survives all three operations. That is recorded here rather than left to be
 * re-derived, because the exemption is exactly what a future reader would reach for.
 *
 * THE ASSERTION IS THE SHARED CENSUS IN `Tests\Support\AssertsNoLiveControlByte`, driven
 * end to end through the REAL check, the real `CheckRunner` and the real
 * `CheckCommand::emitFinding()`, never through a message the test composed itself.
 */
class ForeignCardFieldRenderTest extends TestCase
{
    use AssertsNoLiveControlByte;
    use MaterializesChecks;

    private const BOARD = 8;

    /** Erase-line then carriage return: the shape that overwrites the line already printed. */
    private const PAYLOAD = "\x1b[2K\rWRITEBACK: all clear";

    protected function setUp(): void
    {
        parent::setUp();
        config(['bridge.writeback.correlation' => 'ref']);
    }

    /**
     * THE `source=null` ARM — a DL card on a SHARED board with nothing to derive a source
     * from. Its `id` and its `dl_number` are both attacker-chosen and both land on the line.
     */
    public function test_a_card_id_and_dl_number_reach_the_terminal_escaped(): void
    {
        $findings = $this->findingsForCards([
            ['id' => '7'.self::PAYLOAD, 'payload' => ['dl_number' => '42'.self::PAYLOAD], 'external_link' => null],
        ]);

        $this->assertCount(1, $findings);
        $rendered = $this->render($findings);

        // The fixture actually reached the arm, and the bridge's own prose is INTACT.
        $this->assertStringContainsString('on SHARED board 8 has dl_number but source=null', $rendered);
        $this->assertNoLiveControlByte($rendered);
        // PRESENCE WITNESS ×2 — absence alone is satisfied by a renderer that dropped both spans.
        $this->assertSame(2, substr_count($rendered, '\x1B[2K'), 'both spans must survive in ESCAPED form');
        // ⛔ AND `--format=json` NO LONGER CARRIES THE RAW BYTES EITHER, which is the half
        // this change reverses on purpose (card#9200). The earlier cut escaped at the
        // TERMINAL renderer to keep `Finding::$message` byte-stable, justified by a write
        // contract that `docs/check-json-contract.md` §2 denies exists — *"`message` strings
        // are NOT part of the contract"* — and that false premise is what forced the escape
        // to be declared per call site. Escaping at the producer means a machine consumer
        // rendering a `message` to its own terminal no longer receives an erase-line, which
        // is a strict improvement and a rewording §2's table licenses outright.
        $this->assertStringNotContainsString("\x1b[2K\r", $findings[0]->message);
        // ⚑ THE NON-VACUITY WITNESS MOVES TO THE FIXTURE: the message can no longer hold the
        // raw bytes, so their presence there is not available as proof the payload was planted.
        $this->assertStringContainsString("\x1b[2K\r", self::PAYLOAD);
    }

    /**
     * THE UNMAPPED-SOURCE ARM — `payload.repo` is the attacker's, and `canonicalizeSource()`
     * passes every byte of it through. This is the arm reproduced in review.
     */
    public function test_a_derived_source_reaches_the_terminal_escaped(): void
    {
        $findings = $this->findingsForCards([
            ['id' => 9, 'payload' => ['dl_number' => '43', 'repo' => 'evil/'.self::PAYLOAD], 'external_link' => null],
        ]);

        $this->assertCount(1, $findings);
        $rendered = $this->render($findings);

        $this->assertStringContainsString('matches no repo mapped to that board (owner/repo, owner/second)', $rendered);
        $this->assertNoLiveControlByte($rendered);
        $this->assertStringContainsString('has source=evil/\x1B[2k writeback: all clear,', $rendered);
        // The two NON-foreign values on the same line stay prose and stay UNESCAPED: the
        // board id and the mapped-repo list are this install's own `writeback.json`, and
        // escaping them would say this install does not vouch for what this install wrote.
        $this->assertStringContainsString('board 8', $rendered);
        $this->assertStringContainsString('(owner/repo, owner/second)', $rendered);
        // ⚑ `canonicalizeSource()` REDUCED NO BYTE CLASS, which is the claim this arm rests
        // on — it lower-cased and passed every codepoint through. Witnessed on the FIXTURE's
        // own lower-cased form reaching the line in ESCAPED shape, since the raw bytes no
        // longer survive into `$message` for the assertion to read there.
        $this->assertForeignValueEscapedInto($findings[0]->message, 'evil/'.mb_strtolower(self::PAYLOAD));
    }

    /**
     * ⚑ THE CONTROL. Ordinary card data must render EXACTLY as it did before any of this —
     * an escape that moved the bytes of a healthy install's report would be a regression
     * dressed as a fix, and the census above is equally satisfied by a renderer that mangles
     * every line.
     */
    public function test_ordinary_card_fields_render_unchanged(): void
    {
        $findings = $this->findingsForCards([
            ['id' => 11, 'payload' => ['dl_number' => '44', 'repo' => 'someone/elsewhere'], 'external_link' => null],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(
            $findings[0]->message,
            trim($this->render($findings)),
            'a finding with no hostile byte in it must render to its own message, verbatim',
        );
    }

    // ---- plumbing ----

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<Finding>
     */
    private function findingsForCards(array $cards): array
    {
        Http::fake(fn (Request $request) => str_contains(urldecode($request->url()), 'board_id='.self::BOARD)
            ? Http::response(['data' => $cards, 'links' => ['next' => null]])
            : Http::response(['data' => [], 'links' => ['next' => null]]));

        $ctx = new CheckContext;
        // TWO repos on ONE board — `boardIsShared` reports true, which is what opens the
        // `source=null` arm.
        $ctx->writeback = new WritebackConfig(7, [
            'owner/repo' => new WritebackMapping(boardId: self::BOARD, stages: []),
            'owner/second' => new WritebackMapping(boardId: self::BOARD, stages: []),
        ]);
        $ctx->client = new KanbanClient('https://kanban.test', 'wb-token');

        return array_values(array_filter(
            $this->findingsOf(new WritebackSourceCoverageCheck, $ctx),
            fn (Finding $f) => ! str_contains($f->message, 'all have a mapped source'),
        ));
    }

    /**
     * The findings as an operator sees them — through the real command's one rendering
     * boundary, never through `UntrustedText` directly.
     *
     * @param  list<Finding>  $findings
     */
    private function render(array $findings): string
    {
        $buffer = new BufferedOutput;
        $command = new CheckCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $emit = new ReflectionMethod(CheckCommand::class, 'emitFinding');

        foreach ($findings as $finding) {
            $emit->invoke($command, $finding);
        }

        return $buffer->fetch();
    }
}
