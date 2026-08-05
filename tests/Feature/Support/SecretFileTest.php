<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\SecretFile;
use Tests\TestCase;

class SecretFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'secf');   // tempnam → 0600
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_is_insecure_false_for_0600(): void
    {
        file_put_contents($this->path, 'x');
        chmod($this->path, 0o600);
        $this->assertFalse(SecretFile::isInsecure($this->path));
    }

    public function test_is_insecure_true_for_group_or_world_readable(): void
    {
        file_put_contents($this->path, 'x');
        chmod($this->path, 0o640);
        $this->assertTrue(SecretFile::isInsecure($this->path));
        chmod($this->path, 0o644);
        $this->assertTrue(SecretFile::isInsecure($this->path));
        chmod($this->path, 0o604);
        $this->assertTrue(SecretFile::isInsecure($this->path));
    }

    public function test_is_insecure_false_for_missing_file(): void
    {
        // A missing secret is the caller's concern (unknown_scope / skip), never
        // converted into "insecure".
        $this->assertFalse(SecretFile::isInsecure('/nonexistent/'.uniqid()));
    }

    public function test_read_returns_trimmed_for_0600(): void
    {
        file_put_contents($this->path, "  tok\n");
        chmod($this->path, 0o600);
        $this->assertSame('tok', SecretFile::read($this->path));
    }

    public function test_read_null_for_missing_or_blank(): void
    {
        $this->assertNull(SecretFile::read('/nonexistent/'.uniqid()));
        file_put_contents($this->path, "   \n");
        chmod($this->path, 0o600);
        $this->assertNull(SecretFile::read($this->path));
    }

    public function test_read_throws_on_insecure_perms(): void
    {
        file_put_contents($this->path, 'super-secret-value');
        chmod($this->path, 0o644);
        $this->expectException(InsecureSecretPermsException::class);
        SecretFile::read($this->path);
    }

    /**
     * THE THIRD OUTCOME (card#5778). Present, owner-only, and unreadable by this process
     * is a state BOTH pre-gates a caller reaches for pass in: `is_file()` is true and
     * `isInsecure()` is false, because 0000 has no group/world bit. It used to surface as
     * an undocumented `ErrorException`.
     */
    public function test_read_throws_when_present_but_unreadable_by_this_process(): void
    {
        file_put_contents($this->path, 'super-secret-value');
        chmod($this->path, 0o000);
        $this->skipIfStillReadable();

        // Pinned, not assumed: if either of these flipped, the test would be exercising a
        // different state than the one the callers get caught by.
        $this->assertTrue(is_file($this->path), 'precondition: the file is present');
        $this->assertFalse(SecretFile::isInsecure($this->path), 'precondition: 0000 passes the perms gate');

        $this->expectException(UnreadableSecretException::class);
        SecretFile::read($this->path);
    }

    public function test_unreadable_message_carries_path_never_value(): void
    {
        file_put_contents($this->path, 'super-secret-value');
        chmod($this->path, 0o000);
        $this->skipIfStillReadable();

        try {
            SecretFile::read($this->path);
            $this->fail('expected UnreadableSecretException');
        } catch (UnreadableSecretException $e) {
            $this->assertStringContainsString($this->path, $e->getMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }

    /**
     * An unreadable secret must NOT come back as null — that is the "absent" answer, and
     * conflating the two is the defect this outcome exists to prevent. Asserted as its
     * own case because the two throw tests above would both still pass if `read()` were
     * changed to return null for a DIFFERENT unreadable path than the one they use.
     */
    public function test_unreadable_is_never_reported_as_absent(): void
    {
        file_put_contents($this->path, 'v');
        chmod($this->path, 0o000);
        $this->skipIfStillReadable();

        $threw = false;
        try {
            $result = SecretFile::read($this->path);
        } catch (UnreadableSecretException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result ?? null, true).' for an unreadable secret instead of throwing');
    }

    /**
     * Root reads through mode bits, so on a root runner the state above is not reachable
     * and the assertions would pass vacuously. Skip loudly instead — a test that cannot
     * fail must not report as a pass. CI runs as a non-root user, so this is a local /
     * container guard, not the normal path.
     */
    private function skipIfStillReadable(): void
    {
        clearstatcache(true, $this->path);
        if (is_readable($this->path)) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }
    }

    public function test_perms_message_carries_path_never_value(): void
    {
        file_put_contents($this->path, 'super-secret-value');
        chmod($this->path, 0o640);
        try {
            SecretFile::read($this->path);
            $this->fail('expected InsecureSecretPermsException');
        } catch (InsecureSecretPermsException $e) {
            $this->assertStringContainsString($this->path, $e->getMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }
}
