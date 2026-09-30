<?php

namespace App\Bridge\Writeback;

/**
 * ONE page of a board-scoped `/tasks/search.json` read, with everything kanban's response says about
 * it: the rows, the match count, and how the `q` string was parsed ({@see KanbanClient::searchPage}).
 *
 * `$freeTextTerms` is the free-text half of kanban's DL-282 partition of the `q` tokens (first
 * released in kanban v0.47.0, source-read at `ParsedQuery::toArray`): it is empty IF AND ONLY IF
 * every token was applied as a structured filter — an explicit positive, unlike {@see $freeTextRan}.
 * It is null when the response did not carry it (a kanban older than v0.47.0, or something other
 * than kanban answering): the parse is then UNDISCLOSED, which is not the same as "everything
 * applied".
 *
 * `$freeTextRan` is the older DL-246 signal {@see SearchTotal} carries: `meta.match_mode` present.
 */
final class SearchPage
{
    /**
     * @param  list<array<string, mixed>>|null  $rows  null when the 200 body carried no card collection
     * @param  list<string>|null  $freeTextTerms
     */
    public function __construct(
        public readonly ?array $rows,
        public readonly ?int $total,
        public readonly ?array $freeTextTerms,
        public readonly bool $freeTextRan,
    ) {}
}
