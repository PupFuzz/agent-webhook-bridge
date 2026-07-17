<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\TrackedCardRef;
use App\Bridge\Writeback\TrackedRefKind;
use Tests\TestCase;

/**
 * TrackedCardRef — the shared PR-reference precedence used by bridge:reconcile and the
 * DL-207 promote-on-release scan. These pin the precedence so the two consumers can't
 * drift; reconcile's end-to-end behavior is covered by ReconcileCommandTest.
 */
class TrackedCardRefTest extends TestCase
{
    private ExternalReferenceNormalizer $refs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refs = new ExternalReferenceNormalizer;
    }

    public function test_pr_url_wins_and_yields_repo_and_number(): void
    {
        $ref = TrackedCardRef::fromPayload(
            ['pr_url' => 'https://github.com/Owner/Repo/pull/42', 'pr_number' => 99],
            false,
            $this->refs,
        );

        $this->assertSame(TrackedRefKind::PrUrl, $ref->kind);
        $this->assertSame(42, $ref->prNumber);
        $this->assertSame('https://github.com/Owner/Repo/pull/42', $ref->prUrl);
        $this->assertNotNull($ref->canonRepo);
    }

    public function test_pull_zero_placeholder_falls_through_to_pr_number(): void
    {
        $ref = TrackedCardRef::fromPayload(
            ['pr_url' => 'https://github.com/Owner/Repo/pull/0', 'pr_number' => 42],
            false,
            $this->refs,
        );

        $this->assertSame(TrackedRefKind::PrNumber, $ref->kind);
        $this->assertSame(42, $ref->prNumber);
    }

    public function test_bare_pr_number_is_pr_number_on_a_solo_board(): void
    {
        $ref = TrackedCardRef::fromPayload(['pr_number' => 7], false, $this->refs);

        $this->assertSame(TrackedRefKind::PrNumber, $ref->kind);
        $this->assertSame(7, $ref->prNumber);
    }

    public function test_bare_pr_number_is_ambiguous_on_a_shared_board(): void
    {
        $ref = TrackedCardRef::fromPayload(['pr_number' => 7], true, $this->refs);

        $this->assertSame(TrackedRefKind::Ambiguous, $ref->kind);
        $this->assertSame(7, $ref->prNumber);
    }

    public function test_dl_only_is_dl_only(): void
    {
        $ref = TrackedCardRef::fromPayload(['dl_number' => 'DL-0207'], false, $this->refs);

        $this->assertSame(TrackedRefKind::DlOnly, $ref->kind);
        $this->assertSame('DL-0207', $ref->dl);
    }

    public function test_no_reference_is_none(): void
    {
        $this->assertSame(TrackedRefKind::None, TrackedCardRef::fromPayload([], false, $this->refs)->kind);
        $this->assertSame(TrackedRefKind::None, TrackedCardRef::fromPayload(['pr_number' => 0], false, $this->refs)->kind);
    }
}
