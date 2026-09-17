<?php

namespace Tests\Feature\Workflows;

use Tests\Support\DocRefGateHarness;
use Tests\TestCase;

/**
 * The quoted-console-output rule in `bin/check-doc-refs.php` (DL-401), driven over synthetic repo
 * trees by the shared harness.
 *
 * WHAT THE RULE IS FOR. The operator docs hand-copy `bridge:check` output as sample blocks, and the
 * same copy has gone stale three times (card#8374; card#9251 / DL-393, twice). The defect is an
 * ABSENT severity marker, so no grep finds it — there is no inverted term to search for — and a
 * dedicated doc-sync audit over that very change returned CLEAN and missed one of the three.
 *
 * WHY THE VECTORS LOOK LIKE THIS. Every acceptance is paired with a rejection differing by ONE
 * property, on {@see DocRefCoverageClaimLintTest}'s reasoning: an acceptance with no discriminating
 * twin cannot be told apart from an inert harness. Two pairings carry more than that:
 *
 *   - THE ELISION PAIR. `…` is an exemption, and an exemption nothing can fail is a decoration that
 *     certifies whatever carries it. So the accepted elided quote is pinned against a quote elided
 *     the same way whose surviving text the corpus never printed, one whose segments are real but
 *     live in DIFFERENT capture lines, and one whose segments are real and in the WRONG ORDER.
 *   - THE FLOOR PAIR. The evidence floor is DERIVED from the corpus rather than typed into the
 *     script, so the proof of that is behavioural rather than a number quoted here: the identical
 *     doc that is refused against one corpus is ACCEPTED once the corpus gains a shorter finding
 *     line. A test asserting the figure would be the second copy of it.
 *
 * WHY SO MANY VECTORS RUN WITH NO CORPUS AT ALL. A tree with no captures is where this rule's
 * acceptances are strongest: any span the harvest READS reds there (the quote is unread, not
 * clean), so an acceptance over an empty corpus proves the span was never a subject — which a pass
 * against a full corpus cannot distinguish from a rule that scanned nothing.
 *
 * This file is markdown-free and is not itself scanned by the rule: its surface is `.md`, so the
 * sample lines quoted below are data, not subjects.
 */
class DocRefSampleLintTest extends TestCase
{
    use DocRefGateHarness;

    /** The rejection banner, so a red from one of the other three rules cannot satisfy a vector. */
    private const RULE_HEADER = '`bridge:check` output quoted in markdown';

    /** A stand-in golden capture: the oracle every vector here is read against. */
    private const CAPTURE = <<<'TXT'
        OK: database: connected
        OK: retention: on (delete >30d + null payloads >7d, every 86400s, 500 rows/pass)
        WARN: retention: 12345 rows, 11987 still carry a payload holding 894.0 MiB (share of the database NOT shown: MariaDB counts only the bytes InnoDB stores inline) · oldest row 12.4d old.
        exit: 0
        checks: 38
        TXT;

    /** The withheld-share shape the real docs carry: a deliberate elision of a clause too long to quote. */
    private const ELIDED = 'WARN: retention: 12345 rows, 11987 still carry a payload holding 894.0 MiB (share of the database NOT shown: …) · oldest row 12.4d old.';

    protected function tearDown(): void
    {
        $this->removeGateTrees();

        parent::tearDown();
    }

    /**
     * A tree carrying the capture corpus unless a vector deliberately withholds it.
     *
     * @param  array<string, string>  $docs
     * @return array<string, string>
     */
    private function tree(array $docs, ?string $capture = self::CAPTURE): array
    {
        if ($capture !== null) {
            $docs['tests/Fixtures/check-golden/vector.txt'] = $capture."\n";
        }

        return $docs;
    }

    /** `# Deployment` on line 1, a blank on 2, the fence on 3 — so the first sample sits on line 4. */
    private function fenced(string ...$lines): string
    {
        return "# Deployment\n\n```\n".implode("\n", $lines)."\n```\n";
    }

    /** One backticked span in a sentence — the form the third recurrence was written in. Line 3. */
    private function inline(string $text): string
    {
        return "# Deployment\n\nA healthy preflight prints `".$text."` before the summary.\n";
    }

    /** @param array<string, string> $files */
    private function assertGateRejects(array $files, string $at, string $why): string
    {
        $out = $this->assertRejected($files, $at, $why);

        $this->assertStringContainsString(self::RULE_HEADER, $out,
            "{$why}\nthe rejection must come from the sample rule, not one of the other three:\n{$out}");

        return $out;
    }

    /** THE CONTROL every acceptance below is read against. */
    public function test_the_empty_tree_control_passes(): void
    {
        [$rc, $out] = $this->runGate([]);

        $this->assertSame(0, $rc, "the empty-tree control must pass — every vector here is read against it:\n{$out}");
        $this->assertStringContainsString('doc-refs: every `bridge:check` finding line quoted in markdown', $out,
            "the run must report what this rule measured even when it measured nothing:\n{$out}");
    }

