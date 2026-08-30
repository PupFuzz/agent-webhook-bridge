<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

/**
 * `CLAUDE_DECISIONS.md` still has its TITLE, and nothing sits above it (card#7836).
 *
 * ⭐ WHY THIS EXISTS. A correction to DL-316 was written to the TOP OF THE FILE instead of
 * into the entry it corrects. The `# Decision log` H1 was consumed doing it — the appended
 * text ended `…one careless caller away.# Decision log`, gluing the title to a bullet's tail
 * — leaving ~4,000 lines of decision log under no title, with an untitled fragment above the
 * file's own header, its append-only convention and every `## DL-` entry. **Nothing caught
 * it:** pint, phpstan, `check-doc-refs` and `decision-log.py check` were all green, the last
 * of which validates NUMBERING and says so.
 *
 * ⛔ THE COST OF A MISS IS A DECISION, WHICH IS WHY IT IS WORTH A CHECK. `CLAUDE.md` names
 * this file as the authority for *"why anything is the way it is"*, so a future session
 * orients from its head; a stale fragment there is read as the current rule, and this repo's
 * whole DL-316 finding is that a restatement outliving the rule it restates is the defect.
 *
 * ⚑ WHAT THIS DELIBERATELY DOES NOT DO. It is a structural pin, not a content one: it says
 * nothing about which entry a correction belongs in, and it is not a gate — no exit code and
 * no CI accept-set moves, because `decision-log.py`'s exit contract has machine consumers
 * (`dl-collision-gate.yml`) and widening what that gate REFUSES is not this card's to decide.
 * It lives beside {@see ChannelServerVersionAgreementTest}, the other real-tree invariant in
 * this directory.
 */
class DecisionLogStructureTest extends TestCase
{
    private const TITLE = '# Decision log';

    /** The real file, since the invariant is about THIS tree and not about a fixture. */
    public function test_the_decision_log_keeps_its_title_as_its_first_line(): void
    {
        $defects = $this->structuralDefects(file_get_contents(base_path('CLAUDE_DECISIONS.md')));

        $this->assertSame([], $defects, "CLAUDE_DECISIONS.md's structure has been broken:\n- ".implode("\n- ", $defects));
    }

    /**
     * ⭐ THE CONTROL, AND IT REPRODUCES THE REAL DEFECT RATHER THAN A MADE-UP ONE. The fixture
     * is the LIVE file put back through the exact edit commit `75ba43a` made — a bullet
     * prepended, the H1 glued to its tail — so a predicate that merely happens to pass on the
     * repaired file cannot pass here. Without it this class asserts an absence and certifies
     * whatever replaces it: a `structuralDefects()` stuck on `[]` would be green forever.
     */
    public function test_the_check_rejects_the_shape_that_actually_landed(): void
    {
        $live = file_get_contents(base_path('CLAUDE_DECISIONS.md'));
        $prepended = '- **⛔ Decision 2 — a correction written to the wrong place.** It ends here.'
            .self::TITLE."\n".substr($live, strlen(self::TITLE) + 1);

        $defects = $this->structuralDefects($prepended);

        $this->assertNotSame([], $defects, 'the predicate passed the shape that actually landed — it measures nothing');
        $this->assertStringContainsString('first non-blank line', $defects[0]);
        $this->assertContains('the file carries no `'.self::TITLE.'` line at all — the title was consumed by the text glued to it', $defects);
    }

    /**
     * ⛔ THE SECOND DIRECTION. A title that survives but no longer LEADS is the other half of
     * the same defect (an entry appended above it), and a predicate that only counted the
     * title would call it clean.
     */
    public function test_the_check_rejects_content_above_a_surviving_title(): void
    {
        $live = file_get_contents(base_path('CLAUDE_DECISIONS.md'));

        $defects = $this->structuralDefects("## DL-999 — an entry written above the title\n\n".$live);

        $this->assertNotSame([], $defects, 'an entry above the title read as clean');
        $this->assertStringContainsString('first non-blank line', $defects[0]);
    }

    /** @return list<string> every structural defect found, in reading order; empty is clean. */
    private function structuralDefects(string $text): array
    {
        $lines = explode("\n", $text);
        $defects = [];

        $first = null;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }
        if ($first !== self::TITLE) {
            $defects[] = 'the first non-blank line is '.var_export($first, true).', not '.var_export(self::TITLE, true);
        }

        $titles = count(array_filter($lines, fn (string $line): bool => $line === self::TITLE));
        if ($titles === 0) {
            $defects[] = 'the file carries no `'.self::TITLE.'` line at all — the title was consumed by the text glued to it';
        } elseif ($titles > 1) {
            $defects[] = "the file carries {$titles} `".self::TITLE.'` lines — an entry was written above the original';
        }

        return $defects;
    }
}
