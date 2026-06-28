<?php

namespace App\Bridge\Writeback;

/**
 * The optional `alert_channel` of `writeback.json` (FR-4): where the writeback
 * emits a LOUD per-event signal on a permanent move-failure, in ADDITION to the
 * durable log line. Opt-in — absent ⇒ null ⇒ log-only (today's behavior).
 *
 * Mirrors the agent `channel` config's mutually-exclusive transport shape:
 *  - socket    absolute path to a Unix domain socket
 *  - url       a localhost HTTP endpoint (loopback only)
 *  - tokenPath optional Bearer token file for the HTTP transport
 *
 * A malformed alert_channel (both socket+url, or neither) is surfaced by
 * `bridge:check` as a warning and makes the push fail at runtime (caught) — it
 * does NOT fail the writeback config closed, so a bad alert channel can never
 * break a card move (see WritebackAlertNotifier + WritebackConfig::load).
 */
final class AlertChannel
{
    public function __construct(
        public readonly ?string $socket = null,
        public readonly ?string $url = null,
        public readonly ?string $tokenPath = null,
    ) {}
}
