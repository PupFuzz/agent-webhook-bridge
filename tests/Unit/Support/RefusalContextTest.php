<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\RefusalContext;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RefusalContextTest extends TestCase
{
    private function exception(string $body, int $status = 422): RequestException
    {
        return new RequestException(new Response(new GuzzleResponse($status, [], $body)));
    }

    public function test_from_carries_status_and_verbatim_body(): void
    {
        $ctx = RefusalContext::from($this->exception('{"error":"stage 52 is not on board 8"}', 403));

        $this->assertSame(403, $ctx['status']);
        $this->assertStringContainsString('stage 52 is not on board 8', $ctx['body']);
    }

    public function test_from_truncates_a_long_body(): void
    {
        $ctx = RefusalContext::from($this->exception('{"msg":"'.str_repeat('x', 2000).'"}'));

        $this->assertLessThanOrEqual(520, mb_strlen($ctx['body']));   // 500 + the marker
        $this->assertStringContainsString('(truncated)', $ctx['body']);
    }

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
    public function test_scrub_redacts_credential_adjacent_values(string $body): void
    {
        $scrubbed = RefusalContext::scrub($body);

        $this->assertStringNotContainsString('SUPERSECRETVALUE1234567890', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_scrub_leaves_a_benign_body_intact(): void
    {
        $body = '{"error":"invalid stage","card_id":5,"board_id":8}';

        $this->assertSame($body, RefusalContext::scrub($body));
    }

    public function test_scrub_redacts_even_a_short_bearer_value(): void
    {
        // Bearer/Basic take NO length floor — a short-but-real echoed token must not slip.
        $scrubbed = RefusalContext::scrub('echoed header: Authorization: Bearer wb-tok8');

        $this->assertStringNotContainsString('wb-tok8', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_scrub_redacts_a_github_token_prefix_anywhere(): void
    {
        // Unambiguous prefixes are redacted even un-keyed / in an odd body shape.
        $scrubbed = RefusalContext::scrub('{"note":"leaked ghp_abcDEF123456 in a nested field"}');

        $this->assertStringNotContainsString('ghp_abcDEF123456', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_scrub_preserves_prose_after_the_word_token(): void
    {
        // The `token` scheme's length floor keeps ordinary error prose readable.
        $body = '{"message":"your token expired; the token cannot write custom fields"}';

        $this->assertSame($body, RefusalContext::scrub($body));
    }

    public function test_credential_split_across_the_truncation_boundary_is_still_redacted(): void
    {
        // The token's closing quote sits PAST the 500-char cutoff. Scrubbing must run
        // on the full body BEFORE truncation, or the token's head leaks through.
        $filler = str_repeat('a', 490);
        $body = '{"note":"'.$filler.'","token":"ghp_LEAKEDTOKEN'.str_repeat('z', 200).'"}';

        $ctx = RefusalContext::from($this->exception($body));

        $this->assertStringNotContainsString('ghp_LEAKEDTOKEN', $ctx['body']);
    }

    /**
     * @return list<array{0: int, 1: bool}>
     */
    public static function statuses(): array
    {
        return [
            'lower 4xx boundary' => [400, true],
            'not-found' => [404, true],
            'unprocessable' => [422, true],
            'upper 4xx boundary' => [499, true],
            'lower 5xx boundary — retryable' => [500, false],
            'bad gateway — retryable' => [502, false],
            'service unavailable — retryable' => [503, false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_is_permanent_classifies_4xx_as_permanent_and_5xx_as_retryable(int $status, bool $expected): void
    {
        $this->assertSame($expected, RefusalContext::isPermanent($this->exception('{}', $status)));
    }
}
