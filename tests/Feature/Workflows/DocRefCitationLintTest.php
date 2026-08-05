<?php

namespace Tests\Feature\Workflows;

use Tests\Support\DocRefGateHarness;
use Tests\TestCase;

/**
 * Drives the REAL `bin/check-doc-refs.php` — the copy CI runs — over synthetic repo
 * trees, one vector per tree. The script resolves its scan root as `dirname(__DIR__)`,
 * so copying it into `<tmp>/bin/` makes `<tmp>` the repo it examines. Deliberately NO
 * root argument was added to the script for this: a path argument that overrides the
 * scanned set is precisely how a run fakes coverage, and the citation gate exists to
 * catch what a targeted pass misses.
 *
 * WHY THIS FILE EXISTS. The line-number citation rule shipped with a hand-driven matrix
 * that was thrown away after review, and the next pass over the same gate found THREE
 * holes in it — two of them in its EXEMPTIONS, which the original "proven able to fire"
 * evidence never exercised:
 *
 *   1. the stable-anchor exemption skipped ANY line naming an anchor, so an anchor word
 *      plus a citation of the migrating file passed;
 *   2. the root scan reached only `CLAUDE*.md`, so `README.md` was never examined;
 *   3. the name→offset connector was an enumerated character class, which missed this
 *      repo's own house style — a backtick-quoted class name — and `::handle()`.
 *
 * Each hole was in the half of the rule the original evidence did not cover. That is
 * the failure this file is built against, so its shape is: every REJECTION names the
 * file and line it fired on (an exit code alone cannot say WHICH rule fired), and every
 * ACCEPTANCE is paired with a mutated line that must be rejected — a discriminating
 * control, because an acceptance is indistinguishable from an inert harness without one.
 *
 * `bin/check-doc-refs.php` and this file are the two paths `$citeExcluded` exempts for
 * the same reason: both must be able to quote the citation forms they reject.
 */
class DocRefCitationLintTest extends TestCase
{
    use DocRefGateHarness;

    /**
     * The four roots where an unqualified `L<n>` can only mean the migrating file, per
     * the script's `$bareCiteSurface`. Driven individually: a surface alternative that
     * is dropped or misspelled stops rejecting, and nothing else would notice.
     */
    private const BARE_OFFSET_SURFACE_FILES = [
        'app/Bridge/Check/CheckRunner.php',
        'tests/Support/CheckGolden/PinnedHost.php',
        'tests/Feature/Console/Check/RetentionPostureCheckTest.php',
        'docs/CHECK-REGISTRY-PLAN.md',
    ];

    /**
     * The ways a citation of the migrating file is actually written here. The last two
     * are the forms that slipped an enumerated connector: the repo backtick-quotes
     * class names, and cites methods by call.
     */
    private const VOLATILE_CITE_FORMS = [
        'unquoted' => 'the retention advisory is emitted at CheckCommand L240',
        'backticked name' => 'the retention advisory is emitted at `CheckCommand` L240',
        'method call' => 'the retention advisory is emitted at `CheckCommand::handle()` L240',
        'file and offset' => 'the retention advisory is emitted at CheckCommand.php:961',
    ];

    /**
     * Rule 1 binds ANYWHERE, so the locations span the three scan roots that reach a
     * citation — the root `*.md` glob (invisible to the pre-fix `CLAUDE*.md` glob),
     * `docs/`, and `app/` both outside and inside the bare-offset surface.
     */
    private const VOLATILE_CITE_LOCATIONS = [
        'README.md',
        'docs/board-tools.md',
        'app/Bridge/Support/AgentConfig.php',
        'app/Bridge/Check/CheckRunner.php',
    ];

    /** Append-only history, generated files, and the reviews archive — never rewritten to suit a live rule. */
    private const EXCLUDED_PATHS = [
        'CLAUDE_DECISIONS.md',
        'docs/CHANGELOG.md',
        'docs/reviews/2026-07-28-stage-2.md',
        'docs/check-golden-coverage.md',
    ];

    protected function tearDown(): void
    {
        $this->removeGateTrees();

        parent::tearDown();
    }

    /**
     * A rejection must be attributable: the exit code says only that SOMETHING failed,
     * and this script also enforces an unrelated dangling-reference rule.
     */
    private function assertGateRejects(string $path, string $line, string $why): void
    {
        [$rc, $out] = $this->runGate([$path => $line."\n"]);

        $this->assertSame(1, $rc, "{$why}\nexpected `{$line}` in {$path} to be rejected; the gate said:\n{$out}");
        $this->assertStringContainsString('Line-number citations', $out,
            "{$why}\nthe failure must come from the citation rule, not the dangling-reference rule:\n{$out}");
        $this->assertStringContainsString($path.':1', $out,
            "{$why}\nthe report must name the offending file and line:\n{$out}");
    }

    private function assertGateAccepts(string $path, string $line, string $why): void
    {
        [$rc, $out] = $this->runGate([$path => $line."\n"]);

        $this->assertSame(0, $rc, "{$why}\nexpected `{$line}` in {$path} to be accepted; the gate said:\n{$out}");
    }

    /**
     * THE CONTROL every other vector is read against — an acceptance below means
     * nothing if the empty tree does not pass (a red control once made four negatives
     * read as failures).
     *
     * It doubles as the proof of the script's self-exemption: the tree holds the script
     * and nothing else, and the script's own docblock quotes the citation forms it
     * rejects. That second claim is only worth anything while the quoting is still
     * there, so it is asserted rather than assumed.
     */
    public function test_the_empty_tree_control_passes_and_the_script_exempts_itself(): void
    {
        $body = (string) file_get_contents(base_path('bin/check-doc-refs.php'));
        $this->assertMatchesRegularExpression('/CheckCommand[^\n]{0,24}?\b(L|:)\d{2,4}\b/', $body,
            'the control only proves the self-exemption while the script itself still quotes a rejected citation form');

        [$rc, $out] = $this->runGate([]);
        $this->assertSame(0, $rc, "the empty-tree control must pass — every vector in this file is read against it:\n{$out}");
    }

