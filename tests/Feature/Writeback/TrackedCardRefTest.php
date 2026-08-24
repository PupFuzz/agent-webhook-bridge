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

    /**
     * DL-309 — which pull request a `pr_number` names is the normalizer's answer, not a
     * local `(int)` cast. `1.5` truncated to PR 1 here while the kanban server (its
     * DL-251) derives no `github_pr` ref at all from the same stored value: one card, two
     * authorities, two answers, and PR 1 is a real, unrelated pull request the reconcile
     * would then read and move the card from.
     */
    public function test_a_non_integer_pr_number_names_no_pull_request(): void
    {
        // A number-typed kanban field decodes to a PHP float; the durable inbox / a JSON
        // round-trip can hand back the same value as a string. Both must answer alike.
        foreach ([1.5, '1.5'] as $value) {
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], false, $this->refs);

            $this->assertSame(TrackedRefKind::None, $ref->kind, 'value: '.var_export($value, true));
            $this->assertNull($ref->prNumber);
        }
    }

    public function test_a_non_integer_pr_number_is_not_ambiguous_either_on_a_shared_board(): void
    {
        // The shared-board arm truncated identically, and `Ambiguous` is the kind
        // bridge:reconcile prints an operator-facing skip line for — one that would have
        // named the fabricated number as the card's PR.
        $ref = TrackedCardRef::fromPayload(['pr_number' => 1.5], true, $this->refs);

        $this->assertSame(TrackedRefKind::None, $ref->kind);
        $this->assertNull($ref->prNumber);
    }

    public function test_an_integral_pr_number_is_still_tracked_whatever_its_json_type(): void
    {
        // Control: the refusal is scoped to values naming no single integer, not to floats
        // or numeric strings generally.
        foreach ([85, 85.0, '85', '085'] as $value) {
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], false, $this->refs);

            $this->assertSame(TrackedRefKind::PrNumber, $ref->kind, 'value: '.var_export($value, true));
            $this->assertSame(85, $ref->prNumber);
        }
    }

    /**
     * The admission test deliberately did NOT move with DL-309: this leg is the BARE
     * positive number it always was. Both values below canonicalize to a ref the kanban
     * server does index (`-5` → "5", `#85` → "85"), so routing the value through the
     * normalizer without keeping the admission test would have silently widened what the
     * reconcile acts on — an outward move driven by a value nobody meant as a PR number.
     */
    public function test_a_negative_or_decorated_pr_number_is_still_not_a_bare_pr_number(): void
    {
        foreach ([-5, '-5', '#85', 'PR-85'] as $value) {
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], false, $this->refs);

            $this->assertSame(TrackedRefKind::None, $ref->kind, 'value: '.var_export($value, true));
        }
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
