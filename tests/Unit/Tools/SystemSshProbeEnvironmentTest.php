<?php

namespace Tests\Unit\Tools;

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
