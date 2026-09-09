<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
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
        self::removeTree($this->dir);
        parent::tearDown();
    }

    /**
     * Recursive on purpose: an arm here leaves a populated directory (and one at mode 0000),
     * and a flat sweep would strand it in the system temp dir on every run. `is_link` is
     * checked BEFORE `is_dir` because a symlink to a directory answers both, and rmdir is not
     * how you remove a link.
     */
    private static function removeTree(string $dir): void
    {
        @chmod($dir, 0o700);
        foreach ((array) glob($dir.'/*') as $f) {
            if (! is_string($f)) {
                continue;
            }
            if (! is_link($f) && is_dir($f)) {
                self::removeTree($f);

                continue;
            }
            @unlink($f);
        }
        @rmdir($dir);
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

    // ---- WHICH KIND of refusal: establishing vs withholding (card#9037 r1) -------------
    // ⛔ THE ONE THING A CALLER SPENDS. A caller that already models "there is nothing here"
    // as an ANSWER routes the establishing refusals onto that arm; routing them all onto
    // "could not look" is what silently retired an exit-code-bearing FAIL. Each case below
    // asserts the TYPE, and every establishing case has a withholding twin so the split
    // cannot be satisfied by answering one way for everything.

    public function test_a_directory_establishes_that_no_bytes_are_at_the_path(): void
    {
        mkdir($this->dir.'/subdir', 0o700);

        try {
            UntrustedPathContents::read($this->dir.'/subdir', 'authorized_keys');
            $this->fail('a directory was not refused');
        } catch (PathResolvesToNoFileException $e) {
            $this->assertStringContainsString('directory', $e->getMessage());
        }
    }

    public function test_a_character_device_establishes_that_no_bytes_are_at_the_path(): void
    {
        $this->expectException(PathResolvesToNoFileException::class);
        UntrustedPathContents::read('/dev/null', 'authorized_keys');
    }

    public function test_a_fifo_establishes_that_no_bytes_are_at_the_path(): void
    {
        // ⚠ Only the STATIC FIFO is measurable here, and that is the point of the residue
        // note on the class: it is refused at the `lstat`, BEFORE the open that would block
        // on it. A FIFO swapped in AFTER that measurement wedges the open, and no test in
        // this file can drive that without hanging the suite it runs in.
        // ⛔ SO THIS CASE HANGS UNDER THE TYPE-GUARD MUTATION — measured, and not a flaky
        // test: remove the `isRegular` refusals to check that they discriminate and THIS
        // fixture reaches the unguarded `fopen` and blocks forever. Exclude it from that
        // one mutation run; the wedge it demonstrates is the residue itself.
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('no posix_mkfifo on this build');
        }
        $path = $this->dir.'/fifo';
        $this->assertTrue(posix_mkfifo($path, 0o600), 'could not create the fixture FIFO');

        try {
            UntrustedPathContents::read($path, 'authorized_keys');
            $this->fail('a FIFO was not refused');
        } catch (PathResolvesToNoFileException $e) {
            $this->assertStringContainsString('FIFO', $e->getMessage());
        }
    }

    public function test_a_dangling_symlink_establishes_that_no_bytes_are_at_the_path(): void
    {
        symlink($this->dir.'/never-created', $this->dir.'/dangling2');

        $this->expectException(PathResolvesToNoFileException::class);
        UntrustedPathContents::read($this->dir.'/dangling2', 'authorized_keys');
    }

    public function test_a_symlink_to_a_directory_establishes_that_no_bytes_are_at_the_path(): void
    {
        mkdir($this->dir.'/adir', 0o700);
        symlink($this->dir.'/adir', $this->dir.'/link-to-dir');

        $this->expectException(PathResolvesToNoFileException::class);
        UntrustedPathContents::read($this->dir.'/link-to-dir', 'authorized_keys');
    }

    public function test_a_symlink_to_a_regular_file_withholds_rather_than_establishing(): void
    {
        // ⭐ THE TWIN of the dangling case, and the reason the primitive takes a second,
        // FOLLOWING stat: this link names bytes that really exist and that a follower of the
        // path really reads. We decline to attribute them — which establishes NOTHING, and
        // must not be spendable as "there is nothing here".
        file_put_contents($this->dir.'/real', "x\n");
        symlink($this->dir.'/real', $this->dir.'/link-to-real');

        try {
            UntrustedPathContents::read($this->dir.'/link-to-real', 'authorized_keys');
            $this->fail('a symlink to a regular file was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertNotInstanceOf(PathResolvesToNoFileException::class, $e);
            $this->assertStringContainsString('symbolic link to a regular file', $e->getMessage());
        }
    }

    public function test_a_symlink_this_process_cannot_resolve_withholds_rather_than_establishing(): void
    {
        // ⛔ A FAILED stat IS NOT AN ABSENCE (card#5698). This link's target is really there;
        // only the traversal is denied — and the naive "stat said false, so it is dangling"
        // rule would answer ESTABLISHED here, handing a caller a measured-absence claim over
        // a file it merely could not see.
        mkdir($this->dir.'/closed', 0o700);
        file_put_contents($this->dir.'/closed/target', "x\n");
        symlink($this->dir.'/closed/target', $this->dir.'/link-into-closed');
        chmod($this->dir.'/closed', 0o000);
        clearstatcache();
        if (@stat($this->dir.'/closed/target') !== false) {
            @chmod($this->dir.'/closed', 0o700);
            $this->markTestSkipped('this uid traverses a 0000 directory (root?), so the arm has nothing to measure');
        }

        try {
            UntrustedPathContents::read($this->dir.'/link-into-closed', 'authorized_keys');
            $this->fail('an unresolvable symlink was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertNotInstanceOf(PathResolvesToNoFileException::class, $e);
            $this->assertStringContainsString('could not fully resolve', $e->getMessage());
        } finally {
            @chmod($this->dir.'/closed', 0o700);
        }
    }

    public function test_a_file_past_the_bound_withholds_rather_than_establishing(): void
    {
        // The size refusal is the OTHER accepted cost: this run did not read the file, so it
        // knows nothing about what is in it — including whether it holds the line a caller is
        // looking for. Establishing would be a lie about a file that certainly has content.
        $path = $this->dir.'/huge2';
        file_put_contents($path, str_repeat('k', UntrustedPathContents::MAX_BYTES + 1));

        try {
            UntrustedPathContents::read($path, 'authorized_keys');
            $this->fail('an oversize file was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertNotInstanceOf(PathResolvesToNoFileException::class, $e);
        }
    }

    // ---- the SYMLINK CHAIN WALK (card#9037 r2) — one hop was not enough --------------
    // ⛔ THIS SECTION PINS THE BLOCKER ITSELF. `targetIsConfirmedAbsent()`'s one-hop check
    // answered "cannot confirm" (WITHHOLD) for every case below, because each one's
    // IMMEDIATE target EXISTS (it is another symlink) — defeating the establishing/
    // withholding split with `ln -s authorized_keys authorized_keys`, one command away from
    // the `mkdir` r1 closed. Each case here is `is_file()`-false (verified directly against
    // the real filesystem, not assumed), so `origin/dev` reported the earned FAIL and
    // withholding here would be a REGRESSION this branch introduces.

    public function test_a_self_referential_symlink_establishes_rather_than_withholding(): void
    {
        // `ln -s authorized_keys authorized_keys` — the exact command named in the blocker.
        // ELOOP at the FIRST redirection: `readlink` on this path returns ITS OWN name, so a
        // one-hop check finds "the target exists" (it is the same symlink) and withholds.
        symlink($this->dir.'/self', $this->dir.'/self');

        try {
            UntrustedPathContents::read($this->dir.'/self', 'authorized_keys');
            $this->fail('a self-referential symlink was not refused');
        } catch (PathResolvesToNoFileException $e) {
            $this->assertStringContainsString('LOOPS', $e->getMessage());
        }
    }

    public function test_a_mutual_symlink_pair_establishes_rather_than_withholding(): void
    {
        // a -> b -> a. Neither file's IMMEDIATE target is absent, so the one-hop check
        // withheld both; the walk must detect the REPEAT on the third hop (a, b, a).
        symlink($this->dir.'/b', $this->dir.'/a');
        symlink($this->dir.'/a', $this->dir.'/b');

        foreach (['a', 'b'] as $name) {
            try {
                UntrustedPathContents::read($this->dir.'/'.$name, 'authorized_keys');
                $this->fail("a mutual symlink pair (entering at {$name}) was not refused");
            } catch (PathResolvesToNoFileException $e) {
                $this->assertStringContainsString('LOOPS', $e->getMessage());
            }
        }
    }

    public function test_a_multi_hop_dangling_chain_establishes_rather_than_withholding(): void
    {
        // hop1 -> hop2 -> nowhere. hop1's IMMEDIATE target (hop2) exists, so the one-hop
        // check confirmed nothing and withheld — the walk must follow past hop2 to see that
        // ITS target is genuinely absent.
        symlink($this->dir.'/hop2', $this->dir.'/hop1');
        symlink($this->dir.'/nowhere', $this->dir.'/hop2');

        try {
            UntrustedPathContents::read($this->dir.'/hop1', 'authorized_keys');
            $this->fail('a multi-hop dangling chain was not refused');
        } catch (PathResolvesToNoFileException $e) {
            $this->assertStringContainsString('ends at a target that does not exist', $e->getMessage());
        }
    }

    public function test_a_chain_that_would_loop_behind_an_untraversable_directory_still_withholds(): void
    {
        // ⛔ THE CARD#5698 ASYMMETRY MUST SURVIVE THE WALK. `entry` points INTO `closed`
        // (0000), and what lives at that name inside `closed` — absent, a real file, or a
        // link back out to `entry` closing a loop — this process genuinely cannot see. The
        // walk must stop at "cannot look" and never guess ESTABLISHING from a permission
        // denial, exactly as the single-hop check did before it became a walk.
        mkdir($this->dir.'/closed', 0o700);
        symlink($this->dir.'/entry', $this->dir.'/closed/inner');
        symlink($this->dir.'/closed/inner', $this->dir.'/entry');
        chmod($this->dir.'/closed', 0o000);
        clearstatcache();
        if (@lstat($this->dir.'/closed/inner') !== false) {
            @chmod($this->dir.'/closed', 0o700);
            $this->markTestSkipped('this uid traverses a 0000 directory (root?), so the arm has nothing to measure');
        }

        try {
            UntrustedPathContents::read($this->dir.'/entry', 'authorized_keys');
            $this->fail('a chain blocked by traversal was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertNotInstanceOf(PathResolvesToNoFileException::class, $e);
            $this->assertStringContainsString('could not fully resolve', $e->getMessage());
        } finally {
            @chmod($this->dir.'/closed', 0o700);
        }
    }

    public function test_establishing_is_never_wider_than_is_file_false_across_a_shape_battery(): void
    {
        // ⭐ THE SET PROPERTY, ASSERTED DIRECTLY (card#9037 r2 review) — not per-shape, so
        // it holds over the WHOLE battery below rather than being satisfiable by getting each
        // named case right in isolation (r3 review: this is a claim about how the property is
        // CHECKED, not a claim that every possible shape is covered — a shape this battery
        // does not construct is not exercised by this test). Every shape this class can call
        // ESTABLISHING must be one `is_file()` already called false: migrating a reader onto
        // this class must never mint a FALSE FAIL that the old `is_file()`-gated reader would
        // not also have produced (as `absent()`, in `AuthorizedKeysRead`'s vocabulary).
        $battery = [];

        symlink($this->dir.'/self', $this->dir.'/self');
        $battery[] = 'self';

        symlink($this->dir.'/b', $this->dir.'/a');
        symlink($this->dir.'/a', $this->dir.'/b');
        $battery[] = 'a';
        $battery[] = 'b';

        symlink($this->dir.'/hop2', $this->dir.'/hop1');
        symlink($this->dir.'/nowhere', $this->dir.'/hop2');
        $battery[] = 'hop1';

        symlink($this->dir.'/nowhere2', $this->dir.'/plain1');
        $battery[] = 'plain1';

        mkdir($this->dir.'/adir', 0o700);
        symlink($this->dir.'/adir', $this->dir.'/link-to-dir');
        $battery[] = 'link-to-dir';
        $battery[] = 'adir';

        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->dir.'/fifo2', 0o600);
            $battery[] = 'fifo2';
        }

        file_put_contents($this->dir.'/regular', 'x
');
        symlink($this->dir.'/regular', $this->dir.'/link-to-regular');
        $battery[] = 'regular';
        $battery[] = 'link-to-regular';

        $checked = 0;
        $establishedCount = 0;
        foreach ($battery as $name) {
            $path = $this->dir.'/'.$name;
            $establishing = false;
            try {
                UntrustedPathContents::read($path, 'authorized_keys');
            } catch (PathResolvesToNoFileException) {
                $establishing = true;
            } catch (UnreadableFileException) {
                $establishing = false;
            }
            if ($establishing) {
                $establishedCount++;
                $this->assertFalse(
                    is_file($path),
                    "{$name} was called ESTABLISHING but is_file() is TRUE for it — this would mint a false FAIL"
                );
            }
            $checked++;
        }
        // The battery itself must contain both an ESTABLISHING member and a non-vacuous
        // check count — otherwise this test would pass against a primitive that never
        // establishes anything, or against a battery that silently shrank to nothing.
        $this->assertGreaterThanOrEqual(8, $checked, 'the battery shrank silently');
        $this->assertGreaterThanOrEqual(5, $establishedCount, 'no ESTABLISHING verdict was observed — the subset assertion above never ran');
    }

    // ---- THE HOP-CAP BOUNDARY (card#9037 r3) --------------------------------------
    // ⛔ THIS SECTION PINS THE OFF-BY-ONE ITSELF. `MAX_SYMLINK_HOPS` names the kernel's
    // own limit — the number of symlinks the kernel will FOLLOW before refusing the next
    // one with ELOOP — and the walk must examine the TERMINAL node reached after exactly
    // that many follows, not give up one node short of it. Both cases below need the
    // terminal to be a permission fault (not a plain absence), because a genuinely absent
    // or genuinely readable terminal makes `@stat($path)` in `nonRegularRefusal()` decide
    // the whole question before the walk ever runs — the boundary is invisible on those
    // inputs and only shows up on the EACCES axis, which is exactly why it shipped once.

    /** Builds link_1 -> link_2 -> … -> link_n -> $terminal, all inside $this->dir. */
    private function buildSymlinkChain(int $n, string $terminal): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $target = $i < $n ? $this->dir.'/link_'.($i + 1) : $terminal;
            symlink($target, $this->dir.'/link_'.$i);
        }
    }

    public function test_a_forty_link_chain_behind_an_untraversable_directory_still_withholds(): void
    {
        // THE BLOCKER ITSELF. The kernel follows exactly 40 links and then attempts to
        // resolve the 40th target — here, a name inside a directory this process may not
        // traverse — and gets EACCES, not ELOOP. `MAX_SYMLINK_HOPS` is 40 for exactly this
        // reason; a walk that gives up one node short of it misreports a real permission
        // fault as a confirmed loop.
        mkdir($this->dir.'/closed', 0o700);
        $this->buildSymlinkChain(UntrustedPathContents::MAX_SYMLINK_HOPS, $this->dir.'/closed/hidden');
        chmod($this->dir.'/closed', 0o000);
        clearstatcache();
        if (@lstat($this->dir.'/closed/hidden') !== false) {
            @chmod($this->dir.'/closed', 0o700);
            $this->markTestSkipped('this uid traverses a 0000 directory (root?), so the arm has nothing to measure');
        }

        try {
            UntrustedPathContents::read($this->dir.'/link_1', 'authorized_keys');
            $this->fail('a 40-link chain behind an untraversable directory was not refused');
        } catch (UnreadableFileException $e) {
            $this->assertNotInstanceOf(
                PathResolvesToNoFileException::class,
                $e,
                'a chain of exactly MAX_SYMLINK_HOPS links was called an unearned ESTABLISH instead of withholding on the blocked terminal'
            );
        } finally {
            @chmod($this->dir.'/closed', 0o700);
        }
    }

    public function test_a_forty_one_link_chain_establishes_as_a_loop(): void
    {
        // THE OTHER SIDE OF THE SAME BOUNDARY, in the SAME shape. One link past the cap,
        // the kernel refuses to follow it at all and never reaches the terminal (real
        // ELOOP) — so this must establish regardless of what the terminal is or whether
        // it is even reachable. The control that a fix satisfying the case above cannot
        // satisfy by simply widening the bound without limit.
        mkdir($this->dir.'/closed', 0o700);
        $this->buildSymlinkChain(UntrustedPathContents::MAX_SYMLINK_HOPS + 1, $this->dir.'/closed/hidden');
        chmod($this->dir.'/closed', 0o000);
        clearstatcache();
        if (@lstat($this->dir.'/closed/hidden') !== false) {
            @chmod($this->dir.'/closed', 0o700);
            $this->markTestSkipped('this uid traverses a 0000 directory (root?), so the arm has nothing to measure');
        }

        try {
            UntrustedPathContents::read($this->dir.'/link_1', 'authorized_keys');
            $this->fail('a 41-link chain was not refused');
        } catch (PathResolvesToNoFileException $e) {
            $this->assertStringContainsString('LOOPS', $e->getMessage());
        } finally {
            @chmod($this->dir.'/closed', 0o700);
        }
    }
}
