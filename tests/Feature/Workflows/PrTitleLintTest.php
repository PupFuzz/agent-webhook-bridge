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
     * FALSE GREEN, and only under some locales: bash resolves `[0-9]` in `[[ =~ ]]`
     * by COLLATION, so under `en_US.UTF-8` a Unicode-digit DL satisfies the gate
     * while the classifier (ASCII `\d`, no `/u` — DL-231) correlates it to nothing.
     * Under `C` / `C.UTF-8` / `POSIX` the gate reds it and agrees. GNU `grep -E`
     * does NOT do this in any of those locales, which is why the warn step is
     * unaffected — the engine is the cause, not the pattern.
     */
    private const REQUIRE_STEP_DL_LOCALE_DEPENDENT = ["DL-\u{0663}"];

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
        // No `.sh` suffix: appending one would name a DIFFERENT path than tempnam()
        // created, leaking the original empty file on every one of the ~150 runs a
        // full pass of this class makes. `bash <file>` does not care about the name.
        $script = $this->stepScript($namePrefix);
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
     * (see the locale characterization below, which this loop is what found). Same
     * treatment the card half got: drive the whole vector set through the real gate
     * and require agreement everywhere except the vectors pinned by name.
     */
    public function test_the_require_steps_dl_arm_agrees_with_the_authority_but_the_pinned_ones(): void
    {
        $this->assertNotEmpty(DlTokenGrammar::accepted(), 'the comparison needs accepted vectors');
        $this->assertNotEmpty(DlTokenGrammar::rejected(), 'the comparison needs rejected vectors');

        $exempt = array_merge(self::REQUIRE_STEP_DL_FALSE_RED, self::REQUIRE_STEP_DL_LOCALE_DEPENDENT);
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
     * THE FOURTH DIVERGENCE, found by the vector loop above the day it was written
     * (card#5308) — three grammar edits of hand-picked shapes had never reached it.
     *
     * `[0-9]` inside bash's `[[ =~ ]]` is a COLLATION range, not an ASCII one, so
     * under a UTF-8 collation locale U+0663 falls inside it and the gate GREENS a
     * title carrying a DL the classifier correlates to nothing — the same FALSE
     * GREEN shape as `card4`, and precisely the fleet-wide invariant DL-231
     * ratified ("any other engine implementing this grammar matches ASCII digits
     * only"). The gate is a second engine, and it does not.
     *
     * PINNED, not fixed: repairing it means changing what the CI gate accepts, the
     * hard gate card#5300 owns. Both locale answers are measured here so the pin is
     * a measurement rather than a claim, and so the C-family half proves the vector
     * is otherwise ordinary.
     */
    public function test_the_require_steps_digit_class_is_a_locale_collation_range_unlike_the_authority(): void
    {
        $utf8Collation = 'en_US.UTF-8';
        $available = in_array('en_US.utf8', self::availableLocales(), true);

        foreach (self::REQUIRE_STEP_DL_LOCALE_DEPENDENT as $vector) {
            $title = "fix a thing {$vector}";
            $this->assertNull(DlTokenGrammar::parse($title),
                'the classifier correlates a Unicode-digit DL to NOTHING (DL-231) — that is what makes a green gate false');

            $this->assertSame(1, $this->runRequireStep($title, 'fix/999-slug', 'C.UTF-8'),
                'under a C-family locale the gate reds it and agrees with the grammar');

            if (! $available) {
                $this->markTestIncomplete("no {$utf8Collation} on this box — the FALSE-GREEN half was NOT measured");
            }
            $this->assertSame(0, $this->runRequireStep($title, 'fix/999-slug', $utf8Collation),
                "under {$utf8Collation} the gate PASSES a title that will never move a card — the pinned FALSE GREEN");

            // The warn step is the control: same pattern class, different engine.
            // GNU grep's `[0-9]` is ASCII in every locale measured, so the near-miss
            // leg is unaffected — which is what identifies bash as the cause.
            $this->assertFalse($this->grepMatches('(^|[^0-9a-z_])dl-[0-9]{1,4}([^0-9]|$)', $title),
                'control: the same bracket range under grep -E does NOT match — the divergence is bash\'s engine');
        }
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
