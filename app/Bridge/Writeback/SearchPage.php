<?php

namespace App\Bridge\Writeback;

/**
 * ONE page of a board-scoped `/tasks/search.json` read, with everything kanban's response says about
 * it: the rows, the match count, and how the `q` string was parsed ({@see KanbanClient::searchPage}).
 *
 * `$appliedFilters` / `$freeTextTerms` are kanban's DL-282 partition of the `q` tokens (first
 * released in kanban v0.47.0, source-read at `ParsedQuery::toArray`): every token appears in exactly
 * one of them, and `free_text_terms` is empty IF AND ONLY IF every token was applied as a structured
 * filter — an explicit positive, unlike {@see $freeTextRan}. Both are null when the response did not
 * carry them (a kanban older than v0.47.0, or something other than kanban answering): the parse is
 * then UNDISCLOSED, which is not the same as "everything applied".
 *
 * `$freeTextRan` is the older DL-246 signal {@see SearchTotal} carries: `meta.match_mode` present.
 */
final class SearchPage
{
    /**
     * @param  list<array<string, mixed>>|null  $rows  null when the 200 body carried no card collection
     * @param  list<string>|null  $appliedFilters
     * @param  list<string>|null  $freeTextTerms
     */
    public function __construct(
        public readonly ?array $rows,
        public readonly ?int $total,
        public readonly ?array $appliedFilters,
        public readonly ?array $freeTextTerms,
        public readonly bool $freeTextRan,
    ) {}
}
