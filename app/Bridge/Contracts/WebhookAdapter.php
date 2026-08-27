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
     * ⛔ AN IMPLEMENTATION MUST REFUSE A BODY THAT DOES NOT DECODE TO AN ARRAY, not
     * merely one that fails to decode at all — `5` and `"x"` are valid JSON. The
     * receiver stores `json_decode($body, true)` as the event payload with no
     * fallback of its own (DL-315), so this method's refusal is the ONLY thing
     * standing between a scalar body and a non-array in `webhook_events.payload`,
     * which `bridge:replay` and `bridge:stats` both assume cannot happen.
     * `AbstractWebhookAdapter::decodeJson()` discharges this for every adapter that
     * extends it; an adapter that does not must refuse the body itself.
     *
     * @throws InvalidEnvelopeException on JSON that does not decode to an array, a
     *                                  missing required field/header, a non-scalar
     *                                  field, or an over-length delivery_id.
     */
    public function parse(Request $request, string $body): EventDto;

    /**
     * Whether this is a provider connectivity-test ("ping") event, which the
     * receiver accepts and no-ops (no scope check, no persistence).
     */
    public function isPing(EventDto $event): bool;
}
