<?php

namespace App\Bridge\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The receiver's single plain-text response shape. Every terminal ack/reject in
 * the webhook path — the controller's ok/pong/scope_mismatch/invalid_envelope,
 * the HMAC gate's failures, the size gate's body_too_large — returns a bare
 * `text/plain; charset=utf-8` body so kanban-board's retry logic keys purely off
 * the status code. Constructing it in one place keeps the content-type byte-exact
 * across all call sites.
 */
final class PlainTextResponse
{
    public static function make(string $body, int $code): Response
    {
        return response($body, $code, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
