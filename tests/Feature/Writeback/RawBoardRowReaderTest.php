<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\KanbanClient;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;

/**
 * ⛔ WHO READS A RAW KANBAN BOARD ROW, and what each reader does with the VALUES in it
 * (card#9121, DL-366 Decision 12, round 5).
 *
 * ⭐ WHY THIS PIN EXISTS AND WHAT IT REPLACES. Three review rounds running, the fix for a
 * false coverage claim wrote a NEW coverage claim — the last one in prose, per ARM
 * (*"past this branch the value has been matched, so the later lines print a value from a
 * closed set"*), which was true of one of the five values that branch returns and FALSE of
 * another from the same parse of the same foreign field. Prose that certifies a REGION is
 * the defect; nothing can check it and the next reader audits against it. So the region
 * sentences are deleted and this is what stands in their place: a DERIVED population with a
 * per-reader ruling stated per VALUE, which reds when the population moves.
 *
 * ⚠ **THE POPULATION IS DERIVED HERE, NOT INHERITED.** The round-4 review asserted that a
 * raw board row has *"exactly two reader sites in `app/`"*. It has FOUR — the two named plus
 * `StandupService` and `KanbanPromoteReleasedHandler` — which is why the derivation is a
 * scan and the number appears in no sentence anywhere.
 *
 * WHAT IT COUNTS: calls to `->readBoardCards(` per file under `app/`, over comment-stripped
 * source. That is ONE door — the whole-board raw read — and the rows it hands back are
 * whatever kanban returned, so a string in one may have been chosen by whoever wrote the card.
 *
 * ⛔ WHAT IT DOES NOT DO. These are bounds, not caveats: the last three review rounds on this
 * class were all lost to a sentence that read wider than what stood behind it.
 *  - **It does not claim a reader is safe.** The ruling is prose, per VALUE, per reader, and
 *    a ruling naming a value is a claim about that value and about nothing beside it.
 *  - ⚠ **IT IS ONE DOOR, NOT THE RAW-ROW SURFACE.** `KanbanClient` has other PUBLIC methods
 *    that also return raw card rows, and NONE of them is in this population or ruled on
 *    anywhere: that wider door set is recorded on card#9251 as an open, unruled question.
 *    Do not read this pin as "raw rows are accounted for".
 *  - **A row value that leaves for a LOG or the divergence ledger is named in the ruling and
 *    is not made safe here.** Those are different sinks; DL-366's escape is shaped for a
 *    terminal line and is an encoder for nothing else.
 *  - **It is lexical.** A dynamic call would be counted nowhere.
 */
class RawBoardRowReaderTest extends TestCase
{
    /**
     * Every `app/` file that reads raw board rows, with its call count and a ruling stating,
     * PER VALUE, what leaves the row and where it goes.
     *
     * @var array<string, array{reads: int, ruling: string}>
     */
    private const READERS = [
        'app/Bridge/Check/Checks/WritebackSourceCoverageCheck.php' => [
            'reads' => 1,
            'ruling' => 'TERMINAL sink (a `Finding`). Three values reach the operator and all three are '
                .'DECLARED as `Untrusted` spans: `id`, `payload.dl_number`, and the `source` derived from '
                .'`payload.repo`/`external_link`. `external_link` is read and feeds that `source`; it lands '
                .'on no line of its own. Pinned end to end by `ForeignCardFieldRenderTest`.',
        ],
        'app/Console/Commands/Bridge/ReconcileCommand.php' => [
            'reads' => 1,
            'ruling' => 'TERMINAL sink (direct console writes, so this command IS the renderer). Four values '
                .'reach a line and each is escaped at its write: the `pr_url` REPO on the out-of-scope arm, '
                .'the raw `pr_url` at both writes of `evidence`, and `dl_number` on the `DlOnly` arm. `id` and '
                .'`pr_number` reach a line as `int`; `workflow_stage_id` is compared, never printed. Pinned by '
                .'`ReconcileCommandTest`. ⚠ ONE MORE VALUE LEAVES THE ROW AND IS NOT MADE SAFE HERE: '
                .'`board_id`, taken RAW by `MappedBoardGuard::boardContext()`, which reaches a `Log::info` '
                .'context and, on a divergence, a `writeback_board_divergences` row. Its sink is not a '
                .'terminal — but `bridge:stats` later PRINTS that stored column, so the question is real and '
                .'is recorded unruled on card#9251 rather than answered here.',
        ],
        'app/Bridge/Handlers/KanbanPromoteReleasedHandler.php' => [
            'reads' => 1,
            'ruling' => 'LOG / alert-channel sink, never a terminal. `id` is `(int)`, the PR reference '
                .'resolves to an `int` through `TrackedCardRef`, `workflow_stage_id` is compared and '
                .'`PinGuard::isPinned()` returns a `bool`. ⚠ `board_id` leaves RAW through '
                .'`MappedBoardGuard::boardContext()` into a log context and the divergence ledger — the same '
                .'value and the same open question as the reconcile entry above (card#9251).',
        ],
        'app/Bridge/Standup/StandupService.php' => [
            'reads' => 1,
            'ruling' => 'DIGEST sink. Exactly one value is read from a row — `workflow_stage_id`, through '
                .'`is_numeric` into an `(int)` compare — and what leaves is a COUNT. No row string exists to '
                .'escape. (The digest\'s own encode ruling is DL-366 Decision 11 and is a different question.)',
        ],
    ];

