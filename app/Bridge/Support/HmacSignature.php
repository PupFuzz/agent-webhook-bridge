<?php

namespace App\Bridge\Support;

use App\Bridge\Adapters\AbstractWebhookAdapter;

/**
 * The `sha256=<hex>` signature over a raw body under an HMAC secret — the ONE
 * place that convention is expressed, so a PRODUCER of a signature and its
 * VERIFIER cannot drift apart on the algorithm or on the scheme prefix.
 *
 * The verifier is {@see AbstractWebhookAdapter::verifySignature}
 * (both shipped adapters share it — only the header NAME differs). The producer is
 * `bridge:sign`, which exists so the deployment runbook's smoke test can sign a body
 * without resolving the secret into a command line (DL-322). Two hand-written copies
 * of `hash_hmac('sha256', …)` plus a hand-written `sha256=` prefix is exactly how a
 * smoke test comes to produce something the receiver rejects — the wrong end to debug.
 */
final class HmacSignature
{
    /**
     * The scheme prefix on the header value. Not "an implementation detail of the
     * adapter": GitHub and kanban-board both put it on the wire, so a producer that
     * omits it is producing a value the receiver will not accept.
     */
    public const PREFIX = 'sha256=';

    /**
     * The bare hex digest — what the verifier compares against the header's remainder.
     */
    public static function hex(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * The complete header VALUE (`sha256=<hex>`) — what a client puts in
     * `X-Hub-Signature-256` / `X-Kanban-Signature`.
     */
    public static function headerValue(string $body, string $secret): string
    {
        return self::PREFIX.self::hex($body, $secret);
    }
}
