<?php

namespace Tests\Feature\Workflows;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\DlTokenGrammar;
use App\Bridge\Writeback\PrOutcome;
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
 *  - TIED (the warn step's `good=` and `good_dl=` regexes — one arm per correlation
 *    token since card#5961). Each arm's accepted/rejected ANSWER SET is compared over
 *    the SAME vectors against its own authority — {@see CardTokenGrammar}'s and
 *    {@see DlTokenGrammar}'s respectively — both computed at run time. Neither side
 *    carries a copy of the other's verdicts, so a change to either — a PHP pattern or
 *    a YAML regex — reds this file.
 *    Before card#5267 the lockstep was a comment in the YAML and a claim in this
 *    docblock; the tests were a hand-written snapshot that stayed green when the
 *    grammar moved, which is exactly how the near-miss WARN string spent two
 *    releases naming a narrower accept-set than the code enforced.
 *  - PINNED (the require step's card arm and its four-digit-bounded DL arm — the
 *    spellings are not quoted here, because a quoted pattern is the restatement
 *    this file exists to catch, and two copies of it had already gone stale by
 *    DL-272). It DISAGREES with the grammars on MEASURED shapes and is deliberately
 *    not fixed by default — changing what a CI gate accepts or rejects is a hard
 *    gate. ONE row has been approved and fixed (DL-272: the DL arm now enumerates
 *    its digits, so bash's collation cannot admit non-ASCII ones); the rest stay
 *    pinned. Each divergence is characterized below so it cannot drift unobserved;
 *    all are tracked as card#5300.
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
     * FALSE RED: the gate's four-digit bound reds a DL the classifier parses.
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

    /**
     * WHICH BRANCHES THE REQUIRE STEP INSPECTS AT ALL — the population card#6822
     * measured, plus the shapes that bound it. Every row carries the id the step
     * must EXTRACT, not merely the fact that it fired: a predicate widened without
     * its extraction reds the PR and then names an id that exists nowhere
     * (`card6371`, or the whole branch name), which is a worse gate than the skip
     * it replaced, and a row asserting only "it fired" cannot see that.
     *
     * The enforced and evade rows below are real head branches of MERGED PRs of this
     * repo, not invented spellings — which is the property that makes them evidence,
     * and it is the only claim about the population made HERE. The measured split
     * (how many PRs each shape covered) lives in the card#6822 CHANGELOG entry: a
     * figure restated in a comment no assertion re-derives is the copy nothing
     * holds, and this file already says exactly that about the token grammar.
     *
     * The `skip:no-id` rows split into two kinds, and the difference is stated
     * because it was MEASURED rather than assumed. `fix/2fa-enrollment`,
     * `feat/oauth2-login` and `fix/cards-123-thing` carry ASCII digits, so a widening
     * of the predicate reaches them and they are genuine negative controls — the
     * axis leg below drives each against the mutation that actually flips it.
     * `chore/docs`, `wip/no-id`, `fix/decouple-check-name-from-runtime`,
     * `fix/card-token-grammar` and `docs-changelog-card-token-relocation-upgrading`
     * carry no digits at all and therefore cannot flip under any of those mutations:
     * they are population coverage here and are NOT claimed as controls anywhere.
     * `docs/orientation-v0.35.0` is the near-miss of that split — it has digits, but
     * none followed by a hyphen, so neither axis reaches it either.
     *
     * The `skip:exempt` rows are SYNTHETIC on purpose. Every real exempt branch in
     * the corpus (`dependabot/composer/dev/...`, `release/v0.74.0`,
     * `sync/main-to-dev-post-v0.74.0`) fails the card-id predicate anyway, so it
     * would pass this leg with the `case` block deleted and prove nothing about the
     * exemption. These three are built to satisfy the predicate, so only the `case`
     * block can skip them.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const BRANCH_VERDICTS = [
        // ENFORCED BEFORE AND AFTER — card#6822's stated control that the arm fires.
        ['fix/6100-changelog-gate-scope', '6100', 'the type-segment shape the gate always reached'],
        ['fix/6101-changelog-gate-quotepath', '6101', 'ditto'],
        ['fix/6056-changelog-gate-workflows-scope', '6056', 'ditto'],
        ['ci/5910-release-artifacts-gate', '5910', 'a two-letter type segment'],
        ['docsync/5952-audit-rewords', '5952', 'a type segment carrying no hyphen'],

        // EVADE-A — no type segment. Skipped green before card#6822.
        ['card-5538-drop-redundant-is-link', '5538', 'separated `card-` prefix, no type segment'],
        ['card6027-nearmiss-card-token-guard', '6027', 'glued `card` prefix, no type segment'],
        ['card5698-channel-token-fault', '5698', 'glued, and the slug repeats no id'],
        ['card-5721-bodyless-actor-attribution', '5721', 'separated'],

        // EVADE-B — a type segment IS present; only the literal `card` before the
        // digits put these outside the old regex. The surprising half: they read as
        // compliant, which is why the drift went unnoticed for as long as it did.
        ['fix/card6371-stage-aware-coord-card-create', '6371', 'type segment + glued prefix'],
        ['chore/card-5913-hard-gate-de-enumeration', '5913', 'type segment + separated prefix'],
        ['docs/card5584-http-fake-stacking-gotcha', '5584', 'type segment + glued prefix'],
        ['fix/card-6034-commonmark-bump', '6034', 'type segment + separated prefix'],

        // CASE. The step folds its inputs (#4384) and the pattern is lowercase, so a
        // shouted branch must extract the same id. Without the fold this row skips.
        ['FIX/CARD6371-Stage-Aware-Coord-Card-Create', '6371', 'the fold is what makes an upper-case branch reachable'],
        ['Card-5538-Drop-Redundant-Is-Link', '5538', 'and on the segment-less shape too'],

        // GENUINELY CARD-LESS — must still skip. Negative controls; see the docblock.
        ['chore/docs', 'skip:no-id', 'a type segment and no digits anywhere'],
        ['wip/no-id', 'skip:no-id', 'ditto'],
        ['fix/decouple-check-name-from-runtime', 'skip:no-id', 'a real card-less merged branch'],
        ['docs-changelog-card-token-relocation-upgrading', 'skip:no-id', 'real, carries `card`, carries no id'],
        ['fix/card-token-grammar', 'skip:no-id', 'the `card-` prefix with a WORD after it, not an id'],
        ['fix/cards-123-thing', 'skip:no-id', 'the plural names no card in any spelling'],
        ['feat/oauth2-login', 'skip:no-id', 'digits glued into a word, no hyphen after'],
        ['fix/2fa-enrollment', 'skip:no-id', 'a segment OPENING with digits, no hyphen after'],
        ['docs/orientation-v0.35.0', 'skip:no-id', 'a version, and the digits do not open the segment'],

        // DISCLOSED FALSE RED, characterized so it is observed rather than found.
        // With both prefixes absent the accepted shape is a bare leading `<digits>-`,
        // which a date-named branch satisfies. Loud, never silent; no such branch
        // exists in the measured corpus, and neither does the bare `5538-slug` the
        // same cell admits, so this is a deliberate lean toward the loud failure.
        ['2026-08-18-release-notes', '2026', 'DISCLOSED: a date-named branch reads as card 2026'],
        ['5538-bare-id-slug', '5538', 'the other side of the same cell — a bare id IS enforced'],

        // EXEMPT — synthetic, and built to satisfy the predicate. See the docblock.
        ['release/6822-hotfix-slug', 'skip:exempt', 'release PRs consume the log, they do not name a card'],
        ['sync/6822-backport-slug', 'skip:exempt', 'ditto'],
        ['dependabot/6822-bump-slug', 'skip:exempt', 'automation carries no card'],
    ];

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
    private function grepMatchesAll(string $regex, array $subjects, ?string $locale = null): array
    {
        $this->assertNotEmpty($subjects, 'a batched grep over nothing would report every regex as matching nothing');
        $this->assertSame([], array_values(array_filter($subjects, fn (string $s) => str_contains($s, "\n"))),
            'a batched subject must be one line — a newline in one shifts every later line number');

        $pipeline = 'printf '.escapeshellarg('%s\n').' '
            .implode(' ', array_map('escapeshellarg', $subjects))
            ." | tr '[:upper:]' '[:lower:]' | grep -nE ".escapeshellarg($regex);
        $rc = 0;
        $out = [];
        // `$locale` pins LC_ALL for the same reason {@see runStep()} takes one, and on
        // a second class: grep resolves a `[[:space:]]` bracket expression from locale
        // data, so a leg asking whether the lint and the grammar agree on separators
        // is asking a question with a different answer per locale. Null inherits, which
        // is what every leg over an ASCII corpus wants.
        exec(($locale === null ? '' : 'LC_ALL='.escapeshellarg($locale).' ')
            .'bash -c '.escapeshellarg($pipeline).' 2>/dev/null', $out, $rc);

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
     * The same construction on the OTHER stem (card#5961) — `DL<c>239` and
     * `DLs<c>239` for every printable-ASCII `c`. Not folded into one parameterized
     * helper with the card one: the two feed different ties over different
     * authorities, and a shared helper would only be shorter, not more tied.
     *
     * @return list<string>
     */
    private static function singleCharacterSeparatorDlVectors(): array
    {
        $vectors = [];
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $vectors[] = 'DL'.chr($code).'239';
            $vectors[] = 'DLs'.chr($code).'239';
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
    private function runStep(string $namePrefix, string $title, string $branch, ?string $locale = null, string $base = 'dev'): array
    {
        return $this->runScriptText($this->stepScript($namePrefix), $title, $branch, $locale, $base);
    }

    /**
     * The exec half of {@see runStep}, split out so a leg can run a MUTATED copy of
     * a real step script (the locale characterization reverts the fix and re-runs
     * it) without the mutation having to be re-implemented as a hand-copied regex.
     *
     * @return array{0:int,1:string}
     */
    private function runScriptText(string $script, string $title, string $branch, ?string $locale = null, string $base = 'dev'): array
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
            // Every step is run with a BASE in the environment even though only the
            // closure step reads one: `set -u` makes an absent variable a hard error,
            // so the harness must supply what the `env:` block supplies. The default
            // is the INTEGRATION base — the one every dev-targeted PR carries, and the
            // one under which the structural route is live.
            .' BASE='.escapeshellarg($base)
            .' bash '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        @unlink($tmp);

        return [$rc, implode("\n", $out)];
    }

    private function runWarnStep(string $title, string $branch = 'f'): string
    {
        return $this->runStep('Warn on a card or DL token', $title, $branch)[1];
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
     * step's card-id gate, it is not part of the grammar under test.
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

    /**
     * WHAT THE REQUIRE STEP DID WITH A BRANCH, read out of the step's OWN output:
     * the id it extracted, or which of its two skip arms it took. The id is never
     * re-derived here — a second parse in the test is the same duplication that
     * produced card#6822's trap, where the predicate and the extraction agreed on
     * exactly one shape and a widening of one silently desynchronized them.
     *
     * The title is deliberately token-free, so an enforced branch REDS and prints
     * the id it targeted. That is what makes the extracted value observable at all:
     * on a passing title the step prints the id too, but a wrong id could still
     * pass by matching a token that happens to be there.
     */
    private function requireStepVerdict(string $branch, ?string $script = null): string
    {
        $script ??= $this->stepScript('Require card#/DL token');
        [$rc, $out] = $this->runScriptText($script, 'a title with no token at all', $branch);

        if ($rc === 0) {
            if (str_contains($out, 'is exempt (automation/release/revert)')) {
                return 'skip:exempt';
            }
            if (str_contains($out, 'names no card id')) {
                return 'skip:no-id';
            }
            $this->fail("the step passed '{$branch}' on a token-free title without saying which arm skipped it:\n{$out}");
        }
        if (preg_match('/\btargets card (\S+),/', $out, $m) !== 1) {
            $this->fail("the step failed on '{$branch}' without naming the id it targeted — the id is unobservable:\n{$out}");
        }

        return $m[1];
    }

    /**
     * Apply one mutation to the REAL require-step script and assert it landed. A
     * no-op `str_replace` would leave a control that cannot fail, which is the
     * shape this file already guards against on the DL-272 locale leg.
     */
    private function mutatedRequireStep(string $from, string $to): string
    {
        $script = str_replace($from, $to, $this->stepScript('Require card#/DL token'), $applied);
        $this->assertSame(1, $applied,
            "the require step no longer contains '{$from}' — this control is measuring nothing");

        return $script;
    }

    /** The corpus rows whose expectation is the literal string `$verdict`. */
    private function branchRowsExpecting(string $verdict): array
    {
        return array_values(array_filter(self::BRANCH_VERDICTS, fn (array $r) => $r[1] === $verdict));
    }

    // ---------------------------------------------------------------------
    // THE TRIGGER LIST — whether the gate RUNS AT ALL
    // ---------------------------------------------------------------------

    /**
     * A RECOVERY property, which is a different question from every other leg in
     * this file. Those ask what the gate ANSWERS once it has run; this asks whether
     * it gets to run at all.
     *
     * `opened`/`reopened`/`edited` are the gate's INPUT events: the title and the
     * head branch name are all it reads, and `edited` is the only event a title fix
     * fires. `synchronize` is NOT an input event — a push provably cannot change
     * either input — and it is asserted here anyway, because GitHub creates NO
     * workflow runs at all for a PR whose merge ref it cannot compute, and the push
     * that clears the conflict IS the `synchronize`. Without it a PR opened while
     * conflicting never gets a first run and every recovery left is a human act —
     * fire `edited` or `reopened` by hand, with nothing prompting anyone to
     * (card#7597; measured on kanban-board PR #651, whose copy of this workflow
     * carried the identical trigger list). Deleting `synchronize` as a redundant run
     * is therefore a correct-in-isolation edit that reopens the hole, which is what
     * this leg exists to red.
     *
     * NOT CLAIMED: this reads the COMMITTED trigger list. It does not measure
     * GitHub's dispatch, and nothing in this suite can — see the workflow header for
     * the residual (a check that never ran still looks like one that passed).
     */
    public function test_the_trigger_list_keeps_both_the_input_events_and_the_recovery_event(): void
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/pr-title-lint.yml'));
        $types = $wf['on']['pull_request']['types'] ?? null;
        $this->assertIsArray($types, 'pr-title-lint.yml has no on.pull_request.types list to read');

        $required = [
            'opened' => 'the gate must run when the PR is first created',
            'reopened' => 'a reopened PR is a fresh merge decision and must be re-gated',
            'edited' => 'the title is an INPUT, and a title fix fires only `edited`',
            'synchronize' => 'RECOVERY: a PR opened while conflicting gets no runs at all, and the push that '
                .'clears the conflict is the only NON-HUMAN event left that can give this gate its first one '
                .'(card#7597)',
        ];

        foreach ($required as $type => $why) {
            $this->assertContains($type, $types, "on.pull_request.types has no '{$type}': {$why}");
        }
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
        $good = $this->stepRegex('Warn on a card or DL token', 'good');

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
        $looks = $this->stepRegex('Warn on a card or DL token', 'looks');

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
        $looks = $this->stepRegex('Warn on a card or DL token', 'looks');
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
        [$rc] = $this->runStep('Warn on a card or DL token', "card#{$three}", 'f');
        $this->assertSame(0, $rc, 'the near-miss leg must warn, never fail');
    }

    public function test_the_leg_is_unconditional_not_behind_the_branch_gate(): void
    {
        // The branch gate is WHY the residue exists — it skips every branch it can read
        // no card id out of. A branch the gated step skips must still warn here.
        // `chore/docs` and `wip/no-id` are both branches that step exits early on.
        // (`card-5150-...` used to be named here as a third; card#6822 made that
        // spelling ENFORCED, so it is no longer an example of what the gate skips.)
        $this->assertTrue($this->warned('Fix a thing card_3054', 'chore/docs'));
        $this->assertTrue($this->warned('Fix a thing card_3054', 'wip/no-id'));
    }

    /**
     * THE LIMIT, asserted rather than discovered later: each arm's predicate is
     * whole-subject WITHIN ITS OWN STEM, not per-token. An accepted card token
     * anywhere across title+branch keeps the CARD arm quiet even when a second,
     * malformed card token sits beside it — and only the same stem suppresses:
     * an accepted card token does not quiet the DL arm, nor an accepted DL the
     * card arm (both directions asserted below).
     *
     * The same-stem half is the deliberate no-cry-wolf choice for a WARNING leg:
     * a correlating token means that stem's channel works, so the PR is not in
     * the silent-failure class this exists to catch. The cost is real and
     * bounded — an author who writes `card-5150` in the branch and `card_3054`
     * in the title gets no nudge that the second one is inert, and card 3054
     * stays put. Making that warn needs per-token analysis (tokenize, then test
     * each), which is a bigger change than a warning leg justifies; the
     * co-present-token case that actually MOVES the wrong card is DL-218's
     * conflict path, not this.
     */
    public function test_suppression_is_whole_subject_within_a_stem_not_across_stems(): void
    {
        // Same stem suppresses, whole-subject:
        $this->assertFalse($this->warned('Fix a thing card_3054', 'card-5150-some-slug'));
        $this->assertFalse($this->warned('card-5150 and also card_3054'));

        // Cross-stem does not — asserted on each arm's own text, so the warning
        // is attributed structurally rather than by argument:
        $this->assertStringContainsString('names a DL',
            $this->runWarnStep('Fix card-3410 handling of DL_272', 'f'),
            'an accepted card token must not quiet the DL arm');
        $this->assertStringContainsString('names a card',
            $this->runWarnStep('DL-272 fixes card_3054', 'f'),
            'an accepted DL token must not quiet the card arm');
    }

    // ---------------------------------------------------------------------
    // TIED — the warn step's DL arm vs the authority (card#5961)
    // ---------------------------------------------------------------------

    /**
     * No DL vector may carry card-ish text, or `warned()` — which asks only whether
     * SOME `::warning::` was printed — could be answered by the card arm and every
     * DL leg below would be measuring the wrong regex. Derived rather than eyeballed,
     * because the corpus is allowed to grow.
     */
    private function assertDlVectorsCannotTripTheCardArm(): void
    {
        $this->assertSame([], array_values(array_filter(
            DlTokenGrammar::VECTORS,
            fn (string $v) => str_contains(mb_strtolower($v), 'card'),
        )), 'a DL vector carrying card-ish text would make the DL legs unattributable to the DL arm');
    }

    /**
     * THE DL TIE — the card arm's first tie, on the other stem. Two independent
     * implementations, two answer sets, compared; never a hand-written expectation
     * on either side, and the same three renderings, because the lint's leading
     * `(^|[^0-9a-z_])` and the grammar's `\b` must agree at the start of the
     * subject, mid-prose, and inside a branch ref.
     *
     * The arm exists because the DL stem was WORSE off than the card stem, not
     * merely uncovered (DL-273's stated bound): the runtime probe is ASCII by
     * ratification, so a Unicode-digit DL warns nowhere in the bridge log, and the
     * card stem survives that only because the CI arm names the class by name. With
     * no DL arm, `DL-<U+0663>239` was silent on BOTH surfaces.
     */
    public function test_the_warn_steps_accepted_dl_grammar_and_the_authority_return_the_same_answer_set(): void
    {
        $goodDl = $this->stepRegex('Warn on a card or DL token', 'good_dl');

        $this->assertNotEmpty(DlTokenGrammar::accepted(), 'the tie needs accepted vectors to compare');
        $this->assertNotEmpty(DlTokenGrammar::rejected(), 'the tie needs rejected vectors to compare');

        foreach (['%s', 'fix a thing %s', 'feat/%s-slug'] as $shape) {
            $lintAccepts = $lintRejects = [];
            foreach (DlTokenGrammar::VECTORS as $vector) {
                if ($this->grepMatches($goodDl, sprintf($shape, $vector))) {
                    $lintAccepts[] = $vector;
                } else {
                    $lintRejects[] = $vector;
                }
            }

            $this->assertSame(DlTokenGrammar::accepted(), $lintAccepts,
                "rendered as '{$shape}': the lint's DL arm accepts a different set than the grammar does");
            $this->assertSame(DlTokenGrammar::rejected(), $lintRejects,
                "rendered as '{$shape}': the lint's DL arm rejects a different set than the grammar does");
        }
    }

    /**
     * THE SECOND DL TIE (DL-250(4) applied to the DL stem). `looks_dl` is a second
     * implementation of "does this text appear to name a DL", in a second language,
     * and the vector set alone would say nothing about a shape absent from it — so
     * it is compared to the RUNTIME probe, answer set to answer set, over the UNION
     * of both engines' separator domains.
     *
     * The union is what makes it BIDIRECTIONAL, and the reason is measured, not
     * reasoned (DL-250): driven off `probeVectors()` alone the corpus SHRINKS with
     * the data, so a separator removed from `NearMissProbe::SEPARATORS` is simply
     * never asked of the bash side. The PHP side therefore contributes
     * `probeVectors()` (multi-character members included) and the YAML side every
     * single-character separator it could spell.
     *
     * BOUND, inherited unchanged: `looks_dl` is legitimately LOOSER than the runtime
     * probe — it requires no digit after the separator, so `DL-` and `feat/dl-only`
     * warn in CI and stay out of the bridge log. Equality holds where a digit
     * follows the separator and nothing is non-ASCII, which is what this corpus is;
     * a two-character cross-product would red on correct code.
     */
    public function test_the_looks_dl_predicate_and_the_runtime_probe_return_the_same_answer_set(): void
    {
        $looksDl = $this->stepRegex('Warn on a card or DL token', 'looks_dl');
        $corpus = array_values(array_unique(array_merge(
            DlTokenGrammar::probeVectors(),
            self::singleCharacterSeparatorDlVectors(),
            ['mentions DL 239 in prose', 'DL 239', 'DLs 239', 'handles idl_239',
                'the middleware_2 rewrite', 'no token at all'],
        )));

        $probeRecognises = array_values(array_filter($corpus,
            fn (string $text) => DlTokenGrammar::looksLikeDlToken($text)));
        $lintRecognises = $this->grepMatchesAll($looksDl, $corpus);

        $this->assertNotEmpty($probeRecognises, 'the tie needs recognised shapes to compare');
        $this->assertNotSame(count($corpus), count($probeRecognises),
            'the tie needs unrecognised shapes, or it is [all-true] === [all-true]');
        $this->assertSame($probeRecognises, $lintRecognises,
            "the lint's `looks_dl` predicate and the runtime DL near-miss probe disagree about which shapes appear to name a DL");
    }

    /**
     * The whole step, end to end, on every DL vector — which is what proves the arm
     * is WIRED at all: both ties above read regexes out of the YAML and would stay
     * green if the `if`/`echo` that consumes them were never added.
     *
     * `warn iff looks_dl && ! parses`, with each half taken from the side that owns
     * it: the recognition half from the extracted predicate, the accept half from
     * the grammar. Not `iff the grammar rejects it` — the card arm's form — because
     * `IDL-239` is a leading-boundary row the DL vector set carries and `looks_dl`
     * correctly cannot see, so that stronger claim is false here and asserting it
     * would be asserting the wrong thing rather than finding a defect.
     */
    public function test_the_step_warns_on_exactly_the_dl_shapes_it_sees_and_the_grammar_rejects(): void
    {
        $this->assertDlVectorsCannotTripTheCardArm();
        $looksDl = $this->stepRegex('Warn on a card or DL token', 'looks_dl');

        $warned = 0;
        foreach (DlTokenGrammar::VECTORS as $vector) {
            $subject = "fix a thing {$vector}";
            $expected = $this->grepMatches($looksDl, $subject) && DlTokenGrammar::parse($subject) === null;
            $warned += $expected ? 1 : 0;

            $this->assertSame($expected, $this->warned($subject),
                "'{$vector}' must warn iff the step's own probe sees it and the grammar does not parse it");
        }
        $this->assertGreaterThan(0, $warned, 'a vector set expecting no warning at all would assert nothing');
    }

    /**
     * The DL arm's operator-facing text names the Unicode-digit class BY NAME, which
     * is the whole reason this arm exists: the runtime probe's digit class is ASCII
     * by ratification (DL-231), so `DL-<U+0663>239` can be caught nowhere else. A
     * codepoint printed at a human would be the wrong call for a human-facing string
     * — the CLASS is what is named, exactly as the card arm names it.
     */
    public function test_the_dl_warning_text_names_the_non_ascii_digit_class(): void
    {
        $three = "\u{0663}";
        $message = $this->runWarnStep("fix a thing DL-{$three}239");

        $this->assertStringContainsString('::warning::', $message, 'positive control: the fixture must actually warn');
        $this->assertNull(DlTokenGrammar::parse("DL-{$three}239"),
            'the grammar correlates a Unicode-digit DL to NOTHING — that silence is what this text covers');
        $this->assertStringContainsString('non-ASCII digits', $message,
            'the DL arm must name the class the runtime probe cannot see');
        $this->assertStringContainsString('DL-231', $message,
            'and cite the ratification that makes it ASCII-only');
        $this->assertStringContainsString('names a DL', $message,
            'the warning must be attributable to the DL arm, not read as the card arm\'s');
    }

    /**
     * SEVERITY, for the DL arm specifically. The card arm has had this leg since
     * DL-234, but it drives `card#<U+0663>` — which trips only the CARD arm, so it
     * says nothing about the one added here: an `exit 1` inside the DL `if` would
     * have left it green while silently making this a merge gate.
     *
     * The subject is chosen to fire the DL arm and ONLY the DL arm (it carries no
     * card-ish text), and the warning is asserted present in the same run — an exit
     * code of 0 from a step that printed nothing would pass this while measuring
     * nothing.
     */
    public function test_the_dl_arm_never_fails_the_job(): void
    {
        $three = "\u{0663}";
        [$rc, $out] = $this->runStep('Warn on a card or DL token', "fix a thing DL-{$three}239", 'f');

        $this->assertStringContainsString('names a DL', $out,
            'positive control: the DL arm must actually have fired, or the exit code is unattributed');
        $this->assertSame(0, $rc, 'the DL near-miss arm must warn, never fail — DL-234(c)');
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
        //     the gate's is bounded at four. The 4-digit control is what makes the bound
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

        // The branch-shape test is the fourth site and differs in KIND: it decides
        // whether the step enforces at all, so under a collation locale a
        // Unicode-digit branch is enforced against (and reds) where a C-family
        // locale skips it entirely. card#6822 widened that test's SPELLING and this
        // row is unmoved, which is the point — the widening deliberately kept the
        // `[0-9]` RANGE, because enumerating it here would skip the branch under
        // every locale and make the gate more permissive (card#5300, still pinned).
        // The regex is not quoted here: a copy of it is the restatement this file
        // exists to catch, and it would have gone stale on exactly that widening.
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
        foreach (['dependabot/composer/x-2.0', 'release/v0.71.0', 'chore/docs'] as $branch) {
            $this->assertSame(0, $this->runRequireStep('a title with no token at all', $branch),
                "branch '{$branch}' must be skipped, not failed");
        }

        // `card-5150-slug` was a fourth row of that list until card#6822, and is
        // asserted here rather than deleted: the row did not become uninteresting,
        // it CHANGED SIDES. That spelling sits on 7 merged PRs of this repo and the
        // gate skipped every one of them green. Deleting the row would have left
        // the accept/reject change recorded nowhere in the file that owns this step.
        $this->assertSame(1, $this->runRequireStep('a title with no token at all', 'card-5150-slug'),
            'the segment-less `card-<id>-slug` spelling is ENFORCED now, not skipped');
    }

    // ---------------------------------------------------------------------
    // WHICH BRANCHES ARE INSPECTED AT ALL (card#6822)
    // ---------------------------------------------------------------------

    /**
     * The whole classifier, over the whole corpus, asserting the EXTRACTED VALUE.
     * One leg rather than five, because the rows are one population: a shape moved
     * between buckets is a row edit here, and a bucket that quietly emptied is
     * visible as an absent expectation rather than as a leg nobody ran.
     */
    public function test_the_require_step_classifies_and_extracts_over_the_branch_corpus(): void
    {
        // Each bucket must be non-empty, or a corpus edit could silently reduce this
        // leg to asserting only the half that still had rows.
        foreach (['skip:no-id', 'skip:exempt'] as $verdict) {
            $this->assertNotEmpty($this->branchRowsExpecting($verdict),
                "the corpus has no {$verdict} rows left — the leg would assert only the enforced half");
        }
        $this->assertNotEmpty(array_filter(self::BRANCH_VERDICTS, fn (array $r) => ctype_digit($r[1])),
            'the corpus has no enforced rows left');

        foreach (self::BRANCH_VERDICTS as [$branch, $expected, $why]) {
            $this->assertSame($expected, $this->requireStepVerdict($branch),
                "branch '{$branch}' — {$why}");
        }
    }

    /**
     * THE FIX, pinned to the failure it guards: the pre-card#6822 predicate reverted
     * into the real script must SKIP every shape the widening exists to reach, under
     * the same runner in the same process. Without this, the corpus leg above would
     * pass on a predicate that had been wide all along and prove nothing about the
     * change — the two evade groups are only evidence if the old spelling misses them.
     *
     * The enforced rows are asserted UNCHANGED under the same mutation, which is what
     * makes the widening additive rather than a re-partition.
     */
    public function test_the_pre_fix_predicate_skips_the_two_measured_evasion_shapes(): void
    {
        // The pre-fix state is the PAIR, and it has to be reverted as one: the old
        // predicate captures nothing, so leaving the shipped `${BASH_REMATCH[3]}`
        // extraction beside it kills the step on `set -u` instead of skipping, and
        // this leg would then be measuring an unbound-variable abort rather than the
        // silence card#6822 measured. That coupling is the card's whole point,
        // reproduced here by having to honour it.
        $reverted = str_replace(
            ['^([a-z-]+/)?(card-?)?([0-9]+)-', 'card_id="${BASH_REMATCH[3]}"'],
            ['^[a-z-]+/[0-9]+-', 'card_id="${branch_lc#*/}"'."\n".'card_id="${card_id%%-*}"'],
            $this->stepScript('Require card#/DL token'), $applied);
        $this->assertSame(2, $applied, 'the pre-fix predicate/extraction pair is gone or renamed — this control measures nothing');

        $evaders = ['card-5538-drop-redundant-is-link', 'card6027-nearmiss-card-token-guard',
            'card5698-channel-token-fault', 'card-5721-bodyless-actor-attribution',
            'fix/card6371-stage-aware-coord-card-create', 'chore/card-5913-hard-gate-de-enumeration',
            'docs/card5584-http-fake-stacking-gotcha', 'fix/card-6034-commonmark-bump'];

        foreach ($evaders as $branch) {
            $this->assertSame('skip:no-id', $this->requireStepVerdict($branch, $reverted),
                "'{$branch}' must be SKIPPED by the old predicate — that silence is what card#6822 measured");
            $this->assertNotSame('skip:no-id', $this->requireStepVerdict($branch),
                "'{$branch}' must be ENFORCED by the shipped predicate");
        }

        foreach (['fix/6100-changelog-gate-scope', 'ci/5910-release-artifacts-gate'] as $branch) {
            $this->assertSame($this->requireStepVerdict($branch, $reverted), $this->requireStepVerdict($branch),
                "control: '{$branch}' was already enforced and must extract the same id under both predicates");
        }
    }

    /**
     * THE TRAP THE CARD NAMES, pinned: a predicate widened while the extraction stays
     * the old two-step string chop fires and then compares the title against an id
     * that names nothing — `card6371` for the prefixed shape, and the WHOLE BRANCH
     * NAME for the segment-less one, because the old first step stripped up to the
     * first slash and a segment-less branch has none. That gate is strictly worse
     * than the skip it replaced: it reds
     * the PR and tells the author to add a token spelled after a non-existent id.
     *
     * Asserted by mutation rather than by argument, so it stays true if either half
     * is edited again.
     */
    public function test_extraction_is_tied_to_the_predicate_not_re_derived(): void
    {
        $desynced = $this->mutatedRequireStep(
            'card_id="${BASH_REMATCH[3]}"',
            'card_id="${branch_lc#*/}"'."\n".'          card_id="${card_id%%-*}"',
        );

        // The malformed ids the old extraction yields, named exactly — "it differs"
        // would pass on any garbage and would not show the failure is UNACTIONABLE.
        $this->assertSame('card6371', $this->requireStepVerdict('fix/card6371-stage-aware-coord-card-create', $desynced));
        $this->assertSame('card', $this->requireStepVerdict('chore/card-5913-hard-gate-de-enumeration', $desynced));
        $this->assertSame('card6027', $this->requireStepVerdict('card6027-nearmiss-card-token-guard', $desynced));
        $this->assertSame('card', $this->requireStepVerdict('card-5538-drop-redundant-is-link', $desynced));

        // And the shipped extraction answers the digits on the same four.
        $this->assertSame('6371', $this->requireStepVerdict('fix/card6371-stage-aware-coord-card-create'));
        $this->assertSame('5913', $this->requireStepVerdict('chore/card-5913-hard-gate-de-enumeration'));
        $this->assertSame('6027', $this->requireStepVerdict('card6027-nearmiss-card-token-guard'));
        $this->assertSame('5538', $this->requireStepVerdict('card-5538-drop-redundant-is-link'));
    }

    /**
     * THE NEGATIVE CONTROLS DISCRIMINATE — one mutation per axis that could widen
     * the predicate, each paired with the rows it ACTUALLY flips. The pairing is
     * measured, not reasoned: the first draft of this leg claimed the trailing `-`
     * was what kept `feat/oauth2-login` out, and driving it proved that wrong — the
     * `^` anchor is. Both axes are now separated, which is the only reason either
     * row is evidence of anything.
     *
     * DISPOSED EXPLICITLY: the corpus's other card-less rows — `chore/docs`,
     * `wip/no-id`, `fix/decouple-check-name-from-runtime`, `fix/card-token-grammar`
     * and `docs-changelog-card-token-relocation-upgrading` — carry NO ASCII digits
     * at all, so no widening along either axis can reach them. They are population
     * coverage in the corpus leg above, and they are deliberately NOT claimed here
     * as controls: a row that cannot flip proves nothing about the predicate's width.
     */
    public function test_each_predicate_axis_is_what_keeps_a_card_less_branch_out(): void
    {
        // AXIS 1 — the trailing `-`, which separates "an id then a slug" from
        // "digits opening a segment and running into a word".
        $noHyphen = $this->mutatedRequireStep('^([a-z-]+/)?(card-?)?([0-9]+)-', '^([a-z-]+/)?(card-?)?([0-9]+)');
        $this->assertSame('skip:no-id', $this->requireStepVerdict('fix/2fa-enrollment'));
        $this->assertSame('2', $this->requireStepVerdict('fix/2fa-enrollment', $noHyphen),
            'without the trailing hyphen `2fa` reads as card 2 — that is what the hyphen excludes');

        // AXIS 2 — the `^`. Unanchored, any digits-then-hyphen ANYWHERE in the name
        // qualifies, which is what actually swallows a digit-bearing slug.
        $noAnchor = $this->mutatedRequireStep('^([a-z-]+/)?(card-?)?([0-9]+)-', '([a-z-]+/)?(card-?)?([0-9]+)-');
        foreach (['feat/oauth2-login' => '2', 'fix/cards-123-thing' => '123'] as $branch => $wrongId) {
            $this->assertSame('skip:no-id', $this->requireStepVerdict($branch));
            $this->assertSame($wrongId, $this->requireStepVerdict($branch, $noAnchor),
                "unanchored, '{$branch}' reads as card {$wrongId} — the anchor is what excludes it");
        }

        // And the enforced rows must be indifferent to both mutations, or the axes
        // are re-partitioning the population rather than bounding it.
        foreach (['fix/6100-changelog-gate-scope', 'card-5538-drop-redundant-is-link',
            'fix/card6371-stage-aware-coord-card-create'] as $branch) {
            $expected = $this->requireStepVerdict($branch);
            $this->assertSame($expected, $this->requireStepVerdict($branch, $noHyphen), "'{$branch}' under axis 1");
            $this->assertSame($expected, $this->requireStepVerdict($branch, $noAnchor), "'{$branch}' under axis 2");
        }
    }

    /**
     * THE EXEMPTION ARM is load-bearing only for a branch the predicate WOULD accept,
     * which no real exempt branch in this repo is. Deleting the `case` block must
     * therefore flip the three synthetic rows and nothing else — that is what makes
     * them controls rather than decoration.
     */
    public function test_the_automation_exemption_is_what_skips_a_card_id_shaped_exempt_branch(): void
    {
        $script = $this->stepScript('Require card#/DL token');
        // Column 0: `stepScript()` returns the YAML literal block AFTER parsing, which
        // has already stripped the 10-space block indentation the file shows.
        $noExemption = preg_replace('/^case "\$BRANCH" in\n.*?\nesac\n/ms', '', $script, 1, $count);
        $this->assertSame(1, $count, 'the exemption `case` block is gone or reshaped — this control measures nothing');

        foreach ($this->branchRowsExpecting('skip:exempt') as [$branch, , $why]) {
            $this->assertSame('skip:exempt', $this->requireStepVerdict($branch), "'{$branch}' — {$why}");
            $this->assertSame('6822', $this->requireStepVerdict($branch, $noExemption),
                "'{$branch}' must satisfy the card-id predicate once the exemption is removed, or it is not a control for it");
        }
    }

    /**
     * CASE FOLDING (#4384) survives the widened predicate. The pattern is lowercase
     * and the inputs are folded once with `tr`; removing the fold must make the
     * shouted rows unreachable, on BOTH the type-segment and the segment-less shape.
     * The kanban-board copy of this workflow does not fold — this leg is why the two
     * must not be "converged" by dropping it.
     */
    public function test_the_branch_fold_is_what_reaches_an_upper_case_card_branch(): void
    {
        $unfolded = $this->mutatedRequireStep(
            'branch_lc=$(printf \'%s\' "$BRANCH" | tr \'[:upper:]\' \'[:lower:]\')',
            'branch_lc="$BRANCH"');

        foreach (['FIX/CARD6371-Stage-Aware-Coord-Card-Create' => '6371',
            'Card-5538-Drop-Redundant-Is-Link' => '5538'] as $branch => $id) {
            $this->assertSame($id, $this->requireStepVerdict($branch),
                "'{$branch}' must extract {$id} — the step folds its inputs");
            $this->assertSame('skip:no-id', $this->requireStepVerdict($branch, $unfolded),
                "'{$branch}' must go unreachable without the fold, or the fold is not what carries the answer");
        }
    }
    // ---------------------------------------------------------------------
    // THE CLOSURE STEP (card#8294) — a correlated card with no closure claim
    // ---------------------------------------------------------------------

    /**
     * The step name prefix, once. Every leg below reaches the step through this, so
     * a rename is one edit and cannot leave half the legs silently measuring nothing
     * (`stepScript()` FAILS on an unknown prefix rather than skipping, which is what
     * makes that true).
     */
    private const CLOSURE_STEP = 'Require a closure claim';

    /** The closure step's exit code: 0 = this merge will claim the card it names. */
    private function runClosureStep(string $title, string $branch): int
    {
        return $this->runStep(self::CLOSURE_STEP, $title, $branch)[0];
    }

    /**
     * The corpus the whole-step tie below is driven over: (title, branch) pairs
     * spanning both closure routes, both their misses, and the shapes that carry no
     * closure question at all. Real head branches and real titles from this repo's
     * merged PRs wherever a row has one — the `<type>/<id>-slug` rows ARE the measured
     * defect (card#8294 counted 8 in a row), and the `card-<id>-slug` rows are the
     * convention that worked before it.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function closureCorpus(): array
    {
        $rows = [];
        foreach ([
            'ci/8286-release-tag-check',
            'feat/8290-lane-model-check-warn',
            'chore/8142-regenerate-coverage-artifact',
            'card-8286-release-tag-check',
            'fix/card8286-release-tag-check',
            'card-security-sleep',
            'fix/decouple-check-name-from-runtime',
            // A branch naming a DIFFERENT card than the title correlates — the only
            // shape that distinguishes "the ref names a card" from "the ref names
            // THIS card". Without it, deleting the structural id comparison leaves
            // the whole suite green (measured).
            'card-1234-other-work',
        ] as $branch) {
            foreach ([
                'ci: gate release-promote behind the tag it asserts (card#8286)',
                'ci: gate release-promote behind the tag it asserts (closes card#8286)',
                'ci: gate release-promote (fixes card#8286)',
                'ci: gate release-promote (resolved card#8286)',
                'ci: gate release-promote (closes: card#8286)',
                'ci: closes the bug in card#8286',
                'ci: gate (unfixes card#8286)',
                'ci: gate (closes card#1234) (card#8286)',
                // The mirror of the row above, and the only shape that distinguishes
                // "some card is closed" from "THIS card is closed": the correlated
                // card is 8286 (leftmost) and the closing form names 1234, so the
                // bridge refuses. Without it, deleting the id comparison in the step
                // leaves the whole suite green (measured).
                'ci: gate (card#8286) (closes card#1234)',
                'ci: gate (closes card#82860)',
                'ci: bump both toolkit action pins to v0.31.0',
                'feat: two (closes card#8286, closes card#8290)',
                'feat: two (closes card#8286 and card#8290)',
            ] as $title) {
                $rows[] = [$title, $branch];
            }
        }

        return $rows;
    }

    /**
     * ⭐ THE WHOLE-STEP TIE, and the only leg here that measures what the gate is FOR.
     * Every other tie compares one regex to one grammar; this compares the STEP's
     * verdict to the BRIDGE's — `ClosureGrammar::closesCard()` OR
     * {@see PrOutcome::mergeClosesCard()}, over the card the classifier would select
     * ({@see CardTokenGrammar::parse()} on the title, leftmost-only). Neither side
     * carries a copy of the other's answer.
     *
     * That is the property the gate exists to hold. A leg comparing the bash to a
     * hand-written expectation would have stayed green through the convention flip
     * that caused card#8294, because the expectation would have been written from the
     * same wrong mental model as the convention.
     *
     * ⚠ THE SCOPE OF THE TIE IS THE CLOSURE PREDICATE, NOT THE WRITEBACK. This does
     * NOT establish that CI reds exactly the PRs whose merge the writeback refuses,
     * and the wording that claimed so is corrected here: a merge is refused for
     * several reasons no PR title can express — an unmapped repo, a card the token
     * cannot read, the mapped-board guard, a near-miss hijack, and a PINNED card on
     * every outcome (card#8289). All are downstream of this gate and invisible to it.
     * What is established is the biconditional on THIS predicate, the only one a title
     * and a branch can decide.
     *
     * The `merged` outcome is passed rather than `merged_to_main` because the step
     * exempts `release/*` — the only head that reaches a `main` base — so the base
     * ref it cannot see is pinned by the exemption, not assumed away.
     */
    public function test_the_closure_step_and_the_runtime_predicate_return_the_same_verdict(): void
    {
        $agreed = $reds = 0;
        foreach (self::closureCorpus() as [$title, $branch]) {
            $id = CardTokenGrammar::parse($title);
            $runtime = $id !== null
                && ! (ClosureGrammar::closesCard($title, $id)
                    || PrOutcome::mergeClosesCard(PrOutcome::INTEGRATION_MERGE, $branch, $id, $title));
            $this->assertSame($runtime, $this->runClosureStep($title, $branch) !== 0,
                "the gate and the bridge disagree on '{$title}' / '{$branch}': the bridge "
                .($runtime ? 'REFUSES' : 'moves').' the card');
            $agreed++;
            $reds += $runtime ? 1 : 0;
        }

        // A corpus the gate never reds would make every row above pass by agreeing
        // that nothing is wrong, which is the shape of a check that cannot fail.
        $this->assertGreaterThan(0, $reds, 'the corpus contains no PR the bridge would refuse — the tie measured nothing');
        $this->assertLessThan($agreed, $reds, 'the corpus contains no PR the bridge would move — the tie measured nothing');
    }

    /**
     * SEEN TO FAIL (canon: a pass is evidence only if failure was possible). The four
     * head branches here are real merged PRs of this repo whose cards did NOT move,
     * and the titles are theirs: this leg is the check watching the exact incident
     * card#8294 was filed for.
     */
    public function test_the_closure_step_reds_the_measured_defect(): void
    {
        foreach ([
            'ci: gate release-promote behind the tag it asserts (card#8286)' => 'ci/8286-release-tag-check',
            'feat(check): report the race a half-adopted lane model leaves open (card#8290)' => 'feat/8290-lane-model-check-warn',
            'docs(check): regenerate the golden coverage artifact against current dev (card#8142)' => 'chore/8142-regenerate-coverage-artifact',
            'test(security): the shell-injection assertion waits for the child (card#7233)' => 'card-security-sleep',
        ] as $title => $branch) {
            [$rc, $out] = $this->runStep(self::CLOSURE_STEP, $title, $branch);
            $this->assertSame(1, $rc, "'{$title}' must red — its merge moves nothing");
            $this->assertStringContainsString('::error::', $out);
            $this->assertStringContainsString((string) CardTokenGrammar::parse($title), $out,
                'the error must name the card the author has to act on');
        }
    }

    /**
     * The passing shapes, watched to pass — one per verb the grammar accepts, plus
     * both structural spellings. Driven off {@see ClosureGrammar::accepted()} so a
     * verb added to the grammar is exercised here without an edit.
     */
    public function test_the_closure_step_passes_every_closing_form_the_grammar_accepts(): void
    {
        $forms = array_values(array_filter(ClosureGrammar::accepted(),
            fn (string $v) => ClosureGrammar::closesCard($v, 123)));
        $this->assertNotEmpty($forms, 'no card-closing vectors to drive — this leg would measure nothing');

        foreach ($forms as $form) {
            $title = 'ci: gate release-promote ('.str_replace('123', '8286', $form).')';
            $this->assertSame(0, $this->runClosureStep($title, 'ci/8286-release-tag-check'),
                "'{$title}' carries a closing form the grammar accepts and must pass");
        }

        foreach (['card-8286-slug', 'fix/card8286-slug', 'fix/card#8286-slug'] as $branch) {
            $this->assertSame(0, $this->runClosureStep('ci: gate release-promote (card#8286)', $branch),
                "'{$branch}' names the card structurally and must pass");
        }
    }

    /**
     * THE OPT-OUT, with its control. A PR that cites a card it deliberately does not
     * finish must have a way to say so, or this gate builds the OVER-promotion defect
     * it was designed to avoid. The control is the same title with the marker
     * removed: without it the row reds, so the marker is what carries the answer and
     * not some other property of the title.
     */
    public function test_the_no_close_marker_is_what_passes_a_deliberate_non_closure(): void
    {
        $marker = '[no-close]';
        foreach ([$marker, strtoupper($marker)] as $spelling) {
            $this->assertSame(0, $this->runClosureStep("docs: cite the prior ruling {$spelling} (card#8286)", 'docs/8286-context'),
                "'{$spelling}' must declare a deliberate non-closure");
        }
        $this->assertSame(1, $this->runClosureStep('docs: cite the prior ruling (card#8286)', 'docs/8286-context'),
            'without the marker the same title must red, or the marker is not what passes it');
        $this->assertSame(1, $this->runClosureStep('docs: cite the prior ruling, no close intended (card#8286)', 'docs/8286-context'),
            'prose resembling the marker must not pass — the opt-out is a literal, not a grammar');
    }

    /**
     * The exemption block, with the mutation that proves it carries the answer: an
     * exempt branch whose title correlates a card and closes nothing must pass, and
     * must RED once the `case` block is deleted. Without this leg the exemption is
     * indistinguishable from every one of those branches simply carrying no token.
     *
     * The revert row is the one that matters: GitHub mints a revert title by quoting
     * the original's, so it arrives correlating the card the reverted work closed.
     *
     * ⚠ The row deliberately quotes a BARE-MENTION title. A revert of a PR that
     * closed its card quotes the CLOSING FORM too, and would pass this step on the
     * lexical route with the exemption deleted — so it cannot serve as this control.
     * That is not a defect in the gate; it is a disclosed property of the RUNTIME
     * predicate, which reads the quoted verb the same way and would move the card on
     * a merge that UNDOES its work. Changing that is a change to what the writeback
     * acts on and belongs to its own decision, not to this one.
     */
    public function test_the_exemption_block_is_what_passes_an_exempt_branch(): void
    {
        $script = $this->stepScript(self::CLOSURE_STEP);
        $stripped = preg_replace('/^case "\$BRANCH" in\n.*?^esac\n/ms', '', $script, 1, $count);
        $this->assertSame(1, $count, 'the exemption `case` block is gone or reshaped — this control measures nothing');
        $this->assertIsString($stripped);

        foreach ([
            'Revert "ci: gate release-promote (card#8286)"' => 'revert-608-ci/8286-release-tag-check',
            'chore(release): v0.79.0 (card#8286)' => 'release/v0.79.0',
            'chore: sync main to dev (card#8286)' => 'sync/main-to-dev-post-v0.79.0',
        ] as $title => $branch) {
            $this->assertSame(0, $this->runClosureStep($title, $branch), "'{$branch}' is exempt and must pass");
            $this->assertSame(1, $this->runScriptText($stripped, $title, $branch)[0],
                "'{$branch}' must red without the exemption, or the exemption is not what passes it");
        }
    }

    /**
     * TIED — the step's `closure=` regex is a SECOND implementation of
     * {@see ClosureGrammar}'s card arm, in a second language, and this compares the
     * two answer sets over the grammar's own vectors. The authority is
     * `closedCardIds() !== []` and NOT `hasClosure()`: the bash arm deliberately does
     * not implement the DL half (the step's own comment discloses why), so tying it
     * to `hasClosure()` would red on `Closes DL-239` and report a disclosed scope
     * bound as a defect.
     *
     * Three renderings because the leading boundary differs — `(^|[^0-9a-z_])` here,
     * `\b` there — so they must agree at the start of the subject, mid-prose, and
     * inside the parenthetical this repo's titles actually use.
     */
    public function test_the_closure_steps_grammar_and_the_authority_return_the_same_answer_set(): void
    {
        $closure = $this->stepRegex(self::CLOSURE_STEP, 'closure');

        $cardClosers = array_values(array_filter(ClosureGrammar::VECTORS,
            fn (string $v) => ClosureGrammar::closedCardIds($v) !== []));
        $others = array_values(array_filter(ClosureGrammar::VECTORS,
            fn (string $v) => ClosureGrammar::closedCardIds($v) === []));
        $this->assertNotEmpty($cardClosers, 'the tie needs card-closing vectors to compare');
        $this->assertNotEmpty($others, 'the tie needs non-closing vectors to compare');

        foreach (['%s', 'ci: gate a thing %s', 'ci: gate a thing (%s)'] as $shape) {
            $accepts = $rejects = [];
            foreach (ClosureGrammar::VECTORS as $vector) {
                if ($this->grepMatches($closure, sprintf($shape, $vector))) {
                    $accepts[] = $vector;
                } else {
                    $rejects[] = $vector;
                }
            }
            $this->assertSame($cardClosers, $accepts, "rendered as '{$shape}': the lint closes a different set than the grammar does");
            $this->assertSame($others, $rejects, "rendered as '{$shape}': the lint withholds a different set than the grammar does");
        }
    }

    /**
     * The same tie over the SEPARATOR domain the verb bridge spans — `closes<c>card#123`
     * for every printable ASCII `c`, plus the glued spelling. Built the way
     * {@see singleCharacterSeparatorVectors()} is and for the same reason: the vector
     * list above is curated and finite, so on its own it cannot see a bash character
     * class that admits one separator the PHP `[\s:]+` does not (or the reverse).
     */
    public function test_the_closure_steps_verb_bridge_and_the_authority_agree_over_every_separator(): void
    {
        $closure = $this->stepRegex(self::CLOSURE_STEP, 'closure');

        $vectors = ['closescard#123'];
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $vectors[] = 'closes'.chr($code).'card#123';
        }

        $authority = array_values(array_filter($vectors, fn (string $v) => ClosureGrammar::closesCard($v, 123)));
        $this->assertNotEmpty($authority, 'no separator closes — the tie measured nothing');
        $this->assertNotSame($vectors, $authority, 'every separator closes — the tie measured nothing');
        $this->assertSame($authority, $this->grepMatchesAll($closure, $vectors),
            'the lint and the grammar admit different separators between the verb and the token');
    }

    /**
     * TIED — the step's `token=` regex vs {@see CardTokenGrammar}, the same shape as
     * the warn step's `good=` tie. It exists as a second regex in this file because
     * this step needs the id CAPTURED and `good=` does not; the leg below is what
     * stops the two from disagreeing.
     */
    public function test_the_closure_steps_token_and_the_authority_return_the_same_answer_set(): void
    {
        $token = $this->stepRegex(self::CLOSURE_STEP, 'token');

        foreach (['%s', 'fix a thing %s', 'ci: gate a thing (%s)'] as $shape) {
            $accepts = $rejects = [];
            foreach (CardTokenGrammar::VECTORS as $vector) {
                if ($this->grepMatches($token, sprintf($shape, $vector))) {
                    $accepts[] = $vector;
                } else {
                    $rejects[] = $vector;
                }
            }
            $this->assertSame(CardTokenGrammar::accepted(), $accepts, "rendered as '{$shape}': the closure step accepts a different set than the grammar does");
            $this->assertSame(CardTokenGrammar::rejected(), $rejects, "rendered as '{$shape}': the closure step rejects a different set than the grammar does");
        }
    }

    /**
     * The two card-token regexes THIS FILE now carries — the warn step's `good=` and
     * the closure step's `token=` — compared to each other over the separator domain.
     * Each is independently tied to {@see CardTokenGrammar} above, which already makes
     * a silent divergence impossible; this states the property directly, so a reader
     * asking "are there two accept-sets in this workflow?" gets an assertion rather
     * than an argument from two other tests.
     */
    public function test_the_two_card_token_regexes_in_this_workflow_answer_identically(): void
    {
        $vectors = array_merge(CardTokenGrammar::VECTORS, self::singleCharacterSeparatorVectors());
        $this->assertSame(
            $this->grepMatchesAll($this->stepRegex('Warn on a card or DL token', 'good'), $vectors),
            $this->grepMatchesAll($this->stepRegex(self::CLOSURE_STEP, 'token'), $vectors),
            'this workflow holds two card-token regexes that answer differently');
    }

    /**
     * EXTRACTION TIED TO THE PREDICATE — the card#6822 trap, on the new step. A
     * predicate that fires and then names an id the grammar would not have selected
     * sends the author to close the wrong card, which is worse than the skip it
     * replaced. The id is observable through the error text, which is exactly what
     * makes it assertable at all.
     */
    public function test_the_closure_step_names_the_card_the_grammar_would_select(): void
    {
        foreach ([
            'ci: gate a thing (card#8286)',
            'ci: gate a thing (card-8286)',
            'ci: gate a thing card8286 rework',
            'ci: gate (closes card#1234) (card#8286)',
            'ci: gate a thing (card#08286)',
        ] as $title) {
            $id = CardTokenGrammar::parse($title);
            $this->assertNotNull($id, "'{$title}' must correlate, or this row measures nothing");
            [, $out] = $this->runStep(self::CLOSURE_STEP, $title, 'ci/nothing-slug');
            $this->assertMatchesRegularExpression('/\bcard '.$id.'\b/', $out,
                "'{$title}': the step must name the id CardTokenGrammar selects");
        }
    }

    /**
     * THE MESSAGE, tied in both directions — the failure this repo has paid for three
     * times is operator-facing text naming a NARROWER or simply wrong accept-set than
     * the code enforces (DL-239). The step cannot render {@see ClosureGrammar::describe()}
     * (the job has no checkout, by design), so the quoted list is pinned instead:
     *
     *  - every form it quotes must be one the grammar ACCEPTS and the step's own
     *    regex matches — so the message can never advertise a form that does nothing;
     *  - every verb stem the grammar accepts must APPEAR in the list — derived from
     *    {@see ClosureGrammar::accepted()}, never enumerated here — so a verb added to
     *    the grammar reds this leg instead of going unmentioned for two releases.
     */
    public function test_the_closure_steps_quoted_forms_agree_with_the_grammar(): void
    {
        $forms = $this->stepRegex(self::CLOSURE_STEP, 'forms');
        $closure = $this->stepRegex(self::CLOSURE_STEP, 'closure');

        $quoted = array_map('trim', explode(',', str_replace('ID', '123', $forms)));
        $this->assertNotEmpty($quoted);
        foreach ($quoted as $form) {
            $this->assertTrue(ClosureGrammar::closesCard($form, 123),
                "the message quotes '{$form}', which the grammar does not accept as a closure");
            $this->assertTrue($this->grepMatches($closure, $form),
                "the message quotes '{$form}', which the step's own regex does not match");
        }

        $stems = [];
        foreach (ClosureGrammar::accepted() as $vector) {
            $stems[strtolower(rtrim(explode(' ', $vector)[0], ':'))] = true;
        }
        $this->assertNotEmpty($stems, 'no verb stems derived — this leg would measure nothing');
        foreach (array_keys($stems) as $stem) {
            $this->assertStringContainsString($stem.' card#', $forms,
                "the grammar accepts '{$stem}' and the operator-facing list does not name it");
        }
    }

    /**
     * THE SECOND CONJUNCT of the structural route, and its control. A release merge
     * takes that route NOT AT ALL ({@see PrOutcome::mergeClosesCard()} requires
     * {@see PrOutcome::INTEGRATION_MERGE}), so the identical PR passes against the
     * integration base and must red against the release base — otherwise the step
     * mirrors the predicate minus a conjunct and the whole-step tie above is
     * measuring under an assumption instead of a value.
     *
     * The base name is not spelled here: it is read from the step and compared to the
     * authority's own constant, so a rename moves both or reds.
     */
    public function test_the_structural_route_is_withheld_from_a_release_merge(): void
    {
        $this->assertSame(PrOutcome::RELEASE_BASE, $this->stepRegex(self::CLOSURE_STEP, 'release_base'),
            'the step and the writeback disagree about which base is the release base');

        $title = 'ci: gate release-promote (card#8286)';
        $branch = 'card-8286-release-tag-check';
        $this->assertFalse(PrOutcome::mergeClosesCard(PrOutcome::RELEASE_MERGE, $branch, 8286, $title),
            'the authority must withhold the structural route here, or this leg measures nothing');
        $this->assertTrue(PrOutcome::mergeClosesCard(PrOutcome::INTEGRATION_MERGE, $branch, 8286, $title));

        $this->assertSame(0, $this->runStep(self::CLOSURE_STEP, $title, $branch, null, 'dev')[0],
            'the integration base must take the structural route');
        $this->assertSame(1, $this->runStep(self::CLOSURE_STEP, $title, $branch, null, PrOutcome::RELEASE_BASE)[0],
            'the release base must NOT take the structural route');
        $this->assertSame(0, $this->runStep(self::CLOSURE_STEP, 'ci: gate (closes card#8286)', $branch, null, PrOutcome::RELEASE_BASE)[0],
            'the LEXICAL route is untouched by the base — a release merge still closes on a closing form');
    }

    /**
     * ⛔ THE SEPARATOR CLASS, and why the step does not write `[[:space:]]` — DL-272's
     * ruling re-derived on a second bracket expression, with the measurement rather
     * than the analogy.
     *
     * grep resolves `[[:space:]]` from LOCALE DATA. Measured here with the runner's
     * own tool (GNU grep, not this shell's Unicode-aware shim — the instrument is the
     * finding): under `C.UTF-8` (the Actions default) and `en_US.UTF-8` it admits
     * U+1680, U+2000, U+205F and U+3000, none of which {@see ClosureGrammar} accepts;
     * under plain `C` it agrees. A title separated by one of those four would have
     * PASSED this gate and been REFUSED by the writeback — the false green this whole
     * step exists to delete.
     *
     * THREE LEGS, and the middle one is the control: the divergence is re-measured (so
     * the day glibc or the default locale changes, this says so instead of quietly
     * guarding nothing), the enumeration is shown to answer IDENTICALLY to the grammar
     * in every locale, and the price is pinned — TAB closes at runtime and reds here,
     * the false-RED direction, accepted rather than repaired with an invisible
     * character in the YAML.
     */
    public function test_the_enumerated_separator_answers_the_same_in_every_locale(): void
    {
        $closure = $this->stepRegex(self::CLOSURE_STEP, 'closure');
        $separators = ["\t", "\v", "\f", "\r", ' ', "\u{0085}", "\u{00a0}", "\u{1680}",
            "\u{2000}", "\u{2007}", "\u{202f}", "\u{205f}", "\u{3000}"];
        $vectors = array_map(fn (string $c) => 'closes'.$c.'card#123', $separators);
        $authority = array_values(array_filter($vectors, fn (string $v) => ClosureGrammar::closesCard($v, 123)));

        $this->assertNotEmpty($authority, 'no separator closes — this leg would measure nothing');
        $this->assertNotSame($vectors, $authority, 'every separator closes — this leg would measure nothing');

        // THE CONTROL: the class the step deliberately does NOT use, on the locale the
        // runner actually sets. If this stops over-matching, the enumeration below is
        // no longer protecting anything and the step's comment overstates its case.
        $overMatched = array_values(array_diff(
            $this->grepMatchesAll('(^|[^0-9a-z_])closes[[:space:]]card#123', $vectors, 'C.UTF-8'),
            $authority));
        $this->assertCount(4, $overMatched,
            'the locale-resolved [[:space:]] no longer admits separators the grammar rejects — re-measure before trusting the step comment');

        // THE PROPERTY: the enumerated class reads no locale data, so it returns ONE
        // answer set — the same one — whatever LC_ALL the runner sets. That is the
        // whole reason it is written out, and it is asserted as an identity across
        // locales rather than against the grammar, because it is deliberately NARROWER
        // than the grammar (the price, pinned below).
        $answers = [];
        foreach (['C', 'C.UTF-8', 'en_US.UTF-8'] as $locale) {
            $answers[$locale] = $this->grepMatchesAll($closure, $vectors, $locale);
        }
        $this->assertSame(array_fill_keys(['C', 'C.UTF-8', 'en_US.UTF-8'], $answers['C']), $answers,
            'the step closes a different set of separators depending on the runner locale');

        // AND IT NEVER OVER-CLOSES: every separator the step accepts is one the bridge
        // accepts. This is the direction that matters — a subset can only red a PR the
        // bridge would have moved; a superset greens one it will refuse.
        $this->assertSame([], array_diff($answers['C'], $authority),
            'the step closes a separator the grammar rejects — a title that passes CI and moves no card');

        // THE PRICE, pinned by name: `\s` admits TAB/VT/FF/CR and the enumeration does
        // not, so those four red here and close at runtime. Only TAB can reach a
        // one-line PR title at all.
        $this->assertSame(['closes card#123'], $answers['C'],
            'the accepted separator set moved — re-derive the price the step comment states');
        $this->assertSame(["closes\tcard#123", "closes\vcard#123", "closes\fcard#123", "closes\rcard#123"],
            array_values(array_diff($authority, $answers['C'])),
            'the pinned false-RED set moved — the step comment now misstates the price');
        $this->assertSame(1, $this->runClosureStep("ci: gate closes\tcard#123", 'ci/123-x'),
            'end to end, a tab-separated closing form reds — the accepted false-RED direction');
    }

    /**
     * The opt-out marker must be inert everywhere else in this workflow: it names no
     * card and no DL, so it can neither suppress nor trigger the near-miss warnings,
     * and it must not itself read as a closing form. Asserted rather than assumed —
     * `[no-close]` contains the verb `close`, one character away from a bridge.
     */
    public function test_the_opt_out_marker_is_inert_in_every_other_grammar(): void
    {
        $this->assertFalse(ClosureGrammar::hasClosure('docs: a thing [no-close]'),
            'the opt-out marker reads as a closing form to the runtime grammar');
        $this->assertFalse($this->warned('docs: a thing [no-close]', 'docs/context'),
            'the opt-out marker trips the near-miss warning');
        $this->assertSame(0, $this->runRequireStep('docs: a thing [no-close] (card#8286)', 'docs/8286-context'),
            'the opt-out marker breaks the correlation-token step');
    }
}
