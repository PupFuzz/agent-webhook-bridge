<?php

namespace Tests\Feature\Workflows;

use Tests\Support\DocRefGateHarness;
use Tests\TestCase;

/**
 * The coverage-membership rule in `bin/check-doc-refs.php` (DL-243), driven over synthetic
 * repo trees by the shared harness.
 *
 * WHAT THE RULE IS FOR. `docs/check-golden-coverage.md` is generated from
 * `CheckCommand::handle()` alone, so once a check migrates into the registry, a comment
 * claiming its predicate is one of that file's disclosed gaps is false BY CONSTRUCTION —
 * unfixable by regeneration, and invisible to phpstan, pint and the whole suite, because a
 * false comment is still valid PHP. Fifteen such claims went false in a single merge without
 * a character changing in the files holding them.
 *
 * WHY THE VECTORS LOOK LIKE THIS. The rule's FIRST implementation licensed a mention whenever
 * the bound was written within a distance window of it, and a claim planted into a real check
 * docblock walked straight through: every migrated check opens "…migrated out of
 * `CheckCommand::handle()` (DL-242 stage N)", so the boilerplate opener sat inside the window
 * of anything written beneath it. The window was measured against the corrected corpus and
 * looked decisive there — the corpus simply had no example of the shape that defeats it. So
 * every acceptance below is paired with a rejection that differs by ONE property, and the
 * same-sentence pair is pinned first: an acceptance with no discriminating twin cannot be told
 * apart from an inert harness.
 *
 * This file is not in the rule's surface, so it can quote rejected claims freely — the surface
 * is a root allow-list rather than a repo-wide scan, which is why the rule needs no exclusion
 * list of its own.
 */
class DocRefCoverageClaimLintTest extends TestCase
{
    use DocRefGateHarness;

    /** The defect itself: a positive membership claim, with nothing that bounds it. */
    private const CLAIM = 'This predicate is one of the disclosed gaps `docs/check-golden-coverage.md` lists.';

    /**
     * The four roots the rule scans. Driven individually: a root that is dropped or
     * misspelled simply stops rejecting, and nothing else in the suite would notice.
     */
    private const SURFACE_FILES = [
        'app/Bridge/Check/Checks/ExampleCheck.php',
        'tests/Unit/Bridge/Check/Checks/ExampleCheckTest.php',
        'tests/Support/CheckGolden/GoldenExample.php',
        'tests/Feature/Console/Check/ExampleGoldenTest.php',
    ];

    /**
     * The sanctioned licences, each with the block that is identical except for it. The
     * pairing is the point: without the twin, an acceptance proves only that the vector ran.
     */
    private const MARKERS = [
        'the consequence stated' => 'Absence from that file is not protection.',
        'silence in both directions' => 'That file does not speak for this predicate in either direction.',
        'an explicit negative membership' => 'This leg was never a disclosed gap.',
    ];

    protected function tearDown(): void
    {
        $this->removeGateTrees();

        parent::tearDown();
    }

    /** `<?php` on line 1, a blank on line 2, so a fixture body always starts at line 3. */
    private function php(string $body): string
    {
        return "<?php\n\n".$body."\n";
    }

    private function docblock(string $text): string
    {
        return "/**\n * ".str_replace("\n", "\n * ", $text)."\n */";
    }

    private function assertGateRejects(string $path, string $content, int $atLine, string $why): void
    {
        [$rc, $out] = $this->runGate([$path => $content]);

        $this->assertSame(1, $rc, "{$why}\nexpected a rejection in {$path}; the gate said:\n{$out}");
        $this->assertStringContainsString('Coverage-membership claims', $out,
            "{$why}\nthe failure must come from the claim rule, not one of the other two:\n{$out}");
        $this->assertStringContainsString($path.':'.$atLine, $out,
            "{$why}\nthe report must name the offending file and the line the BLOCK starts on:\n{$out}");
    }

    private function assertGateAccepts(string $path, string $content, string $why): void
    {
        [$rc, $out] = $this->runGate([$path => $content]);

        $this->assertSame(0, $rc, "{$why}\nexpected {$path} to be accepted; the gate said:\n{$out}");
    }

    /** THE CONTROL every acceptance below is read against. */
    public function test_the_empty_tree_control_passes(): void
    {
        [$rc, $out] = $this->runGate([]);

        $this->assertSame(0, $rc, "the empty-tree control must pass — every vector here is read against it:\n{$out}");
    }

    public function test_a_membership_claim_is_rejected_in_every_root_of_the_surface(): void
    {
        foreach (self::SURFACE_FILES as $path) {
            $this->assertGateRejects($path, $this->php($this->docblock(self::CLAIM)), 3,
                "a membership claim in {$path} is inside the rule's surface");
        }
    }

    /**
     * Scope is deliberate — `docs/` narrates the gap list at length and a whitelist there
     * would be churn — so the out-of-surface acceptance is paired with the identical file
     * inside it. An acceptance is otherwise indistinguishable from a file never scanned.
     */
    public function test_a_claim_outside_the_surface_is_accepted_and_the_same_file_inside_it_is_not(): void
    {
        $content = $this->php($this->docblock(self::CLAIM));

        $this->assertGateAccepts('app/Bridge/Support/AgentConfig.php', $content,
            'outside the check surface the rule is deliberately silent');

        $this->assertGateRejects('app/Bridge/Check/Checks/ExampleCheck.php', $content, 3,
            'the witness: the identical file inside the surface must be rejected');
    }

