<?php

namespace Tests\Unit\Http;

use App\Bridge\Http\PlainTextResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the receiver's single plain-text response shape. Every webhook-path
 * ack/reject flows through make(), so the status + body + the exact
 * `text/plain; charset=utf-8` content-type are asserted here once for the three
 * live call sites (controller ok/pong/scope_mismatch/invalid_envelope, HMAC-gate
 * failures, size-gate body_too_large). kanban-board's retry keys off the status,
 * so the content-type must stay byte-exact.
 */
class PlainTextResponseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function cases(): iterable
    {
        yield 'ok 200' => ['ok', 200];
        yield 'pong 200' => ['pong', 200];
        yield 'invalid_envelope 400' => ['invalid_envelope', 400];
        yield 'scope_mismatch 401' => ['scope_mismatch', 401];
        yield 'sig_mismatch 401' => ['sig_mismatch', 401];
        yield 'body_too_large 413' => ['body_too_large', 413];
        yield 'config_secret_dir_missing 500' => ['config_secret_dir_missing', 500];
    }

    #[DataProvider('cases')]
    public function test_make_builds_a_byte_identical_plain_text_response(string $body, int $code): void
    {
        $response = PlainTextResponse::make($body, $code);

        $this->assertSame($code, $response->getStatusCode());
        $this->assertSame($body, $response->getContent());
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
    }
}
