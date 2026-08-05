<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Exceptions\ChannelTokenFault;
use App\Bridge\Support\ChannelToken;
use Tests\TestCase;

class ChannelTokenTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'chtok');   // tempnam → 0600
    }

    protected function tearDown(): void
    {
        @chmod($this->path, 0o600);
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_reads_and_trims_a_0600_token(): void
    {
        file_put_contents($this->path, "  abc123\n");
        $this->assertSame('abc123', ChannelToken::read($this->path));
    }

    public function test_group_or_world_readable_rejected(): void
    {
        file_put_contents($this->path, 'abc');
        chmod($this->path, 0o644);
        $this->expectException(ChannelTokenException::class);
        ChannelToken::read($this->path);
    }

    public function test_empty_token_rejected(): void
    {
        file_put_contents($this->path, "   \n");
        $this->expectException(ChannelTokenException::class);
        ChannelToken::read($this->path);
    }

    public function test_missing_file_rejected(): void
    {
        $this->expectException(ChannelTokenException::class);
        ChannelToken::read('/nonexistent/'.uniqid().'.token');
    }

    /**
     * THE FAULT IS THE CONTRACT `bridge:check` READS, and the five cases are asserted
     * separately because the split that matters is not "which rule broke" but WHO the
     * broken rule is broken FOR (card#5698): three faults are the same for every reader,
     * two are relative to the asking uid, and only the read itself can tell them apart.
     * A test asserting merely "it threw" would pass against the bundled shape this
     * replaced.
     */
    public function test_an_absent_token_under_a_traversable_dir_is_missing(): void
    {
        unlink($this->path);

        $this->assertSame(ChannelTokenFault::Missing, $this->faultFor($this->path));
    }

    public function test_a_present_but_unreadable_token_is_not_readable_rather_than_missing(): void
    {
        $this->skipAsRoot();
        file_put_contents($this->path, 'abc');
        chmod($this->path, 0o000);

        // The whole point: `is_file()` says the file IS there, so this is NOT an absence —
        // and the mode is relative to US, so it is no evidence about the receiver's read.
        $this->assertSame(ChannelTokenFault::NotReadable, $this->faultFor($this->path));
    }

    public function test_a_token_under_an_untraversable_dir_is_not_visible(): void
    {
        $this->skipAsRoot();
        $locked = sys_get_temp_dir().'/chtok-locked-'.bin2hex(random_bytes(6));
        mkdir($locked, 0o755);
        file_put_contents($locked.'/token', 'abc');
        chmod($locked.'/token', 0o600);
        chmod($locked, 0o000);

        try {
            // Indistinguishable from Missing to a bare stat — this is the discrimination.
            $fault = $this->faultFor($locked.'/token');
        } finally {
            chmod($locked, 0o755);
            exec('rm -rf '.escapeshellarg($locked));
        }

        $this->assertSame(ChannelTokenFault::NotVisible, $fault);
    }

    public function test_a_group_readable_token_is_insecure_perms(): void
    {
        file_put_contents($this->path, 'abc');
        chmod($this->path, 0o644);

        $this->assertSame(ChannelTokenFault::InsecurePerms, $this->faultFor($this->path));
    }

    public function test_a_blank_token_is_empty_file(): void
    {
        file_put_contents($this->path, "   \n");

        $this->assertSame(ChannelTokenFault::EmptyFile, $this->faultFor($this->path));
    }

    public function test_exception_message_carries_path_never_token_value(): void
    {
        file_put_contents($this->path, 'super-secret-value');
        chmod($this->path, 0o640);
        try {
            ChannelToken::read($this->path);
            $this->fail('expected ChannelTokenException');
        } catch (ChannelTokenException $e) {
            $this->assertStringContainsString($this->path, $e->getMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }

    private function faultFor(string $path): ChannelTokenFault
    {
        try {
            ChannelToken::read($path);
        } catch (ChannelTokenException $e) {
            return $e->fault;
        }

        $this->fail("expected ChannelTokenException for {$path}");
    }
}
