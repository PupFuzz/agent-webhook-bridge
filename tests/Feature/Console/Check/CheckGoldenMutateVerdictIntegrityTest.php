<?php

namespace Tests\Feature\Console\Check;

use Tests\TestCase;

/**
 * `bin/check-golden-mutate.php` must refuse to publish a verdict that no fixture backs
 * (card#7994), and must derive every verdict from phpunit's own JUnit log rather than from
 * whatever a substituted result printer happened to put on the suite's stdout (card#8019).
 * Three guards do that, and this file drives all three:
 *
 *   THE BASELINE CONTROL — one suite run against the UNMUTATED tree before the loop starts.
 *     It refuses the whole run unless that run is green AND wrote a readable JUnit log.
 *   THE UNMEASURED-ITERATION REFUSAL — a predicate whose suite wrote no readable log.
 *   THE IN-LOOP ABORT REFUSAL — an `observed-via-abort` naming no failing fixture.
 *
 * WHY THE VERDICT COMES FROM `--log-junit`. It used to be `json_decode()`d off the suite's
 * stdout, and that JSON exists only while an installed result printer chooses to emit it — so
 * every verdict depended on a property of the SHELL rather than of the mutant. In a shell whose
 * printer emitted nothing, `json_decode` returned null: EVERY red predicate scored
 * `observed-via-abort` naming no fixture and every green one scored UNOBSERVED. Two distinct
 * statuses, so the degenerate-result-set arm stayed quiet — and the run published a full artifact
 * of pure noise at exit 0. The JUnit log is phpunit's own artifact and cannot drift with the
 * installed printer; `tests/Support/NestedSuite.php` states that rule and is this repo's other
 * caller that follows it. THE STUB SUITE BELOW PRINTS PHPUNIT'S CLASSIC TEXT BANNER ON STDOUT,
 * which is what makes every test here a witness for that: there is no report on stdout for a
 * generator to read. In exactly ONE scenario it prints one anyway, and that report deliberately
 * DISAGREES with the log — which is what turns the witness into a discriminator.
 *
 * WHY AN ABSENT LOG IS A REFUSAL AND NOT A VERDICT. The two verdicts an unmeasured iteration
 * would otherwise fall into are both false, and the second is the silent one: red with no
 * evidence scores `observed-via-abort` naming no fixture, and rc 0 with no evidence scores
 * UNOBSERVED — which renders as a disclosed gap, the strongest claim this artifact makes about a
 * predicate, off evidence nobody read.
 *
 * WHAT WENT WRONG WITHOUT THE IN-LOOP ABORT REFUSAL. Run 1 of card#7835's regeneration scored the
 * `CheckSlot::ProbeTools` `emitReport` arm `observed-via-abort` with `"failing": []`. The
 * generated table renders that row exactly like every other reached-but-not-distinguished
 * predicate, so it read as a real result: the false verdict was committed, and it had already
 * propagated into a conclusion in `docs/CHECK-REGISTRY-PLAN.md` before an adversarial review
 * caught it. (Cited by its CONDITION and never by the artifact's own row id: those ids are
 * line-anchored, they rot the moment anything above them moves — the id that record carried
 * now belongs to the same predicate scored `observed` — and the coverage diff keys on the
 * condition text anyway.)
 *
 * ⚠ WHAT AN EMPTY `failing` DOES AND DOES NOT MEAN. An earlier cut of that guard claimed that
 * a genuine `observed-via-abort` carries a non-empty `failing` BY CONSTRUCTION, so an empty
 * one meant the corpus had reacted to nothing. That is false for one of the two abort shapes:
 *
 *   RENDERED — the mutant threw INSIDE one of the fail-soft envelopes in `handle()`. The
 *     command still prints, the golden capture still differs, phpunit records a FAILURE, and
 *     the failure message carries both the `fixture '<name>'` the scrape reads and a
 *     `Fatal error|Uncaught|…` token. Non-empty `failing`. This shape publishes.
 *   ESCAPED — the mutant threw OUTSIDE every envelope. `handle()` has no top-level try/catch,
 *     `GoldenCapture::capture()` calls `Artisan::call` with no catch, and
 *     `Illuminate\Console\Application::call()` runs with `setCatchExceptions(false)`, so the
 *     throwable propagates out of the test and phpunit records an ERROR, not a failure. The
 *     JUnit log puts that on a `<testcase><error>`, the fixture-name scrape reads `<failure>`
 *     only, so `failing` is necessarily `[]`. That is a REAL abort the fixture set genuinely
 *     provoked.
 *
 * REFUSING THE ESCAPED SHAPE IS A RULING, NOT AN OVERSIGHT — stated here rather than left to
 * read as an accident. The row it would render says "reached, and the guard is load-bearing",
 * and with no fixture named nothing the fixture set produced backs either half. An abort with
 * zero fixture-level evidence gives the artifact nothing to publish, so the chosen failure
 * mode is to refuse loudly and let the operator read the error, not to render a row that
 * asserts more than the run measured. What the JUnit log changes is the REFUSAL and not the
 * ruling: the errored testcases are NAMED in the message, where the old report carried nothing
 * to name.
 *
 * WHY THIS TEST CAN RUN IN SECONDS AND NOT ~57 MINUTES. Every path the generator touches is
 * `--repo`-relative: the enumerator subprocess (`$repo/bin/check-golden-predicates.php`), the
 * suite subprocess (`cd $repo && vendor/bin/phpunit …`), the mutation target and both
 * artifacts under `$repo/docs/`. Only its own `require` of the autoloader resolves against the
 * real bin dir. So this builds a SYNTHETIC repo in a temp dir — a three-predicate source, a
 * stub enumerator, a scripted stub `phpunit` — and runs the REAL script against it. The stub
 * suite is scripted per invocation rather than reactive, which is the point: this exercises
 * the generator's VERDICT logic, not php's ability to parse a mutant.
 *
 * ⛔ WHAT A GREEN RUN HERE DOES NOT BUY. It proves the three guards fire on the signatures that
 * were OBSERVED, and (via the control) that none of them refuses a legitimate abort. It does NOT
 * make every degenerate iteration detectable: the mixed shape — some fixtures errored while
 * others reached a golden diff — still publishes as an ordinary `observed-via-abort` whose
 * evidence is partial, which is why the generator persists the `errors` count into each
 * record and why the control below asserts it. A general "was this iteration real" term stays
 * open on card#7994.
 *
 * Nor does it say anything about any particular predicate. The artifact this guards is
 * generated from `CheckCommand::handle()` alone, so a check that has already migrated into the
 * registry has no predicates there at all — absence from its disclosed-gap list is NOT
 * protection, in either direction, and a refusal that never fires is not a coverage claim.
 */
