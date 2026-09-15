<?php

namespace App\Bridge\Writeback;

/**
 * A `/tasks/search.json` count, with the one fact kanban's own response gives about whether the
 * `q` string was read the way it was written.
 *
 * `$freeTextRan` is kanban's DL-246 disclosure: the search `meta` carries `match_mode` exactly
 * when some `q` token fell through to the free-text arm. A structured filter the server does not
 * recognise is such a token, so a count whose query was meant to be structured-only and that
 * carries the disclosure is a count of something else. ⚠ A kanban older than that disclosure
 * never sends it, so `false` there is not evidence the filter was honoured —
 * {@see KanbanClient::searchDisclosesFreeText} asks which kind of kanban answered.
 */
final class SearchTotal
{
    public function __construct(
        public readonly ?int $total,
        public readonly bool $freeTextRan,
    ) {}
}
