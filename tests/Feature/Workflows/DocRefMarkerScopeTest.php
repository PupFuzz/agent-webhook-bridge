<?php

namespace Tests\Feature\Workflows;

use Tests\Support\DocRefGateHarness;
use Tests\TestCase;

/**
 * Drives the REAL `bin/check-doc-refs.php` over the SCOPE at which rule 1's escape hatches read
 * an annotation — `annotationCovers()`, the one helper both of them now consume (card#7127).
 *
 * WHY THIS FILE EXISTS AND IS NOT A THIRD BLOCK IN `DocRefMemberLintTest`. The scope used to be
 * two implementations: the removed-marker read the whole LINE, the rejected-alternative read the
 * SENTENCE. One question, two answers, in one file — so the evidence for it belongs to the shared
 * helper rather than to either caller, and every vector here is written against BOTH callers: the
 * member leg and the file/FQCN leg, which are the two places the marker vocabulary is applied.
 *
 * THE SHAPE THE LINE SCOPE COST, and the reason the fix is worth a gate change: `docs/CHANGELOG.md`
 * entries are single lines that narrate a removal AND cite the live members that replaced it. One
 * truthful "X was removed" therefore switched the member leg off for every other citation on that
 * line, and the blindness grew with each release note — a gate that goes quiet as the docs it
 * guards get longer.
 *
 * EVERY ACCEPTANCE IS PAIRED, per this rule's existing discipline. An acceptance alone cannot
 * distinguish "the marker is scoped correctly" from "nothing is checked at all", and that is the
 * precise failure being fixed: the pre-fix gate was GREEN over these vectors because it examined
 * none of them.
 */
class DocRefMarkerScopeTest extends TestCase
{
    use DocRefGateHarness;

    /** A class with one real method, so a citation of it can be made true or phantom at will. */
    private const SUBJECT = <<<'PHP'
<?php

namespace App\Bridge\Support;

final class Widget
{
    public function stamp(): void {}
}
PHP;

    protected function tearDown(): void
    {
        $this->removeGateTrees();

        parent::tearDown();
    }

    /**
     * THE CONTROL THE CARD ASKED FOR, on the surface it was measured on. One CHANGELOG line
     * narrates a removal and goes on to cite a live member; the live citation must be EXAMINED.
     *
     * The witness is what makes the acceptance mean anything: with the second sentence's citation
     * made phantom, the same line must red. Under the line scope it did not — the marker in the
     * first sentence discharged the whole line, so a false claim in the second was invisible.
     */
    public function test_a_removal_narrated_in_one_sentence_leaves_the_next_sentence_examined(): void
    {
        $line = "- **v0.60** — `Widget::brand()` was removed. `Widget::%s` now writes the label.\n";

        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/CHANGELOG.md' => sprintf($line, 'stamp()'),
        ], 'the removal is discharged by its own sentence and the live citation beside it resolves');

        $this->assertRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/CHANGELOG.md' => sprintf($line, 'emboss()'),
        ], 'docs/CHANGELOG.md:1', 'the witness: a phantom two clauses from the marker is a finding, not a discharge');
    }

    /**
     * THE SENTENCE BOUNDARY THIS REPO ACTUALLY WRITES, and the one a `[.!?]\s` splitter does not
     * see. A terminator here is routinely NOT followed by whitespace: these documents close a
     * sentence with markdown emphasis, a quote or a paren — `.**`, `.*`, `."`, `.)` — and the two
     * surfaces the sentence scope exists for carry hundreds of the bold form each.
     *
     * ⛔ WITHOUT THIS, THE WHOLE FIX IS ONE CHARACTER FROM INERT. A line whose marker sentence ends
     * `removed.**` merges with everything after it, the merged fragment is handed back to the
     * marker, and the gate goes silent over exactly the CHANGELOG shape this file exists to pin —
     * the defect re-minted by the prose form the target documents actually use, at green.
     *
     * The vector is the plain case with ONE character added, so a moved verdict is attributable to
     * the terminator and to nothing else.
     */
    public function test_a_sentence_closed_by_emphasis_or_a_bracket_still_terminates(): void
    {
        foreach (['.**' => 'bold', '.*' => 'italic', '."' => 'quote', '.)' => 'paren', '.**]**' => 'annotation'] as $closer => $form) {
            $line = "- **v0.60** — `Widget::brand()` was removed{$closer} `Widget::%s` now writes the label.\n";

            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'docs/CHANGELOG.md' => sprintf($line, 'stamp()'),
            ], "a {$form}-closed removal sentence terminates, leaving the live citation beside it resolving");

            $this->assertRejected([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'docs/CHANGELOG.md' => sprintf($line, 'emboss()'),
            ], 'docs/CHANGELOG.md:1', "the witness: a phantom after a {$form}-closed terminator is examined, not swallowed by the marker");
        }
    }

    /**
     * THE NEGATIVE PIN. A citation genuinely inside the removal sentence is still skipped — a fix
     * whose only evidence is the newly-checked case cannot tell "checks the right thing" from
     * "checks everything", and a marker that stopped discharging its own sentence would be a gate
     * reddening on truthful prose, which is how a gate gets switched off.
     *
     * Paired against the same sentence with the marker word taken out, so the acceptance is
     * attributable to the marker rather than to the citation being unreachable.
     */
    public function test_a_citation_inside_the_removal_sentence_is_still_discharged(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/CHANGELOG.md' => "- **v0.60** — `Widget::brand()` was removed; nothing replaced it.\n",
        ], 'the marker and the citation share a sentence, which is exactly what the hatch is for');

        $this->assertRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/CHANGELOG.md' => "- **v0.60** — `Widget::brand()` was retired; nothing replaced it.\n",
        ], 'docs/CHANGELOG.md:1', 'the witness: the same sentence with no marker word discharges nothing');
    }

    /**
     * THE SECOND CALLER. The file/FQCN leg applies the same vocabulary over the six current-state
     * docs, and it was line-scoped for the same reason and with the same cost. Sharing the helper
     * is what stops the two drifting apart again — a third hatch cannot arrive at a third scope
     * without adding a second implementation on purpose.
     */
    public function test_the_path_leg_reads_the_marker_at_the_same_scope(): void
    {
        $line = "`app/Bridge/Support/Gone.php` was deleted. The label is written by `app/Bridge/Support/%s.php`.\n";

        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => sprintf($line, 'Widget'),
        ], 'the deleted path is discharged by its own sentence and the live path beside it resolves');

        $this->assertRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => sprintf($line, 'AlsoGone'),
        ], 'CLAUDE.md:1', 'the witness: a dangling path two clauses from the marker is a finding on the path leg too');
    }

    /**
     * The rejected-alternative hatch is the caller whose scope was already right, and it must stay
     * right through the hoist: a decision-log entry names the alternative it refused beside the
     * consequence it built, and only the alternative is exempt.
     *
     * This duplicates no vector in `DocRefMemberLintTest` — that one pins the hatch's BEHAVIOUR;
     * this one pins that moving the splitter under a shared helper did not move it.
     */
    public function test_the_rejected_alternative_hatch_keeps_the_same_scope_after_the_hoist(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "**Alternatives:** a per-target flag (`Widget::brand()`) — rejected: the call sites must remember to set it.\n",
        ], 'an alternative the entry says it rejected names a construct that must not exist');

        $this->assertRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "**Alternatives:** a per-target flag (`Widget::stamp()`) — rejected: it is fiddly. **Consequences:** `Widget::emboss()` writes the label.\n",
        ], 'CLAUDE_DECISIONS.md:1', 'the witness: the built consequence in the next sentence is still examined');
    }
}
