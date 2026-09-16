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
            // ⭐ card#9528 SHAPE (a): the space is INSIDE the userinfo, so the scrubber's old
            // bound could not reach the `@` behind it and this branch — the one a paste error
            // always lands on — quoted the whole password.
            'whitespace INSIDE the userinfo' => [
                'https://svc:'.$canary.' tail@kanban.example/api/v3',
                'https://***@kanban.example/api/v3',
                'contains whitespace',
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

    /**
     * ⭐ THE ACCEPTANCE CHANGE card#9528 AUTHORIZES (operator decision on the card, 2026-09-16).
     *
     * `httpUrl()` checked only non-empty string, whitespace, `parse_url`, scheme and host — no
     * userinfo character validation at all — so `https://svc:pw"tail@host/webhooks` was ACCEPTED
     * and the credential then travelled into places that echo it back (a kanban error body
     * quoting the URL it was handed) in a form no reader of a finished string can recognise.
     *
     * Every character below is already ILLEGAL UNENCODED in a userinfo under RFC 3986, whose
     * userinfo is `*( unreserved / pct-encoded / sub-delims / ":" )`. So this refuses input that
     * was never valid — loudly, at validation time, instead of carrying it.
     *
     * ⛔ BOTH DIRECTIONS. The refusal must not print the credential it refuses, and it must still
     * name a value the operator can find in their own config.
     *
     * @return list<array{0: string}>
     */
    public static function illegalUserinfoCharacters(): array
    {
        return [
            ['"'], ['\\'], ['|'], ['<'], ['>'], ['^'], ['`'], ['{'], ['}'], ['['], [']'], ["\x7F"],
        ];
    }

    #[DataProvider('illegalUserinfoCharacters')]
    public function test_a_userinfo_carrying_a_character_illegal_unencoded_is_refused(string $char): void
    {
        $canary = 'CANARY9528SYNTHETICVALUE';

        try {
            UrlValidator::httpUrl('https://svc:'.$canary.$char.'tail@bridge.example.com/webhooks', 'bridge.receiver_base_url');
            $this->fail('the validator accepted a userinfo carrying a character RFC 3986 does not allow unencoded');
        } catch (ConfigException $e) {
            $this->assertStringNotContainsString($canary, $e->getMessage());
            $this->assertStringContainsString("bridge.receiver_base_url 'https://***@bridge.example.com/webhooks'", $e->getMessage());
            $this->assertStringContainsString('illegal unencoded', $e->getMessage());
        }
    }

    /**
     * ⛔ THE CONTROL, AND THE REFUSAL ABOVE PROVES NOTHING WITHOUT IT. A predicate that simply
     * refused every `@`-bearing value would satisfy every case in `illegalUserinfoCharacters`
     * while turning a working install's config into a refusal.
     *
     * The first four carry a userinfo built only of what RFC 3986 permits there: unreserved
     * characters, the sub-delims (`!$&'()*+,;=`), `:` and percent-encoding. The last three carry
     * NO userinfo at all — their `@` sits behind the authority's first `/` or `?`, where `@` is
     * a perfectly legal path or query character — which is why the userinfo is bound to the
     * AUTHORITY here, rather than to the last `@` in the whole value the way the REDACTOR binds
     * it. The redactor errs toward removing too much; a validator erring that way refuses a
     * config that works.
     *
     * @return list<array{0: string}>
     */
    public static function acceptedUserinfoValues(): array
    {
        return [
            ['https://svc:p4ss-w0rd_x.y~z@bridge.example.com/webhooks'],
            ["https://user:p!\$&'()*+,;=@bridge.example.com/webhooks"],
            ['https://svc:a%20b%2Fc@bridge.example.com/webhooks'],
            ['https://svc:pw@bridge.example.com:8443/webhooks'],
            ['https://bridge.example.com/@you/webhooks'],
            ['https://board.example/api?to=a@b.example'],
            ['https://[::1]/api/v3'],
        ];
    }

    #[DataProvider('acceptedUserinfoValues')]
    public function test_a_userinfo_rfc_3986_allows_unencoded_is_still_accepted(string $value): void
    {
        $this->assertSame($value, UrlValidator::httpUrl($value, 'bridge.receiver_base_url'));
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
