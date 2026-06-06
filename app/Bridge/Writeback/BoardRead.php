<?php

namespace App\Bridge\Writeback;

/**
 * The result of a paged board-cards read (DL-028): the array rows PLUS whether
 * the page walk hit the MAX_PAGES safety ceiling (and so may have left cards
 * unread). `$truncated` is decided inside the page loop on the RAW batch length
 * — it must NOT be re-derived downstream from `count($cards)`, because the rows
 * are array-filtered (a non-array row would desync a count-based check), and a
 * full final page is indistinguishable from a truncated one without this flag.
 */
final class BoardRead
{
    /** @param list<array<string, mixed>> $cards */
    public function __construct(
        public readonly array $cards,
        public readonly bool $truncated,
    ) {}
}
