<?php

namespace Tests\Support;

use SimpleXMLElement;
use Tests\Feature\Support\StrayRequestGuardCascadeTest;
use Tests\Feature\Support\StrayRequestGuardTest;

/**
 * Runs throwaway test classes in a REAL nested `phpunit` process and reads the verdict from
 * phpunit's own JUnit log.
 *
 * For the two properties of `Tests\TestCase::tearDown()` that no in-process assertion can
 * observe, because their subject is *what the runner reports for a test case that has already
 * finished*: that a reported stray does not cascade into the NEXT class
 * ({@see StrayRequestGuardCascadeTest}), and that a stale
 * `expectStrayRequest()` declaration fails its own test
 * ({@see StrayRequestGuardTest}) — a test that fails on purpose cannot
 * assert its own failure from inside itself.
 *
 * ⚑ The verdict is read from `--log-junit` and never from stdout: the JUnit log is phpunit's
 * own, so it cannot drift with whichever result printer the tree happens to have installed.
 * Fixture AUTHORING stays with each caller — the shared thing is the run and the read.
 */
trait NestedSuite
{
    /**
     * Run every test class in `$dir` in a nested phpunit process.
     *
     * @return array{0:int,1:SimpleXMLElement} [exit code, parsed JUnit log]
     */
    protected function runNestedSuite(string $dir): array
    {
        $junitPath = $dir.'/junit.xml';

        // The repo's own configuration, so the nested run's env is the suite's env — a
        // fixture that resolved a different bridge dir would be answering about a tree this
        // suite does not have.
        //
        // ⛔ EXCEPT the database, which is pinned to sqlite `:memory:` and must stay pinned.
        // CI's MariaDB matrix sets `DB_CONNECTION=mysql` as a JOB-level env var, which a child
        // process inherits and `phpunit.xml`'s unforced `<env>` does not override — and a
        // `RefreshDatabase` fixture on a non-in-memory driver runs `migrate:fresh`, so an
        // unpinned nested run would DROP EVERY TABLE of the shared CI database while the outer
        // suite was still using it. Pinned, the child owns a private in-memory database and can
        // touch nothing the parent has. It is also the shape the property under test lives in:
        // the rollback that a skipped `parent::tearDown()` loses is cached on the STATIC
        // `RefreshDatabaseState::$inMemoryConnections`.
        $command = sprintf(
            'cd %s && DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= %s %s --configuration %s --do-not-cache-result --log-junit %s %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('vendor/bin/phpunit')),
            escapeshellarg(base_path('phpunit.xml')),
            escapeshellarg($junitPath),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertFileExists($junitPath, "the nested run produced no JUnit log:\n".implode("\n", $output));

        $junit = simplexml_load_string((string) file_get_contents($junitPath));
        $this->assertInstanceOf(SimpleXMLElement::class, $junit, 'the nested run wrote an unparseable JUnit log');

        return [$exitCode, $junit];
    }

    /** The `<testsuite>` element the nested run recorded for one fixture class. */
    protected function nestedSuiteFor(SimpleXMLElement $junit, string $class): SimpleXMLElement
    {
        foreach ($junit->testsuite->testsuite as $suite) {
            if (str_ends_with((string) $suite['name'], '\\'.$class)) {
                return $suite;
            }
        }

        $this->fail("the nested run recorded no results at all for {$class} — it did not run");
    }

    /**
     * The `<failure>` text recorded for a fixture class's single test, or '' when it recorded
     * none. Deliberately reads `<failure>` ONLY: an `<error>` is a different verdict, and
     * accepting either would pass on exactly the cascade this harness exists to detect.
     */
    protected function nestedFailureFor(SimpleXMLElement $junit, string $class): string
    {
        return (string) ($this->nestedSuiteFor($junit, $class)->testcase->failure ?? '');
    }
}