    public function test_the_raw_board_row_readers_are_exactly_these(): void
    {
        $found = $this->readersUnderApp();

        // Non-vacuous: a broken scan returns nothing, and empty-vs-empty would green through
        // a sweep that deleted every reader.
        $this->assertNotEmpty($found, 'the scan found no readers at all — the scan is broken, not the code');

        $expected = [];
        foreach (self::READERS as $file => $row) {
            $expected[$file] = $row['reads'];
        }
        ksort($expected);
        ksort($found);

        $this->assertSame(
            $expected,
            $found,
            "the set of RAW BOARD ROW readers has MOVED.\n".
            "Every string in one of those rows was chosen by whoever wrote the card, so a NEW reader owes a ruling — per VALUE — naming what leaves the row and which sink it reaches.\n".
            'Add it to READERS in the same commit; a removed reader is fine, drop its entry.',
        );
    }

    /**
     * ⚑ THE WHOLE-BOARD READ'S INTERNAL TWIN IS SHUT BY THE LANGUAGE, not by this pin's prose.
     *
     * `KanbanClient::correlationCards()` runs the same whole-board read and returns its rows.
     * It is PRIVATE, so a caller in `app/` cannot reach a whole-board raw read through it
     * without that showing up as a language-level change. ⚠ THAT IS ALL THIS ESTABLISHES —
     * it is NOT a statement that `READERS` is the whole raw-row surface, which it is not (see
     * the bound in the class docblock). It is asserted rather than written down because "it is
     * private" is exactly the kind of sentence that goes stale in a refactor with nothing red.
     */
    public function test_the_whole_board_reads_internal_twin_stays_private(): void
    {
        $this->assertTrue(
            (new ReflectionMethod(KanbanClient::class, 'correlationCards'))->isPrivate(),
            'correlationCards() has been opened up — it runs the same whole-board read, so a caller can now reach raw rows without appearing in READERS',
        );
    }

    /**
     * ⚑ THE CONTROL. A scan that matched nothing would satisfy the count above the moment
     * READERS were emptied to match it, and a scan that ignored comments would count the
     * rulings in this very file. Both directions are shown.
     */
    public function test_the_derivation_discriminates(): void
    {
        $this->assertSame(2, $this->countIn('<?php $a->readBoardCards(1); $b->readBoardCards(2);'));
        $this->assertSame(0, $this->countIn("<?php\n// a comment naming ->readBoardCards( must not count\nclass X {}"));
        $this->assertSame(0, $this->countIn('<?php public function readBoardCards(int $b): array { return []; }'));
    }

    /** @return array<string, int> */
    private function readersUnderApp(): array
    {
        $root = base_path('app');
        $counts = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $n = $this->countIn((string) file_get_contents($file->getPathname()));
            if ($n > 0) {
                $counts[str_replace(base_path().'/', '', $file->getPathname())] = $n;
            }
        }

        return $counts;
    }

    /**
     * `->readBoardCards(` and not `readBoardCards(`: the second spelling also matches the
     * DECLARATION inside `KanbanClient`, which reads no row it did not just build.
     */
    private function countIn(string $source): int
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        return substr_count($code, '->readBoardCards(');
    }
}
