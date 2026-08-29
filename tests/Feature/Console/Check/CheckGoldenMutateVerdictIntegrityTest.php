<?php

namespace Tests\Feature\Console\Check;

use Tests\TestCase;

/**
 * `bin/check-golden-mutate.php` must refuse to publish a verdict that no fixture backs
 * (card#7994). Two guards do that, and this file drives both:
 *
 *   THE BASELINE CONTROL — one suite run against the UNMUTATED tree before the loop starts.
 *     It refuses the whole run unless that run is green AND emitted a decodable report.
 *   THE IN-LOOP REFUSAL — an `observed-via-abort` naming no failing fixture.
 *
 * WHAT WENT WRONG WITHOUT THE BASELINE CONTROL. Every verdict the generator scores is read
 * out of a JSON report on the suite's stdout, and `vendor/bin/phpunit` emits that report only
 * under `laravel/pao`, which activates when `laravel/agent-detector` finds an agent
 * environment variable (`CLAUDECODE`, `AI_AGENT`, `CURSOR_AGENT`, …) or when `PAO_FORCE` is
 * set. In an ordinary operator shell phpunit prints its classic text output, `json_decode`
 * returns null, and EVERY red predicate scores `observed-via-abort` naming no fixture while
 * every green one scores UNOBSERVED. Two distinct statuses, so the degenerate-result-set arm
 * stays quiet — and the run publishes a full artifact of pure noise at exit 0. Nothing in the
 * script or in any doc stated that dependency before this card.
 *
 * WHAT WENT WRONG WITHOUT THE IN-LOOP REFUSAL. Run 1 of card#7835's regeneration scored the
 * `CheckSlot::ProbeTools` `emitReport` arm `observed-via-abort` with `"failing": []`. The
 * generated table renders that row exactly like every other reached-but-not-distinguished
 * predicate, so it read as a real result: the false verdict was committed, and it had already
 * propagated into a conclusion in `docs/CHECK-REGISTRY-PLAN.md` before an adversarial review
 * caught it. (Cited by its CONDITION and never by the artifact's own row id: those ids are
 * line-anchored, they rot the moment anything above them moves — the id that record carried
 * now belongs to the same predicate scored `observed` — and the coverage diff keys on the
 * condition text anyway.)
 *
 * ⚠ WHAT AN EMPTY `failing` DOES AND DOES NOT MEAN. An earlier cut of this guard claimed that
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
 *     throwable propagates out of the test and phpunit records an ERROR, not a failure.
 *     `laravel/pao` writes the `failures` key only when its failed count is above zero, so an
 *     all-errors report has no `failures` at all and `failing` is necessarily `[]`. That is a
 *     REAL abort the fixture set genuinely provoked.
 *
 * REFUSING THE ESCAPED SHAPE IS A RULING, NOT AN OVERSIGHT — stated here rather than left to
 * read as an accident. The row it would render says "reached, and the guard is load-bearing",
 * and with no fixture named nothing the fixture set produced backs either half. An abort with
 * zero fixture-level evidence gives the artifact nothing to publish, so the chosen failure
 * mode is to refuse loudly and let the operator read the error, not to render a row that
 * asserts more than the run measured.
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
 * ⛔ WHAT A GREEN RUN HERE DOES NOT BUY. It proves the two guards fire on the signatures that
 * were OBSERVED, and (via the control) that neither refuses a legitimate abort. It does NOT
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

    public function test_an_observed_via_abort_verdict_naming_no_fixture_refuses_the_whole_run(): void
    {
        // Predicate 1 ERRORED — `errors: 1` with error_details and NO `failures` key at all.
        // That is red + aborted + `failing === []`: the exact shape run 1 of card#7835
        // published. Predicates 2 and 3 are scripted green, so that WITHOUT the refusal the
        // three would score `observed-via-abort` + `UNOBSERVED` + `UNOBSERVED` — two distinct
        // statuses, which keeps the pre-fix run out of the DEGENERATE RESULT SET arm and makes
        // it write both artifacts at exit 0. That is what this test must see refused.
        $this->scriptBaseline(0, $this->passedReport());
        $this->scriptPredicate(1, 1, $this->erroredReport());
        $this->scriptPredicate(2, 0, $this->passedReport());
        $this->scriptPredicate(3, 0, $this->passedReport());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "the generator exited {$code}, so it did not refuse.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        // Anchored to the banner's `cause:` slot rather than to bare containment: the
        // progress line the loop prints per predicate also carries the id, so a loose
        // `assertStringContainsString('if-L7')` would go vacuous the moment the throw moved
        // below that `fprintf`. It pins the id INTO the existing report, not the sentence
        // around it.
        $this->assertMatchesRegularExpression(
            '/cause:\s+if-L7 /',
            $stderr,
            'the refusal must NAME the predicate whose verdict was refused — a run that says only "aborted" leaves the reader with no way to find the record.',
        );
        $this->assertStringContainsString(
            'ABORTED — no measurement was produced and no artifact was written.',
            $stderr,
            'the refusal must land in the script\'s EXISTING measurement-integrity report, not in a second differently-shaped one.',
        );
        // The message must not relay the cause the baseline control has already ruled out.
        $this->assertStringNotContainsString(
            'PAO_FORCE',
            $stderr,
            'with a green, decodable baseline behind it this refusal is not an environment failure, and blaming one is a wrong-but-specific cause.',
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
        $this->scriptBaseline(0, $this->passedReport());
        $this->scriptPredicate(1, 0, $this->passedReport());
        $this->scriptPredicate(2, 0, $this->passedReport());
        $this->scriptPredicate(3, 1, $this->erroredReport());

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

    public function test_a_legitimate_abort_naming_a_fixture_still_publishes(): void
    {
        // The control. Without it, a refusal that refused EVERYTHING would pass the tests
        // above. Predicate 1 reds with a failure message carrying BOTH a fixture name and an
        // `Uncaught` token — the RENDERED abort shape, non-empty `failing`, zero errors.
        // Predicate 2 is the MIXED shape the refusal deliberately does not catch: a fixture
        // named AND an errored test beside it, so its evidence is partial and the persisted
        // `errors` count is the only thing that makes that legible in the artifact.
        // Predicate 3 passes ⇒ `UNOBSERVED`.
        //
        // ⚠ The statuses MUST NOT all coincide. If every predicate scored the same
        // non-`observed` status the generator's own DEGENERATE RESULT SET arm would refuse for
        // an entirely unrelated reason, and this control would be measuring that arm instead
        // of the ones under test.
        $this->scriptBaseline(0, $this->passedReport());
        $this->scriptPredicate(1, 1, $this->renderedAbortReport());
        $this->scriptPredicate(2, 1, $this->mixedAbortReport());
        $this->scriptPredicate(3, 0, $this->passedReport());

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
    }

    public function test_a_baseline_emitting_no_decodable_report_refuses_before_any_mutation(): void
    {
        // The arm that fires in a real operator shell, and the one the stub could not reach
        // until now: `laravel/pao` is not emitting, so phpunit prints its classic text banner
        // and `json_decode` returns null. rc 0 here — a HEALTHY corpus in a non-agent shell —
        // which is what makes this the discriminating case: the refusal cannot be coming from
        // the baseline's "already red" term, because the baseline is green.
        $this->scriptBaseline(0, $this->classicTextReport('OK (33 tests, 99 assertions)'));

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a baseline that emits no decodable report must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('BASELINE CONTROL FAILED', $stderr);
        $this->assertStringContainsString('NO DECODABLE REPORT', $stderr);
        $this->assertStringContainsString('laravel/pao', $stderr, 'the refusal must name the actual cause — the report emitter, not the golden harness.');
        $this->assertStringContainsString('PAO_FORCE=1', $stderr, 'the refusal must carry the lever that fixes it.');
        $this->assertStringNotContainsString('ALREADY RED', $stderr, 'a green-but-undecodable baseline is not a red corpus, and reporting one would be a wrong-but-specific cause.');

        $this->assertNoMutationWasEverApplied();
    }

    public function test_a_red_undecodable_baseline_reports_the_missing_report_and_not_the_corpus(): void
    {
        // The same environment failure over a corpus that is ALSO red. Both terms of the
        // baseline predicate are true, and the message must lead with the one that explains
        // the other: with no decodable report the script cannot know whether the corpus is
        // red for a real reason, so naming the corpus would assert what it cannot establish.
        $this->scriptBaseline(1, $this->classicTextReport('FAILURES!'));

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a baseline that emits no decodable report must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('NO DECODABLE REPORT', $stderr);
        $this->assertStringContainsString('PAO_FORCE=1', $stderr);
        $this->assertStringNotContainsString('ALREADY RED', $stderr);

        $this->assertNoMutationWasEverApplied();
    }

    public function test_a_baseline_that_is_already_red_refuses_naming_the_corpus(): void
    {
        // The second baseline cause, and the one that proves the message discriminates: a
        // decodable report, so the environment is fine — the golden corpus itself is red
        // before anything is mutated, and every verdict the run would produce is contaminated.
        $this->scriptBaseline(1, $this->renderedAbortReport());

        [$code, $stdout, $stderr] = $this->runGenerator();

        $this->assertSame(2, $code, "a red baseline must refuse the run.\nstdout:\n{$stdout}\nstderr:\n{$stderr}");
        $this->assertStringContainsString('BASELINE CONTROL FAILED', $stderr);
        $this->assertStringContainsString('ALREADY RED on the unmutated tree', $stderr);
        $this->assertStringContainsString('failing: minimal', $stderr, 'the refusal must name what was already failing — that list is the operator\'s starting point.');
        $this->assertStringNotContainsString('PAO_FORCE', $stderr, 'the report decoded fine, so the environment is not the cause and must not be offered as one.');

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

    /** The report body + exit code the stub suite replies with on the BASELINE run. */
    private function scriptBaseline(int $rc, string $body): void
    {
        $this->scriptSuite(self::BASELINE_INVOCATION, $rc, $body);
    }

    /** The report body + exit code for the `$ordinal`-th PREDICATE (1-based), not invocation. */
    private function scriptPredicate(int $ordinal, int $rc, string $body): void
    {
        $this->scriptSuite(self::BASELINE_INVOCATION + $ordinal, $rc, $body);
    }

    /** The report body + exit code the stub suite replies with on invocation `$n`. */
    private function scriptSuite(int $n, int $rc, string $body): void
    {
        file_put_contents($this->repo."/vendor/bin/reply-{$n}", $body."\n");
        file_put_contents($this->repo."/vendor/bin/rc-{$n}", (string) $rc);
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

    private function passedReport(): string
    {
        return json_encode([
            'result' => 'passed',
            'tests' => 3,
            'passed' => 3,
            'duration_ms' => 9,
        ], JSON_THROW_ON_ERROR);
    }

    /** phpunit ERRORED: no `failures` key at all, so `failing` is necessarily empty. */
    private function erroredReport(): string
    {
        return json_encode([
            'result' => 'failed',
            'tests' => 3,
            'passed' => 2,
            'duration_ms' => 9,
            'errors' => 1,
            'error_details' => [[
                'test' => 'Tests\Feature\Console\Check\CheckGoldenTest::test_golden_output',
                'file' => 'tests/Feature/Console/Check/CheckGoldenTest.php',
                'line' => 1,
                'message' => 'Error: Call to a member function name() on null',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** The RENDERED abort: a fail-soft envelope printed, so the failure names its fixture. */
    private function renderedAbortReport(): string
    {
        return json_encode([
            'result' => 'failed',
            'tests' => 3,
            'passed' => 2,
            'duration_ms' => 9,
            'failed' => 1,
            'failures' => [[
                'test' => 'Tests\Feature\Console\Check\CheckGoldenTest::test_golden_output',
                'file' => 'tests/Feature/Console/Check/CheckGoldenTest.php',
                'line' => 1,
                'message' => "golden fixture 'minimal' differs: Uncaught RuntimeException: boom",
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** The MIXED shape: one fixture reached a golden diff while another errored outright. */
    private function mixedAbortReport(): string
    {
        return json_encode([
            'result' => 'failed',
            'tests' => 3,
            'passed' => 1,
            'duration_ms' => 9,
            'failed' => 1,
            'failures' => [[
                'test' => 'Tests\Feature\Console\Check\CheckGoldenTest::test_golden_output',
                'file' => 'tests/Feature/Console/Check/CheckGoldenTest.php',
                'line' => 1,
                'message' => "golden fixture 'minimal' differs: - expected + actual",
            ]],
            'errors' => 1,
            'error_details' => [[
                'test' => 'Tests\Feature\Console\Check\CheckGoldenTest::test_golden_output',
                'file' => 'tests/Feature/Console/Check/CheckGoldenTest.php',
                'line' => 1,
                'message' => 'Error: Call to a member function name() on null',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** What phpunit prints when `laravel/pao` is not active: no JSON anywhere in it. */
    private function classicTextReport(string $verdict): string
    {
        return "PHPUnit 13.2.6 by Sebastian Bergmann and contributors.\n"
            ."\nRuntime:       PHP 8.5.9\nConfiguration: /synthetic/phpunit.xml\n"
            ."\n...F                                                              33 / 33 (100%)\n"
            ."\nTime: 00:04.112, Memory: 64.00 MB\n"
            ."\n{$verdict}";
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
        // differently — and driven entirely by the `reply-N`/`rc-N` files the test writes, so
        // no scenario logic lives in here. An UNSCRIPTED invocation is loud (exit 99, no
        // report) rather than silently defaulting to something that would read as a verdict.
        // It also snapshots the mutation target it was called against, which is how the
        // refusal tests prove a mutant really was live — and how the baseline tests prove one
        // never was.
        file_put_contents($this->repo.'/vendor/bin/phpunit', <<<'BASH'
            #!/usr/bin/env bash
            set -u
            dir="$(cd "$(dirname "$0")" && pwd)"
            n=$(( $(cat "$dir/invocations" 2>/dev/null || echo 0) + 1 ))
            printf '%s' "$n" > "$dir/invocations"
            cp "$dir/../../app/Console/Commands/Bridge/CheckCommand.php" "$dir/seen-$n.php"
            if [ ! -f "$dir/reply-$n" ]; then
                printf 'unscripted suite invocation %s\n' "$n" >&2
                exit 99
            fi
            cat "$dir/reply-$n"
            exit "$(cat "$dir/rc-$n")"
            BASH);
        chmod($this->repo.'/vendor/bin/phpunit', 0755);
    }
}
