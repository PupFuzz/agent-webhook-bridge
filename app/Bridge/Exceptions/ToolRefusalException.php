<?php

namespace App\Bridge\Exceptions;

use App\Bridge\Tools\BoardCallRefusal;
use App\Bridge\Tools\BoardToolDispatcher;
use RuntimeException;

/**
 * A board tool (DL-217) refusing a caller's request for a reason that is the
 * CALLER's to fix — a reserved/forbidden tag, an out-of-charset idempotency key,
 * a missing required arg. 422-class: a deterministic client error, never a 5xx
 * (retrying an identical bad request never succeeds). The controller renders it
 * as a structured refusal (HTTP 422) naming the offending input; distinct from a
 * ConfigException (an install/provisioning fault → the tool is unavailable) and
 * from a transient kanban 5xx (which the caller may retry).
 *
 * `$installFault` marks a refusal built by {@see BoardCallRefusal::readRefusal()}: the board or
 * the install refused a READ, which the caller's arguments cannot fix. {@see BoardToolDispatcher}
 * adds no "update your channel client" sentence to one (card#10566 / DL-426), deciding by this
 * marker rather than by the message text. A WRITE the board refuses for a reason no argument can
 * fix (its 401 and 403) carries the marker too, for the same reason.
 *
 * `$reason` is an optional machine-readable code for the refusal, relayed beside the message as
 * the response body's `reason` (card#11150 / DL-449): a caller that BRANCHES on a refusal reads
 * the code, never the wording. It is absent on every refusal that does not set one; which
 * refusals set one, and the codes, are `docs/board-tools.md`'s to list.
 */
final class ToolRefusalException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $installFault = false, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
