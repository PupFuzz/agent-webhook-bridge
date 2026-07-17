<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\LoopbackHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoopbackHostTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function hostCases(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1', true],
            'localhost' => ['localhost', true],
            'ipv6 loopback bracketed' => ['[::1]', true],
            'ipv6 loopback bare' => ['::1', true],
            'uppercase localhost' => ['LOCALHOST', true],
            'bracketed non-loopback ipv6' => ['[fe80::1]', false],
            'remote host' => ['example.com', false],
            'private but non-loopback' => ['10.0.0.1', false],
            'empty' => ['', false],
            'loopback-lookalike suffix' => ['127.0.0.1.evil.com', false],
        ];
    }

    #[DataProvider('hostCases')]
    public function test_matches(string $host, bool $expected): void
    {
        $this->assertSame($expected, LoopbackHost::matches($host));
    }
}
