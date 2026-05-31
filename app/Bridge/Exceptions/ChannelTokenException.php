<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when an agent's configured channel auth token (channel.auth.token_path)
 * is missing, unreadable, group/world-readable, or empty. The message carries
 * the token PATH only — never the token value (it is persisted to the dispatch
 * note and logged). The handler wraps this as a HandlerException (fail-closed:
 * a routed push with unusable auth is recorded, not sent unauthenticated);
 * bridge:check catches it to warn at preflight.
 */
final class ChannelTokenException extends RuntimeException {}
