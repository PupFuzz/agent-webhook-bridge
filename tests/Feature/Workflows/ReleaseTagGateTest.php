<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The untagged-release gate's ORDER and its preconditions, asserted from the PHP
 * suite because the workflow they live in cannot assert them about itself
 * (card#8286; the same reason PythonToolsPathFilterTest lives here).
 *
 * `release-promote-cards.yml` and `auto-tag-version.yml` share the `push: main`
 * trigger with no `needs:` between them, so promote wins the race on EVERY
 * release. Before the gate, that meant cards stamped "Released to main" against
 * a tag that did not exist yet — and where the tag never arrived, the board's
 * assertion was simply false and nothing corrected it (measured in
 * agent-board-toolkit on its v0.28.0 merge: 17m27s of false "shipped").
 *
 * WHAT MAKES THIS WORTH A TEST rather than a comment: every way this gate stops
 * working is SILENT AND GREEN. Delete the step, move it below the promote step,
 * drop its `if:` guard, or drop the checkout's `fetch-depth: 0`, and CI stays
 * green while a release either reports a state it never verified or refuses
 * every release at the moment it matters most. The gate is only exercised on a
 * `push` to `main` — the one event no PR can rehearse — so a PR is structurally
 * unable to observe its own breakage. That is the same absent-check class this
 * repo has paid for before.
 *
 * BOUND — this asserts the WIRING, not the tool. What the gate does once it runs
 * (the wait, the bound, the three refusals) belongs to the toolkit's
 * `bin/release-tag-check` and is tested there; asserting it here would mint a
 * second copy of numbers this repo deliberately does not carry.
 */
class ReleaseTagGateTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/release-promote-cards.yml';

    /** The toolkit action path, without its `@<sha>` pin. */
    private const GATE_ACTION = 'PupFuzz/agent-board-toolkit/release-tag-check';

    private const PROMOTE_ACTION = 'PupFuzz/agent-board-toolkit/promote';

    /**
     * The promote job's steps, in file order.
     *
     * @return list<array<string,mixed>>
     */
    private function steps(): array
    {
        $workflow = Yaml::parseFile(base_path(self::WORKFLOW));

        $steps = $workflow['jobs']['promote']['steps'] ?? null;

        // A floor, not a formality: every assertion below indexes into this
        // list, so a renamed job or a restructured file would otherwise let
        // this class pass over an empty population having measured nothing.
        $this->assertIsArray($steps, self::WORKFLOW.' has no jobs.promote.steps — this test measured nothing');
        $this->assertNotEmpty($steps, self::WORKFLOW.' jobs.promote.steps is empty — this test measured nothing');

        return array_values($steps);
    }

    /**
     * Index of the first step whose `uses:` names $action, or null.
     *
     * @param  list<array<string,mixed>>  $steps
     */
    private function indexOfStepUsing(array $steps, string $action): ?int
    {
        foreach ($steps as $i => $step) {
            $uses = $step['uses'] ?? null;

            if (is_string($uses) && str_starts_with($uses, $action.'@')) {
                return $i;
            }
        }

        return null;
    }

    public function test_the_tag_gate_runs_before_the_promote_step(): void
    {
        $steps = $this->steps();

        $gate = $this->indexOfStepUsing($steps, self::GATE_ACTION);
        $promote = $this->indexOfStepUsing($steps, self::PROMOTE_ACTION);

        $this->assertNotNull($promote, sprintf(
            'no step in %s uses %s@… — the job this gate exists to order against is gone, so the ordering assertion below would be vacuous',
            self::WORKFLOW,
            self::PROMOTE_ACTION
        ));

        $this->assertNotNull($gate, sprintf(
            "no step in %s uses %s@… — nothing stops this workflow reporting a release as shipped before its tag exists (card#8286).\n"
            .'Adopt the toolkit action per its docs/INSTALL.md §6d; do not replace it with a sleep or a workflow reordering.',
            self::WORKFLOW,
            self::GATE_ACTION
        ));

        $this->assertLessThan($promote, $gate, sprintf(
            'the %s step (index %d) must run BEFORE the %s step (index %d). A gate placed after the thing it guards '
            .'refuses a release the board has already been told shipped — the board is already wrong and the red run is now about repairing it.',
            self::GATE_ACTION,
            $gate,
            self::PROMOTE_ACTION,
            $promote
        ));
    }

    public function test_the_tag_gate_is_skipped_on_the_event_that_carries_no_range(): void
    {
        $steps = $this->steps();
        $gate = $this->indexOfStepUsing($steps, self::GATE_ACTION);
        $this->assertNotNull($gate, 'the tag gate step is absent — see the ordering test');

        // The gate classifies a RANGE, and only a `push` carries a before-ref.
        // This workflow also has a workflow_dispatch trigger; without the guard
        // the gate dies rc 2 on the operator path, which is exactly the surface
        // an operator reaches for when the automatic path already looks wrong.
        $this->assertSame(
            "github.event_name == 'push'",
            $steps[$gate]['if'] ?? null,
            'the tag gate must be guarded to the push event — a workflow_dispatch run carries no before-ref to classify against'
        );

        // The caller owns the backstop, and it must be PRESENT: the tool's own
        // bound is what makes a refused release report `failure`, and it only
        // gets to fire first if the outer kill is sized above it. The VALUE is a
        // toolkit-owned figure this repo deliberately does not restate, so only
        // its presence is asserted here.
        $this->assertArrayHasKey(
            'timeout-minutes',
            $steps[$gate],
            'the tag gate needs a timeout-minutes backstop; without one a hung job is killed by the default 360-minute cap, and a kill reports `cancelled`, which is not a refused release'
        );
    }

    /**
     * The gate DECLARES which tag it waits for; this CHECKS that declaration is
     * true of this repo's own tagger. Without it the declaration is a comment.
     *
     * Which tag a version maps to is `.release-pr.json`'s `tag_format` (absent =
     * `v{{version}}`), and that key is what the gate polls for. It does NOT
     * change what `auto-tag-version.yml` CREATES — nothing in the toolkit
     * constrains that workflow — so the two can silently disagree, and the cost
     * of disagreeing is now much higher than it was before the gate existed:
     * the gate waits for a tag nobody creates and refuses EVERY release at its
     * bound. That hazard is minted by adopting the gate, so it is guarded here
     * rather than left to the toolkit's prose.
     *
     * Both sides are DERIVED from the artifacts — no expected tag name is
     * written down — so this reds whichever of the two moves alone.
     */
    public function test_the_tag_the_gate_waits_for_is_the_tag_the_tagger_creates(): void
    {
        $config = json_decode((string) file_get_contents(base_path('.release-pr.json')), true);
        $this->assertIsArray($config, '.release-pr.json did not parse — the gate reads it for the tag name');

        // The default is the toolkit's, applied when the key is absent.
        $tagFormat = $config['tag_format'] ?? 'v{{version}}';

        $tagger = Yaml::parseFile(base_path('.github/workflows/auto-tag-version.yml'));

        $assignments = [];
        array_walk_recursive($tagger, function (mixed $value) use (&$assignments): void {
            if (! is_string($value) || preg_match_all('/^\s*TAG=(\S+)/m', $value, $m) === 0) {
                return;
            }

            foreach ($m[1] as $rhs) {
                $assignments[] = $this->normaliseShellTag($rhs);
            }
        });

        // A floor: a tagger that stopped assigning TAG= at all — renamed the
        // variable, moved to an action — would otherwise pass this vacuously,
        // and vacuous is exactly the state the gate cannot survive.
        $this->assertNotEmpty(
            $assignments,
            'no `TAG=` assignment found in auto-tag-version.yml, so this test measured nothing about which tag the tagger creates'
        );

        $expected = $this->normaliseShellTag(str_replace('{{version}}', '${VERSION}', $tagFormat));

        $this->assertSame(
            [$expected],
            array_values(array_unique($assignments)),
            sprintf(
                "auto-tag-version.yml creates a tag that .release-pr.json's tag_format (%s) does not name.\n"
                .'The gate waits for the name tag_format gives, so a disagreement makes it wait for a tag nobody creates '
                .'and refuse EVERY release at its bound. Move both in the same change.',
                $tagFormat
            )
        );
    }

    /** `${VERSION}` and `$VERSION` are the same expansion; quoting is not the subject here. */
    private function normaliseShellTag(string $raw): string
    {
        return str_replace('${VERSION}', '$VERSION', trim($raw, '"\''));
    }

    public function test_the_checkout_the_tag_gate_classifies_from_is_not_shallow(): void
    {
        $steps = $this->steps();

        $checkout = null;
        foreach ($steps as $step) {
            $uses = $step['uses'] ?? null;
            if (is_string($uses) && str_starts_with($uses, 'actions/checkout@')) {
                $checkout = $step;
                break;
            }
        }

        $this->assertNotNull($checkout, self::WORKFLOW.' has no actions/checkout step — the gate and the promote step both read this repo\'s git history');

        // fetch-depth: 0 is the gate's precondition (1). The release
        // classification resolves a merge base and reads the version file at
        // BOTH ends via `git show`; on a shallow clone it refuses rather than
        // reading an unresolvable range as "not a release", so losing this key
        // reds every release instead of skipping the check — loud, but at the
        // most expensive moment there is.
        $this->assertSame(
            0,
            $checkout['with']['fetch-depth'] ?? null,
            'the checkout must be unshallow (fetch-depth: 0) — the release classification reads both ends of the pushed range'
        );
    }
}
