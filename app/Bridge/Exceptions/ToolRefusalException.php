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
 * a fault that is the board's or the install's.
 *
 * ⚠ THE DEFAULT, `false`, IS NOT A CLAIM THAT EVERY OTHER REFUSAL IS CALLER-FIXABLE (r4-m2). Only
 * `readRefusal()` sets this marker. Every OTHER install-fault refusal in this door — the four
 * `SeatKanbanUser` config-fault throws, `BoardTakeCardTool`/`BoardCorrectCardTool`'s unreadable-row
 * refusals, `BoardCreateCardTool`'s agent-name-too-long refusal, and each write-refusal builder's
 * 401 arm — is left at the default and gets the clause if it ever backticks an argument newer than
 * its own tool. Today none does: `resources/client-capabilities.json` dates every argument of
 * every OTHER tool to that tool's own `since`, so the clause can never fire for one (unmeasured by
 * a test; true by reading the table). The FIRST tool that gains an argument newer than itself
 * must mark that tool's install-fault refusals before it ships, or an old client gets told to
 * update over a fault its own arguments never caused.
 */
final class ToolRefusalException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $installFault = false)
    {
        parent::__construct($message);
    }
}
