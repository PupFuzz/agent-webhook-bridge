<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\UntrustedPathContents;
use App\Bridge\Tools\SystemSshProbeEnvironment;
use Tests\TestCase;

/**
 * The REAL `authorized_keys` read, on a real filesystem (card#8976).
 *
 * ⭐ WHY THIS CLASS EXISTS, when the rest of this seam is left to the fakes: the defect it
 * pins was a CLASSIFICATION defect at the syscall boundary — `is_file()` answering false for
 * a file that is not there and for a file this process may not look at — and no fake can
 * fail in that direction, because a fake states its answer rather than measuring one.
 * {@see SshTransportProbeTest} proves what the PROBE does with three stated answers and
 * says nothing about whether those answers are measured correctly; this is the other half.
 *
 * ⚑ THE PERMISSION ARMS SKIP UNDER A UID THAT IGNORES THE MODE (root, and some CI
 * containers) rather than asserting: the arm is checked against a real open first, so a
 * pass here is never a mode that was not actually enforced.
 */
class SystemSshProbeEnvironmentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ssh-probe-env-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/.ssh', 0o700, true);
    }

    protected function tearDown(): void
    {
        // Restore any mode an arm dropped, or the recursive delete cannot enter.
        @chmod($this->dir.'/.ssh', 0o700);
        @chmod($this->dir.'/.ssh/authorized_keys', 0o600);
        foreach (glob($this->dir.'/.ssh/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir.'/.ssh');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_file_that_is_not_there_reads_as_consulted_and_empty(): void
    {
        // ⭐ THE DEFECT'S OWN INPUT. `.ssh/authorized_keys2` is absent on essentially every
        // host, and the OpenSSH default names it — so if this answered "not consulted", the
        // authoritative not-wired FAIL would be unreachable on the default install.
        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($this->dir.'/.ssh/authorized_keys2');

        $this->assertTrue($read->consulted, 'an absent file was CONSULTED — sshd takes no keys from it');
        $this->assertNull($read->text);
    }

    public function test_a_readable_file_reads_as_its_text(): void
    {
        file_put_contents($this->dir.'/.ssh/authorized_keys', "# a comment\n");

        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($this->dir.'/.ssh/authorized_keys');

        $this->assertTrue($read->consulted);
        $this->assertSame("# a comment\n", $read->text);
    }

    public function test_a_present_file_this_process_may_not_open_reads_as_not_consulted(): void
    {
        // The other side of the pair, and the reason the classification cannot be dropped:
        // the same null the old `?string` returned, over an input where absence is NOT
        // established.
        $path = $this->dir.'/.ssh/authorized_keys';
        file_put_contents($path, "# secret\n");
        chmod($path, 0o000);
        $this->skipUnlessTheModeIsEnforced($path);

        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($path);

        $this->assertFalse($read->consulted, 'a file this process cannot open establishes NOTHING');
        $this->assertNull($read->text);
    }

    public function test_a_path_under_an_untraversable_directory_reads_as_not_consulted(): void
    {
        // The stat half, which the read half cannot see: with no +x on the parent,
        // `is_file()` returns false for a file that is really there, and calling that
        // "absent" is the card#5698 conflation. `PathVisibility` is asked first for exactly
        // this input.
        $path = $this->dir.'/.ssh/authorized_keys';
        file_put_contents($path, "# secret\n");
        chmod($this->dir.'/.ssh', 0o000);
        $this->skipUnlessTheModeIsEnforced($path);

        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($path);

        $this->assertFalse($read->consulted);
        $this->assertNull($read->text);
        // The control: it is not answering "not consulted" for every input — the arm above
        // reads the same shape with the mode restored and gets the text back.
        chmod($this->dir.'/.ssh', 0o700);
        $this->assertSame("# secret\n", (new SystemSshProbeEnvironment)->readAuthorizedKeys($path)->text);
    }

    // ─── the ROOT-SAFE read is the one ADOPTED here (card#9037) ───────────────
    // What the guards themselves refuse is measured in UntrustedPathContentsTest. These
    // two cases measure the only thing that suite cannot: that THIS leg reads through
    // that primitive, and that a refusal lands on the `unreadable()` arm the probe
    // already has rather than on `absent()` — which is the arm the authoritative FAIL is
    // drawn over.

    public function test_a_symlinked_authorized_keys_reads_as_not_consulted(): void
    {
        // `bridge:check` runs this leg as ROOT over a path the inspected ACCOUNT owns, so
        // a symlink here is that account naming a file for root to open. The leg must say
        // it did not look — never read the target's bytes as the account's keys, and never
        // report the absence that an authoritative FAIL is spent on.
        $path = $this->dir.'/.ssh/authorized_keys';
        $target = $this->dir.'/elsewhere';
        file_put_contents($target, "# not this account's file\n");
        symlink($target, $path);

        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($path);

        $this->assertFalse($read->consulted, 'a symlinked path was CONSULTED — its target is not the account\'s file');
        $this->assertNull($read->text);

        // ⛔ THE CONTROL: the same bytes at a path that is a real file ARE read, so the
        // leg is refusing the indirection and has not simply stopped reading.
        $this->assertSame("# not this account's file\n", (new SystemSshProbeEnvironment)->readAuthorizedKeys($target)->text);
        @unlink($target);
    }

    public function test_a_path_sshd_can_take_no_keys_from_reads_as_consulted_and_empty(): void
    {
        // ⛔ THE EXIT CODE LIVES ON THIS ANSWER. `consulted` is what keeps the authoritative
        // not-wired FAIL reachable, and a directory or a dangling symlink at this name is a
        // path sshd reads and takes NOTHING from — an established absence, exactly like no
        // file at all. Routing it to `unreadable()` is what let the inspected account
        // suppress root's own FAIL with one `mkdir` (card#9037 r1).
        $dirPath = $this->dir.'/.ssh/authorized_keys';
        mkdir($dirPath, 0o700);
        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($dirPath);
        $this->assertTrue($read->consulted, 'a directory at authorized_keys was not CONSULTED');
        $this->assertNull($read->text);
        rmdir($dirPath);

        symlink($this->dir.'/.ssh/nothing-here', $dirPath);
        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($dirPath);
        $this->assertTrue($read->consulted, 'a dangling symlink at authorized_keys was not CONSULTED');
        $this->assertNull($read->text);

        // The control, from the other side of the split: a symlink to a REGULAR file is the
        // one shape that withholds, so this pair cannot be satisfied by answering
        // "consulted" for every refusal.
        @unlink($dirPath);
        file_put_contents($this->dir.'/real-keys', "# a comment\n");
        symlink($this->dir.'/real-keys', $dirPath);
        $this->assertFalse((new SystemSshProbeEnvironment)->readAuthorizedKeys($dirPath)->consulted);
        @unlink($this->dir.'/real-keys');
    }

    public function test_an_authorized_keys_past_the_read_bound_is_not_consulted(): void
    {
        // The size half of the same adoption. The old reader had no bound at all, and
        // `is_file()` is true for `/proc/kcore`.
        $path = $this->dir.'/.ssh/authorized_keys';
        file_put_contents($path, str_repeat('k', UntrustedPathContents::MAX_BYTES + 1));

        $read = (new SystemSshProbeEnvironment)->readAuthorizedKeys($path);

        $this->assertFalse($read->consulted);
        $this->assertNull($read->text);

        // The control, at the same path: an ordinary file is still consulted and read.
        file_put_contents($path, "# a comment\n");
        $this->assertSame("# a comment\n", (new SystemSshProbeEnvironment)->readAuthorizedKeys($path)->text);
    }

    // ─── file IDENTITY (card#8976 r2) ─────────────────────────────────────────
    // Two AuthorizedKeysFile entries can name ONE file, and the probe counts LINES over
    // them: keyed by the path string, one physical line is counted once per spelling and
    // the ambiguity FAIL fires on an install that has exactly one. What the filesystem
    // actually answers for each way of aliasing a file is measured HERE; what the probe
    // does with those answers is stated in SshTransportProbeTest.

    public function test_a_symlinked_second_file_shares_the_target_s_identity(): void
    {
        // `.ssh/authorized_keys2` pointing at `.ssh/authorized_keys` — one file, two names,
        // and the shape an operator produces by linking rather than copying a pin.
        $target = $this->dir.'/.ssh/authorized_keys';
        $link = $this->dir.'/.ssh/authorized_keys2';
        file_put_contents($target, "# a comment\n");
        symlink($target, $link);
        $env = new SystemSshProbeEnvironment;

        $this->assertSame($env->fileIdentity($target), $env->fileIdentity($link));

        // ⛔ THE CONTROL, and it is what stops this method answering "same" to everything:
        // a REAL second file in the same directory must not share the identity.
        file_put_contents($this->dir.'/.ssh/other_keys', "# a comment\n");
        $this->assertNotSame($env->fileIdentity($target), $env->fileIdentity($this->dir.'/.ssh/other_keys'));
    }

    public function test_a_hard_link_shares_the_identity_where_realpath_alone_would_not(): void
    {
        // ⭐ WHY THIS IS NOT `realpath()`. A hard link has no symlink to resolve: both names
        // are the file. `realpath()` answers each name with ITSELF, so a probe deduplicating
        // on it would still count one physical line twice.
        $target = $this->dir.'/.ssh/authorized_keys';
        $link = $this->dir.'/.ssh/authorized_keys2';
        file_put_contents($target, "# a comment\n");
        link($target, $link);
        $env = new SystemSshProbeEnvironment;

        $this->assertSame($env->fileIdentity($target), $env->fileIdentity($link));
        // The pinned negative that makes the line above a measurement of the INODE and not
        // of a path rule: realpath disagrees on exactly this input.
        $this->assertNotSame(realpath($target), realpath($link));
    }

    public function test_two_spellings_of_one_path_share_an_identity_present_or_absent(): void
    {
        // `%h//.ssh/authorized_keys` beside `.ssh/authorized_keys` is one file spelled
        // twice — POSIX collapses the inner slashes. Asserted in BOTH states, because the
        // two are answered by different halves of the method: an existing file compares by
        // inode, and one that is not there has no inode to compare, so the fallback has to
        // normalise the spelling itself.
        $path = $this->dir.'/.ssh/authorized_keys';
        $doubled = $this->dir.'/.ssh//authorized_keys';
        $env = new SystemSshProbeEnvironment;

        $this->assertSame($env->fileIdentity($path), $env->fileIdentity($doubled), 'absent, so this is the normalised-path fallback');

        file_put_contents($path, "# a comment\n");
        $this->assertSame($env->fileIdentity($path), $env->fileIdentity($doubled), 'present, so this is the inode');
    }

    public function test_two_paths_with_no_file_at_them_stay_distinct(): void
    {
        // The fallback's other direction: nothing to stat is not a licence to call two
        // different absent paths one file. (Two SPELLINGS of one absent path are the case
        // above; these are two paths.)
        $env = new SystemSshProbeEnvironment;

        $this->assertNotSame(
            $env->fileIdentity($this->dir.'/.ssh/authorized_keys'),
            $env->fileIdentity($this->dir.'/.ssh/authorized_keys2'),
        );
    }

    /**
     * A mode is only evidence if the kernel enforced it for THIS uid — root, and some
     * container uids, read straight through 0000. Asked with a real open rather than by
     * comparing uids, because that is the same question the subject asks.
     */
    private function skipUnlessTheModeIsEnforced(string $path): void
    {
        if (@file_get_contents($path) !== false) {
            $this->markTestSkipped('this uid reads through the mode (root?), so the arm has nothing to measure');
        }
    }
}
