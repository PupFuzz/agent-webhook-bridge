<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SecretScrubber;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Tests\Support\StraddlingRequestException;
use Tests\TestCase;

/**
 * ⛔ EVERY ABSENCE HERE HAS A PRESENCE BESIDE IT: a primitive that returned `''` would pass
 * every absence assertion and take the operator's whole diagnosis with it.
 */
class RedactedErrorTextTest extends TestCase
{
    private function exception(string $body, int $status = 422): RequestException
    {
        return new RequestException(new Response(new GuzzleResponse($status, [], $body)));
    }

    public function test_a_credential_straddling_laravels_message_cut_is_redacted(): void
    {
        $text = RedactedErrorText::of(StraddlingRequestException::make());

        $this->assertStringNotContainsString(StraddlingRequestException::STEM, $text);
        $this->assertStringContainsString('HTTP request returned status code 422: ', $text);
        $this->assertStringContainsString('The url field is invalid.', $text);
        $this->assertStringContainsString('https:\/\/***@bridge.example.com', $text);
    }

    public function test_a_credential_straddling_the_body_bound_is_redacted_before_the_bound(): void
    {
        // The value opens 10 characters before the bound, so a bound taken first leaves it with
        // no closing quote for the JSON rule to match. No vendor prefix: the prefix rule matches
        // a fragment too, and would hide exactly the ordering this pins.
        $body = '{"note":"'.str_repeat('a', 470).'","token":"LEAKED9486'.str_repeat('z', 200).'"}';

        $text = RedactedErrorText::of($this->exception($body));

        $this->assertStringNotContainsString('LEAKED', $text);
        $this->assertStringContainsString('"note":"aaaa', $text);
        $this->assertStringEndsWith('…(truncated)', $text);
        $this->assertSame(500, mb_strlen(RedactedErrorText::body($body)) - mb_strlen('…(truncated)'));
    }

    public function test_the_callers_redaction_runs_on_the_unbounded_body(): void
    {
        $held = 'caller-held-value-9486';
        $body = str_repeat('x', 490).$held;

        $text = RedactedErrorText::of(
            $this->exception($body),
            static fn (string $t): string => str_replace($held, '<held>', $t),
        );

        $this->assertStringNotContainsString('caller-he', $text);
        $this->assertStringContainsString('xxxx<held>', $text);
    }

    public function test_any_other_throwable_takes_the_scrubbers_text_path(): void
    {
        $e = new RuntimeException('push to https://ops.example/hook?k=CANARY9486 failed: token=CANARY9486');

        $this->assertSame(SecretScrubber::text($e->getMessage()), RedactedErrorText::of($e));
        $this->assertStringContainsString('push to https://ops.example/hook?[REDACTED]', RedactedErrorText::of($e));
    }

    public function test_a_body_guzzle_would_not_summarise_is_omitted_and_the_status_kept(): void
    {
        $this->assertSame('HTTP request returned status code 500', RedactedErrorText::of($this->exception("ok\x1B[2Kforged", 500)));
        $this->assertSame('HTTP request returned status code 502', RedactedErrorText::of($this->exception('', 502)));
        // The control: `\r`, `\n` and `\t` are inside Guzzle's rule, so such a body IS shown —
        // escaping it for a terminal is `UntrustedText::forOperator()`'s job, not this class's.
        $this->assertSame("HTTP request returned status code 500: \rline\t\n", RedactedErrorText::of($this->exception("\rline\t\n", 500)));
    }
}
