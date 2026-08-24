<?php

namespace Tests\Feature\Support;

use Tests\Feature\Workflows\DlCollisionGateTest;
use Tests\Support\NestedSuite;
use Tests\TestCase;

/**
 * ⛔ ONE stray must not silence the NEXT class's stray — the property `DL-303`'s derivation
 * *"run the whole suite and read the reds"* stands on, and the reason `Tests\TestCase::tearDown()`
 * wraps its guards in `finally`.
 *
 * Every guard there reports by THROWING, and a throw out of `tearDown()` skips `parent::tearDown()`.
 * `RefreshDatabase` registers its rollback as a `beforeApplicationDestroyed` callback and caches
 * the PDO in the STATIC `RefreshDatabaseState::$inMemoryConnections`, so the skipped rollback
 * leaves an open transaction on a connection that outlives the test case: every later test in the
 * process errors `cannot start a transaction within a transaction` in `setUp()` — before it can
 * make, refuse, or report a request of its own. The reds would then be a SAMPLE of the strays
 * with the sample size decided by which class happened to run first, which is the defect
 * card#7300 exists to remove, re-minted in the mechanism that removes it.
 *
 * WHY A NESTED `phpunit` RUN AND NOT AN IN-PROCESS ASSERTION. The failure mode is *what the
 * runner reports for a LATER class*, and no assertion inside one test case can observe that: the
 * cascade begins after this test's own `tearDown()` returns. So the subject is a real run over
 * two throwaway `RefreshDatabase` classes, and the verdict is read from phpunit's own JUnit log
 * rather than from stdout — the log is phpunit's, not a printer's, so it cannot drift with one.
 * Same shape as {@see DlCollisionGateTest}, which executes the real CI
 * bash rather than re-implementing its predicate.
 *
 * ⚑ WHAT IT DOES NOT COVER. `parent::tearDown()` can itself throw — `Mockery::close()` raises
 * `InvalidCountException` for an unmet expectation — and a `finally` lets that exception REPLACE
 * the guard's. Measured: a fixture that strays AND leaves a mock expectation unmet reports the
 * Mockery error and never names its url. That is a bounded third under-report, disclosed in
 * `CLAUDE_TESTING.md` § Outbound HTTP; it is not the cascade, which this test pins closed.
 */
class StrayRequestGuardCascadeTest extends TestCase
{
    use NestedSuite;

    private const FIRST_URL = 'https://kanban.example.com/api/v3/first-class-stray.json';

    private const SECOND_URL = 'https://kanban.example.com/api/v3/second-class-stray.json';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/stray-cascade-'.uniqid();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }

        parent::tearDown();
    }

    public function test_a_stray_in_one_class_does_not_stop_the_next_class_reporting_its_own(): void
    {
        // Alphabetical file order decides which runs first; both are asserted, so the claim
        // ("each class names its OWN url") does not depend on that order.
        $this->writeStrayingClass('FirstStrayingTest', self::FIRST_URL);
        $this->writeStrayingClass('SecondStrayingTest', self::SECOND_URL);

        [$exitCode, $junit] = $this->runNestedSuite($this->dir);

        // The presence witness: without this the pair below could pass on a run where the
        // guard never fired at all — nothing to cascade, and nothing measured.
        $this->assertStringContainsString(
            self::FIRST_URL,
            $this->nestedFailureFor($junit, 'FirstStrayingTest'),
            'the FIRST straying class must fail naming its own url — if it does not, the guard did not fire and this test measured nothing',
        );

        // The finding. Before the `finally`, this testcase carried an <error> from setUp
        // (`cannot start a transaction within a transaction`) and its url was never named.
        $this->assertStringContainsString(
            self::SECOND_URL,
            $this->nestedFailureFor($junit, 'SecondStrayingTest'),
            'the SECOND straying class must fail naming its OWN url — a stray that only reports for the first class makes the reds a sample',
        );

        $this->assertSame(
            '0',
            (string) $junit->testsuite['errors'],
            'a reported stray must not ERROR any other test: parent::tearDown() has to run so RefreshDatabase can roll back',
        );
        $this->assertSame(1, $exitCode, 'phpunit exits 1 for failures and 2 once anything errors');

        // The witness for the harness's database pin, which nothing else can red. These
        // fixtures use RefreshDatabase, and on a non-in-memory driver that runs
        // `migrate:fresh` — so an unpinned nested run would drop every table of the database
        // the OUTER run is using (CI's MariaDB matrix exports DB_CONNECTION at job level).
        $this->assertSame(
            'sqlite',
            trim((string) file_get_contents($this->dir.'/driver.txt')),
            'the nested run must resolve its own in-memory sqlite database — see Tests\Support\NestedSuite',
        );
    }

    private function writeStrayingClass(string $class, string $url): void
    {
        file_put_contents($this->dir.'/'.$class.'.php', <<<PHP
        <?php

        namespace Tests\\Fixtures\\StrayCascade;

        use Illuminate\\Foundation\\Testing\\RefreshDatabase;
        use Illuminate\\Support\\Facades\\Http;
        use Tests\\TestCase;

        class {$class} extends TestCase
        {
            use RefreshDatabase;

            public function test_it_strays_and_the_caller_swallows_the_refusal(): void
            {
                file_put_contents(__DIR__.'/driver.txt', \DB::connection()->getDriverName());

                try {
                    Http::get('{$url}');
                } catch (\\Throwable) {
                    // degraded and said nothing — the production posture the recorder exists for
                }

                \$this->assertTrue(true);
            }
        }
        PHP);
    }
}
