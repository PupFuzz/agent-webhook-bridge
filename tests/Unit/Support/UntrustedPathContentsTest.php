<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\UntrustedPathContents;
use Tests\TestCase;

/**
 * card#9037 — the read that is taken over a path a LOWER-TRUST account controls.
 *
 * ⭐ EVERY REFUSAL ARM CARRIES ITS OWN CONTROL, and the control is the same shape each
 * time: the guard must be refusing THE THING NAMED IN THE TEST and not simply refusing
 * everything. A test that only asserts a throw would pass against a `read()` whose body is
 * `throw`, and the three guards here would then be indistinguishable from a broken
 * primitive that never reads anything at all.
 *
 * ⛔ ONE ARM IS DELIBERATELY NOT COVERED, and saying so is the honest report: the
 * `dev`/`ino` comparison between the `lstat` and the opened descriptor fires only when the
 * path resolves to a DIFFERENT inode between the two calls, which needs a second process
 * winning a race inside one function call. Nothing here can drive it deterministically, so
 * it is exercised only in its non-firing direction (every successful read below passes
 * through it). It is kept rather than dropped because without it the symlink refusal is
 * defeated by winning that race — but it has no red-once witness, and that is a real gap
 * rather than a covered case. MEASURED, not assumed: deleting that comparison outright
 * leaves this file and `SystemSshProbeEnvironmentTest` fully green, while each of the three
 * guards below reds at least two cases when ITS predicate is broken.
 */
class UntrustedPathContentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/untrusted-path-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            if (is_string($f)) {
                @chmod($f, 0o644);
                is_dir($f) && ! is_link($f) ? @rmdir($f) : @unlink($f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ---- the ordinary answers, unchanged from the reader this one replaces ----------

    public function test_a_present_regular_file_reads_as_its_bytes(): void
    {
        file_put_contents($this->dir.'/plain', "hello\n");

        $this->assertSame("hello\n", UntrustedPathContents::read($this->dir.'/plain', 'thing'));
    }

    public function test_an_empty_regular_file_reads_as_the_empty_string_not_absent(): void
    {
        // The size-bounded read has its own zero-length branch, and it must not answer the
        // absent null: an EMPTY authorized_keys is a file sshd consulted.
        touch($this->dir.'/empty');

        $this->assertSame('', UntrustedPathContents::read($this->dir.'/empty', 'thing'));
    }

    public function test_an_absent_path_reads_as_null(): void
    {
        $this->assertNull(UntrustedPathContents::read($this->dir.'/nope', 'thing'));
    }

    public function test_a_present_but_unreadable_file_throws_rather_than_reading_as_absent(): void
    {
        $path = $this->dir.'/locked';
        file_put_contents($path, "# secret\n");
        chmod($path, 0o000);
        clearstatcache(true, $path);
        if (@file_get_contents($path) !== false) {
            $this->markTestSkipped('this uid reads through mode 0000 (root?), so the arm has nothing to measure');
        }

        $threw = false;
        $result = 'not-set';
        try {
            $result = UntrustedPathContents::read($path, 'thing');
        } catch (UnreadableFileException $e) {
            $threw = true;
            $this->assertStringContainsString('permissions fault rather than an absence', $e->getMessage());
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result, true).' for an unreadable file');
    }

    // ---- guard 1: a symlinked FINAL COMPONENT is refused -----------------------------

    public function test_a_symlinked_final_component_is_refused(): void
    {
        // The defect's own input: the account owning the directory points the name it
        // controls at a file it does not own, and the reader follows it as root.
        $target = $this->dir.'/target';
        $link = $this->dir.'/link';
        file_put_contents($target, "the target's bytes\n");
        symlink($target, $link);

        $threw = false;
        $result = 'not-set';
        try {
            $result = UntrustedPathContents::read($link, 'authorized_keys');
        } catch (UnreadableFileException $e) {
            $threw = true;
            $this->assertStringContainsString('symbolic link', $e->getMessage());
            $this->assertStringContainsString($link, $e->getMessage(), 'the refusal must name the path');
            $this->assertStringNotContainsString("the target's bytes", $e->getMessage());
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result, true).' for a symlinked path');

