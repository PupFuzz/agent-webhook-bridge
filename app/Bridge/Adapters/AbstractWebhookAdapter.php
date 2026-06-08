<?php

namespace App\Bridge\Adapters;

use App\Bridge\Contracts\WebhookAdapter;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use Illuminate\Http\Request;

/**
 * Shared behaviour for the sha256=<hex> HMAC providers (kanban + GitHub use
 * an identical signing convention; only the header name differs). Keeping the
 * single hash_equals path here means there is exactly one constant-time
 * compare to audit. Envelope field lengths are asserted here too
 * (assertFieldLengths): every value written to webhook_events must fit its
 * column, so an over-length field is a deterministic 400 rather than a DB-side
 * "data too long" 5xx the upstream would redeliver forever.
 */
abstract class AbstractWebhookAdapter implements WebhookAdapter
{
    /**
     * The request header carrying `sha256=<hex>` for this provider.
     */
    abstract protected function signatureHeader(): string;

    public function verifySignature(Request $request, string $body, string $secret): bool
    {
        $header = $request->header($this->signatureHeader());
        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $provided = substr($header, 7);
        $expected = hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Assert every envelope field fits its webhook_events column, so an
     * over-length value is a deterministic 400 (InvalidEnvelope) rather than a
     * DB-side "data too long" 5xx the upstream would redeliver forever (the same
     * rationale as delivery_id, applied to the siblings that flow through the
     * same INSERT). Widths mirror 2026_05_29_000001_create_webhook_events_table.
     */
    protected function assertFieldLengths(EventDto $event): void
    {
        $limits = [
            'delivery_id' => [$event->deliveryId, 64],
            'scope_id' => [$event->scopeId, 128],
            'event_type' => [$event->eventType, 64],
            'actor_id' => [$event->actorId, 64],
        ];
        foreach ($limits as $field => [$value, $max]) {
            if ($value !== null && strlen($value) > $max) {
                throw new InvalidEnvelopeException("{$field}_too_long");
            }
        }
    }

    /**
     * @return array<mixed>
     */
    protected function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new InvalidEnvelopeException('json_decode_failed');
        }

        return $decoded;
    }

    /**
     * Require a scalar field and return it as a string. A non-scalar (array/
     * object) value is a malformed envelope, not a value to stringify.
     *
     * @param  array<mixed>  $decoded
     */
    protected function requireScalar(array $decoded, string $key): string
    {
        if (! array_key_exists($key, $decoded)) {
            throw new InvalidEnvelopeException("missing_field:{$key}");
        }

        return $this->scalarToString($decoded[$key], $key);
    }

    /**
     * Return a scalar field as a string, or null when absent/null.
     *
     * @param  array<mixed>  $decoded
     */
    protected function optionalScalar(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return $this->scalarToString($value, $key);
    }

    private function scalarToString(mixed $value, string $key): string
    {
        if (! is_scalar($value)) {
            throw new InvalidEnvelopeException("invalid_field:{$key}");
        }

        return (string) $value;
    }
}
