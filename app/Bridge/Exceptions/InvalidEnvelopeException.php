<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Thrown by a WebhookAdapter::parse() when the envelope is malformed —
 * undecodable JSON, a missing required field/header, a non-scalar field, or
 * an over-length delivery_id. Maps to a deterministic 400 (NOT a 5xx):
 * kanban-board does not retry a 4xx, which is correct for a bad request.
 */
final class InvalidEnvelopeException extends RuntimeException {}