class CheckGoldenMutateVerdictIntegrityTest extends TestCase
{
    /**
     * The stub suite is invoked once by the BASELINE CONTROL before the loop, so the
     * `$n`-th PREDICATE is invocation `$n + 1`. Every scenario below scripts through
     * {@see scriptBaseline()} / {@see scriptPredicate()} rather than raw invocation numbers,
     * so the offset lives here once instead of being re-derived at each call site.
     */
    private const BASELINE_INVOCATION = 1;

    private string $repo = '';

    /**
     * The synthetic mutation target. Three predicates — two `if` conditions and one `foreach`
     * — deliberately distinguishable by `strpos` so the stub enumerator can report exact byte
     * ranges without a parser. The `foreach` is here to give the "not restricted by predicate
     * kind" ruling a witness; without it the enumerator emitted only `if` records and the
     * ruling was recorded but never exercised.
     */
    private const TARGET_SOURCE = <<<'PHP'
        <?php

        class CheckCommand
        {
            public function handle(): int
            {
                if ($alpha === 'first-marker') {
                    return 1;
                }

                if ($beta === 'second-marker') {
                    return 2;
                }

                foreach ($gammaIterable as $item) {
                    return 3;
                }

                return 0;
            }
        }
        PHP;

    private const FIRST_PREDICATE = "\$alpha === 'first-marker'";

    private const SECOND_PREDICATE = "\$beta === 'second-marker'";

