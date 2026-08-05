<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The shared not-visible guard (card#5698).
 *
 * THE POINT OF EVERY TEST HERE is the one distinction the class exists to make:
 * `is_dir()`/`is_file()` return false for EACCES exactly as for ENOENT, so a leg that
 * reads a bare `false` as "absent" is asserting a fact its evidence cannot support. The
 * assertions below therefore always pair the two worlds — a genuinely absent path AND an
 * unseeable one — because a test that only exercised one of them would pass on an
 * implementation that always returned the same answer.
 */
class PathVisibilityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/path-visibility-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0755);
        @chmod($this->dir.'/locked', 0755);
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_genuinely_absent_path_under_a_traversable_dir_is_visible(): void
    {
        // The load-bearing negative: absence is ANSWERABLE here, so the guard must stand
        // aside and let the caller make its definite claim.
        $this->assertTrue(PathVisibility::ancestorIsTraversable($this->dir.'/nope'));
        $this->assertNull(PathVisibility::unverifiedUnlessVisible($this->dir.'/nope', 'subject'));
    }

    public function test_a_path_under_an_untraversable_dir_is_not_visible(): void
    {
        $this->skipAsRoot();
        $locked = $this->dir.'/locked';
        mkdir($locked, 0755);
        chmod($locked, 0000);

        try {
            $this->assertFalse(PathVisibility::ancestorIsTraversable($locked.'/token'));
            $finding = PathVisibility::unverifiedUnlessVisible($locked.'/token', 'the token');
        } finally {
            chmod($locked, 0755);
        }

        $this->assertNotNull($finding);
        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('the token', $finding->message);
        $this->assertStringContainsString('is not visible to this user', $finding->message);
    }

    public function test_the_two_worlds_are_indistinguishable_to_a_bare_stat(): void
    {
        // The premise the whole class rests on, asserted rather than assumed — if this
        // ever stopped being true, every adopting call site would be unnecessary.
        $this->skipAsRoot();
        $locked = $this->dir.'/locked';
        mkdir($locked, 0755);
        file_put_contents($locked.'/present', 'x');
        chmod($locked, 0000);

        try {
            // A file that EXISTS, under a directory we cannot traverse.
            $this->assertFalse(is_file($locked.'/present'));
            // A file that genuinely does not exist, under one we can.
            $this->assertFalse(is_file($this->dir.'/absent'));
            // Same `false`. Only the guard separates them.
            $this->assertFalse(PathVisibility::ancestorIsTraversable($locked.'/present'));
            $this->assertTrue(PathVisibility::ancestorIsTraversable($this->dir.'/absent'));
        } finally {
            chmod($locked, 0755);
        }
    }

    public function test_traversability_does_not_require_readability(): void
    {
        // 0711 answers stat on its children fine, so requiring +r would report a healthy
        // install as unverifiable.
        $this->skipAsRoot();
        $execOnly = $this->dir.'/exec-only';
        mkdir($execOnly, 0755);
        chmod($execOnly, 0711);

        try {
            $this->assertTrue(PathVisibility::ancestorIsTraversable($execOnly.'/child'));
            $this->assertNull(PathVisibility::unverifiedUnlessVisible($execOnly.'/child', 'subject'));
        } finally {
            chmod($execOnly, 0755);
        }
    }

    public function test_the_message_door_states_no_opinion_of_its_own(): void
    {
        // `notVisibleFinding()` is for a caller whose failed READ already established
        // non-visibility, so it must NOT re-measure. Asserted on a path whose ancestor IS
        // traversable — the world where the statting door returns null — which is exactly
        // the discriminator: an implementation that re-checked would answer differently here.
        $this->assertNull(PathVisibility::unverifiedUnlessVisible($this->dir.'/nope', 'the token'));

        $finding = PathVisibility::notVisibleFinding('the token');

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('the token', $finding->message);
        $this->assertStringContainsString('is not visible to this user', $finding->message);
    }

    public function test_it_walks_up_to_the_nearest_existin_g_ancestor(): void
    {
        // The path may be several non-existent segments deep; the question is whether the
        // deepest directory that DOES exist lets us look.
        $this->assertTrue(PathVisibility::ancestorIsTraversable($this->dir.'/a/b/c/d'));
    }
}
