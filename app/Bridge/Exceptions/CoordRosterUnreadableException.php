<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when a kanban event needs an agent's kanban user id and the coord roster — the one place
 * the bridge reads it from (DL-450) — cannot be read. It propagates out of dispatch, so the
 * delivery answers 5xx and kanban redelivers it once the install is fixed: attributing the event
 * to nobody would let an agent's own write wake it as somebody else's, which is how a loop starts.
 * The message names the setting or the path, and which fault it is.
 */
final class CoordRosterUnreadableException extends RuntimeException {}
