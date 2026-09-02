<?php

namespace Tests\Unit\Bridge\Support;

use App\Bridge\Support\TitleChangeEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared retitle-evidence primitive (hoisted at DL-341 out of the two classifiers that
 * read it — DL-328's `pull_request.edited` arm and DL-341's `issues.edited` one).
 *
 * ⭐ WHAT THESE PIN IS A SECURITY-ADJACENT RULE, not a getter. The string this returns is
 * the ownership test two card-NAME writes are gated on: the bridge rewrites a card's name
 * only when the name still equals this string byte for byte. So the arms that must NOT
 * return a string matter more than the one that must — a spurious non-null here would let a
 * write proceed on evidence nobody produced, onto a card the bridge may not own, and no
 * later event corrects a wrongly-renamed card.
 */
class TitleChangeEvidenceTest extends TestCase
{
    public function test_a_title_change_yields_the_previous_title_verbatim(): void
    {
        // Verbatim, including surrounding whitespace: the comparison downstream is BYTE
        // equality, so this must not normalize anything.
        $this->assertSame(
            '[QUERY] can we ship? ',
            TitleChangeEvidence::previousTitle(['changes' => ['title' => ['from' => '[QUERY] can we ship? ']]]),
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[DataProvider('nonEvidence')]
    public function test_everything_that_is_not_a_usable_previous_title_reads_as_no_retitle(string $case, array $payload): void
    {
        $this->assertNull(TitleChangeEvidence::previousTitle($payload), $case);
    }

    /**
     * @return array<string, array{0: string, 1: array<mixed>}>
     */
    public static function nonEvidence(): array
    {
        return [
            // GitHub sends `changes` with a key per field the edit changed, so the TITLE
            // key's presence is the signal. A body edit carries no title key.
            'a body edit' => ['a body edit', ['changes' => ['body' => ['from' => 'old body']]]],
            'no changes envelope at all' => ['no changes envelope at all', ['issue' => ['title' => 'x']]],
            // ⛔ THE LOAD-BEARING ONE: an empty `from` is read as NO retitle, never as an
            // empty previous title. An empty previous title would compare equal to nothing,
            // prove nothing, and restamp on no evidence.
            'an empty from' => ['an empty from', ['changes' => ['title' => ['from' => '']]]],
            'a null from' => ['a null from', ['changes' => ['title' => ['from' => null]]]],
            'a non-string from' => ['a non-string from', ['changes' => ['title' => ['from' => 42]]]],
            'a title key with no from' => ['a title key with no from', ['changes' => ['title' => ['to' => 'new']]]],
            // Boundary reads of a foreign payload: a scalar where an object is expected must
            // not throw on the classify path.
            'a scalar changes' => ['a scalar changes', ['changes' => 'nope']],
            'a scalar changes.title' => ['a scalar changes.title', ['changes' => ['title' => 'nope']]],
            'an empty payload' => ['an empty payload', []],
        ];
    }
}