        // ⛔ THE CONTROL. The very same bytes are read when the path names the file
        // DIRECTLY — so what was refused is the indirection, not the file, and this
        // primitive is not simply refusing every input it is handed.
        $this->assertSame("the target's bytes\n", UntrustedPathContents::read($target, 'authorized_keys'));
    }

    public function test_a_dangling_symlink_is_refused_rather_than_reading_as_absent(): void
    {
        // ⭐ The dangerous shape, because the OTHER answer is plausible: a link to nothing
        // could read as "no file here". It must not — the account can create the target
        // at any moment, and "absent" is a conclusion the probe spends on an
        // authoritative FAIL.
        $link = $this->dir.'/dangling';
        symlink($this->dir.'/never-created', $link);

        $threw = false;
        $result = 'not-set';
        try {
            $result = UntrustedPathContents::read($link, 'authorized_keys');
        } catch (UnreadableFileException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result, true).' for a dangling symlink');
    }

    // ---- guard 2: a NON-REGULAR file is refused ---------------------------------------

    public function test_a_non_regular_file_is_refused(): void
    {
        // A character device: it opens, it reads, and it is not the account's key file.
        // `/dev/null` stands in for the whole class — `is_file()` is the predicate that
        // could not tell them apart, and it is true for `/proc/kcore` too.
        $threw = false;
        $result = 'not-set';
        try {
            $result = UntrustedPathContents::read('/dev/null', 'authorized_keys');
        } catch (UnreadableFileException $e) {
            $threw = true;
            $this->assertStringContainsString('character device', $e->getMessage());
            $this->assertStringContainsString('/dev/null', $e->getMessage());
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result, true).' for a character device');

        // The control: a REGULAR file of the same (empty) length is read, so the refusal
        // is about the file TYPE and not about the size or the read failing.
        touch($this->dir.'/empty');
        $this->assertSame('', UntrustedPathContents::read($this->dir.'/empty', 'authorized_keys'));
    }

    public function test_a_directory_at_the_path_is_refused_as_a_directory(): void
    {
        // Distinguished from the arm above because the DIAGNOSIS differs: without the type
        // guard this path still fails, but as an unexplained "permissions fault" — which
        // sends the operator to chmod a directory that is not the problem.
        mkdir($this->dir.'/subdir', 0o700);

        try {
            UntrustedPathContents::read($this->dir.'/subdir', 'authorized_keys');
            $this->fail('a directory was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertStringContainsString('directory', $e->getMessage());
        }
    }

    // ---- guard 3: the read is BOUNDED --------------------------------------------------

    public function test_a_file_past_the_bound_is_refused_rather_than_read(): void
    {
        $path = $this->dir.'/huge';
        file_put_contents($path, str_repeat('k', UntrustedPathContents::MAX_BYTES + 1));

        $threw = false;
        $result = 'not-set';
        try {
            $result = UntrustedPathContents::read($path, 'authorized_keys');
        } catch (UnreadableFileException $e) {
            $threw = true;
            $this->assertStringContainsString((string) UntrustedPathContents::MAX_BYTES, $e->getMessage());
            $this->assertStringContainsString($path, $e->getMessage());
        }
        $this->assertTrue(
            $threw,
            'read() returned '.(is_string($result) ? strlen($result).' bytes' : var_export($result, true))
            .' for a file past the bound',
        );

        // ⛔ THE CONTROL, and it pins the bound at the boundary rather than somewhere below
        // it: a file of EXACTLY MAX_BYTES is read in full. Without this the same test
        // would pass against a primitive that refuses every non-trivial file.
        $atBound = $this->dir.'/at-bound';
        file_put_contents($atBound, str_repeat('k', UntrustedPathContents::MAX_BYTES));
        $read = UntrustedPathContents::read($atBound, 'authorized_keys');
        $this->assertSame(UntrustedPathContents::MAX_BYTES, strlen((string) $read));
    }

    public function test_the_read_never_returns_more_than_the_size_it_measured(): void
    {
        // The bound is applied to the READ and not only to the refusal: a file within the
        // limit comes back at exactly its own length, which is what makes the fread's
        // length argument load-bearing rather than decorative.
        $path = $this->dir.'/sized';
        file_put_contents($path, str_repeat('x', 4096));

        $this->assertSame(4096, strlen((string) UntrustedPathContents::read($path, 'thing')));
    }
}
