<?php

namespace Tests\Unit\Validation;

use App\Bridge\Validation\EndpointValidationException;
use App\Bridge\Validation\LocalhostUrl;
use PHPUnit\Framework\TestCase;

class LocalhostUrlTest extends TestCase
{
    public function test_accepts_loopback_http(): void
    {
        LocalhostUrl::assertValid('http://127.0.0.1:9931/hook', 's');
        LocalhostUrl::assertValid('http://localhost/hook', 's');
        LocalhostUrl::assertValid('http://[::1]/hook', 's');
        $this->expectNotToPerformAssertions();
    }

    public function test_normalizes_scheme_and_ipv6_host(): void
    {
        LocalhostUrl::assertValid('HTTP://[::1]/hook', 's');
        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_userinfo(): void
    {
        $this->expectException(EndpointValidationException::class);
        $this->expectExceptionMessageMatches('/must not contain a userinfo component/');
        LocalhostUrl::assertValid('http://user:pass@127.0.0.1/hook', 's');
    }

    public function test_rejects_https(): void
    {
        $this->expectException(EndpointValidationException::class);
        $this->expectExceptionMessageMatches('/must be http/');
        LocalhostUrl::assertValid('https://127.0.0.1/hook', 's');
    }

    public function test_rejects_non_loopback_host(): void
    {
        $this->expectException(EndpointValidationException::class);
        $this->expectExceptionMessageMatches('/must point at/');
        LocalhostUrl::assertValid('http://example.com/hook', 's');
    }
}
