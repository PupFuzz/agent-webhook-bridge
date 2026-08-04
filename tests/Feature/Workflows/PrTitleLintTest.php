<?php

namespace Tests\Feature\Workflows;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\DlTokenGrammar;
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
 *  - PINNED (the require step, `card[-#]?<id>` and `dl-[0-9]{1,4}`). It DISAGREES
 *    with the grammars on MEASURED shapes and is deliberately not fixed — changing
 *    what a CI gate accepts or rejects is a hard gate. Each divergence is
 *    characterized below so it cannot drift unobserved; all are tracked as card#5300.
 *    The count deliberately lives in the tests rather than in this docblock, because
 *    a number here is one more copy nothing holds — and it has already been wrong
 *    once (see below).
 *
 * The DL half of all this arrived late: until card#5308 the DL token had no public
 * owner, so this file reached it by REFLECTION and compared exactly three shapes by
 * hand. {@see DlTokenGrammar} is that owner, and driving BOTH arms over their
 * authority's whole vector set — which is what having an owner made possible —
 * surfaced a further divergence on its first run. Hand-picked shapes are why it
 * took three grammar edits to find.
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

    /**
     * The same idea for the DL half (card#5308), split by SHAPE because the two
     * disagreements fail in opposite directions and one of them is conditional on
     * the runner. Each list is driven by its own pin below as well as by the
     * agreement test, so a stale exemption reds instead of silently excusing.
     *
     * FALSE RED: the gate's `[0-9]{1,4}` bound reds a DL the classifier parses.
     */
    private const REQUIRE_STEP_DL_FALSE_RED = ['DL-12345'];

    /**
     * FIXED (card#5300, user-approved): the DL arm's digit class was the RANGE
     * `[0-9]`, which bash resolves by COLLATION inside `[[ =~ ]]`, so under
     * `en_US.UTF-8` a Unicode-digit DL satisfied the gate while the classifier
     * (ASCII `\d`, no `/u` — DL-231) correlates it to nothing. The arm now
     * enumerates its digits and the vector reds in every locale.
     *
     * The vector stays named here because it is no longer an exemption but a
     * REGRESSION anchor: it is driven through the agreement loop like any other
     * vector, AND through the locale characterization below, which measures both
     * locales and mutates the real workflow back to the range to prove the
     * enumeration is what carries the answer.
     */
    private const REQUIRE_STEP_DL_UNICODE_DIGIT = ["DL-\u{0663}"];

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

    /**
     * Run one extracted regex under real bash + `grep -E` over MANY subjects in ONE
     * process, returning the subjects it matched, in corpus order. Batched because
     * the second tie below asks about ~200 of them: a process per subject measured
     * 3.0s against 0.02s batched. `grep` is line-oriented and so is the step — its
     * subject is `printf '%s\n%s' "$TITLE" "$BRANCH"` — so one line per subject is
     * exactly what CI evaluates. A subject carrying a newline would silently become
     * two and shift every later line number, so that is asserted, not assumed.
     *
     * @param  list<string>  $subjects
     * @return list<string>
     */
    private function grepMatchesAll(string $regex, array $subjects): array
    {
        $this->assertNotEmpty($subjects, 'a batched grep over nothing would report every regex as matching nothing');
        $this->assertSame([], array_values(array_filter($subjects, fn (string $s) => str_contains($s, "\n"))),
            'a batched subject must be one line — a newline in one shifts every later line number');

        $pipeline = 'printf '.escapeshellarg('%s\n').' '
            .implode(' ', array_map('escapeshellarg', $subjects))
            ." | tr '[:upper:]' '[:lower:]' | grep -nE ".escapeshellarg($regex);
        $rc = 0;
        $out = [];
        exec('bash -c '.escapeshellarg($pipeline).' 2>/dev/null', $out, $rc);

        $matched = [];
        foreach ($out as $line) {
            $matched[] = $subjects[((int) explode(':', $line, 2)[0]) - 1];
        }

        return $matched;
    }

    /** Run one extracted regex under real bash + `grep -E`, folded as the step folds. */
    private function grepMatches(string $regex, string $subject): bool
    {
        return $this->grepMatchesAll($regex, [$subject]) !== [];
    }

    /**
     * `card<c>123` and `cards<c>123` for every printable-ASCII `c` — a SUPERSET of
     * any single-character separator class the YAML's `looks` regex can spell, which
     * is what lets the tie below compare over both sides' domains without parsing
     * the YAML's bracket class. Parsing it was the alternative and is the wrong
     * failure mode for a behavioural tie: it reds on a cosmetic regex edit and says
     * nothing about what either engine answers.
     *
     * @return list<string>
     */
    private static function singleCharacterSeparatorVectors(): array
    {
        $vectors = [];
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $vectors[] = 'card'.chr($code).'123';
            $vectors[] = 'cards'.chr($code).'123';
        }

        return $vectors;
    }

    /**
     * Run a step with a given title/branch; return [exit code, combined output].
     * `$locale` pins `LC_ALL` for the run — needed because bash resolves a `[0-9]`
     * bracket expression by COLLATION, so the require step's answer on a
     * Unicode-digit token depends on the runner's locale (card#5308; pinned in the
     * characterization below). Null inherits the ambient locale, which is what every
     * other leg wants: measured across `C`, `C.UTF-8` and `en_US.UTF-8`, they return
     * identical answers, so only the legs that pass a locale are sensitive to it.
     */
    private function runStep(string $namePrefix, string $title, string $branch, ?string $locale = null): array
    {
        return $this->runScriptText($this->stepScript($namePrefix), $title, $branch, $locale);
    }

    /**
     * The exec half of {@see runStep}, split out so a leg can run a MUTATED copy of
     * a real step script (the locale characterization reverts the fix and re-runs
     * it) without the mutation having to be re-implemented as a hand-copied regex.
     *
     * @return array{0:int,1:string}
     */
    private function runScriptText(string $script, string $title, string $branch, ?string $locale = null): array
    {
        // No `.sh` suffix: appending one would name a DIFFERENT path than tempnam()
        // created, leaking the original empty file on every one of the ~150 runs a
        // full pass of this class makes. `bash <file>` does not care about the name.
        $tmp = tempnam(sys_get_temp_dir(), 'lint');
        file_put_contents($tmp, $script);
        $rc = 0;
        $out = [];
        exec(($locale === null ? '' : 'LC_ALL='.escapeshellarg($locale).' ')
            .'TITLE='.escapeshellarg($title).' BRANCH='.escapeshellarg($branch)
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
    private function runRequireStep(string $title, string $branch, ?string $locale = null): int
    {
        return $this->runStep('Require card#/DL token', $title, $branch, $locale)[0];
    }

    /** @return list<string> the locales installed on this box, as `locale -a` names them. */
    private static function availableLocales(): array
    {
        $out = [];
        exec('locale -a 2>/dev/null', $out);

        return array_values(array_map('trim', $out));
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
     * Every `DL-<alnum>` spelling in a piece of operator-facing text. A letter-run
     * standing in for the digits (`DL-NNNN`) necessarily ENCODES a digit count —
     * which is how this step spent three grammar edits telling operators the token
     * was four digits against an unbounded `\d+`. A DELIMITED placeholder
     * (`DL-<number>`) is not collected, because it asserts nothing about width.
     *
     * @return list<string>
     */
    private static function dlSpellings(string $text): array
    {
        preg_match_all('/\bDL-[a-z0-9]+/i', $text, $m);

        return $m[0];
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
     * THE SECOND TIE (DL-250). `looks` is a THIRD implementation of "does this text
     * appear to name a card", in a second language, and nothing compared it to the
     * RUNTIME probe — only to the vector set they both happen to cover, which says
     * nothing about a shape absent from that set. So `looks` could be widened to
     * catch `cards #123` and miss `cards#123` — the one-character shape of the
     * defect that started this — and every other leg here would stay green.
     *
     * Answer set to answer set, over the UNION of both sides' separator domains —
     * and the union is what makes the tie BIDIRECTIONAL. Driven off `probeVectors()`
     * alone it catches only a WIDENING: the corpus shrinks with the data, so a
     * separator REMOVED from `NEAR_MISS_SEPARATORS` is simply never asked of the bash
     * side and the tie stays green (measured). That is one corpus leaving every guard
     * blind together — the defect DL-250 exists to close, reproduced inside DL-250's
     * own guard. So the PHP side contributes `probeVectors()`, multi-character
     * members included, and the YAML side contributes every single-character
     * separator it could spell; a narrowing on either side now leaves the other
     * side's separator in the corpus and the answer sets diverge.
     *
     * BOUND. Single-character separators are exhaustive on both sides by
     * construction; multi-character ones are covered only as `NEAR_MISS_SEPARATORS`
     * declares them, so one added to the YAML class alone sits outside the corpus. A
     * two-character cross-product cannot close that: `cards-x123` would red on
     * correct code, because `looks` is legitimately LOOSER than the runtime probe (it
     * needs no digit after the separator, so `card-layout-rework` warns in CI and
     * stays out of the bridge log). Equality holds where a digit follows the
     * separator and nothing is non-ASCII, which is what this corpus is.
     */
    public function test_the_looks_predicate_and_the_runtime_probe_return_the_same_answer_set(): void
    {
        $looks = $this->stepRegex('Warn on a card token', 'looks');
        $corpus = array_values(array_unique(array_merge(
            CardTokenGrammar::probeVectors(),
            self::singleCharacterSeparatorVectors(),
            ['supports card 2 in prose', 'card 123', 'cards 123', 'discards 5 items',
                'wildcards 3 more', 'scorecard_2', 'no token at all'],
        )));

        $probeRecognises = array_values(array_filter($corpus,
            fn (string $text) => CardTokenGrammar::looksLikeCardToken($text)));
        $lintRecognises = $this->grepMatchesAll($looks, $corpus);

        $this->assertNotEmpty($probeRecognises, 'the tie needs recognised shapes to compare');
        $this->assertNotSame(count($corpus), count($probeRecognises),
            'the tie needs unrecognised shapes, or it is [all-true] === [all-true]');
        $this->assertSame($probeRecognises, $lintRecognises,
            "the lint's `looks` predicate and the runtime near-miss probe disagree about which shapes appear to name a card");
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
        //     the demonstrated cause rather than an asserted one. Read from the public
        //     owner since card#5308 — this used to reach a `private const` by reflection.
        foreach (self::REQUIRE_STEP_DL_FALSE_RED as $vector) {
            $this->assertNotNull(DlTokenGrammar::parse($vector), "'{$vector}' must still be a shape the grammar parses");
            $this->assertSame(1, $this->runRequireStep("fix a thing {$vector}", 'fix/999-slug'),
                "'{$vector}' must still red the gate — this is the pinned FALSE RED");
        }
        $this->assertSame(0, $this->runRequireStep('dl-1234 fix', 'fix/999-slug'),
            'control: 4 digits pass, so the {1,4} bound is the cause');
    }

    /**
     * The DL half of the require step is a SECOND implementation of the DL token,
     * in bash, and before card#5308 exactly three of its shapes were checked by
     * hand — so a further divergence could enter unobserved, and one already had
     * (the Unicode-digit row below, which this loop is what found — and which is
     * now fixed, so it is no longer exempt and rides this loop like any other
     * vector). Same treatment the card half got: drive the whole vector set through
     * the real gate and require agreement everywhere except the vectors pinned by
     * name.
     */
    public function test_the_require_steps_dl_arm_agrees_with_the_authority_but_the_pinned_ones(): void
    {
        $this->assertNotEmpty(DlTokenGrammar::accepted(), 'the comparison needs accepted vectors');
        $this->assertNotEmpty(DlTokenGrammar::rejected(), 'the comparison needs rejected vectors');

        $exempt = self::REQUIRE_STEP_DL_FALSE_RED;
        foreach (DlTokenGrammar::VECTORS as $vector) {
            if (in_array($vector, $exempt, true)) {
                continue;
            }
            // A branch id no DL vector can coincidentally satisfy the CARD arm with —
            // the card arm needs a literal `card`, which no DL vector carries — so the
            // step's verdict here is attributable to its DL arm alone.
            $title = "fix a thing {$vector}";

            $this->assertSame(
                DlTokenGrammar::parse($title) !== null,
                $this->runRequireStep($title, 'fix/999-slug') === 0,
                "the gate must pass '{$title}' iff the DL grammar parses it"
            );
        }
    }

    /**
     * THE FOURTH DIVERGENCE — found by the vector loop above the day it was written
     * (card#5308), three grammar edits of hand-picked shapes having never reached
     * it — and now FIXED (card#5300, the one row the user approved).
     *
     * A bracket RANGE inside bash's `[[ =~ ]]` is resolved by COLLATION, not by
     * codepoint, so under a UTF-8 collation locale U+0663 fell inside `[0-9]` and
     * the gate GREENED a title carrying a DL the classifier correlates to nothing —
     * the same FALSE GREEN shape as `card4`, and a breach of the fleet-wide
     * invariant DL-231 ratified ("any other engine implementing this grammar
     * matches ASCII digits only"). The gate is a second engine, and it did not.
     *
     * WHAT THIS LEG HAS TO PROVE is not "the vector reds" — it red under a C-family
     * locale before the fix too, so a same-locale assertion would have passed
     * throughout and measured nothing. It has to prove the ENUMERATION is what
     * carries the answer. So the real script is mutated back to the range and re-run:
     * the old spelling must GREEN where the shipped one REDS, under the same locale,
     * in the same process. That is the control pinned to the exact failure it guards
     * (and the mutation asserts it applied — a no-op `str_replace` would leave a
     * control that cannot fail).
     */
    public function test_the_require_steps_dl_arm_matches_ascii_digits_only_in_every_locale(): void
    {
        $utf8Collation = 'en_US.UTF-8';
        $available = in_array('en_US.utf8', self::availableLocales(), true);
        $script = $this->stepScript('Require card#/DL token');

        // The mutation: the shipped enumeration reverted to the pre-fix range. Its
        // application is asserted, so this control can never silently become a
        // second run of the unmutated script.
        $reverted = str_replace('dl-[0123456789]{1,4}', 'dl-[0-9]{1,4}', $script, $applied);
        $this->assertSame(1, $applied,
            'the DL arm no longer spells its digits as an enumerated set — the collation fix this leg guards is gone or renamed');

        foreach (self::REQUIRE_STEP_DL_UNICODE_DIGIT as $vector) {
            $title = "fix a thing {$vector}";
            $this->assertNull(DlTokenGrammar::parse($title),
                'the classifier correlates a Unicode-digit DL to NOTHING (DL-231) — that is what would make a green gate false');

            foreach (['C.UTF-8', $utf8Collation] as $locale) {
                if ($locale === $utf8Collation && ! $available) {
                    $this->markTestIncomplete("no {$utf8Collation} on this box — the collation half was NOT measured");
                }
                $this->assertSame(1, $this->runRequireStep($title, 'fix/999-slug', $locale),
                    "under {$locale} the gate must red a DL the grammar correlates to nothing");
            }

            // THE DISCRIMINATOR: same locale, same vector, only the digit class
            // differs. A run where this greens is what makes the two reds above
            // evidence rather than a locale that was never going to match.
            $this->assertSame(0, $this->runScriptText($reverted, $title, 'fix/999-slug', $utf8Collation)[0],
                "the pre-fix range GREENS under {$utf8Collation} — if this reds, the collation behaviour is gone and the "
                .'enumeration is no longer the thing being measured');
            $this->assertSame(1, $this->runScriptText($reverted, $title, 'fix/999-slug', 'C.UTF-8')[0],
                'and reds under C.UTF-8 — which is what makes the locale, not the pattern, the variable');

            // The warn step is the second control: same pattern class, different
            // engine. GNU grep's `[0-9]` is ASCII in every locale measured, so the
            // near-miss leg never had the defect — which is what identified bash's
            // engine as the cause rather than the bracket expression.
            $this->assertFalse($this->grepMatches('(^|[^0-9a-z_])dl-[0-9]{1,4}([^0-9]|$)', $title),
                'control: the same bracket range under grep -E does NOT match — the divergence was bash\'s engine');
        }
    }

    /**
     * THE SIBLINGS THE FIX ABOVE DELIBERATELY DID NOT TOUCH (canon #7, measured
     * during card#5300's build, appended to that card).
     *
     * The require step's REMAINING bracket expressions are locale-collation
     * sensitive too, but they are all NEGATED classes or the branch-shape test,
     * where a collation-wide range REDS a title the authority correlates instead of
     * greening one it does not. Narrowing them would make this gate MORE PERMISSIVE
     * — a hard gate the user has not answered — so they are measured here and left
     * alone. Every row is a FALSE RED: annoying, never silent.
     *
     * These are characterizations. A RED here means the divergence moved, and the
     * pin is what to revisit first — not the gate.
     */
    public function test_the_require_steps_negated_classes_are_still_collation_sensitive_pending_a_gate(): void
    {
        if (! in_array('en_US.utf8', self::availableLocales(), true)) {
            $this->markTestIncomplete('no en_US.UTF-8 on this box — the collation half of these rows was NOT measured');
        }

        // Each row: [title, branch, what the authority answers]. The authority is
        // ASSERTED, not assumed, because "false red" is a claim about both engines.
        $this->assertSame(4, CardTokenGrammar::parse('card-4'."\u{0663}"));
        $this->assertSame('DL-1234', DlTokenGrammar::parse('fix dl-1234'."\u{0663}"));
        $this->assertSame(44, CardTokenGrammar::parse("\u{e9}".'card-44'));

        $rows = [
            // trailing ([^0-9]|$), card arm — a Unicode digit after the id is not a
            // boundary under a collation locale, so the token stops being bounded.
            ['card-4'."\u{0663}", 'fix/4-slug'],
            // trailing ([^0-9]|$), DL arm — same shape, other grammar.
            ['fix dl-1234'."\u{0663}", 'fix/999-slug'],
            // leading (^|[^0-9a-z_]) — here the collation-wide range is `a-z`, not
            // the digits, which is why narrowing the digit class did not move it.
            ["\u{e9}".'card-44', 'fix/44-slug'],
        ];
        foreach ($rows as [$title, $branch]) {
            $this->assertSame(0, $this->runRequireStep($title, $branch, 'C.UTF-8'),
                "'{$title}' passes the gate under C.UTF-8, agreeing with the authority");
            $this->assertSame(1, $this->runRequireStep($title, $branch, 'en_US.UTF-8'),
                "'{$title}' is the pinned locale-dependent FALSE RED under en_US.UTF-8");
        }

        // The branch-shape test `^[a-z-]+/[0-9]+-` is the fourth site and differs in
        // KIND: it decides whether the step enforces at all, so under a collation
        // locale a Unicode-digit branch is enforced against (and reds) where a
        // C-family locale skips it entirely.
        $this->assertSame(0, $this->runRequireStep('a title with no token at all', 'fix/'."\u{0663}".'-slug', 'C.UTF-8'),
            'under C.UTF-8 the branch carries no card id and the step skips');
        $this->assertSame(1, $this->runRequireStep('a title with no token at all', 'fix/'."\u{0663}".'-slug', 'en_US.UTF-8'),
            'under en_US.UTF-8 the same branch reads as a card branch and is enforced — the step\'s SCOPE is locale-dependent');
    }

    /**
     * THE PROSE TIE, at the only strength available across the language boundary:
     * no PHP function can render into a YAML string, so what is tied is that the
     * step's operator-facing text never RESTATES the digit grammar. `DL-NNNN` —
     * the spelling this step carried for three grammar edits — encodes "four
     * digits" against an unbounded `\d+`; a delimited `DL-<number>` encodes
     * nothing and a real `DL-1234` is checkable, so both pass.
     */
    public function test_the_require_steps_operator_text_never_restates_the_dl_digit_grammar(): void
    {
        // Positive control FIRST: the collector must actually catch the historical
        // defect, or "no bad spellings in the real script" would be a measurement
        // that never happened.
        $historical = self::dlSpellings("Fix: add the relevant 'DL-NNNN' to the PR title.");
        $this->assertSame(['DL-NNNN'], $historical, 'the collector must see the spelling this leg exists to forbid');
        $this->assertNull(DlTokenGrammar::parse($historical[0]), 'and the grammar must reject it');

        foreach (self::dlSpellings($this->stepScript('Require card#/DL token')) as $spelling) {
            $this->assertNotNull(DlTokenGrammar::parse($spelling),
                "the step spells '{$spelling}' at operators: a letter-run standing in for the digits asserts a "
                .'digit count the grammar does not enforce — use a delimited placeholder or a real token');
        }
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
