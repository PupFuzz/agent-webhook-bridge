<?php

namespace Tests\Feature\Workflows;

use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Support\CardTokenGrammar;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash out of `.github/workflows/pr-title-lint.yml` — each step's
 * `run:` block is extracted from the YAML and driven under `bash`, so this cannot
 * drift from what CI actually runs the way a re-implementation of the predicate would.
 *
 * WHAT THIS FILE HOLDS, stated at the strength it actually has (card#5267). The lint
 * is a SECOND implementation of the card-token grammar, in a second language. Two
 * kinds of assertion live here and they are not equally strong:
 *
 *  - TIED (the warn step's `good=` regex). Its accepted/rejected ANSWER SET is
 *    compared against {@see CardTokenGrammar}'s answer set over the SAME vectors,
 *    both computed at run time. Neither side carries a copy of the other's verdicts,
 *    so a change to either — the PHP pattern or the YAML regex — reds this file.
 *    Before card#5267 the lockstep was a comment in the YAML and a claim in this
 *    docblock; the tests were a hand-written snapshot that stayed green when the
 *    grammar moved, which is exactly how the near-miss WARN string spent two
 *    releases naming a narrower accept-set than the code enforced.
 *  - PINNED (the require step, `card[-#]?<id>`). It DISAGREES with the grammar on
 *    three measured shapes and is deliberately not fixed — changing what a CI gate
 *    accepts or rejects is a hard gate. The divergence is characterized below so it
 *    cannot drift unobserved, and is tracked as card#5300.
 *
 * WHY THIS FILE EXISTS AT ALL. The lint's grammar has been edited three times (DL-201
 * widened `card[-#]`, DL-233 made the separator optional, DL-234 added the near-miss
 * warn) and each round's matrix was driven ad-hoc during review and then thrown away —
 * so every later edit re-derived correctness by reading.
 */
class PrTitleLintTest extends TestCase
{
    /**
     * The vectors on which the REQUIRE step is known to disagree with the grammar
     * and is pinned rather than fixed. Every entry must actually diverge — the
     * divergence test drives this same list, so a stale exemption reds instead of
     * silently excusing a vector from the agreement check.
     */
    private const REQUIRE_STEP_DIVERGENCES = ['card4'];

    /** Extract one step's `run:` script from the workflow by name prefix. */
    private function stepScript(string $namePrefix): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/pr-title-lint.yml'));
        foreach ($wf['jobs']['lint-title']['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), $namePrefix)) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash');

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '{$namePrefix}' in pr-title-lint.yml");
    }

    /**
     * Pull one `<name>='<regex>'` assignment out of a step's real script. Absence is
     * a LOUD failure, not a skip: if the assignment is gone or renamed, the tie to
     * the grammar is broken, and a test that quietly stopped comparing would report
     * that as health.
     */
    private function stepRegex(string $stepNamePrefix, string $name): string
    {
        $script = $this->stepScript($stepNamePrefix);
        if (preg_match('/^\s*'.preg_quote($name, '/')."='([^']*)'\s*$/m", $script, $m) !== 1) {
            $this->fail("no `{$name}=` assignment in the '{$stepNamePrefix}' step — the answer-set tie to CardTokenGrammar cannot be evaluated");
        }

        return $m[1];
    }

    /** Run one extracted regex under real bash + `grep -qE`, folded as the step folds. */
    private function grepMatches(string $regex, string $subject): bool
    {
        $pipeline = 'printf %s '.escapeshellarg($subject)
            ." | tr '[:upper:]' '[:lower:]' | grep -qE ".escapeshellarg($regex);
        $rc = 0;
        $out = [];
        exec('bash -c '.escapeshellarg($pipeline).' 2>/dev/null', $out, $rc);

        return $rc === 0;
    }

    /** Run a step with a given title/branch; return [exit code, combined output]. */
    private function runStep(string $namePrefix, string $title, string $branch): array
    {
        // No `.sh` suffix: appending one would name a DIFFERENT path than tempnam()
        // created, leaking the original empty file on every one of the ~150 runs a
        // full pass of this class makes. `bash <file>` does not care about the name.
        $script = $this->stepScript($namePrefix);
        $tmp = tempnam(sys_get_temp_dir(), 'lint');
        file_put_contents($tmp, $script);
        $rc = 0;
        $out = [];
        exec('TITLE='.escapeshellarg($title).' BRANCH='.escapeshellarg($branch)
            .' bash '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        @unlink($tmp);

        return [$rc, implode("\n", $out)];
    }

    private function runWarnStep(string $title, string $branch = 'f'): string
    {
        return $this->runStep('Warn on a card token', $title, $branch)[1];
    }

    private function warned(string $title, string $branch = 'f'): bool
    {
        return str_contains($this->runWarnStep($title, $branch), '::warning::');
    }

    /** The require step's exit code: 0 = the title carries a correlation token. */
    private function runRequireStep(string $title, string $branch): int
    {
        return $this->runStep('Require card#/DL token', $title, $branch)[0];
    }

    /**
     * The branch card-id a vector would be filed under: its own ASCII digits, or an
     * arbitrary id when it has none (`card#٣`) — the branch only has to reach the
     * step's `<type>/<card-id>-slug` gate, it is not part of the grammar under test.
     */
    private static function branchIdFor(string $vector): int
    {
        return preg_match('/\d+/', $vector, $m) === 1 ? (int) $m[0] : 123;
    }

    /**
     * The `DL-NNN` authority, read by reflection — deliberately, and ONLY for the
     * divergence pin below. A hand-copied regex here would be the very defect this
     * card closes (a remembered value that keeps passing after the thing it
     * describes moved); reflection at least reds when the const moves. It is a
     * private const because the DL token is a SECOND accept-set that has never been
     * given a public owner — the finding this card's other-accept-set audit
     * returned, filed as card#5308, not something to fix inside a
     * characterization test.
     */
    private function dlTokenPattern(): string
    {
        return (string) (new \ReflectionClassConstant(GitHubPrCardMoveClassifier::class, 'DL_TOKEN_PATTERN'))->getValue();
    }

    // ---------------------------------------------------------------------
    // TIED — the warn step's accepted grammar vs the authority
    // ---------------------------------------------------------------------

    /**
     * THE TIE. Two independent implementations, two answer sets, compared — never a
     * hand-written expectation on either side. Three renderings because the lint's
     * leading boundary is `(^|[^0-9a-z_])` where the grammar's is `\b`: the two must
     * agree at the start of the subject, mid-prose, and inside a branch ref.
     */
    public function test_the_warn_steps_accepted_grammar_and_the_authority_return_the_same_answer_set(): void
    {
        $good = $this->stepRegex('Warn on a card token', 'good');

        // An empty vector set would make every comparison below `[] === []`. The
        // tie must be incapable of passing by having measured nothing.
        $this->assertNotEmpty(CardTokenGrammar::accepted(), 'the tie needs accepted vectors to compare');
        $this->assertNotEmpty(CardTokenGrammar::rejected(), 'the tie needs rejected vectors to compare');

        foreach (['%s', 'fix a thing %s', 'feat/%s-slug'] as $shape) {
            $lintAccepts = $lintRejects = [];
            foreach (CardTokenGrammar::VECTORS as $vector) {
                if ($this->grepMatches($good, sprintf($shape, $vector))) {
                    $lintAccepts[] = $vector;
                } else {
                    $lintRejects[] = $vector;
                }
            }

            $this->assertSame(CardTokenGrammar::accepted(), $lintAccepts,
                "rendered as '{$shape}': the lint accepts a different set than the grammar does");
            $this->assertSame(CardTokenGrammar::rejected(), $lintRejects,
                "rendered as '{$shape}': the lint rejects a different set than the grammar does");
        }
    }

    /**
     * The composition `warn iff looks && ! good` is only coherent while `looks` is a
     * SUPERSET of the shapes the grammar classifies: a vector `looks` misses can
     * never warn, whatever the grammar says about it. Asserted over both halves —
     * the rejected half is the near-miss coverage, the accepted half proves `!good`
     * is the only thing suppressing the warning there.
     */
    public function test_the_looks_probe_recognises_every_shape_the_grammar_classifies(): void
    {
        $looks = $this->stepRegex('Warn on a card token', 'looks');

        foreach (CardTokenGrammar::VECTORS as $vector) {
            $this->assertTrue($this->grepMatches($looks, $vector),
                "'{$vector}' is invisible to the near-miss probe — it can never warn");
        }
    }

    /**
     * The whole step, end to end, on every vector: it warns on exactly the shapes
     * the grammar rejects. Derived from the vector set rather than listed, so a
     * shape added to the grammar is covered here the moment it lands — the ASCII
     * near-misses, the single-digit glued `card4` DL-233 created, and the Unicode
     * digit DL-231 made silent are all rows of this one loop now.
     */
    public function test_the_step_warns_on_exactly_the_shapes_the_grammar_rejects(): void
    {
        foreach (CardTokenGrammar::VECTORS as $vector) {
            $this->assertSame(
                CardTokenGrammar::parse($vector) === null,
                $this->warned("Fix a thing {$vector}"),
                "'{$vector}' must warn iff the grammar does not parse it"
            );
        }
    }

    /**
     * The operator-facing WARNING TEXT names the near-misses, and that list is a
     * restatement living in YAML where no PHP function can render it. Tie what can
     * be tied: every ASCII shape the grammar rejects must be NAMED in the message,
     * so a new near-miss cannot enter the grammar and leave the text behind. The
     * Unicode vector is excluded by construction — the message describes that class
     * ("non-ASCII digits") rather than printing a codepoint, which is the right call
     * for a human-facing string and is why this leg is scoped to the ASCII half.
     */
    public function test_the_warning_text_names_every_ascii_near_miss_the_grammar_rejects(): void
    {
        $message = $this->runWarnStep('Fix a thing card_123');
        $this->assertStringContainsString('::warning::', $message, 'positive control: the fixture must actually warn');
        $this->assertNotEmpty(CardTokenGrammar::rejected(), 'with nothing rejected this leg would assert nothing');

        foreach (CardTokenGrammar::rejected() as $vector) {
            if (preg_match('/^[\x20-\x7e]+$/', $vector) !== 1) {
                continue;   // covered by class, not by spelling — see the docblock
            }
            $this->assertStringContainsString($vector, $message,
                "the operator-facing warning must name the near-miss '{$vector}'");
        }
    }

    /**
     * Neither side may go case-sensitive alone: the grammar is `/i` and the step
     * folds its inputs with `tr`. Driven off the vector set so this holds for
     * spellings added later, not just the ones someone thought to type in caps.
     */
    public function test_case_is_folded_on_both_sides(): void
    {
        foreach (CardTokenGrammar::VECTORS as $vector) {
            $upper = mb_strtoupper($vector);
            $this->assertSame(
                CardTokenGrammar::parse($vector) !== null,
                CardTokenGrammar::parse($upper) !== null,
                "the grammar must classify '{$upper}' as it classifies '{$vector}'"
            );
            $this->assertSame(
                $this->warned("Fix a thing {$vector}"),
                $this->warned("Fix a thing {$upper}"),
                "the lint must classify '{$upper}' as it classifies '{$vector}'"
            );
        }
    }

    /**
     * The deliberate SILENCES, which are facts about the boundary rather than
     * members of the accept-set — DL-201 ruled prose ("supports card 2") stays
     * silent, and discard/wildcard are what the leading boundary class protects.
     * Tied to the grammar the same way: warn iff it does not parse... except these
     * must not warn AT ALL, which is the stronger claim, so it is asserted directly.
     */
    public function test_does_not_warn_on_prose_or_embedded_words(): void
    {
        foreach (['supports card 2 in prose', 'Refactor the discard4524 helper',
            'Use a wildcard-2 match', 'a discard-1 path', 'no token at all'] as $text) {
            $this->assertNull(CardTokenGrammar::parse($text), "'{$text}' must not parse");
            $this->assertFalse($this->warned($text), "'{$text}' must NOT warn");
        }
    }

    public function test_the_branch_is_examined_not_only_the_title(): void
    {
        // The classifier correlates on title OR head branch, so the lint must read both
        // or it goes blind on exactly the branch-carried tokens it was built for.
        $this->assertTrue($this->warned('a clean title', 'feat/card_3054-fix'));
        $this->assertFalse($this->warned('a clean title', 'card-5150-slug'));
    }

    public function test_the_warn_leg_never_fails_the_job(): void
    {
        // Severity is the decision: warning, not error. If this ever exits non-zero it
        // has silently become a merge gate.
        $three = "\u{0663}";
        [$rc] = $this->runStep('Warn on a card token', "card#{$three}", 'f');
        $this->assertSame(0, $rc, 'the near-miss leg must warn, never fail');
    }

    public function test_the_leg_is_unconditional_not_behind_the_branch_gate(): void
    {
        // The branch gate is WHY the residue exists — it skips `card-<id>-...` and every
        // non `<type>/<id>-slug` shape. A branch the gated step skips must still warn here.
        // `chore/docs` and `card-5150-...` are both branches that step exits early on.
        $this->assertTrue($this->warned('Fix a thing card_3054', 'chore/docs'));
        $this->assertTrue($this->warned('Fix a thing card_3054', 'wip/no-id'));
    }

    /**
     * THE LIMIT, asserted rather than discovered later: the predicate is whole-subject,
     * not per-token. If ANY accepted token is present across title+branch, nothing warns —
     * even if a second, malformed token sits beside it.
     *
     * That is the deliberate no-cry-wolf choice for a WARNING leg: a correlating token
     * means the card does move, so the PR is not in the silent-failure class this exists
     * to catch. The cost is real and bounded — an author who writes `card-5150` in the
     * branch and `card_3054` in the title gets no nudge that the second one is inert, and
     * card 3054 stays put. Making that warn needs per-token analysis (tokenize, then test
     * each), which is a bigger change than a warning leg justifies; the co-present-token
     * case that actually MOVES the wrong card is DL-218's conflict path, not this.
     */
    public function test_whole_subject_predicate_does_not_warn_when_another_token_correlates(): void
    {
        $this->assertFalse($this->warned('Fix a thing card_3054', 'card-5150-some-slug'));
        $this->assertFalse($this->warned('card-5150 and also card_3054'));
    }

    // ---------------------------------------------------------------------
    // PINNED — the require step, which diverges (card#5300, hard gate)
    // ---------------------------------------------------------------------

    /**
     * The require step is a THIRD implementation of the token (`card[-#]?<id>`,
     * bounded on both sides) and it is the one with teeth — it reds the PR. Where it
     * agrees with the grammar, that agreement is asserted per vector: the gate must
     * pass exactly the titles that will actually move the card. The one vector it
     * disagrees on is exempted by name and pinned below.
     */
    public function test_the_require_step_and_the_authority_agree_on_every_vector_but_the_pinned_ones(): void
    {
        foreach (CardTokenGrammar::VECTORS as $vector) {
            if (in_array($vector, self::REQUIRE_STEP_DIVERGENCES, true)) {
                continue;
            }
            $id = self::branchIdFor($vector);
            $title = "fix a thing {$vector}";
            $correlates = CardTokenGrammar::parse($title) === $id;

            $this->assertSame(
                $correlates,
                $this->runRequireStep($title, "fix/{$id}-slug") === 0,
                "the gate must pass '{$title}' iff the grammar correlates it to card {$id}"
            );
        }
    }

    /**
     * THE CHARACTERIZATION. Three measured disagreements between the require step and
     * the correlation grammar, PINNED and deliberately NOT fixed: repairing either
     * direction changes what a CI gate accepts or rejects, which is a hard gate the
     * user has not answered. Tracked as card#5300. Reachability on this repo today is
     * ~nil (board 8 ids are 4-digit, nothing emits leading zeros, DL is at 238) — which
     * is why shipping the pin is honest and shipping a silent fix would not be.
     *
     * If any assertion here goes RED the divergence has MOVED, and the pin — not the
     * gate — is what must be revisited first.
     */
    public function test_the_require_step_diverges_from_the_authority_on_three_shapes_pending_a_gate(): void
    {
        // (1) FALSE GREEN — `card[-#]?4` has no 2-digit floor, so the gate certifies a
        //     title that the grammar will never parse: the PR merges green and the card
        //     silently never moves, which is the exact failure the gate exists to stop.
        foreach (self::REQUIRE_STEP_DIVERGENCES as $vector) {
            $id = self::branchIdFor($vector);
            $title = "fix a thing {$vector}";
            $this->assertNull(CardTokenGrammar::parse($title), "'{$vector}' must still be a shape the grammar rejects");
            $this->assertSame(0, $this->runRequireStep($title, "fix/{$id}-slug"),
                "'{$vector}' must still pass the gate — this is the pinned FALSE GREEN");
        }

        // (2) FALSE RED — a leading-zero id. The grammar parses `card#0123` as card 123
        //     and would move it; the gate's literal `${card_id}` compare does not.
        $this->assertSame(123, CardTokenGrammar::parse('card#0123 fix'));
        $this->assertSame(1, $this->runRequireStep('card#0123 fix', 'fix/123-slug'));

        // (3) FALSE RED — a 5-digit DL. The classifier's DL token is `\d+` (unbounded);
        //     the gate's is `[0-9]{1,4}`. The 4-digit control is what makes the bound
        //     the demonstrated cause rather than an asserted one.
        $this->assertSame(1, preg_match($this->dlTokenPattern(), 'dl-12345 fix'),
            'the classifier parses a 5-digit DL');
        $this->assertSame(1, $this->runRequireStep('dl-12345 fix', 'fix/999-slug'),
            'the gate reds a title the classifier would correlate');
        $this->assertSame(0, $this->runRequireStep('dl-1234 fix', 'fix/999-slug'),
            'control: 4 digits pass, so the {1,4} bound is the cause');
    }

    public function test_the_require_step_skips_branches_with_no_card_id(): void
    {
        // The gate is why the warn leg exists: it exits 0 on every shape it cannot
        // read a card id out of, so a token that correlates to nothing sails through.
        foreach (['dependabot/composer/x-2.0', 'release/v0.71.0', 'card-5150-slug', 'chore/docs'] as $branch) {
            $this->assertSame(0, $this->runRequireStep('a title with no token at all', $branch),
                "branch '{$branch}' must be skipped, not failed");
        }
    }
}
