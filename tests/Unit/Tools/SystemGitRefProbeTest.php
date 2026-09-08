<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\SystemGitRefProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * {@see SystemGitRefProbe} against REAL git repositories, because the whole question it
 * answers is a property of git and a fake would only re-assert what the fake was told.
 *
 * ⭐ THE ANCESTOR LEG IS THE ONE THAT MATTERS AND THE ONE A FAKE CANNOT WITNESS. The
 * packet tells an impl seat to clone a branch and check a sha out of it; a sha that
 * exists only in the bridge box's working copy is not in the seat's clone. Deleting the
 * `merge-base --is-ancestor` call reds the unpushed-commit test here — and nothing else
 * in the suite, because every other test binds the seam.
 */
class SystemGitRefProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/gitref-'.uniqid();
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_a_head_that_its_upstream_contains_answers_with_its_short_sha_and_branch(): void
    {
        $work = $this->repoWithUpstream();

        $ref = (new SystemGitRefProbe)->headOnPushedBranch($work);

        $this->assertNotNull($ref);
        $this->assertSame('work', $ref['branch']);
        $this->assertSame($this->git($work, ['rev-parse', '--short', 'HEAD']), $ref['sha']);
    }

    public function test_an_unpushed_commit_answers_nothing_rather_than_a_ref_the_seat_cannot_fetch(): void
    {
        // ⭐ CONTROL: remove the `merge-base --is-ancestor HEAD @{upstream}` call from
        // SystemGitRefProbe and this reds — the probe starts answering with a sha that
        // exists nowhere but this box, which the packet would print as the ref to check
        // out and the seat would meet as `fatal: reference is not a tree`.
        $work = $this->repoWithUpstream();
        file_put_contents($work.'/local.txt', "local\n");
        $this->git($work, ['add', 'local.txt']);
        $this->git($work, ['-c', 'user.email=t@example.invalid', '-c', 'user.name=t', 'commit', '-m', 'unpushed']);

        $this->assertNull((new SystemGitRefProbe)->headOnPushedBranch($work));
    }

    public function test_a_branch_with_no_upstream_at_all_answers_nothing(): void
    {
        $work = $this->repoWithUpstream();
        $this->git($work, ['checkout', '-b', 'no-upstream']);

        $this->assertNull((new SystemGitRefProbe)->headOnPushedBranch($work));
    }

    public function test_a_directory_that_is_not_a_repository_answers_nothing(): void
    {
        mkdir($this->root.'/plain', 0o700, true);

        $this->assertNull((new SystemGitRefProbe)->headOnPushedBranch($this->root.'/plain'));
    }

    /** A working checkout on branch `work`, pushed to a bare "remote" and tracking it. */
    private function repoWithUpstream(): string
    {
        $bare = $this->root.'/remote.git';
        $work = $this->root.'/work';
        $this->git($this->root, ['init', '--bare', '-b', 'work', $bare]);
        $this->git($this->root, ['init', '-b', 'work', $work]);
        file_put_contents($work.'/README', "hi\n");
        $this->git($work, ['add', 'README']);
        $this->git($work, ['-c', 'user.email=t@example.invalid', '-c', 'user.name=t', 'commit', '-m', 'first']);
        $this->git($work, ['remote', 'add', 'origin', $bare]);
        $this->git($work, ['push', '-u', 'origin', 'work']);

        return $work;
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $proc = new Process(['git', ...$args], $cwd);
        $proc->run();
        $this->assertTrue($proc->isSuccessful(), 'git '.implode(' ', $args).' failed: '.$proc->getErrorOutput());

        return trim($proc->getOutput());
    }
}
