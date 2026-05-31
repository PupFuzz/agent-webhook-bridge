<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\ChannelTokenException;
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
}
