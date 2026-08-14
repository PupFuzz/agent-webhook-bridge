<?php

namespace Tests\Feature\Workflows;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The one copy of the bin/ python tool set that CANNOT be consolidated away
 * (card#5977), made detectable instead.
 *
 * `provision-tools-python.yml` used to name the tool set three times — both
 * `paths:` filters and a `python3 -m unittest <modules>` list. Two of those are
 * gone: the run step discovers, so the filesystem is the enumeration. The third
 * cannot follow. A `paths:` key is static YAML that GitHub evaluates BEFORE the
 * job exists, so no step in that workflow — and no python test it runs — can
 * observe it. Its failure mode is also the worst of the three: a filter that
 * stops covering the tools does not turn the job red, it stops the job from
 * running at all, and an absent check reports nothing (measured on this very
 * workflow: the DL-242 stage-7 migration invalidated a guard that was
 * structurally unable to fire on the change that invalidated it, and `dev` sat
 * red unseen because the `push:` filter had the same gap).
 *
 * So the check lives OUTSIDE that workflow, in the PHP suite, which runs under
 * laravel-tests.yml — the one workflow that deliberately carries no path filter
 * of its own and is a required status check. A gap in the python job's filter is
 * therefore red on every PR, including PRs the python job itself cannot see.
 */
class PythonToolsPathFilterTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/provision-tools-python.yml';

    /** @return array<string,mixed> */
    private function workflow(): array
    {
        return Yaml::parseFile(base_path(self::WORKFLOW));
    }

    /**
     * Every python file under bin/, repo-relative — the population the run step's
     * `unittest discover` reads, plus the tools those tests exercise.
     *
     * @return list<string>
     */
    private function pythonFilesUnderBin(): array
    {
        $root = base_path('bin');
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'py') {
                $found[] = 'bin/'.ltrim(substr($file->getPathname(), strlen($root)), '/');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * GitHub's filter-pattern matcher is NOT reimplemented here. Only the two
     * forms this workflow actually uses are understood — a literal path, and a
     * pattern carrying exactly one `**` and no other wildcard — and any other
     * form fails the test rather than being guessed at. Both guesses are wrong
     * in a way that matters: a matcher that returned false for an unknown form
     * would report a covered file as a gap, and one that returned true would
     * report a real gap as covered. Widen this deliberately, with a case, if a
     * new form is ever needed.
     *
     * `**` is "zero or more of any character", INCLUDING `/` — GitHub's own
     * filter-pattern table gives `**.js` matching `index.js`, `js/index.js` and
     * `src/js/app.js`. A single `*` is the same minus `/`, and `?`, `+`, `[]`
     * and a leading `!` are all further forms; none is used here and none is
     * approximated here.
     */
    private function patternFormIsUnderstood(string $pattern): bool
    {
        if (preg_match('/[?+\[\]]/', $pattern) === 1 || str_starts_with($pattern, '!')) {
            return false;
        }

        $stars = substr_count($pattern, '*');

        return $stars === 0 || ($stars === 2 && str_contains($pattern, '**'));
    }

    /** @param  list<string>  $patterns */
    private function assertPatternFormsAreUnderstood(array $patterns, string $key): void
    {
        foreach ($patterns as $pattern) {
            $this->assertTrue(
                $this->patternFormIsUnderstood($pattern),
                "on.{$key}.paths carries the pattern '{$pattern}', a form this test does not know how to "
                .'evaluate. Teach it that form — leaving it unhandled would make the coverage assertion below '
                .'silently meaningless for that entry.'
            );
        }
    }

    /** @param  list<string>  $patterns */
    private function filterCovers(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (! str_contains($pattern, '**')) {
                if ($pattern === $path) {
                    return true;
                }

                continue;
            }

            [$before, $after] = explode('**', $pattern, 2);
            $regex = '#^'.preg_quote($before, '#').'.*'.preg_quote($after, '#').'$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0:string}>
     */
    public static function eventProvider(): array
    {
        return [['pull_request'], ['push']];
    }

    #[DataProvider('eventProvider')]
    public function test_the_path_filter_covers_every_python_file_under_bin(string $event): void
    {
        $files = $this->pythonFilesUnderBin();

        // An empty population would pass every assertion below without measuring
        // anything — the exact shape this whole card is about.
        $this->assertNotEmpty($files, 'found no bin/**.py at all, so this test measured nothing');

        $patterns = $this->workflow()['on'][$event]['paths'];
        $this->assertPatternFormsAreUnderstood($patterns, $event);

        $uncovered = array_values(array_filter(
            $files,
            fn (string $f): bool => ! $this->filterCovers($patterns, $f)
        ));

        $this->assertSame([], $uncovered, sprintf(
            'on.%s.paths in %s does not cover %d of the %d python files under bin/. A change touching only '
            .'those files cannot run the job that tests them — the gate goes ABSENT, not red.',
            $event,
            self::WORKFLOW,
            count($uncovered),
            count($files)
        ));
    }

    /**
     * The matcher above is the instrument every coverage assertion in this file
     * reads through, so it gets its own control: one that answered `true` to
     * everything would report a filter with the tools deleted out of it as fully
     * covered, which is the failure this file exists to catch.
     */
    public function test_the_pattern_matcher_discriminates(): void
    {
        $this->assertTrue($this->filterCovers(['bin/**'], 'bin/tool.py'));
        $this->assertTrue($this->filterCovers(['bin/**'], 'bin/nested/tool.py'), '** spans /');
        $this->assertFalse($this->filterCovers(['bin/**'], 'binaries/tool.py'), 'the trailing / in the prefix is load-bearing');
        $this->assertFalse($this->filterCovers(['bin/**'], 'other/bin/tool.py'), 'the prefix is anchored at the start');

        // The suffixed form is not the one shipped (see the workflow header on why
        // a composed pattern was declined), but the matcher understands it, so the
        // control covers it — an untested branch of the instrument is a gap in it.
        $this->assertTrue($this->filterCovers(['bin/**.py'], 'bin/tool.py'));
        $this->assertFalse($this->filterCovers(['bin/**.py'], 'bin/tool.php'), 'the suffix is load-bearing');

        $this->assertTrue($this->filterCovers(['.github/workflows/a.yml'], '.github/workflows/a.yml'));
        $this->assertFalse($this->filterCovers(['.github/workflows/a.yml'], '.github/workflows/a.yml.bak'), 'a literal is exact');

        $this->assertTrue($this->patternFormIsUnderstood('bin/**'));
        $this->assertFalse($this->patternFormIsUnderstood('bin/*.py'), 'single-* is a different form and is not approximated');
        $this->assertFalse($this->patternFormIsUnderstood('!bin/x.py'), 'negation inverts the answer and is not approximated');
    }

    /**
     * `unittest discover` skips a subdirectory that is not an importable package
     * (namespace-package discovery was removed in Python 3.11), so a test file
     * placed one level down is collected by nothing while the path filter above
     * happily fires the job. That is this card's defect re-minted one directory
     * deeper — tests that exist, never run, and leave the job green — so it is a
     * tripwire rather than something to discover later.
     */
    public function test_every_python_file_sits_directly_in_bin_where_discovery_looks(): void
    {
        $nested = array_values(array_filter(
            $this->pythonFilesUnderBin(),
            fn (string $f): bool => substr_count($f, '/') > 1
        ));

        $this->assertSame([], $nested,
            'these python files live below bin/, where `unittest discover -s bin` will not reach them unless '
            .'the directory is an importable package. Either add an __init__.py and confirm discovery collects '
            .'them, or keep the tools flat in bin/.'
        );
    }

    public function test_the_push_and_pull_request_filters_are_identical(): void
    {
        $on = $this->workflow()['on'];

        $this->assertSame(
            $on['pull_request']['paths'],
            $on['push']['paths'],
            'the two filters in '.self::WORKFLOW.' have diverged. They are one decision written twice, and '
            .'the push-side copy is the quieter half: a PR-side gap costs one un-run job, while a push-side '
            .'gap lets dev sit red with nothing reporting it (DL-246).'
        );
    }

    public function test_the_test_step_discovers_rather_than_naming_modules(): void
    {
        $steps = $this->workflow()['jobs']['python-unit']['steps'];

        $run = null;
        foreach ($steps as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), 'Run bin/ unit tests')) {
                $run = (string) $step['run'];
            }
        }

        $this->assertNotNull($run, "no 'Run bin/ unit tests' step in ".self::WORKFLOW);

        $this->assertStringContainsString('unittest discover', $run, 'the run step must derive the module set from the filesystem');

        // The reintroduced list is invisible to everything else: a tool left out
        // of it has tests that exist, never run, and leave the job green.
        $this->assertDoesNotMatchRegularExpression(
            '/\bbin\.test_/',
            $run,
            'the run step names individual test modules again. That list is a second copy of the filesystem '
            .'and drifts the moment a tool is added (card#5977).'
        );
    }
}
