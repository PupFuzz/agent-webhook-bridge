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
    public function test_a_userinfo_carrying_a_character_illegal_unencoded_is_refused_at_a_config_door(string $char): void
    {
        $canary = 'CANARY9528SYNTHETICVALUE';

        try {
            UrlValidator::configDoorHttpUrl('https://svc:'.$canary.$char.'tail@bridge.example.com/webhooks', 'bridge.receiver_base_url');
            $this->fail('the config door accepted a userinfo carrying a character RFC 3986 does not allow unencoded');
        } catch (ConfigException $e) {
            $this->assertStringNotContainsString($canary, $e->getMessage());
            $this->assertStringContainsString("bridge.receiver_base_url 'https://***@bridge.example.com/webhooks'", $e->getMessage());
            $this->assertStringContainsString('illegal unencoded', $e->getMessage());
        }
    }

    /**
     * ⛔ THE SCOPE OF THE ACCEPTANCE CHANGE, AND THE REASON IT IS A DEDICATED PATH RATHER THAN
     * A WIDER `httpUrl()` (OPERATOR RULING, 2026-09-16; canon #3).
     *
     * The ruling narrowed the refusal to the CONFIG DOORS — `bridge:check` and
     * `bridge:provision`, where the value is being JUDGED and QUOTED. The premise the first
     * cut was granted on (*"none is a runtime path"*) was false: `httpUrl()`/`secureHttpUrl()`
     * are also asked at RUNTIME, by `WritebackClientFactory` on every writeback and by
     * `IdleNudgeConfig` on every nudge pass. Such an install WORKS TODAY — Guzzle parses and
     * normalizes `svc:pw"tail` to `svc:pw%22tail` — so refusing there would stop a board
     * moving to protect nothing: the REDACTOR is what keeps the credential off the operator's
     * terminal, and it applies everywhere regardless of this predicate.
     *
     * ⛔ ABSENCE WOULD BE SATISFIED BY DELETING THE CHECK. That is why this asserts the value
     * comes back IDENTICAL and the paired case above asserts the door still refuses it: the
     * two together say the rule moved, not that it went away.
     */
    #[DataProvider('illegalUserinfoCharacters')]
    public function test_the_runtime_validators_still_accept_what_a_config_door_refuses(string $char): void
    {
        $value = 'https://svc:CANARY9528SYNTHETICVALUE'.$char.'tail@bridge.example.com/webhooks';

        $this->assertSame($value, UrlValidator::httpUrl($value, 'bridge.receiver_base_url'));
        $this->assertSame($value, UrlValidator::secureHttpUrl($value, 'bridge.providers.kanban.api_base_url'));
    }

    /**
     * The secret-bearing door keeps BOTH rules, and in the order that names the more serious
     * fault first: a cleartext base with an illegal userinfo is refused for the https floor,
     * because that one puts the credential on the wire.
     */
    public function test_the_secure_config_door_carries_the_https_floor_and_the_userinfo_rule(): void
    {
        $this->assertSame(
            'https://kanban.example/api/v3',
            UrlValidator::configDoorSecureHttpUrl('https://kanban.example/api/v3', 'bridge.providers.kanban.api_base_url'),
        );

        try {
            UrlValidator::configDoorSecureHttpUrl('https://svc:pw"tail@kanban.example/api/v3', 'bridge.providers.kanban.api_base_url');
            $this->fail('the secure config door accepted an illegal userinfo');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('illegal unencoded', $e->getMessage());
        }

        try {
            UrlValidator::configDoorSecureHttpUrl('http://svc:pw"tail@kanban.internal/api/v3', 'bridge.providers.kanban.api_base_url');
            $this->fail('the secure config door accepted a cleartext remote base');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('must use https', $e->getMessage());
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
        // Asked of the CONFIG DOOR, because that is the only predicate that could over-reach:
        // `httpUrl()` judges no userinfo character at all.
        $this->assertSame($value, UrlValidator::configDoorHttpUrl($value, 'bridge.receiver_base_url'));
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
