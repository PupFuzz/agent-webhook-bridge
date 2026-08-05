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
 *
 * {@see ChannelTokenFault} names WHICH rule broke, for the one consumer that reads
 * the token as a PROXY for the runtime and must not turn "I could not read it" into
 * "the push will fail" (card#5698). The runtime callers ignore it: there, the reader
 * that threw is the reader that matters.
 */
final class ChannelTokenException extends RuntimeException
{
    public function __construct(string $message, public readonly ChannelTokenFault $fault)
    {
        parent::__construct($message);
    }
}