    /**
     * THE REGRESSION THE FIRST IMPLEMENTATION SHIPPED WITH. Naming the bound licenses a
     * mention only where the two share a SENTENCE; the standard "migrated out of
     * `CheckCommand::handle()`" opener is not a bound stated about the coverage file, however
     * close to the claim it happens to sit.
     */
    public function test_the_bound_licenses_a_mention_only_inside_the_same_sentence(): void
    {
        $path = 'app/Bridge/Check/Checks/ExampleCheck.php';

        $this->assertGateAccepts($path,
            $this->php($this->docblock(
                '`docs/check-golden-coverage.md` would not list the gap either — it'."\n".
                'enumerates predicates in `CheckCommand::handle()`.'
            )),
            'the bound and the mention in one sentence is the correct form this rule asks for');

        $this->assertGateRejects($path,
            $this->php($this->docblock(
                'The database check, migrated out of `CheckCommand::handle()` (DL-242 stage 4).'."\n".
                "\n".
                self::CLAIM
            )),
            3,
            'the witness: the bound in a PRIOR sentence is boilerplate, not a licence — this is the vector a distance window walked straight through');
    }

    public function test_each_sanctioned_marker_licenses_a_mention_and_the_block_without_it_does_not(): void
    {
        $path = 'tests/Unit/Bridge/Check/Checks/ExampleCheckTest.php';

        foreach (self::MARKERS as $label => $marker) {
            $this->assertGateAccepts($path, $this->php($this->docblock(self::CLAIM."\n".$marker)),
                "a block carrying {$label} has said what the mention does not buy");
        }

        $this->assertGateRejects($path, $this->php($this->docblock(self::CLAIM)), 3,
            'the witness: the same claim with every marker removed must be rejected, or the acceptances above prove nothing');
    }

    /**
     * The unit is the BLOCK, not the line: these claims run three to six wrapped lines and
     * the mention routinely wraps away from the verb that makes it a claim.
     */
    public function test_a_claim_wrapped_across_lines_is_rejected(): void
    {
        $this->assertGateRejects('app/Bridge/Check/Checks/ExampleCheck.php',
            $this->php($this->docblock(
                'The coverage table in `docs/check-golden-coverage.md`'."\n".
                'discloses this predicate as UNOBSERVED, so a regression here would red a'."\n".
                'golden file.'
            )),
            3,
            'a claim whose mention and verb sit on different lines is still one claim');
    }

    /** A bound in a DIFFERENT block is not a bound on this one. */
    public function test_a_bound_in_a_neighbouring_block_does_not_license_the_claim(): void
    {
        $this->assertGateRejects('app/Bridge/Check/Checks/ExampleCheck.php',
            $this->php(
                $this->docblock('Generated from `CheckCommand::handle()` alone, in either direction.')."\n".
                "class Example {}\n\n".
                $this->docblock(self::CLAIM)
            ),
            8,
            'a licence in the block above is not a licence here — and the report must name the SECOND block');
    }

    /**
     * A run of `//` lines is one authorial unit. The acceptance is what proves the merge
     * happens at all: unmerged, the first line alone carries a mention with no licence and
     * would be rejected. The blank-line twin proves the adjacency condition is real rather
     * than every `//` in the file being swept together.
     */
    public function test_a_run_of_line_comments_is_one_block_and_a_blank_line_ends_it(): void
    {
        $path = 'app/Bridge/Check/Checks/ExampleCheck.php';

        $this->assertGateAccepts($path,
            $this->php("// Absent from `docs/check-golden-coverage.md`,\n// but absence there is not protection."),
            'consecutive line comments are one block, so the licence on the second line covers the first');

        $this->assertGateRejects($path,
            $this->php("// Absent from `docs/check-golden-coverage.md`,\n\n// but absence there is not protection."),
            3,
            'the witness: a blank line ends the block, so the licence no longer covers the mention');
    }

    /**
     * A claim need not name the file to be a claim — the ones that rot worst never do, which
     * is why the trigger also stands on the bare phrase.
     */
    public function test_a_claim_naming_no_file_is_still_rejected(): void
    {
        $this->assertGateRejects('app/Bridge/Check/Checks/ExampleCheck.php',
            $this->php('// This leg is one of the disclosed gaps the coverage table enumerates.'),
            3,
            'a membership claim that never names the file is invisible to a grep for it and must still be caught');
    }

    /**
     * The blocks are tokenized rather than pattern-matched, so a comment delimiter inside a
     * string literal cannot open a phantom block. Fixture strings in a test are exactly that
     * shape, and a repo-wide rule that mistook them for comments would be unfixable except by
     * an exclusion list.
     */
    public function test_a_comment_delimiter_inside_a_string_literal_does_not_open_a_block(): void
    {
        $path = 'tests/Unit/Bridge/Check/Checks/ExampleCheckTest.php';

        $this->assertGateAccepts($path,
            $this->php('$fixture = \'/** '.self::CLAIM.' */\';'),
            'a rejected claim quoted as a string literal is data, not a comment');

        $this->assertGateRejects($path, $this->php($this->docblock(self::CLAIM)), 3,
            'the witness: the identical text as a real comment must be rejected');
    }
}
