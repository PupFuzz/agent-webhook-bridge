<?php

namespace App\Bridge\Writeback;

use RuntimeException;

/**
 * A board-scoped search walk that kanban answered with a 2xx and that still cannot be reported as
 * complete (card#10653): a page it would continue from was not in descending id order, a keyed
 * page carried a row outside the id window it asked for, or two walks in a row delivered fewer
 * cards than their first page declared. {@see KanbanClient::pagedSearch} owns the three causes;
 * the message names which one fired. Never thrown for a read that stopped at the MAX_PAGES
 * ceiling — that is {@see BoardRead::$truncated}.
 */
final class BoardReadRefused extends RuntimeException {}
