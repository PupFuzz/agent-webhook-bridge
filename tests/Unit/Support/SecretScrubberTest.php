<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\SecretScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The app's one credential redactor (card#8433).
 *
 * ⭐ EVERY CASE ASSERTS BOTH DIRECTIONS — the planted value is GONE and something the
 * caller can still read is THERE. An absence-only assertion is satisfied by a redactor
 * that dropped the whole string, which would silently turn every message this thing
 * protects into an empty one, and no test would notice.
 *
 * The shape cases came over from `RefusalContextTest` with the method. The POSITIONAL cases
 * are new, and each one names which entry point reaches it: `text()` covers an embedded
 * `http(s)://…` run, `url()` covers the whole value including a SCHEMELESS one, and
 * `test_only_url_reaches_a_value_carrying_no_scheme` is the discriminator that keeps the two
 * from being collapsed into one.
 */
class SecretScrubberTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function credentialBodies(): array
    {
        return [
            'json token value' => ['{"token":"ghp_SUPERSECRETVALUE1234567890"}'],
            'json api_token key (key-contains)' => ['{"api_token":"ghp_SUPERSECRETVALUE1234567890"}'],
            'json access_token key' => ['{"access_token":"ghp_SUPERSECRETVALUE1234567890"}'],
            'json password value' => ['{"password":"SUPERSECRETVALUE1234567890"}'],
            'json authorization value' => ['{"authorization":"Bearer SUPERSECRETVALUE1234567890"}'],
            'bearer scheme in prose' => ['upstream sent header Bearer SUPERSECRETVALUE1234567890 back'],
            'github token scheme' => ['Authorization: token SUPERSECRETVALUE1234567890'],
            'query/form style' => ['api_key=SUPERSECRETVALUE1234567890&board=8'],
        ];
    }

    #[DataProvider('credentialBodies')]
    public function test_text_redacts_credential_adjacent_values(string $body): void
    {
        $scrubbed = SecretScrubber::text($body);

        $this->assertStringNotContainsString('SUPERSECRETVALUE1234567890', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_text_leaves_a_benign_body_intact(): void
    {
        $body = '{"error":"invalid stage","card_id":5,"board_id":8}';

        $this->assertSame($body, SecretScrubber::text($body));
    }

    public function test_text_redacts_even_a_short_bearer_value(): void
    {
        // Bearer/Basic take NO length floor — a short-but-real echoed token must not slip.
        $scrubbed = SecretScrubber::text('echoed header: Authorization: Bearer wb-tok8');

        $this->assertStringNotContainsString('wb-tok8', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_text_redacts_a_github_token_prefix_anywhere(): void
    {
        // Unambiguous prefixes are redacted even un-keyed / in an odd body shape.
        $scrubbed = SecretScrubber::text('{"note":"leaked ghp_abcDEF123456 in a nested field"}');

        $this->assertStringNotContainsString('ghp_abcDEF123456', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_text_preserves_prose_after_the_word_token(): void
    {
        // The `token` scheme's length floor keeps ordinary error prose readable.
        $body = '{"message":"your token expired; the token cannot write custom fields"}';

        $this->assertSame($body, SecretScrubber::text($body));
    }

    public function test_url_strips_userinfo_and_keeps_the_endpoint_readable(): void
    {
        $safe = SecretScrubber::url('https://svc:SUPERSECRETVALUE1234567890@board.example/api/v3');

        $this->assertStringNotContainsString('SUPERSECRETVALUE1234567890', $safe);
        $this->assertSame('https://***@board.example/api/v3', $safe);
    }

    public function test_url_strips_the_query_even_under_a_benign_parameter_name(): void
    {
        // ⭐ THE CASE THE SHAPE RULES CANNOT REACH. `k` matches no sensitive-key pattern,
        // so only the POSITIONAL rule removes this.
        $safe = SecretScrubber::url('https://board.example/api/v3?k=SUPERSECRETVALUE1234567890');

        $this->assertStringNotContainsString('SUPERSECRETVALUE1234567890', $safe);
        $this->assertSame('https://board.example/api/v3?[REDACTED]', $safe);
    }

    public function test_text_applies_the_positional_rule_to_an_embedded_url(): void
    {
        // What a third-party handler's own message looks like: a URL it composed, inside
        // prose we did not. Neither `k` nor the userinfo password matches a shape rule.
        $this->assertSame(
            'push to https://ops.example/hook?[REDACTED] failed',
            SecretScrubber::text('push to https://ops.example/hook?k=SUPERSECRETVALUE1234567890 failed'),
        );
        $this->assertSame(
            'GET https://***@board.example/api/v3 refused',
            SecretScrubber::text('GET https://svc:SUPERSECRETVALUE1234567890@board.example/api/v3 refused'),
        );
    }

    public function test_only_url_reaches_a_value_carrying_no_scheme(): void
    {
        // ⭐ WHY url() IS NOT A WRAPPER text() COULD ABSORB. text()'s embedded-URL pass
        // anchors on `http(s)://`; `UrlValidator` quotes a SCHEMELESS value on the very
        // branch that refuses it for having no usable scheme, and only url() covers that.
        $raw = 'svc:SUPERSECRETVALUE1234567890@board.example/api';

        $this->assertStringContainsString('SUPERSECRETVALUE1234567890', SecretScrubber::text($raw));
        $this->assertSame('***@board.example/api', SecretScrubber::url($raw));
    }

    public function test_url_strips_a_fragment(): void
    {
        $this->assertSame(
            'https://board.example/api#[REDACTED]',
            SecretScrubber::url('https://board.example/api#SUPERSECRETVALUE1234567890'),
        );
    }

    public function test_url_redacts_a_value_that_does_not_parse_as_a_url(): void
    {
        // ⭐ WHY THE RULE IS LEXICAL. `UrlValidator::httpUrl()` quotes the value on its
        // `is not a valid URL` branch — the branch reached BECAUSE parse_url() failed — so
        // a parse_url()-based redactor would give up exactly where it is needed and echo
        // the credential. A non-numeric port is one such value.
        $raw = 'https://svc:SUPERSECRETVALUE1234567890@board.example:notaport/api';
        $this->assertFalse(parse_url($raw));

        $safe = SecretScrubber::url($raw);

        $this->assertStringNotContainsString('SUPERSECRETVALUE1234567890', $safe);
        $this->assertSame('https://***@board.example:notaport/api', $safe);
    }

    public function test_url_leaves_an_ordinary_endpoint_untouched(): void
    {
        // The verdicts these messages carry are ABOUT the scheme/host/path, so redacting
        // those would make the message name a value the operator cannot find in their config.
        foreach (['https://board.example/api/v3', 'http://127.0.0.1:8000/api/v3', 'not-a-url', 'not a url'] as $value) {
            $this->assertSame($value, SecretScrubber::url($value));
        }
    }

    /**
     * ⭐ THE USERINFO IS BOUND AT THE LAST `@`, NOT AT THE FIRST `/`, `?` OR `#`.
     *
     * `/` is in the base64 alphabet and `?`/`#` are ordinary generated-password characters,
     * so all three occur in a real userinfo. A userinfo bounded by `[^/?#]*` cannot reach
     * the `@` behind them and left the credential in the message.
     *
     * ⛔ THE `?` AND `#` SHAPES ARE WHY THIS IS ASSERTED ON THE EXACT STRING rather than on
     * `[REDACTED]` being present: under the old bound the query cut still fired, so the
     * output CARRIED `[REDACTED]` while the password's head sat in front of it. A
     * presence-of-`[REDACTED]` assertion — and an operator reading the line — called that
     * safe. Absence of the canary plus the exact survivor is the only pair that catches it.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function userinfoDelimiters(): array
    {
        return [
            // [what an operator's generated password can contain, the whole scrubbed value]
            'slash in the password (base64 alphabet)' => [
                'http://svc:CANARYab/cdSYNTH@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
            'question mark in the password' => [
                'http://svc:CANARYsec?retSYNTH@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
            'hash in the password' => [
                'http://svc:CANARYsec#fragSYNTH@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
        ];
    }

    #[DataProvider('userinfoDelimiters')]
    public function test_url_strips_a_userinfo_containing_a_url_delimiter(string $raw, string $expected): void
    {
        $safe = SecretScrubber::url($raw);

        $this->assertStringNotContainsString('CANARY', $safe);
        $this->assertSame($expected, $safe);
    }

    #[DataProvider('userinfoDelimiters')]
    public function test_text_strips_a_userinfo_containing_a_url_delimiter(string $raw, string $expected): void
    {
        // The same hole was in the embedded-URL pass, so it was never confined to the
        // config-value leg: a handler's own message quoting the URL it called leaked too.
        $safe = SecretScrubber::text('GET '.$raw.' failed');

        $this->assertStringNotContainsString('CANARY', $safe);
        $this->assertSame('GET '.$expected.' failed', $safe);
    }

    public function test_the_userinfo_binding_still_stops_at_whitespace(): void
    {
        // ⭐ THE REGRESSION CASE FOR THE LAST-`@` BINDING. `UrlValidator` quotes a
        // whitespace-bearing value on the branch that exists to say "check for paste
        // errors"; a binding that ran past the space would swallow the rest of the message.
        $this->assertSame(
            'https://***@remote host:8787',
            SecretScrubber::url('https://svc:CANARYSYNTHETIC@remote host:8787'),
        );
    }

    public function test_an_at_sign_outside_the_userinfo_over_redacts_and_that_is_the_accepted_cost(): void
    {
        // ⚠ PINNING THE COST, NOT A DESIRED BEHAVIOUR. Binding at the last `@` means an `@`
        // in a PATH or QUERY is taken for a userinfo terminator and the host/path in front
        // of it goes. That is the side this class declares it errs on — the alternative
        // bound echoed real credentials — and it is stated on the class docblock. If a
        // future change narrows the binding, this case must be re-derived, not deleted.
        $this->assertSame('https://***@user/x', SecretScrubber::url('https://h/@user/x'));
        $this->assertSame(
            'GET https://***@b.example failed',
            SecretScrubber::text('GET https://board.example/api?to=a@b.example failed'),
        );
    }

    public function test_text_reaches_a_url_whose_slashes_are_json_escaped(): void
    {
        // ⭐ THE ONLY INPUT `RefusalContext::from()` EVER HAS IS A KANBAN JSON BODY, and
        // Symfony's `JsonResponse::DEFAULT_ENCODING_OPTIONS` does not set
        // `JSON_UNESCAPED_SLASHES` — so the wire form is `https:\/\/…`. A pass keyed on
        // `://` alone was inert on precisely the surface it was written for.
        //
        // ⛔ THE INPUT IS BUILT BY `json_encode`, NOT HAND-TYPED. Hand-typing the escaped
        // form is how the encoder's actual behaviour stopped being the thing under test.
        $body = json_encode(['error' => 'GET https://board.example/api/v3?k=CANARYSYNTHETIC failed']);
        $this->assertIsString($body);
        $this->assertStringContainsString('https:\/\/', $body);

        $scrubbed = SecretScrubber::text($body);

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed);
        $this->assertSame('{"error":"GET https:\\/\\/board.example\\/api\\/v3?[REDACTED] failed"}', $scrubbed);
    }

    public function test_text_reaches_a_json_escaped_urls_userinfo(): void
    {
        $body = json_encode(['error' => 'GET https://svc:CANARYSYNTHETIC@board.example/api/v3 failed']);
        $this->assertIsString($body);

        $scrubbed = SecretScrubber::text($body);

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed);
        $this->assertSame('{"error":"GET https:\\/\\/***@board.example\\/api\\/v3 failed"}', $scrubbed);
    }

    public function test_the_redaction_run_ends_at_whitespace_and_takes_what_it_covers(): void
    {
        // ⚠ A STATED BOUND UNDER TEST, NOT A PROPERTY WORTH KEEPING. Everything from the
        // `?`/`#` to the next whitespace goes: a comma-joined second URL and a closing
        // bracket included. It is not narrowed because every earlier stop (`,`, `)`,
        // trailing punctuation) also occurs INSIDE real query strings, where stopping there
        // leaves the tail of the credential in the message — a leak traded for cosmetics.
        // If the run is ever narrowed, the class docblock and DL-344 must move with it.
        $this->assertSame(
            'urls: https://a.example/?[REDACTED]',
            SecretScrubber::text('urls: https://a.example/?k=1,https://b.example/?j=2'),
        );
        $this->assertSame(
            'see (https://x/?[REDACTED] for detail',
            SecretScrubber::text('see (https://x/?k=1) for detail'),
        );
    }

    public function test_url_still_applies_the_text_rules_to_what_survives(): void
    {
        // A vendor-prefixed token sitting in the PATH — the one path-borne shape that IS
        // unambiguous — is still caught by the text pass url() composes.
        $safe = SecretScrubber::url('https://board.example/hooks/ghp_abcDEF1234567890');

        $this->assertStringNotContainsString('ghp_abcDEF1234567890', $safe);
        $this->assertStringContainsString('[REDACTED]', $safe);
    }
}
