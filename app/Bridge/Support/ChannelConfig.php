<?php

namespace App\Bridge\Support;

/**
 * The resolved `channel` section of a per-agent config: where the dispatcher
 * live-pushes staged intents and how it authenticates.
 *
 *  - socket / url    mutually-exclusive transport endpoint (at most one set)
 *  - routeIntents    auto-push every staged intent to that endpoint (DL-006)
 *  - tokenPath       absolute path to the Bearer token file for the HTTP
 *                    transport (DL-008); read fail-closed at push time by
 *                    ChannelToken. Null = no auth header (UDS / no-token HTTP).
 *
 * A value object (not a positional tuple) so adding the next channel field
 * doesn't silently mis-assign at the destructuring site — matches the
 * EchoSuppressionConfig idiom.
 */
final class ChannelConfig
{
    public function __construct(
        public readonly ?string $socket = null,
        public readonly ?string $url = null,
        public readonly bool $routeIntents = false,
        public readonly ?string $tokenPath = null,
    ) {}
}
