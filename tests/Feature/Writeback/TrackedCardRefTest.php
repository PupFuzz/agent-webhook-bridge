<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\TrackedCardRef;
use App\Bridge\Writeback\TrackedRefKind;
use Tests\TestCase;

/**
 * TrackedCardRef — the shared PR-reference precedence used by bridge:reconcile, the
 * DL-207 promote-on-release scan and the DL-270 corroboration gate. These pin the precedence
 * so the consumers can't drift; their end-to-end behavior is covered at each surface.
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
            $this->refs,
        );

        $this->assertSame(TrackedRefKind::PrUrl, $ref->kind);
        $this->assertSame(42, $ref->prNumber);
        $this->assertSame('https://github.com/Owner/Repo/pull/42', $ref->prUrl);
        $this->assertNotNull($ref->canonRepo);
    }

    public function test_pull_zero_placeholder_falls_through_to_a_bare_pr_number_it_does_not_qualify(): void
    {
        // card#9850: the placeholder's repo is not evidence of which repo the number came
        // from (the stamp writes `pr_number` beside a kept FOREIGN placeholder), so the card
        // names no pull request — not even the placeholder repo's #42.
        $ref = TrackedCardRef::fromPayload(
            ['pr_url' => 'https://github.com/Owner/Repo/pull/0', 'pr_number' => 42],
            $this->refs,
        );

        $this->assertSame(TrackedRefKind::BarePrNumber, $ref->kind);
        $this->assertSame(42, $ref->prNumber);
        $this->assertNull($ref->canonRepo);
        $this->assertFalse($ref->namesPr('owner/repo', 42, $this->refs));
    }

    /**
     * DL-429 — a bare pr_number names no repo on ANY board. It used to be attributed to a
     * 1:1 board's sole mapping, which an org move falsifies: the mapping becomes the new
     * repo, whose restarted PR numbers collide with the old repo's.
     */
    public function test_a_bare_pr_number_names_no_pull_request_in_any_repo(): void
    {
        $ref = TrackedCardRef::fromPayload(['pr_number' => 7], $this->refs);

        $this->assertSame(TrackedRefKind::BarePrNumber, $ref->kind);
        $this->assertSame(7, $ref->prNumber);
        $this->assertNull($ref->canonRepo);
        $this->assertFalse($ref->namesPr('owner/repo', 7, $this->refs));
    }

    /**
     * DL-429 — the collision itself: OLD/repo#N and NEW/repo#N are two pull requests. A card
     * names only the one its pr_url names, whatever spelling the repo or number arrive in.
     */
    public function test_a_pr_url_names_its_own_repos_pull_request_and_no_other_repos_same_number(): void
    {
        $ref = TrackedCardRef::fromPayload(['pr_url' => 'https://github.com/OldOrg/Repo/pull/148', 'pr_number' => 148], $this->refs);

        $this->assertFalse($ref->namesPr('neworg/repo', 148, $this->refs), 'same number, other repo');
        $this->assertTrue($ref->namesPr('oldorg/repo', 148, $this->refs), 'control: its own repo');
        $this->assertTrue($ref->namesPr('OLDORG/REPO', '0148', $this->refs), 'control: repo case and number spelling are not identity');
        $this->assertFalse($ref->namesPr('oldorg/repo', 149, $this->refs), 'same repo, other number');
        $this->assertFalse($ref->namesPr('oldorg/repo', null, $this->refs), 'an event naming no number names nothing');
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
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], $this->refs);

            $this->assertSame(TrackedRefKind::None, $ref->kind, 'value: '.var_export($value, true));
            $this->assertNull($ref->prNumber);
        }
    }

    public function test_an_integral_pr_number_is_still_tracked_whatever_its_json_type(): void
    {
        // Control: the refusal is scoped to values naming no single integer, not to floats
        // or numeric strings generally.
        foreach ([85, 85.0, '85', '085'] as $value) {
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], $this->refs);

            $this->assertSame(TrackedRefKind::BarePrNumber, $ref->kind, 'value: '.var_export($value, true));
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
            $ref = TrackedCardRef::fromPayload(['pr_number' => $value], $this->refs);

            $this->assertSame(TrackedRefKind::None, $ref->kind, 'value: '.var_export($value, true));
        }
    }

    public function test_dl_only_is_dl_only(): void
    {
        $ref = TrackedCardRef::fromPayload(['dl_number' => 'DL-0207'], $this->refs);

        $this->assertSame(TrackedRefKind::DlOnly, $ref->kind);
        $this->assertSame('DL-0207', $ref->dl);
    }

    public function test_no_reference_is_none(): void
    {
        $this->assertSame(TrackedRefKind::None, TrackedCardRef::fromPayload([], $this->refs)->kind);
        $this->assertSame(TrackedRefKind::None, TrackedCardRef::fromPayload(['pr_number' => 0], $this->refs)->kind);
    }
}
