<?php

namespace App\Bridge\Tools;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The real-host {@see GitRefProbe}. Fails SAFE in the same shape
 * {@see SystemSshProbeEnvironment} does: any leg that cannot answer returns null, and
 * the caller prints its unmeasured sentence rather than a fabricated ref.
 *
 * THREE COMMANDS, ALL THREE REQUIRED. `--short HEAD` and `--abbrev-ref HEAD` supply the
 * two values; `merge-base --is-ancestor HEAD @{upstream}` decides whether they may be
 * SAID (see the interface). A non-zero exit anywhere — no git binary, not a repository,
 * no upstream configured, an unpushed HEAD — collapses to null.
 */
final class SystemGitRefProbe implements GitRefProbe
{
    /** @return ?array{sha: string, branch: string} */
    public function headOnPushedBranch(string $dir): ?array
    {
        $git = (new ExecutableFinder)->find('git', null, ['/usr/bin', '/usr/local/bin', '/bin']);
        if ($git === null) {
            return null;
        }

        $sha = $this->capture($git, $dir, ['rev-parse', '--short', 'HEAD']);
        $branch = $this->capture($git, $dir, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $ancestor = $this->capture($git, $dir, ['merge-base', '--is-ancestor', 'HEAD', '@{upstream}']);

        if ($sha === null || $sha === '' || $branch === null || $branch === '' || $ancestor === null) {
            return null;
        }

        return ['sha' => $sha, 'branch' => $branch];
    }

    /**
     * The trimmed stdout of one successful git invocation, or null on any failure.
     * `--is-ancestor` prints nothing and answers through its exit code alone, so an
     * EMPTY STRING is a success here and the caller tests it for `null`, never for `''`.
     *
     * @param  list<string>  $args
     */
    private function capture(string $git, string $dir, array $args): ?string
    {
        try {
            $proc = new Process([$git, ...$args], $dir);
            $proc->setTimeout(10);
            $proc->run();
            if (! $proc->isSuccessful()) {
                return null;
            }

            return trim($proc->getOutput());
        } catch (\Throwable) {
            return null;
        }
    }
}
