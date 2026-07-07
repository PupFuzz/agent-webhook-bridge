<?php

namespace App\Bridge\Validation;

use RuntimeException;

/**
 * Raised by the shared loopback-endpoint validators ({@see SocketEndpoint},
 * {@see LocalhostUrl}) when a socket path or localhost URL fails validation. It
 * extends RuntimeException so a caller that wants a domain-specific type (e.g.
 * ChannelPushHandler's HandlerException) can catch and re-wrap it, while a
 * caller that already runs inside a `catch (\Throwable)` best-effort guard (e.g.
 * WritebackAlertNotifier) can let it propagate unchanged. The message carries
 * the caller-supplied subject, so the surfaced text stays surface-appropriate.
 */
final class EndpointValidationException extends RuntimeException {}
