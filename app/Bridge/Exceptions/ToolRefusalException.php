<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * A board tool (DL-217) refusing a caller's request for a reason that is the
 * CALLER's to fix — a reserved/forbidden tag, an out-of-charset idempotency key,
 * a missing required arg. 422-class: a deterministic client error, never a 5xx
 * (retrying an identical bad request never succeeds). The controller renders it
 * as a structured refusal (HTTP 422) naming the offending input; distinct from a
 * ConfigException (an install/provisioning fault → the tool is unavailable) and
 * from a transient kanban 5xx (which the caller may retry).
 */
final class ToolRefusalException extends RuntimeException {}
