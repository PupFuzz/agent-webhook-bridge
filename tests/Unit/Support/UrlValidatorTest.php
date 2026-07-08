<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

class UrlValidatorTest extends TestCase
{
    public function test_http_url_accepts_http_and_https(): void
    {
        $this->assertSame('https://a.example/x', UrlValidator::httpUrl('https://a.example/x', 'f'));
        $this->assertSame('http://a.example/x', UrlValidator::httpUrl('http://a.example/x', 'f'));
    }

    public function test_secure_http_url_accepts_https(): void
    {
        $this->assertSame('https://kanban.example/api/v3', UrlValidator::secureHttpUrl('https://kanban.example/api/v3', 'f'));
    }

    public function test_secure_http_url_allows_cleartext_only_to_loopback(): void
    {
        $this->assertSame('http://127.0.0.1:8000/api/v3', UrlValidator::secureHttpUrl('http://127.0.0.1:8000/api/v3', 'f'));
        $this->assertSame('http://localhost/api/v3', UrlValidator::secureHttpUrl('http://localhost/api/v3', 'f'));
        $this->assertSame('http://[::1]/api/v3', UrlValidator::secureHttpUrl('http://[::1]/api/v3', 'f'));
    }

    public function test_secure_http_url_rejects_cleartext_to_a_remote_host(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/must use https/');
        UrlValidator::secureHttpUrl('http://kanban.internal/api/v3', 'bridge.providers.kanban.api_base_url');
    }

    public function test_secure_http_url_keeps_the_base_shape_checks(): void
    {
        $this->expectException(ConfigException::class);
        UrlValidator::secureHttpUrl('ftp://kanban.example/api', 'f');
    }
}
