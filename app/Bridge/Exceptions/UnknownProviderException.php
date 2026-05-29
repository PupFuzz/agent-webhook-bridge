<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Thrown by WebhookAdapterFactory::for() for a provider with no registered
 * adapter. The middleware pre-checks supports() and maps this to a 400
 * (unknown_provider) before it can surface as a 500.
 */
final class UnknownProviderException extends RuntimeException {}