    private const THIRD_PREDICATE = '$gammaIterable';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/check-golden-mutate-'.bin2hex(random_bytes(8));
        $this->buildSyntheticRepo();
    }

    protected function tearDown(): void
    {
        // Deliberately before parent::tearDown() only in ordering, never in effect: this
        // cannot throw, so it cannot cascade over the real teardown's own failures.
        if ($this->repo !== '' && is_dir($this->repo)) {
            exec('rm -rf '.escapeshellarg($this->repo));
        }

        parent::tearDown();
    }

    public function test_the_verdict_is_read_from_the_junit_log_and_not_from_the_suite_stdout(): void
    {
        // THE DISCRIMINATING CASE for card#8019, and the only scenario where the two possible
        // sources disagree ON PURPOSE. Predicate 1's stub prints a report on STDOUT — shaped
        // exactly like the one the generator used to `json_decode()` — naming a fixture that
        // does not exist, while its JUnit log names `minimal`. A generator still reading stdout
        // publishes `stdout-lie`; one reading the log publishes `minimal`.
        //
        // Nothing else in this file needs a lying stdout: the stub prints phpunit's classic
        // text banner everywhere else, so no other scenario has any JSON to read at all.
        $this->scriptBaseline(0, $this->passedJunit());
        $this->scriptPredicate(1, 1, $this->renderedAbortJunit(), $this->paoStyleStdout('stdout-lie'));
        $this->scriptPredicate(2, 0, $this->passedJunit());
        $this->scriptPredicate(3, 0, $this->passedJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(0, $code, "the run must publish.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");

        $raw = (string) file_get_contents($this->repo.'/docs/check-golden-coverage.json');
        $this->assertStringNotContainsString(
            'stdout-lie',
            $raw,
            'the published verdict came from the suite\'s STDOUT — the whole point of reading --log-junit is that a substituted result printer cannot decide what this artifact says.',
        );

        $results = json_decode($raw, true);
        $this->assertIsArray($results);
        $viaAbort = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed-via-abort'));
        $this->assertCount(1, $viaAbort);
        $this->assertSame(
            ['minimal'],
            $viaAbort[0]['failing'],
            'the published record must carry the fixture the JUNIT LOG named.',
        );

        // The presence witness for the absence assertion above: a run that published nothing at
        // all would satisfy `assertStringNotContainsString` trivially.
        $this->assertSame(
            ['observed-via-abort', 'UNOBSERVED', 'UNOBSERVED'],
            array_column($results, 'status'),
            'all three predicates must have been scored, or the absence assertion above is vacuous.',
        );
    }

    public function test_an_observed_via_abort_verdict_naming_no_fixture_refuses_the_whole_run(): void
    {
        // Predicate 1 ERRORED — a `<testcase><error>` and no `<failure>` anywhere. That is red +
        // aborted + `failing === []`: the exact shape run 1 of card#7835 published. Predicates 2
        // and 3 are scripted green, so that WITHOUT the refusal the three would score
        // `observed-via-abort` + `UNOBSERVED` + `UNOBSERVED` — two distinct statuses, which keeps
        // the pre-fix run out of the DEGENERATE RESULT SET arm and makes it write both artifacts
        // at exit 0. That is what this test must see refused.
        $this->scriptBaseline(0, $this->passedJunit());
        $this->scriptPredicate(1, 1, $this->erroredJunit());
        $this->scriptPredicate(2, 0, $this->passedJunit());
        $this->scriptPredicate(3, 0, $this->passedJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "the generator exited {$code}, so it did not refuse.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        // Anchored to the banner's `cause:` slot rather than to bare containment: the
        // progress line the loop prints per predicate also carries the id, so a loose
        // `assertStringContainsString('if-L7')` would go vacuous the moment the throw moved
        // below that `fprintf`. It pins the id INTO the existing report, not the sentence
        // around it.
        $this->assertMatchesRegularExpression(
            '/cause:\s+if-L7 scored observed-via-abort/',
            $stderr,
            'the refusal must NAME the predicate whose verdict was refused — a run that says only "aborted" leaves the reader with no way to find the record.',
        );
        $this->assertStringContainsString(
            'ABORTED — no measurement was produced and no artifact was written.',
            $stderr,
            'the refusal must land in the script\'s EXISTING measurement-integrity report, not in a second differently-shaped one.',
        );
        // What the JUnit log buys over the report it replaced: the errored testcase and its
        // exception type reach the operator, instead of a bare "errors: 1".
        $this->assertStringContainsString(
            'test_golden_output with data set #0',
            $stderr,
            'the refusal must name the errored testcase — the JUnit log carries it, and the whole reason this shape used to be unreadable is that the old report did not.',
        );
        $this->assertStringContainsString(
            'Call to a member function name() on null',
            $stderr,
            'the refusal must relay the error\'s own message, or the operator it tells to "read the suite output" has been given nothing to start from.',
        );

        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.md');
        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.json');

        // The refusal THROWS rather than exits precisely so the `finally` restore still runs;
        // asserting the restore is asserting that reason. Non-vacuous: the stub snapshotted
        // the file mid-run, and that snapshot must DIFFER — otherwise no mutant was ever live
        // and "restored" would be true of a file nothing touched.
        $this->assertNotSame(
            self::TARGET_SOURCE,
            $this->snapshotForPredicate(1),
            'no mutant was live during predicate 1, so the restore assertion below would prove nothing.',
        );
        $this->assertSame(
            self::TARGET_SOURCE,
            (string) file_get_contents($this->repo.'/app/Console/Commands/Bridge/CheckCommand.php'),
            'the mutation target was not restored — the refusal must throw into the existing `finally`, never exit past it.',
        );
    }

    public function test_the_refusal_is_not_restricted_to_if_predicates(): void
    {
        // The recorded ruling is that the signature is "red with no fixture-level evidence",
        // which says nothing about whether the mutation was an `if` negation or a `foreach`
        // emptied to `[]`. Until the synthetic source grew a `foreach` that ruling had no
        // witness. Predicates 1 and 2 pass, so the run reaches the third — the `foreach` —
        // and refuses there.
        $this->scriptBaseline(0, $this->passedJunit());
        $this->scriptPredicate(1, 0, $this->passedJunit());
        $this->scriptPredicate(2, 0, $this->passedJunit());
        $this->scriptPredicate(3, 1, $this->erroredJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "the generator exited {$code}, so it did not refuse a `foreach` predicate.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        // The id is matched by PREFIX rather than written out: a `foreach` id carries a
        // two-digit line offset, and `bin/check-doc-refs.php` bans bare offsets in this
        // directory precisely because they rot. The prefix is the part that carries the claim.
        $this->assertMatchesRegularExpression(
            '/cause:\s+foreach-\S+ scored observed-via-abort/',
            $stderr,
            'the refusal must fire on a `foreach` predicate too — restricting it by kind would leave the identical false verdict publishable.',
        );
        // Pins WHICH predicate it reached without naming its id: the banner reports the
        // count consumed before the throw, so this fails if the enumerator stopped emitting
        // three records or if the run refused earlier than the `foreach`.
        $this->assertStringContainsString(
            'after 2 of 3 predicates',
            $stderr,
            'the run must have scored the two `if` predicates and refused on the third.',
        );
        $this->assertStringContainsString(
            'foreach ([] as $item)',
            $this->snapshotForPredicate(3),
            'the `foreach` mutant must have been the iterable emptied to `[]` — otherwise this witnesses a mutation that was never applied.',
        );

        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.md');
        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.json');
    }

    public function test_a_predicate_whose_suite_wrote_no_junit_log_refuses_the_run(): void
    {
        // ⭐ THE SILENT SHAPE, and the reason card#8019 exists. rc 0 with no log at all: without
        // this guard the predicate scores UNOBSERVED, which the artifact renders as a DISCLOSED
        // GAP — the strongest claim it makes about a predicate — off a run that measured nothing.
        // ⛔ Nothing here is a claim about any real predicate's membership of that list, which is
        // generated from `CheckCommand::handle()` alone: absence from it is not protection, in
        // either direction. What is under test is that an unmeasured iteration cannot reach the
        // list at all. Predicates 2 and 3
        // are green, so the pre-guard run would have produced three UNOBSERVED rows: a single
        // status, which the DEGENERATE RESULT SET arm WOULD have caught here. The real corpus is
        // what makes that arm useless (49 red predicates score one status and 7 green ones
        // another), so the guard is asserted on the iteration and not on the tally.
        $this->scriptBaseline(0, $this->passedJunit());
        $this->scriptPredicate(1, 0, null);
        $this->scriptPredicate(2, 0, $this->passedJunit());
        $this->scriptPredicate(3, 0, $this->passedJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "an iteration that wrote no JUnit log must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertMatchesRegularExpression(
            '/cause:\s+if-L7 produced NO READABLE JUNIT LOG/',
            $stderr,
            'the refusal must name the predicate AND say the log is what was missing — an unmeasured iteration is not an abort, and reporting it as one is a wrong-but-specific cause.',
        );
        $this->assertStringContainsString(
            'ABORTED — no measurement was produced and no artifact was written.',
            $stderr,
            'the refusal must land in the script\'s EXISTING measurement-integrity report.',
        );
        $this->assertStringContainsString(
            'after 0 of 3 predicates',
            $stderr,
            'the run must have refused on the FIRST predicate, before scoring anything.',
        );

        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.md');
        $this->assertFileDoesNotExist($this->repo.'/docs/check-golden-coverage.json');

        // Same reasoning as the abort refusal: a mutant is live on disk here, so this guard
        // must throw into the `finally` rather than exit past it.
        $this->assertNotSame(
            self::TARGET_SOURCE,
            $this->snapshotForPredicate(1),
            'no mutant was live during predicate 1, so the restore assertion below would prove nothing.',
        );
        $this->assertSame(
            self::TARGET_SOURCE,
            (string) file_get_contents($this->repo.'/app/Console/Commands/Bridge/CheckCommand.php'),
            'the mutation target was not restored — this guard must throw into the existing `finally`, never exit past it.',
        );
    }

    public function test_a_legitimate_abort_naming_a_fixture_still_publishes(): void
    {
        // The control. Without it, a refusal that refused EVERYTHING would pass the tests
        // above. Predicate 1 reds with a `<failure>` carrying BOTH a fixture name and an
        // `Uncaught` token — the RENDERED abort shape, non-empty `failing`, zero errors.
        // Predicate 2 is the MIXED shape the refusal deliberately does not catch: a fixture
        // named AND an errored testcase beside it, so its evidence is partial and the persisted
        // `errors` count is the only thing that makes that legible in the artifact.
        // Predicate 3 passes ⇒ `UNOBSERVED`.
        //
        // ⚠ The statuses MUST NOT all coincide. If every predicate scored the same
        // non-`observed` status the generator's own DEGENERATE RESULT SET arm would refuse for
        // an entirely unrelated reason, and this control would be measuring that arm instead
        // of the ones under test.
        $this->scriptBaseline(0, $this->passedJunit());
        $this->scriptPredicate(1, 1, $this->renderedAbortJunit());
        $this->scriptPredicate(2, 1, $this->mixedAbortJunit());
        $this->scriptPredicate(3, 0, $this->passedJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(0, $code, "a legitimate observed-via-abort must still publish.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertFileExists($this->repo.'/docs/check-golden-coverage.md');
        $this->assertFileExists($this->repo.'/docs/check-golden-coverage.json');

        $results = json_decode((string) file_get_contents($this->repo.'/docs/check-golden-coverage.json'), true);
        $this->assertIsArray($results);
        $viaAbort = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed-via-abort'));
        $this->assertCount(2, $viaAbort, 'the control scripted exactly two aborting mutants.');
        $this->assertSame(['minimal'], $viaAbort[0]['failing'], 'the published record must carry the fixture the abort was scraped from.');
        $this->assertSame(['minimal'], $viaAbort[1]['failing']);
        // The `errors` field is what makes the partial-evidence shape auditable after the
        // fact. Its presence is asserted before its value, so dropping the field reds with
        // the reason rather than with an undefined-key error; and it is asserted at BOTH
        // values, so a hard-coded constant cannot satisfy it.
        $this->assertArrayHasKey('errors', $viaAbort[0], 'each published record must carry the run\'s error count — without it the partial-evidence shape is invisible in the artifact.');
        $this->assertArrayHasKey('errors', $viaAbort[1]);
        $this->assertSame(0, $viaAbort[0]['errors'], 'a rendered abort errored nothing, and the record must say so.');
        $this->assertSame(1, $viaAbort[1]['errors'], 'the mixed shape publishes, so its error count must reach the artifact — that count IS the disclosure of its partial evidence.');
        $this->assertSame(
            ['UNOBSERVED'],
            array_values(array_unique(array_column(array_filter($results, fn (array $r) => $r['status'] !== 'observed-via-abort'), 'status'))),
            'the remaining predicate must score UNOBSERVED, or the DEGENERATE RESULT SET arm is what this control measured.',
        );

        // The measurement file must not be written into the tree being mutated: this script
        // already warns that a commit from the same tree can capture a mutant, and a stray
        // artifact in the working copy is one more thing a run can leave behind. Asserted from
        // the path the STUB was handed, so it also witnesses that `--log-junit` was passed at
        // all — the stub refuses the invocation outright when it is not.
        $path = $this->junitPathForBaseline();
        $this->assertNotSame('', $path, 'the generator must invoke the suite with --log-junit.');
        $this->assertStringStartsNotWith($this->repo, $path, 'the JUnit log must be written outside the repo under measurement.');
    }

    public function test_a_baseline_that_wrote_no_junit_log_refuses_before_any_mutation(): void
    {
        // rc 0 here — a HEALTHY corpus that nonetheless produced no measurement — which is what
        // makes this the discriminating case: the refusal cannot be coming from the baseline's
        // "already red" term, because the baseline is green.
        $this->scriptBaseline(0, null);

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a baseline that wrote no JUnit log must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('BASELINE CONTROL FAILED', $stderr);
        $this->assertStringContainsString('NO JUNIT LOG', $stderr);
        $this->assertStringContainsString('--log-junit', $stderr, 'the refusal must name the instrument it did not get, or the operator cannot tell which of the suite\'s outputs is missing.');
        $this->assertStringContainsString('found:   the file was never written', $stderr, 'the banner must say WHICH way the log failed — the three ways take different next steps, and it cannot point at the log itself, which is a temp file the run deletes.');
        $this->assertStringNotContainsString('ALREADY RED', $stderr, 'a green-but-unmeasured baseline is not a red corpus, and reporting one would be a wrong-but-specific cause.');
        // Paired with the presence witness above rather than standing alone: what this asserts
        // is that the refusal no longer offers a REMEDY for a dependency the generator does not
        // have. It read the suite's stdout for its verdict until card#8019, and the remedy for
        // that was to force a result printer to emit JSON; the log makes both false.
        $this->assertDoesNotMatchRegularExpression(
            '/result printer|PAO_FORCE|AI.agent shell/i',
            $stderr,
            'the refusal must not blame — or offer a lever for — a stdout report this generator no longer reads.',
        );

        $this->assertNoMutationWasEverApplied();
    }

    public function test_a_baseline_whose_junit_log_does_not_parse_refuses_before_any_mutation(): void
    {
        // The second term of `measured`, and a separate line of code from the absent-file one:
        // phpunit began writing and did not finish, so the log exists and is not a document.
        // Scored rather than refused, it would decode to zero failures and zero errors — a
        // green verdict, which is the worst possible reading of a truncated file.
        $this->scriptBaseline(0, "<?xml version=\"1.0\"?>\n<testsuites><testsuite name=\"trunc");

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a baseline whose JUnit log does not parse must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('BASELINE CONTROL FAILED', $stderr);
        $this->assertStringContainsString('NO JUNIT LOG', $stderr);
        $this->assertMatchesRegularExpression(
            '/found:\s+\d+ bytes that do not parse as XML: .+ \(line \d+\)/',
            $stderr,
            'the banner must distinguish an unparseable log from an absent one AND relay the parser\'s own complaint — an operator told only "no log" will go looking for a file that is right there.',
        );
        $this->assertStringNotContainsString('ALREADY RED', $stderr);

        $this->assertNoMutationWasEverApplied();
    }

    public function test_a_red_baseline_with_no_junit_log_reports_the_missing_log_and_not_the_corpus(): void
    {
        // The same measurement failure over a corpus that is ALSO red. Both terms of the
        // baseline predicate are true, and the message must lead with the one that explains
        // the other: with no log the script cannot know whether the corpus is red for a real
        // reason, so naming the corpus would assert what it cannot establish.
        $this->scriptBaseline(1, null);

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a baseline that wrote no JUnit log must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('NO JUNIT LOG', $stderr);
        $this->assertStringNotContainsString('ALREADY RED', $stderr);

        $this->assertNoMutationWasEverApplied();
    }

    public function test_a_baseline_that_is_already_red_refuses_naming_the_corpus(): void
    {
        // The second baseline cause, and the one that proves the message discriminates: a
        // readable log, so the instrument is fine — the golden corpus itself is red before
        // anything is mutated, and every verdict the run would produce is contaminated.
        $this->scriptBaseline(1, $this->renderedAbortJunit());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a red baseline must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('BASELINE CONTROL FAILED', $stderr);
        $this->assertStringContainsString('ALREADY RED on the unmutated tree', $stderr);
        $this->assertStringContainsString('failing: minimal', $stderr, 'the refusal must name what was already failing — that list is the operator\'s starting point.');
        $this->assertStringNotContainsString('NO JUNIT LOG', $stderr, 'the log was readable, so the instrument is not the cause and must not be offered as one.');

        $this->assertNoMutationWasEverApplied();
    }

    /**
     * The baseline runs BEFORE the `try`, so a refusal there must leave the tree untouched.
     * Two independent facts, because either alone is satisfiable by an unrelated mistake: the
     * suite was invoked exactly once and saw the pristine source, and no second invocation
     * exists — i.e. the loop never started.
     */
    private function assertNoMutationWasEverApplied(): void
    {
        $this->assertSame(
            self::TARGET_SOURCE,
            $this->snapshotForBaseline(),
            'the baseline control must run against the UNMUTATED tree.',
        );
        $this->assertFileDoesNotExist(
            $this->snapshotPath(self::BASELINE_INVOCATION + 1),
            'the mutation loop ran after a failed baseline — the refusal must precede it.',
        );
        $this->assertSame(
            self::TARGET_SOURCE,
            (string) file_get_contents($this->repo.'/app/Console/Commands/Bridge/CheckCommand.php'),
            'the mutation target changed even though no mutation should have been written.',
        );
    }

    /**
     * Run the REAL generator against the synthetic repo, capturing stdout and stderr apart.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runGenerator(): array
    {
        $stderrFile = $this->repo.'/stderr.txt';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('bin/check-golden-mutate.php'))
            .' --repo '.escapeshellarg($this->repo).' 2>'.escapeshellarg($stderrFile);
        $out = [];
        exec($cmd, $out, $code);

        return [$code, implode("\n", $out), (string) file_get_contents($stderrFile)];
    }

    /** The JUnit log + exit code the stub suite replies with on the BASELINE run. */
    private function scriptBaseline(int $rc, ?string $junit, ?string $stdout = null): void
    {
        $this->scriptSuite(self::BASELINE_INVOCATION, $rc, $junit, $stdout);
    }

    /** The JUnit log + exit code for the `$ordinal`-th PREDICATE (1-based), not invocation. */
    private function scriptPredicate(int $ordinal, int $rc, ?string $junit, ?string $stdout = null): void
    {
        $this->scriptSuite(self::BASELINE_INVOCATION + $ordinal, $rc, $junit, $stdout);
    }

    /**
     * What the stub suite does on invocation `$n`. A NULL `$junit` scripts the suite to write
     * no log at all — the shape a phpunit that never finished leaves behind. `$stdout` defaults
     * to phpunit's classic text banner, which carries no verdict this generator can read.
     */
    private function scriptSuite(int $n, int $rc, ?string $junit, ?string $stdout = null): void
    {
        file_put_contents($this->repo."/vendor/bin/rc-{$n}", (string) $rc);
        file_put_contents($this->repo."/vendor/bin/stdout-{$n}", ($stdout ?? $this->classicTextStdout())."\n");
        if ($junit !== null) {
            file_put_contents($this->repo."/vendor/bin/junit-{$n}", $junit);
        }
    }

    /** The mutation target as the stub saw it on the BASELINE run. */
    private function snapshotForBaseline(): string
    {
        return (string) file_get_contents($this->snapshotPath(self::BASELINE_INVOCATION));
    }

    /** The mutation target as the stub saw it while the `$ordinal`-th predicate was live. */
    private function snapshotForPredicate(int $ordinal): string
    {
        return (string) file_get_contents($this->snapshotPath(self::BASELINE_INVOCATION + $ordinal));
    }

    private function snapshotPath(int $invocation): string
    {
        return $this->repo."/vendor/bin/seen-{$invocation}.php";
    }

    /** The `--log-junit` path the generator handed the stub on the baseline run, or ''. */
    private function junitPathForBaseline(): string
    {
        $path = $this->repo.'/vendor/bin/junitpath-'.self::BASELINE_INVOCATION;

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    /**
     * A JUnit log in the shape phpunit actually writes one: a `<testsuites>` root, a per-class
     * `<testsuite>`, a per-method `<testsuite>` inside it, and the `<testcase>` elements under
     * that. The nesting is faithful on purpose — the generator reads the document with an
     * `//testcase/...` xpath, and a flattened fixture would not exercise that.
     *
     * @param  list<array{failure?: string, error?: string}>  $cases
     */
    private function junitLog(array $cases): string
    {
        $class = 'Tests\Feature\Console\Check\CheckGoldenTest';
        $failures = 0;
        $errors = 0;
        $body = '';
        foreach ($cases as $i => $case) {
            $name = "test_golden_output with data set #{$i}";
            $open = sprintf(
                '      <testcase name="%s" file="/synthetic/CheckGoldenTest.php" line="1" class="%s" classname="Tests.Feature.Console.Check.CheckGoldenTest" assertions="1" time="0.01"',
                htmlspecialchars($name, ENT_XML1 | ENT_QUOTES),
                htmlspecialchars($class, ENT_XML1 | ENT_QUOTES),
            );
            if (isset($case['failure'])) {
                $failures++;
                $body .= $open.">\n        <failure type=\"PHPUnit\\Framework\\ExpectationFailedException\">"
                    .htmlspecialchars("{$class}::{$name}\n".$case['failure'], ENT_XML1 | ENT_QUOTES)
                    ."</failure>\n      </testcase>\n";
            } elseif (isset($case['error'])) {
                $errors++;
                $body .= $open.">\n        <error type=\"Error\">"
                    .htmlspecialchars("{$class}::{$name}\n".$case['error'], ENT_XML1 | ENT_QUOTES)
                    ."</error>\n      </testcase>\n";
            } else {
                $body .= $open."/>\n";
            }
        }

        $counts = sprintf(
            'tests="%d" assertions="%d" errors="%d" failures="%d" skipped="0" time="0.03"',
            count($cases), count($cases), $errors, $failures,
        );

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n"
            ."  <testsuite name=\"{$class}\" file=\"/synthetic/CheckGoldenTest.php\" {$counts}>\n"
            ."    <testsuite name=\"{$class}::test_golden_output\" {$counts}>\n"
            .$body
            ."    </testsuite>\n  </testsuite>\n</testsuites>\n";
    }

    /** Three green testcases: no `<failure>`, no `<error>`. */
    private function passedJunit(): string
    {
        return $this->junitLog([[], [], []]);
    }

    /** phpunit ERRORED: an `<error>` and no `<failure>`, so `failing` is necessarily empty. */
    private function erroredJunit(): string
    {
        return $this->junitLog([
            ['error' => 'Error: Call to a member function name() on null'],
            [],
            [],
        ]);
    }

    /** The RENDERED abort: a fail-soft envelope printed, so the failure names its fixture. */
    private function renderedAbortJunit(): string
    {
        return $this->junitLog([
            ['failure' => "golden fixture 'minimal' differs: Uncaught RuntimeException: boom"],
            [],
            [],
        ]);
    }

    /** The MIXED shape: one fixture reached a golden diff while another errored outright. */
    private function mixedAbortJunit(): string
    {
        return $this->junitLog([
            ['failure' => "golden fixture 'minimal' differs: - expected + actual"],
            ['error' => 'Error: Call to a member function name() on null'],
            [],
        ]);
    }

    /**
     * What phpunit prints on stdout with no JSON-emitting result printer installed. This is the
     * stub's DEFAULT stdout in every scenario, which is what makes the whole file a witness that
     * the verdict comes from the log: a generator reading stdout finds nothing here.
     */
    private function classicTextStdout(): string
    {
        return "PHPUnit 13.2.6 by Sebastian Bergmann and contributors.\n"
            ."\nRuntime:       PHP 8.5.9\nConfiguration: /synthetic/phpunit.xml\n"
            ."\n...                                                                 3 / 3 (100%)\n"
            ."\nTime: 00:00.030, Memory: 64.00 MB\n\nOK (3 tests, 3 assertions)";
    }

    /**
     * A report on STDOUT shaped like the one this generator used to read, naming `$fixture` as
     * the failure. Used only where the two sources are made to DISAGREE.
     */
    private function paoStyleStdout(string $fixture): string
    {
        return json_encode([
            'tool' => 'phpunit',
            'result' => 'failed',
            'tests' => 3,
            'passed' => 2,
            'duration_ms' => 9,
            'failed' => 1,
            'failures' => [[
                'test' => 'Tests\Feature\Console\Check\CheckGoldenTest::test_golden_output with data set #0',
                'file' => 'tests/Feature/Console/Check/CheckGoldenTest.php',
                'line' => 1,
                'message' => "golden fixture '{$fixture}' differs: Uncaught RuntimeException: boom",
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function buildSyntheticRepo(): void
    {
        foreach (['app/Console/Commands/Bridge', 'bin', 'docs', 'vendor/bin'] as $dir) {
            mkdir($this->repo.'/'.$dir, 0755, true);
        }
        file_put_contents($this->repo.'/app/Console/Commands/Bridge/CheckCommand.php', self::TARGET_SOURCE);

        // The enumerator stub. The real one parses `handle()` with php-parser; this locates
        // each marker with `strpos` and emits the same record shape the generator consumes —
        // id / kind / line / inclusive byte range / source, with the id spelled
        // `<kind>-L<line>` exactly as the real enumerator spells it. It ignores `--json` and
        // always prints JSON, because the generator only ever invokes it that way.
        $markers = var_export([
            [self::FIRST_PREDICATE, 'if'],
            [self::SECOND_PREDICATE, 'if'],
            [self::THIRD_PREDICATE, 'foreach'],
        ], true);
        file_put_contents($this->repo.'/bin/check-golden-predicates.php', <<<PHP
            #!/usr/bin/env php
            <?php
            \$code = (string) file_get_contents(dirname(__DIR__).'/app/Console/Commands/Bridge/CheckCommand.php');
            \$predicates = [];
            foreach ({$markers} as [\$marker, \$kind]) {
                \$start = strpos(\$code, \$marker);
                if (\$start === false) {
                    fwrite(STDERR, "marker not found: {\$marker}\\n");
                    exit(2);
                }
                \$line = substr_count(substr(\$code, 0, \$start), "\\n") + 1;
                \$predicates[] = [
                    'id' => \$kind.'-L'.\$line,
                    'kind' => \$kind,
                    'line' => \$line,
                    'start' => \$start,
                    'end' => \$start + strlen(\$marker) - 1,
                    'source' => \$marker,
                ];
            }
            echo json_encode(\$predicates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\\n";
            exit(0);
            PHP);

        // The suite stub. Stateful across invocations — the generator runs it once for the
        // baseline control and once per predicate, and they must be able to answer
        // differently — and driven entirely by the `rc-N`/`junit-N`/`stdout-N` files the test
        // writes, so no scenario logic lives in here. An UNSCRIPTED invocation is loud (exit 99)
        // rather than silently defaulting to something that would read as a verdict, and so is
        // an invocation carrying no `--log-junit` (exit 98) — that flag IS the mechanism, so
        // losing it must not degrade into a scenario that merely looks unscripted.
        //
        // It writes the scripted log to the path the GENERATOR chose, records that path so the
        // test can assert where it lands, and snapshots the mutation target it was called
        // against — which is how the refusal tests prove a mutant really was live, and how the
        // baseline tests prove one never was.
        file_put_contents($this->repo.'/vendor/bin/phpunit', <<<'BASH'
            #!/usr/bin/env bash
            set -u
            dir="$(cd "$(dirname "$0")" && pwd)"
            n=$(( $(cat "$dir/invocations" 2>/dev/null || echo 0) + 1 ))
            printf '%s' "$n" > "$dir/invocations"
            cp "$dir/../../app/Console/Commands/Bridge/CheckCommand.php" "$dir/seen-$n.php"
            junit=""
            prev=""
            for arg in "$@"; do
                if [ "$prev" = "--log-junit" ]; then junit="$arg"; fi
                prev="$arg"
            done
            if [ -z "$junit" ]; then
                printf 'the generator invoked the suite with no --log-junit path\n' >&2
                exit 98
            fi
            printf '%s' "$junit" > "$dir/junitpath-$n"
            if [ ! -f "$dir/rc-$n" ]; then
                printf 'unscripted suite invocation %s\n' "$n" >&2
                exit 99
            fi
            if [ -f "$dir/junit-$n" ]; then
                cat "$dir/junit-$n" > "$junit"
            fi
            cat "$dir/stdout-$n"
            exit "$(cat "$dir/rc-$n")"
            BASH);
        chmod($this->repo.'/vendor/bin/phpunit', 0755);
    }
}
