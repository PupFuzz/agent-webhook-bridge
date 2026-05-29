<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised by a handler on a malformed ReactionTarget payload (e.g. a
 * channel_push with neither socket nor url, a spawn_detached with a non-list
 * cmd). Best-effort: the dispatcher records it and acks 200 — the durable
 * inbox backstop already holds the intent.
 */
final class HandlerException extends RuntimeException {}