    public function test_a_quoted_finding_line_the_corpus_prints_is_accepted_and_one_character_of_drift_is_not(): void
    {
        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connected')]),
            'a fenced sample the corpus prints verbatim is the state the docs are supposed to be in');

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connectee')]),
            'CLAUDE_DEPLOYMENT.md:4',
            'the witness, and the drift this rule exists for: ONE character the corpus never printed');
    }

    /**
     * The third recurrence was written INLINE, not in a fence — a rule reading fences alone would
     * have watched that instance land.
     */
    public function test_a_backticked_span_is_read_as_well_as_a_fenced_line(): void
    {
        $this->assertAccepted($this->tree(['docs/CHECK-REGISTRY-PLAN.md' => $this->inline('OK: database: connected')]),
            'a backticked quote of a real line is as correct as a fenced one');

        $this->assertGateRejects($this->tree(['docs/CHECK-REGISTRY-PLAN.md' => $this->inline('OK: database: connectee')]),
            'docs/CHECK-REGISTRY-PLAN.md:3',
            'the witness: the same drift inside a backticked span must red, or the inline form is unread');
    }

    /**
     * ⭐ THE EXEMPTION, AND THE CONTROL THAT STOPS IT BEING A DECORATION. A wholesale skip of any
     * line carrying `…` would let one ellipsis silence this rule forever.
     */
    public function test_an_elided_quote_is_accepted_and_drift_outside_the_elision_is_not(): void
    {
        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced(self::ELIDED)]),
            'the deliberate elision of a clause too long to quote is the treatment this rule must preserve');

        $this->assertGateRejects(
            $this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced(str_replace('894.0', '894.5', self::ELIDED))]),
            'CLAUDE_DEPLOYMENT.md:4',
            'the witness: an elided quote is checked on every character that SURVIVES the elision');
    }

    /** Segments that are each real but live in different capture lines assemble a line no run printed. */
    public function test_an_elision_may_not_gather_its_text_from_two_different_capture_lines(): void
    {
        $this->assertGateRejects(
            $this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connected … every 86400s, 500 rows/pass)')]),
            'CLAUDE_DEPLOYMENT.md:4',
            'both halves are in the corpus and no single line holds both — the quote is of a line nothing printed');
    }

    /** Order is part of the claim: the same words in the wrong sequence are a different line. */
    public function test_an_elision_may_not_reorder_the_line(): void
    {
        $this->assertGateRejects(
            $this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: retention: on (every 86400s, … delete >30d + null payloads >7d)')]),
            'CLAUDE_DEPLOYMENT.md:4',
            'every segment is real and the capture line carries them in the opposite order');
    }

    /**
     * ⭐ THE FLOOR IS DERIVED FROM THE CORPUS, PROVEN BEHAVIOURALLY. The same doc is refused against
     * one corpus and accepted once the corpus gains a shorter finding line — so the floor cannot be
     * a number typed beside the rule, and this test does not quote one either.
     */
    public function test_an_elision_that_swallows_the_message_is_refused_and_the_floor_moves_with_the_corpus(): void
    {
        $swallowed = $this->fenced('OK: retention: …');

        $out = $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $swallowed]),
            'CLAUDE_DEPLOYMENT.md:4',
            'an elision that leaves less text than the least informative real line is evidence of nothing');
        $this->assertStringContainsString('shortest finding line in the corpus carries', $out,
            "the refusal must say what the floor IS derived from, not merely that one was crossed:\n{$out}");

        $this->assertAccepted(
            $this->tree(['CLAUDE_DEPLOYMENT.md' => $swallowed], self::CAPTURE."\nOK: up: yes"),
            'the witness: the identical quote passes once the corpus itself holds a shorter finding line — '
            .'the floor is re-derived per run rather than written into the rule'
        );
    }

    /**
     * ⭐ RECURRENCE 2'S SHAPE, AND IT IS INVISIBLE TO THE MARKER-KEYED LEG BY CONSTRUCTION: a line
     * that dropped its severity marker is not a finding line, so a rule harvesting on the marker
     * cannot see the defect that removed it.
     */
    public function test_a_finding_message_quoted_without_its_severity_marker_is_refused(): void
    {
        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('database: connected')]),
            'CLAUDE_DEPLOYMENT.md:3',
            'the command prints the marker as part of the line, so a quote without it is a copy of output that never existed');

        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('OK: database: connected')]),
            'the witness: the identical message WITH its marker is the correct quote');

        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('database: sqlite')]),
            'a backticked span that is not a finding message at all is ordinary prose vocabulary');
    }

    /**
     * Severity VOCABULARY, which the docs quote constantly (`OK: ` beside `WARN: `), is not a
     * sample. The empty corpus is what proves it: a harvested span would red there.
     */
    public function test_a_bare_severity_marker_is_vocabulary_and_not_a_quote(): void
    {
        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('OK: ')], null),
            'a marker with no message is the vocabulary the docs use to name a severity');

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('OK: x')], null),
            'CLAUDE_DEPLOYMENT.md:3',
            'the witness: a marker WITH a message is harvested, and over an empty corpus it must red');
    }

    /** The summary lines of a real run are not findings, and the empty corpus proves they are unread. */
    public function test_a_non_finding_line_in_a_fence_is_not_a_quote(): void
    {
        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('exit: 0', 'checks: 38')], null),
            'the summary lines carry no severity marker and are not finding lines');

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: 0')], null),
            'CLAUDE_DEPLOYMENT.md:4',
            'the witness: a fenced line that IS a finding line must be harvested from the same fence');
    }

    /** Output is discussed in sentences constantly; admitting prose would red on every paraphrase. */
    public function test_prose_outside_a_fence_and_outside_backticks_is_not_a_quote(): void
    {
        $this->assertAccepted(
            $this->tree(['CLAUDE_DEPLOYMENT.md' => "# Deployment\n\nA healthy run says OK: database: connectee and moves on.\n"], null),
            'an unquoted sentence is a paraphrase, not a copy of the output');

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->inline('OK: database: connectee')], null),
            'CLAUDE_DEPLOYMENT.md:3',
            'the witness: the identical text inside backticks is a quote and is read');
    }

    /**
     * ⭐ THE ANTI-VACUITY CONTROL FOR THE WHOLE RULE. A missing corpus is the one state in which
     * every quote would silently pass, and it is reachable by renaming a directory.
     */
    public function test_a_quote_with_no_corpus_to_read_it_against_is_refused_rather_than_passed(): void
    {
        $out = $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connected')], null),
            'CLAUDE_DEPLOYMENT.md:4',
            'with no captures to read, a quote is UNREAD — reporting it clean is the failure mode this rule is built against');
        $this->assertStringContainsString('tests/Fixtures/check-golden/*.txt', $out,
            "the refusal must name the corpus it could not find:\n{$out}");

        $this->assertAccepted($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connected')]),
            'the witness: the identical doc passes once the corpus is there');
    }

    /**
     * Append-only history records what a version printed. Greening it would mean editing a frozen
     * entry to suit a live rule — rule 2's precedent, and its reason.
     */
    public function test_frozen_history_is_outside_the_rule_and_a_live_doc_is_not(): void
    {
        $quote = $this->inline('OK: database: connectee');

        [$rc, $out] = $this->runGate($this->tree(['CLAUDE_DECISIONS.md' => $quote]));

        $this->assertSame(0, $rc, "a quote in append-only history is outside this rule:\n{$out}");
        $this->assertStringContainsString('are unread by it', $out,
            "a run that accepts the decision log must SAY it never read it — a bare pass reads as a clean bill:\n{$out}");

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $quote]),
            'CLAUDE_DEPLOYMENT.md:3',
            'the witness: the identical quote in a live doc must red, or the acceptance above proves only that the vector ran');
    }

    /** The surface is markdown; a sample quoted in source is outside it, and the twin proves it. */
    public function test_a_drifted_quote_in_a_non_markdown_file_is_outside_the_rule(): void
    {
        $this->assertAccepted(
            $this->tree(['app/Bridge/Support/VectorSample.php' => "<?php\n\n// OK: database: connectee\n"]),
            'this rule reads markdown; a quote in source is deliberately not a subject');

        $this->assertGateRejects($this->tree(['CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connectee')]),
            'CLAUDE_DEPLOYMENT.md:4',
            'the witness: the identical text in markdown must red');
    }

    /**
     * THE RUN NAMES THE POPULATION IT MEASURED, because the card requires a clean run to state what
     * it covered rather than imply it — and because this rule exists after a clean audit was read
     * as covering quotes it never opened.
     *
     * The roots are compared against the script's OWN `scannedRoots()` literal by SET EQUALITY: a
     * contains-each loop only catches understatement, and a line naming a root the scan never walks
     * overstates the coverage in the same direction as the defect.
     */
    public function test_the_run_names_the_population_it_measured(): void
    {
        [$rc, $out] = $this->runGate($this->tree([
            'CLAUDE_DEPLOYMENT.md' => $this->fenced('OK: database: connected', self::ELIDED),
        ]));

        $this->assertSame(0, $rc, "both quotes are real, so the run must be clean:\n{$out}");
        $this->assertStringContainsString('2 quote(s) in 1 of the 1 markdown file(s)', $out,
            "the run must count the quotes and the files it read them from:\n{$out}");
        $this->assertStringContainsString('1 of them carrying the `…` elision', $out,
            "the run must say how much of its population it matched through the exemption:\n{$out}");

        $script = (string) file_get_contents(base_path('bin/check-doc-refs.php'));
        $this->assertSame(1, preg_match('/function scannedRoots\(\): array\s*\{\s*return \[(.+?)\];/s', $script, $m),
            'the roots must be readable from the script, or this test is asserting nothing');
        $roots = array_map(
            static fn (string $root): string => trim(trim($root), "'"),
            explode(',', trim($m[1]))
        );

        $this->assertSame(1, preg_match('/markdown file\(s\) under (.+?) and the root \*\.md/', $out, $printed),
            "the run must name the roots it walked in the form this test reads:\n{$out}");
        $this->assertSame($roots, explode(', ', $printed[1]),
            'the printed roots must be the scanned set EXACTLY — a missing root understates the coverage, '
            ."an extra one overstates it, and only set equality catches both:\n{$out}");
    }
}
