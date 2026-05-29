<?php

namespace App\Bridge\Contracts;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use Illuminate\Http\Request;

/**
 * Per-provider webhook contract: HMAC verification convention + envelope
 * shape. HMAC verify and envelope parse are split (not a combined
 * verifyAndParse) because verification runs in middleware before the
 * controller, while parsing runs in the controller once the request is
 * trusted — mirroring the receiver's verify→parse ordering.
 */
interface WebhookAdapter
{
    /**
     * Constant-time verification of the provider's signature header against
     * the raw body bytes (HMAC-SHA256). Returns false on any mismatch or a
     * malformed/absent signature header.
     */
    public function verifySignature(Request $request, string $body, string $secret): bool;

    /**
     * Extract the bridge envelope from the (already verified) request + body.
     *
     * @throws InvalidEnvelopeException on undecodable JSON, a missing
     *                                  required field/header, a non-scalar
     *                                  field, or an over-length delivery_id.
     */
    public function parse(Request $request, string $body): EventDto;

    /**
     * Whether this is a provider connectivity-test ("ping") event, which the
     * receiver accepts and no-ops (no scope check, no persistence).
     */
    public function isPing(EventDto $event): bool;
}
