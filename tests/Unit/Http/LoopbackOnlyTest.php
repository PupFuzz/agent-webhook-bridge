<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\LoopbackOnly;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoopbackOnlyTest extends TestCase
{
    /**
     * @return list<array{string, bool}>
     */
    public static function ipCases(): array
    {
        return [
            // Admitted: the WHOLE 127.0.0.0/8 block + ::1 + IPv4-mapped loopback.
            ['127.0.0.1', true],
            ['127.0.0.53', true],
            ['127.255.255.254', true],
            ['::1', true],
            ['0:0:0:0:0:0:0:1', true],          // ::1 long form
            ['::ffff:127.0.0.1', true],
            // Refused: RFC1918, public, off-by-one, the hostname string, empty.
            ['10.0.0.1', false],
            ['192.168.1.10', false],
            ['128.0.0.1', false],               // NOT 127.* — a classic near-miss
            ['126.255.255.255', false],
            ['8.8.8.8', false],
            ['::2', false],
            ['2001:db8::1', false],
            ['::ffff:10.0.0.1', false],         // IPv4-mapped NON-loopback
            ['localhost', false],               // a peer IP is numeric, never a hostname
            ['', false],
            ['not-an-ip', false],
        ];
    }

    #[DataProvider('ipCases')]
    public function test_is_loopback_decides_per_peer(string $ip, bool $expected): void
    {
        $this->assertSame($expected, LoopbackOnly::isLoopback($ip === '' ? null : $ip));
    }

    public function test_null_peer_is_refused(): void
    {
        $this->assertFalse(LoopbackOnly::isLoopback(null));
    }
}
