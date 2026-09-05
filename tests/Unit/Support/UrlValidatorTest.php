<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * ⭐ THE VALUE IS REDACTED AT THE INTERPOLATION, NOT BY A READER OF THE MESSAGE
     * (card#8433, canon #20). `secureHttpUrl`'s own verdict text says this field *receives
     * the bearer token/webhook secret*; echoing the value back is the validator that guards
     * the credential handing it to whoever reads `bridge:check`. Once the value is inside
     * the message nothing marks which substring was the secret, so no downstream scrubber
     * can close this.
     *
     * ⛔ BOTH DIRECTIONS, EVERY CASE. Absence alone is satisfied by a message that dropped
     * the value entirely — which would name no config an operator could go and fix.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function refusedValues(): array
    {
        $canary = 'CANARY8433SYNTHETICVALUE';

        return [
            // [the configured value, what must survive, the branch it exercises]
            'https floor, credential in the query' => [
                'http://kanban.internal/api/v3?k='.$canary,
                'http://kanban.internal/api/v3?[REDACTED]',
                'must use https',
            ],
            'https floor, credential in the userinfo' => [
                'http://svc:'.$canary.'@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
                'must use https',
            ],
            'wrong scheme' => [
                'ftp://svc:'.$canary.'@kanban.example/api',
                'ftp://***@kanban.example/api',
                'must use http or https',
            ],
            'whitespace — quoted BEFORE parse_url is even tried' => [
                'https://svc:'.$canary.'@kanban.example/a b',
                'https://***@kanban.example/a b',
                'contains whitespace',
            ],
            'not a valid URL — the branch parse_url failed on' => [
                'https://svc:'.$canary.'@kanban.example:notaport/api',
                'https://***@kanban.example:notaport/api',
                'is not a valid URL',
            ],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_a_refused_value_is_redacted_but_still_identifiable(string $value, string $expected, string $verdict): void
    {
        try {
            UrlValidator::secureHttpUrl($value, 'bridge.providers.kanban.api_base_url');
            $this->fail('the validator accepted a value it must refuse');
        } catch (ConfigException $e) {
            $this->assertStringNotContainsString('CANARY8433SYNTHETICVALUE', $e->getMessage());
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringContainsString($verdict, $e->getMessage());
            $this->assertStringContainsString('bridge.providers.kanban.api_base_url', $e->getMessage());
        }
    }

    public function test_a_refused_value_with_nothing_to_hide_is_quoted_unchanged(): void
    {
        // The redaction must not cost the ordinary message its value: nothing here sits in
        // a userinfo, a query or a fragment, so nothing is removed.
        try {
            UrlValidator::httpUrl('ftp://kanban.example/api/v3', 'bridge.receiver_base_url');
            $this->fail('the validator accepted a value it must refuse');
        } catch (ConfigException $e) {
            $this->assertSame("bridge.receiver_base_url 'ftp://kanban.example/api/v3' must use http or https", $e->getMessage());
        }
    }
}
