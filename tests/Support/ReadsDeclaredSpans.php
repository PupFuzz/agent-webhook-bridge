<?php

namespace Tests\Support;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Untrusted;

/**
 * The foreign spans a producer DECLARED, read back off a finding's segment list — the
 * adoption predicate the per-producer coverage pins assert on (card#9121, DL-366).
 *
 * ⭐ IT LIVES IN THE SUITE, NOT ON `Finding`. Nothing in `app/` needs to enumerate a
 * finding's spans by value: the renderer walks the segments in order and the JSON document
 * reads the flat message, so a production accessor returning the values would exist for the
 * tests alone — and, worse, would be exactly the shape (a span as a VALUE, detached from its
 * position) whose removal from the production API is the point of this change. A new
 * producer must not be able to reach for one.
 *
 * ⚑ IT COUNTS OCCURRENCES, NOT DISTINCT VALUES. A message that interpolates one foreign
 * value twice declares it twice, because a declaration is a POSITION — so a leg's span count
 * is the number of places the value lands on the operator's line, which is the quantity a
 * coverage pin should be asserting anyway.
 */
trait ReadsDeclaredSpans
{
    /** @return list<string> */
    protected function declaredSpans(Finding $finding): array
    {
        $spans = [];
        foreach ($finding->segments as $segment) {
            if ($segment instanceof Untrusted) {
                $spans[] = $segment->raw;
            }
        }

        return $spans;
    }
}
