<?php

namespace Tests\Feature\Workflows;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * DL-182's constraint, made mechanical: a value reaches a `run:` body through
 * the step's `env:` block, never through an inline `${{ }}` interpolation.
 *
 * The rule was written for the Actions script-injection class — an
 * attacker-controlled PR title spliced into the shell text before bash ever
 * sees it. It also guards a second, quieter failure the repo has already paid
 * for: GitHub's template reader scans every string scalar for `${{`
 * REGARDLESS of shell comments, so a `${{ }}` written inside a `#` comment in
 * a run body made the whole workflow unloadable and silently disabled the gate
 * it was documenting. Nothing could see it — this repo's workflow tests model
 * the BASH layer (they extract a `run:` string and execute it), and in bash
 * that token is inert inside a comment.
 *
 * BOUND — this is a run-body check, not a template validator. A `${{` written
 * into any other string scalar (`if:`, `name:`, a `with:` value) is equally
 * capable of making a file unloadable and is NOT covered here; validating a
 * whole file as an Actions template would need the template engine, which this
 * repo does not run.
 */
class RunBodyInterpolationTest extends TestCase
{
    private const TOKEN = '${{';

    /**
     * Every workflow file, repo-relative. Derived from the directory, never
     * enumerated: a workflow added tomorrow is covered with no edit here.
     *
     * @return list<string>
     */
    private function workflowFiles(): array
    {
        $found = array_merge(
            glob(base_path('.github/workflows/*.yml')) ?: [],
            glob(base_path('.github/workflows/*.yaml')) ?: [],
        );

        return $this->repoRelative($found);
    }

    /**
     * Every composite-action definition, at any depth under `.github/actions/`.
     *
     * @return list<string>
     */
    private function actionFiles(): array
    {
        $root = base_path('.github/actions');

        if (! is_dir($root)) {
            return [];
        }

        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile() && in_array($file->getFilename(), ['action.yml', 'action.yaml'], true)) {
                $found[] = $file->getPathname();
            }
        }

        return $this->repoRelative($found);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function repoRelative(array $paths): array
    {
        $root = base_path().'/';

        $relative = array_map(
            fn (string $p): string => str_starts_with($p, $root) ? substr($p, strlen($root)) : $p,
            $paths
        );

        sort($relative);

        return array_values($relative);
    }

    /**
     * Every `run:` string anywhere in a parsed workflow or action, paired with a
     * locator that names where it sits. Structure-driven rather than
     * job-name-driven: workflow jobs' steps, composite `runs.steps`, and any
     * nesting either grows later are all reached by the same walk, so a new job
     * or step is covered without touching this file.
     *
     * @return list<array{locator:string,run:string}>
     */
    private function runBodies(mixed $node, string $locator = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        // `defaults.run:` is a MAPPING (shell/working-directory), not a script —
        // only a string `run:` is a shell body.
        if (isset($node['run']) && is_string($node['run'])) {
            $name = isset($node['name']) && is_string($node['name']) ? " \"{$node['name']}\"" : '';
            $found[] = ['locator' => ($locator === '' ? 'run' : $locator).$name, 'run' => $node['run']];
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $segment = is_int($key) ? "[{$key}]" : ($locator === '' ? (string) $key : '.'.$key);
            $found = array_merge($found, $this->runBodies($value, $locator.$segment));
        }

        return $found;
    }

    /**
     * @return list<array{file:string,locator:string,run:string}>
     */
    private function allRunBodies(): array
    {
        $found = [];

        foreach (array_merge($this->workflowFiles(), $this->actionFiles()) as $file) {
            foreach ($this->runBodies(Yaml::parseFile(base_path($file))) as $body) {
                $found[] = ['file' => $file, 'locator' => $body['locator'], 'run' => $body['run']];
            }
        }

        return $found;
    }

    public function test_no_run_body_uses_actions_template_interpolation(): void
    {
        $workflows = $this->workflowFiles();
        $actions = $this->actionFiles();
        $bodies = $this->allRunBodies();

        // An empty population passes the assertion below without measuring
        // anything, and each leg can empty independently: a moved directory, a
        // renamed action file, or a walk that stops reaching composite steps.
        $this->assertNotEmpty($workflows, 'found no .github/workflows/*.yml at all, so this test measured nothing');
        $this->assertNotEmpty($actions, 'found no .github/actions/**/action.yml at all, so composite steps were not measured');
        $this->assertNotEmpty($bodies, 'extracted no run: bodies at all, so this test measured nothing');

        $fromActions = array_filter($bodies, fn (array $b): bool => str_starts_with($b['file'], '.github/actions/'));
        $this->assertNotEmpty($fromActions, 'no run: body came from a composite action — the walk does not reach runs.steps');

        $offenders = [];
        foreach ($bodies as $body) {
            if (str_contains($body['run'], self::TOKEN)) {
                $offenders[] = $body['file'].' → '.$body['locator'];
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "these run: bodies contain a literal %s, out of %d bodies read from %d workflow and %d action files.\n"
            .'Values reach a run body through the step\'s env: block and are used quoted ("$VAR") — never inline '
            ."interpolation (DL-182).\nThis is not only the script-injection rule: GitHub's template reader scans "
            .'every string scalar for the token regardless of shell comments, so even a commented-out one can make '
            .'the whole file unloadable and silently stop the workflow from running.',
            self::TOKEN,
            count($bodies),
            count($workflows),
            count($actions)
        ));
    }

    /**
     * The extractor is the instrument every assertion above reads through, so it
     * gets its own control. One that walked only known job names, or stopped at
     * `runs.steps`, would report a clean repo while never having looked.
     */
    public function test_the_extractor_reaches_every_shape_of_run_body(): void
    {
        $workflow = $this->runBodies([
            'jobs' => [
                'build' => ['steps' => [
                    ['name' => 'first', 'run' => 'echo a'],
                    ['uses' => 'actions/checkout@sha'],
                    ['run' => 'echo b'],
                ]],
            ],
        ]);

        $this->assertSame(['jobs.build.steps[0] "first"', 'jobs.build.steps[2]'], array_column($workflow, 'locator'));
        $this->assertSame(['echo a', 'echo b'], array_column($workflow, 'run'));

        $composite = $this->runBodies([
            'runs' => ['using' => 'composite', 'steps' => [['name' => 'setup', 'run' => 'echo c']]],
        ]);

        $this->assertSame(['runs.steps[0] "setup"'], array_column($composite, 'locator'));

        // A mapping `run:` is the workflow-level shell default, not a script.
        $this->assertSame([], $this->runBodies(['defaults' => ['run' => ['shell' => 'bash']]]));
    }
}
