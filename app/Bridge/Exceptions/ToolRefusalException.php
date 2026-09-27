<?php

namespace App\Bridge\Exceptions;

use App\Bridge\Tools\BoardCallRefusal;
use App\Bridge\Tools\RemedyText;
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
 * `$installFault` marks a refusal built by {@see BoardCallRefusal::readRefusal()}
 * — the board or the install refused a READ, never something the caller's arguments could have
 * caused (card#10566 / DL-426 r3-m3). {@see RemedyText::advise()} skips this
 * marker rather than the message text, because "update your channel client" is the wrong fix for
 * a fault that is the board's or the install's; a refusal the caller's own arguments caused keeps
 * the clause. The default is `false` — every other refusal in this door is caller-fixable.
 */
final class ToolRefusalException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $installFault = false)
    {
        parent::__construct($message);
    }
}
