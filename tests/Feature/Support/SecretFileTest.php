<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\InsecureSecretPermsException;
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
