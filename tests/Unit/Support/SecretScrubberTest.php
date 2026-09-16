<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\SecretScrubber;
use App\Bridge\Support\UntrustedText;
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

    public function test_text_redacts_a_mezzanine_token_whatever_its_characters(): void
    {
        // The alphabet is not pinned, so the canary carries characters a [A-Za-z0-9] class
        // would stop at — a partial redaction would leave the tail readable.
        $scrubbed = SecretScrubber::text('refused: mzr_a.b~c/d-e_9 was revoked');   // gitleaks:allow — test fixture

        $this->assertStringNotContainsString('a.b~c', $scrubbed);
        $this->assertStringNotContainsString('d-e_9', $scrubbed);
        $this->assertSame('refused: [REDACTED] was revoked', $scrubbed);
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

    public function test_the_userinfo_binding_keeps_everything_after_the_last_at_sign(): void
    {
        // ⭐ THE REGRESSION CASE FOR THE LAST-`@` BINDING. `UrlValidator` quotes a
        // whitespace-bearing value on the branch that exists to say "check for paste
        // errors"; a binding that ran past the last `@` would swallow the rest of the message.
        //
        // ⛔ THIS USED TO BE NAMED `…still_stops_at_whitespace`, AND THAT RULE WAS THE LEAK
        // card#9528 (a) RECORDS — re-derived rather than deleted. Stopping at whitespace
        // cannot reach an `@` BEHIND a space, and a space is precisely what a paste error puts
        // in a userinfo, so the whole password came back on that same branch. What survives of
        // the old case is this: the text after the binding must still be there.
        $this->assertSame(
            'https://***@remote host:8787',
            SecretScrubber::url('https://svc:CANARYSYNTHETIC@remote host:8787'),
        );
    }

    /**
     * ⭐ SHAPE (a) OF card#9528 — A USERINFO CONTAINING WHITESPACE.
     *
     * The old binding stopped at the first whitespace, so it could not reach the `@` behind a
     * space and `UrlValidator::httpUrl()`'s *contains whitespace; check for paste errors*
     * branch — the one branch a pasted-with-a-space credential always lands on — quoted the
     * password in full to `bridge:check`, `bridge:provision` and every log they reach.
     */
    public function test_url_strips_a_userinfo_containing_whitespace(): void
    {
        $safe = SecretScrubber::url('https://svc:CANARYSYNTHETIC TAIL@bridge.example.com/webhooks');

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $safe);
        $this->assertSame('https://***@bridge.example.com/webhooks', $safe);
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
        // The cost card#9528 (a) ADDED to this one: with the binding no longer stopping at
        // whitespace, an `@` sitting in the prose AFTER the value takes the whole value with
        // it. Same side of the trade — an unredacted password was what the old bound bought.
        $this->assertSame('https://***@x:8787', SecretScrubber::url('https://svc:CANARYSYNTHETIC@remote host@x:8787'));
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

    /**
     * ⭐ SHAPE (b) OF card#9528 AT THE `text()` LEG, IN THE FORM IT ARRIVES IN.
     *
     * A raw `"` is illegal unencoded in a userinfo, and `json_encode` writes it as `\"`. The
     * embedded-URL run admitted `\/` and nothing else, so it STOPPED AT THAT BACKSLASH — before
     * the `@` — and the positional rule was handed a run with no userinfo in it at all. In a
     * JSON string EVERY backslash begins an escape; ending the run at one is what ended it early.
     *
     * ⛔ THE INPUT IS BUILT BY `json_encode`, NOT HAND-TYPED, for the reason
     * `test_text_reaches_a_url_whose_slashes_are_json_escaped` states.
     */
    public function test_text_reaches_a_json_escaped_urls_userinfo_through_an_escaped_quote(): void
    {
        $body = json_encode(['error' => 'GET https://svc:CANARYSYNTHETIC"tail@board.example/api/v3 failed']);
        $this->assertIsString($body);
        $this->assertStringContainsString('\\"', $body, 'the encoder did not escape the quote, so this input is not the wire form');

        $scrubbed = SecretScrubber::text($body);

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed);
        $this->assertSame('{"error":"GET https:\\/\\/***@board.example\\/api\\/v3 failed"}', $scrubbed);
    }

    /**
     * The same early terminator on a character RFC 3986 ALLOWS UNENCODED in a userinfo, so
     * `UrlValidator::httpUrl()` accepts such a base and a generated password can legitimately
     * carry one: `'` is a sub-delim. The run excluded it, so it ended before the `@`.
     *
     * ⚠ On `bridge:provision` this one was masked by a caller-side substitution
     * (`ProvisionCommand::apiErrorText()` replaces the receiver URL it holds), never closed —
     * the bare scrub leaked it, which is what this pins.
     */
    public function test_text_reaches_a_userinfo_containing_an_apostrophe(): void
    {
        $safe = SecretScrubber::text("GET https://svc:CANARYSYNTHETIC'tail@board.example/api/v3 refused");

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $safe);
        $this->assertSame('GET https://***@board.example/api/v3 refused', $safe);
    }

    /**
     * ⭐ THE CLASS card#9528 comment 5425 NAMES: A VALUE RUN THAT ENDS BEFORE THE VALUE DOES.
     * The auth-scheme and `key=value` rules each bound their value with a character set, and a
     * character outside it ended the run with the rest of the secret written out verbatim —
     * `[REDACTED]` present in the output, so an operator, a reviewer and any
     * presence-of-`[REDACTED]` assertion all read the leak as a redaction.
     *
     * Each case is asserted on the WHOLE result for that reason, never on absence alone.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function valueRunsThatEndedEarly(): array
    {
        return [
            // [the text, the whole scrubbed result]
            // `id|secret` is Sanctum's format — the shape of kanban's own API tokens.
            'bearer carrying a sanctum id|secret' => ['Bearer 12|CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            // The form a kanban 4xx body arrives in: JSON escapes every forward slash.
            'bearer carrying a JSON-escaped slash' => ['Bearer abc\\/CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            'basic carrying a pipe' => ['Basic dXNlcjpwYXNz|CANARYSYNTHETIC', 'Basic [REDACTED]'],
            'github token scheme carrying a pipe' => ['token 0123456789abcdef|CANARYSYNTHETIC', 'token [REDACTED]'],
            'github token scheme carrying a JSON-escaped slash' => ['token 0123456789abcdef\\/CANARYSYNTHETIC', 'token [REDACTED]'],
            // The `key=value` rule needed a word boundary before `token`, which `_` blocks, and
            // `api_token` is not one of the spellings SENSITIVE enumerates.
            'key joining the sensitive word with an underscore' => ['api_token=CANARYSYNTHETIC', 'api_token=[REDACTED]'],
            'key joining the sensitive word with a dot' => ['auth.token=CANARYSYNTHETIC', 'auth.token=[REDACTED]'],
            'key prefixed before an enumerated spelling' => ['x_api_key=CANARYSYNTHETIC', 'x_api_key=[REDACTED]'],
            // ⭐ R1 REVIEW OF THIS CARD: the first cut replaced the value CHARACTER LIST with a
            // WIDER character list, so the run still ended at the first character the list
            // happened to forget — `:` `%` `!` `'` `,` `;` `(` were all outside it. Each row
            // below came back as `[REDACTED]` followed by the rest of the secret. The run is
            // now bound by EXCLUSION, to the next character that cannot be inside an HTTP
            // header value at all, so no row here is a spelling this list has to enumerate.
            'basic carrying a colon — the form Basic credentials are written in' => ['Basic user:CANARYSYNTHETIC', 'Basic [REDACTED]'],
            'bearer carrying a percent-escape' => ['Bearer C%2FtailCANARYSYNTHETIC', 'Bearer [REDACTED]'],
            'bearer carrying an apostrophe — a sub-delim legal unencoded in a userinfo' => ["Bearer C'CANARYSYNTHETIC", 'Bearer [REDACTED]'],
            'bearer carrying an exclamation mark' => ['Bearer C!CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            'bearer carrying a comma' => ['Bearer a,CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            'bearer carrying a semicolon' => ['Bearer a;CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            'bearer carrying a parenthesis' => ['Bearer a(CANARYSYNTHETIC', 'Bearer [REDACTED]'],
            // The KEY was an enumeration too, and the bracketed nested form is the ordinary
            // PHP/Rails/Laravel query spelling — raw, and percent-encoded as a browser sends it.
            'key in the bracketed nested form' => ['params[api_token]=CANARYSYNTHETIC', 'params[api_token]=[REDACTED]'],
            'key in the percent-encoded bracketed form' => ['user%5Btoken%5D=CANARYSYNTHETIC', 'user%5Btoken%5D=[REDACTED]'],
        ];
    }

    #[DataProvider('valueRunsThatEndedEarly')]
    public function test_text_redacts_a_whole_value_whose_run_used_to_end_early(string $text, string $expected): void
    {
        $scrubbed = SecretScrubber::text($text);

        $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed);
        $this->assertSame($expected, $scrubbed);
    }

    /**
     * ⭐ THE POPULATION IS THE DEFECT CLASS, DERIVED FROM THE OPERATOR SURFACE ITSELF — not
     * from a Unicode table (card#9528 comment 5426, and its R1 review).
     *
     * The reported defect is *a separator the operator's terminal renders as an ordinary
     * space*: the token then prints with a space in front of it, which reads as though the
     * redactor had looked at it and passed it.
     *
     * ⛔ THE PREVIOUS CUT OF THIS TEST DERIVED ITS DENOMINATOR FROM `\p{Zs}` — the same table
     * the constant under test encoded — so it could only ever prove that two copies of one
     * list agreed, and U+2028, U+2029 and U+0085 leaked while sitting OUTSIDE ITS OWN
     * DENOMINATOR. A drift guard between two copies of a list is not a test of the defect.
     *
     * So membership is decided by `UntrustedText::forOperator()`'s OWN rendering: a codepoint
     * is in the class when the surface an operator reads turns it into a plain space. Nothing
     * here knows or cares which Unicode category that is, which is why a table that moves
     * cannot move this denominator out from under the assertion.
     *
     * ⛔ THREE GUARDS, AND THE THIRD IS A CONTROL RATHER THAN A COVERAGE CLAIM. The population
     * must be non-empty; it must contain BOTH the originally reported instance (U+00A0) and
     * the one the replaced denominator could not see (U+2028); and it must EXCLUDE an ordinary
     * letter — without that last one a classifier answering *yes* to everything would satisfy
     * the whole loop.
     */
    public function test_text_redacts_an_auth_scheme_separated_by_anything_the_operator_reads_as_a_space(): void
    {
        $separators = [];
        for ($codepoint = 0; $codepoint <= 0xFFFF; $codepoint++) {
            $char = mb_chr($codepoint, 'UTF-8');
            if (is_string($char) && UntrustedText::forOperator('A'.$char.'B') === 'A B') {
                $separators[$codepoint] = $char;
            }
        }
        $this->assertNotSame([], $separators, 'the derived separator population is empty, so the loop below asserts nothing');
        $this->assertArrayHasKey(0xA0, $separators, 'the reported instance is outside the derived population, so this test is not about it');
        $this->assertArrayHasKey(0x2028, $separators, 'U+2028 renders as a space and leaked the whole token past the \p{Zs} denominator this replaced');
        $this->assertArrayNotHasKey(0x78, $separators, 'the letter x classifies as a separator, so the classifier says yes to everything and the loop proves nothing');

        foreach ($separators as $codepoint => $separator) {
            foreach (['Bearer', 'Basic', 'token'] as $scheme) {
                $where = sprintf('%s separated by U+%04X', $scheme, $codepoint);
                $scrubbed = SecretScrubber::text($scheme.$separator.'CANARYSYNTHETICVALUE');

                $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed, $where);
                $this->assertSame($scheme.' [REDACTED]', $scrubbed, $where);
            }
        }
    }

    /**
     * The other half of the same class, and the reason the rule is not written in terms of
     * *whitespace* at all: a separator the operator surface ESCAPES rather than collapses
     * (`\x{200B}`) still sits between a scheme and its value, and the token behind it is no
     * less a credential. The separator is bound by EXCLUSION — anything that is not a visible
     * ASCII character — so these need no enumerating either, and the derived loop above is a
     * SUBSET of what the rule reaches rather than its edge.
     */
    public function test_text_redacts_an_auth_scheme_separated_by_a_character_the_operator_surface_shows(): void
    {
        foreach (["\u{200B}" => 'U+200B ZERO WIDTH SPACE', "\u{180E}" => 'U+180E MONGOLIAN VOWEL SEPARATOR', "\u{FEFF}" => 'U+FEFF BOM'] as $separator => $where) {
            $scrubbed = SecretScrubber::text('Bearer'.$separator.'CANARYSYNTHETICVALUE');

            $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed, $where);
            $this->assertSame('Bearer [REDACTED]', $scrubbed, $where);
        }
    }

    /**
     * ⭐ THE HEADER FORM, WHICH NO RULE MATCHED AT ALL (card#9528 R1 review).
     *
     * `authorization` and `x-api-key` are both in this class's OWN sensitive list, and an
     * echoed `Name: value` header still carried its value out verbatim: the `key=value` rule
     * needs an `=`, the JSON rule needs quotes, and an opaque `Authorization:` value is not a
     * `Bearer` scheme. An echoed request header is ordinary content of the upstream error
     * bodies this class exists to read.
     */
    public function test_text_redacts_a_credential_echoed_in_the_header_form(): void
    {
        foreach ([
            'authorization' => ['upstream echoed authorization: CANARYSYNTHETICVALUE', 'upstream echoed authorization: [REDACTED]'],
            'x-api-key' => ['X-Api-Key: CANARYSYNTHETICVALUE', 'X-Api-Key: [REDACTED]'],
            'authorization carrying a scheme' => ['Authorization: Bearer CANARYSYNTHETICVALUE', 'Authorization: [REDACTED]'],
            'api_token, the underscore-joined spelling' => ['api_token: CANARYSYNTHETICVALUE', 'api_token: [REDACTED]'],
            'client_secret' => ['client_secret: CANARYSYNTHETICVALUE', 'client_secret: [REDACTED]'],
        ] as $where => [$text, $expected]) {
            $scrubbed = SecretScrubber::text($text);

            $this->assertStringNotContainsString('CANARYSYNTHETIC', $scrubbed, $where);
            $this->assertSame($expected, $scrubbed, $where);
        }
    }

    /**
     * ⛔ THE COST CONTROL FOR THE RULE ABOVE, AND IT IS WHAT FIXES THAT RULE'S BOUND. These
     * lines carry a PATH, not a credential, and `bridge:check` prints exactly this shape — so
     * a colon rule keyed on the sensitive word appearing ANYWHERE in the key (which is the
     * right rule for `key=value`, where the far end names the field) would redact an
     * operator's own configuration out of the diagnostic that exists to show it to them.
     *
     * The discriminator is that a credential header ENDS with the sensitive word
     * (`api_token`, `x-api-key`, `client_secret`) while these compounds use it as a MODIFIER.
     * Without this test the rule above is satisfied by one that takes every line.
     */
    public function test_a_path_whose_key_merely_mentions_a_sensitive_word_is_left_readable(): void
    {
        $this->assertSame('secret_dir: /srv/bridge/.config', SecretScrubber::text('secret_dir: /srv/bridge/.config'));
        $this->assertSame('token_path: /srv/bridge/.config/kanban/token', SecretScrubber::text('token_path: /srv/bridge/.config/kanban/token'));
    }

    /**
     * ⛔ THE COST CONTROL FOR THE WIDER BINDING, and what says the run was BOUND rather than
     * merely widened again. The separator is now *anything that is not a visible ASCII
     * character*, so a rule reading one class too far would start eating ordinary prose — and
     * every line here is text an upstream error body genuinely carries. GREEN before this
     * change and after it.
     */
    public function test_the_scheme_binding_does_not_eat_prose(): void
    {
        $this->assertSame('Basically everything failed', SecretScrubber::text('Basically everything failed'));
        $this->assertSame('token expired', SecretScrubber::text('token expired'));
        $this->assertSame('the bearer. next', SecretScrubber::text('the bearer. next'));
        // ⚠ A STATED COST, NOT A PROPERTY: `Bearer` FOLLOWED BY A WORD IS REDACTED, exactly as
        // it was before this change. These keywords are not followed by prose in the bodies
        // this class reads, and a short-but-real token must not slip through on a length floor.
        $this->assertSame('Bearer [REDACTED] required', SecretScrubber::text('Bearer authentication required'));
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