    /**
     * Rule 2: inside the check-registry surface an unqualified offset means the
     * migrating file by context, so it is rejected with no filename on the line at all.
     * This is the citation form a manual pass cannot grep for.
     */
    public function test_a_bare_offset_is_rejected_in_every_root_of_the_surface(): void
    {
        foreach (self::BARE_OFFSET_SURFACE_FILES as $path) {
            $this->assertGateRejects(
                $path,
                $this->asLine($path, 'the retention advisory is emitted at L240'),
                'a bare offset inside the surface is a citation of the migrating file'
            );
        }
    }

    /**
     * Rule 1: a line naming the migrating file next to an offset is rejected wherever
     * it lives and however it is quoted. The matrix is the whole point — three of the
     * four forms and one of the four locations were live holes.
     */
    public function test_a_citation_naming_the_migrating_file_is_rejected_in_every_form_and_location(): void
    {
        foreach (self::VOLATILE_CITE_FORMS as $form => $text) {
            foreach (self::VOLATILE_CITE_LOCATIONS as $path) {
                $this->assertGateRejects($path, $this->asLine($path, $text),
                    "a citation written as a {$form} must be rejected in {$path}");
            }
        }
    }

    /**
     * The stable-anchor exemption covers BARE offsets only. Without that bound one
     * anchor word anywhere on a line whitelists the exact citation the rule exists to
     * catch — which is how it shipped.
     */
    public function test_the_stable_anchor_exemption_does_not_cover_a_line_that_also_cites_the_migrating_file(): void
    {
        $surfaceFile = 'app/Bridge/Check/CheckRunner.php';

        $this->assertGateAccepts($surfaceFile,
            '// the pin (`GitHubTokenResolver` reads the token at L204) is stable',
            'a bare offset attributed to a stable anchor is the exemption working as intended');

        $this->assertGateRejects($surfaceFile,
            '// the pin (`SubscriptionRegistry` reads the token at L204) is stable',
            'the witness: with a non-anchor class the same line must be rejected, or the acceptance above proves nothing');

        $this->assertGateRejects($surfaceFile,
            '// `GitHubTokenResolver` is pinned, and CheckCommand L240 calls it',
            'an anchor word must not whitelist a citation of the migrating file on the same line');
    }

    /**
     * Rule 2 is deliberately scoped: the receiver core is documented as static and its
     * offsets have held for months, so a bare offset outside the surface is left alone.
     * Paired with the same line inside the surface — an acceptance that cannot be
     * distinguished from an unscanned file is not evidence.
     */
    public function test_a_bare_offset_outside_the_surface_is_accepted_and_the_same_line_inside_it_is_not(): void
    {
        $line = 'the receiver rejects an oversized envelope at L204';

        $this->assertGateAccepts('docs/board-tools.md', $line,
            'a bare offset outside the check-registry surface is out of scope by design');

        $this->assertGateRejects('docs/CHECK-REGISTRY-PLAN.md', $line,
            'the witness: the identical line inside the surface must be rejected');
    }

    /**
     * The name→offset connector is a bounded window rather than an enumerated set of
     * quoting characters — enumerating spellings is the losing half of that trade. The
     * bound is what keeps prose that merely mentions the file next to an unrelated
     * offset out of the rule, so both sides of it are pinned here.
     */
    public function test_the_window_between_the_name_and_the_offset_is_bounded(): void
    {
        $path = 'docs/board-tools.md';

        $this->assertGateAccepts($path,
            'CheckCommand is renamed by the plan; the resolver reads its token at L204',
            'an offset far from the name is not a citation of it');

        $this->assertGateRejects($path,
            'CheckCommand is renamed; see L204',
            'the witness: the same words with the offset inside the window must be rejected');
    }

    /**
     * Naming the migrating file is not itself the defect — the offset is. The rule has
     * to leave construct-named prose alone, since that is exactly what it asks authors
     * to write instead.
     */
    public function test_prose_naming_the_migrating_file_without_an_offset_is_accepted(): void
    {
        $path = 'docs/board-tools.md';

        $this->assertGateAccepts($path, 'CheckCommand is migrated one cluster per stage',
            'the rule asks for construct names, so a construct name must not trip it');

        $this->assertGateRejects($path, 'CheckCommand is migrated from L163',
            'the witness: the same sentence with an offset must be rejected');
    }

    /**
     * The exclusions are load-bearing in one direction only: append-only history
     * records what was true at that version, and the generated coverage file's
     * predicate ids are literally offsets. Each is paired with a non-excluded sibling
     * carrying the identical line, so an exclusion that silently widened to the whole
     * of `docs/` would red here.
     */
    public function test_the_excluded_paths_are_exempt_and_a_sibling_path_is_not(): void
    {
        $line = 'the retention advisory was emitted at CheckCommand L240';

        foreach (self::EXCLUDED_PATHS as $path) {
            $this->assertGateAccepts($path, $line, "{$path} is excluded from the citation rule");
        }

        $this->assertGateRejects('docs/board-tools.md', $line,
            'the witness: the identical line in a non-excluded doc must be rejected');
    }

    /** Fixture lines land in PHP files as comments, so the tree reads like the repo it stands in for. */
    private function asLine(string $path, string $text): string
    {
        return str_ends_with($path, '.php') ? '// '.$text : $text;
    }
}
