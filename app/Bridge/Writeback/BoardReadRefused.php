<?php

namespace App\Bridge\Writeback;

use RuntimeException;

/**
 * A board-scoped search walk that kanban answered with a 2xx and that still cannot be reported as
 * complete (card#10653). Which answers cause it is owned by {@see KanbanClient::pagedSearch}'s
 * docblock (THE REFUSALS) and deliberately not restated here; the message names the one that fired.
 * Never thrown for a read that stopped at the MAX_PAGES ceiling — that is {@see BoardRead::$truncated}.
 */
final class BoardReadRefused extends RuntimeException {}
