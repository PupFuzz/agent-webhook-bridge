<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\StoredPrNumberKind;
use App\Bridge\Writeback\StoredPrRef;
use App\Bridge\Writeback\StoredPrUrlKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * StoredPrRef (DL-429 r4) — the one classification of what a card's stored refs name, read by
 * the stamp, its drop note, the corroboration refusal and the PR comment. These pin every case
 * of both axes; the wording each surface maps a case to is pinned at the surface
 * (KanbanMoveCardHandlerTest, PrCorrelationCommentTest).
 */
class StoredPrRefTest extends TestCase
{
    private const REPO = 'Owner/Repo';

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('urlCases')]
    public function test_the_url_axis(array $payload, StoredPrUrlKind $expected): void
    {
        $this->assertSame($expected, StoredPrRef::of(['payload' => $payload], self::REPO, 7)->url);
    }

    /** @return array<string, array{array<string, mixed>, StoredPrUrlKind}> */
    public static function urlCases(): array
    {
        return [
            'absent' => [[], StoredPrUrlKind::None],
            'empty' => [['pr_url' => ''], StoredPrUrlKind::None],
            'this pull request' => [['pr_url' => 'https://github.com/owner/repo/pull/7'], StoredPrUrlKind::NamesThisPr],
            'this pull request, compared canonically' => [['pr_url' => 'https://github.com/Owner/Repo/pull/7/files'], StoredPrUrlKind::NamesThisPr],
            'another pull request of this repo' => [['pr_url' => 'https://github.com/owner/repo/pull/8'], StoredPrUrlKind::NamesOtherPr],
            'the same number in another repo' => [['pr_url' => 'https://github.com/other/repo/pull/7'], StoredPrUrlKind::NamesOtherPr],
            'this repo\'s placeholder, compared canonically' => [['pr_url' => 'https://github.com/OWNER/repo/pull/0'], StoredPrUrlKind::PlaceholderThisRepo],
            'another repo\'s placeholder' => [['pr_url' => 'https://github.com/other/repo/pull/0'], StoredPrUrlKind::PlaceholderOtherRepo],
            'free text' => [['pr_url' => 'see the linked PR'], StoredPrUrlKind::NotAPrUrl],
            'an issue url' => [['pr_url' => 'https://github.com/owner/repo/issues/7'], StoredPrUrlKind::NotAPrUrl],
        ];
    }

    #[DataProvider('numberCases')]
    public function test_the_number_axis(mixed $stored, mixed $eventPr, StoredPrNumberKind $expected): void
    {
        $this->assertSame($expected, StoredPrRef::of(['payload' => ['pr_number' => $stored]], self::REPO, $eventPr)->number);
    }

    /** @return array<string, array{mixed, mixed, StoredPrNumberKind}> */
    public static function numberCases(): array
    {
        return [
            'absent' => [null, 7, StoredPrNumberKind::None],
            'empty' => ['', 7, StoredPrNumberKind::None],
            'zero' => ['0', 7, StoredPrNumberKind::NamesNoPr],
            'free text' => ['PR 12 of 34', 7, StoredPrNumberKind::NamesNoPr],
            'a #-decorated number' => ['#7', 7, StoredPrNumberKind::NamesNoPr],
            'the same number, as a string' => ['7', 7, StoredPrNumberKind::SameNumber],
            'the same number, leading zero' => ['007', 7, StoredPrNumberKind::SameNumber],
            'a different number' => [148, 7, StoredPrNumberKind::DifferentNumber],
            'an event with no number' => [148, null, StoredPrNumberKind::DifferentNumber],
        ];
    }

    public function test_a_payload_that_is_not_an_array_classifies_as_nothing(): void
    {
        $ref = StoredPrRef::of(['payload' => 'nonsense'], self::REPO, 7);

        $this->assertSame(StoredPrUrlKind::None, $ref->url);
        $this->assertSame(StoredPrNumberKind::None, $ref->number);
    }

    public function test_only_a_url_naming_a_real_pull_request_confirms_a_number(): void
    {
        $bare = StoredPrRef::of(['payload' => ['pr_number' => 7]], self::REPO, 7);
        $placeholder = StoredPrRef::of(['payload' => ['pr_number' => 7, 'pr_url' => 'https://github.com/owner/repo/pull/0']], self::REPO, 7);
        $confirmed = StoredPrRef::of(['payload' => ['pr_number' => 7, 'pr_url' => 'https://github.com/owner/repo/pull/7']], self::REPO, 7);

        $this->assertTrue($bare->numberUnconfirmed());
        $this->assertTrue($placeholder->numberUnconfirmed());
        $this->assertFalse($confirmed->numberUnconfirmed());
        $this->assertFalse($confirmed->numberIsBare());
    }

    public function test_a_differing_number_is_bare_unless_a_real_pull_request_url_sits_beside_it(): void
    {
        $this->assertTrue(StoredPrRef::of(['payload' => ['pr_number' => 148]], self::REPO, 7)->numberIsBare());
        $this->assertFalse(StoredPrRef::of(['payload' => ['pr_number' => 148, 'pr_url' => 'https://github.com/owner/repo/pull/148']], self::REPO, 7)->numberIsBare());
    }
}
