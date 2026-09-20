<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\ProgramCardGuard;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Handlers\KanbanMoveCardHandlerTest;
use Tests\TestCase;

/**
 * The PREDICATE and the DECLARATION legs of card#9929. What the refusal DOES to a card — that
 * nothing is moved and nothing is stamped — is asserted against the live handler in
 * {@see KanbanMoveCardHandlerTest}, with a leg card as the control;
 * this class pins the two things that live here rather than there.
 *
 * ⛔ THE SECOND LEG IS A SEAM CHECK, NOT A DOC-TIDINESS ONE. This refusal was designed as a
 * guarantee holding ACROSS a repo boundary, and ⛔ NO FAR END IMPLEMENTS IT TODAY — the
 * measurement behind that is {@see ProgramCardGuard}'s docblock and is deliberately not copied
 * here. So the `program` row of `docs/kanban-integration-contract.md` § 3 declares a REQUEST
 * for a counterpart, not a description of one — and a declaration with no check is a comment
 * rather than a contract, which is worse than silence in BOTH directions: a spelling that had
 * drifted would let a far-end maintainer audit their half and get confidence instead of a
 * question, and so would a row that kept asserting a counterpart nobody built. This leg is
 * that check, and it reads the very artifact the far end reads — the spelling AND the
 * unmet-request statement, so neither can be edited back out silently.
 *
 * ⚠ WHAT IT CANNOT ESTABLISH, and the check's correct output is to say so by name rather than
 * to imply otherwise by passing: nothing here can see a future far-end implementation's
 * spelling, and nothing on either side can establish that a parent card actually CARRIES the
 * tag. Both remainders are written into the contract row itself, where the far end reads them.
 * Nor can it see whether the far end has since implemented the rule — that stays a measurement
 * somebody makes against the framework, recorded on the row with the version that lands it.
 */
class ProgramCardGuardTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function rows(): array
    {
        return [
            'the tag, alone' => [['tags' => ['program']], true],
            'the tag among others' => [['tags' => ['triaged', 'program', 'owner:kanban/kanban']], true],
            // Exact match, like `no-automove`'s: kanban normalizes neither case nor
            // whitespace, so these are not the tag and must not be refused. Each of them
            // would pass under a `strtolower`/`trim`/substring spelling of the predicate.
            'a different case is not the tag' => [['tags' => ['Program']], false],
            'upper case is not the tag' => [['tags' => ['PROGRAM']], false],
            'padded is not the tag' => [['tags' => ['program ']], false],
            'a tag CONTAINING it is not the tag' => [['tags' => ['program:kanban']], false],
            'an ordinary tag set' => [['tags' => ['triaged']], false],
            'no tags' => [['tags' => []], false],
            // Boundary reads. `tags` is a system boundary (kanban may answer null, and a
            // caller may hand in a row it never read), and a bare in_array over a non-array
            // is a PHP 8.5 TypeError — so these assert the predicate ANSWERS rather than
            // throws. It answers "not a parent", degrading toward writing: the same direction
            // PinGuard degrades in, and detected by PinGuard's own degraded-row detector,
            // which this consult's false verdict lets the delivery go on to reach.
            'tags present-null' => [['tags' => null], false],
            'no tags key at all' => [['id' => 5], false],
            // ⚠ ACCEPTED, NOT DESIGNED, and recorded here so it is falsifiable rather than
            // folklore: `in_array` is key-blind, so a `tags` MAP (a shape kanban does not
            // produce — `TaskResource` emits a list) whose VALUE is the tag reads as a parent.
            // The verdict is incidental to reusing PinGuard's boundary-safe read; it is left
            // as-is because for THIS guard it degrades toward REFUSING, which is the safe
            // direction. (It is the opposite direction from the pin's, whose same-shaped
            // verdict degrades toward writing — that asymmetry is why it is stated.)
            'a tags MAP carrying it as a value' => [['tags' => ['a' => 'program']], true],
        ];
    }

    #[DataProvider('rows')]
    public function test_the_parent_predicate(array $card, bool $expected): void
    {
        $this->assertSame($expected, ProgramCardGuard::isProgramParent($card));
    }

    public function test_the_contract_row_declares_the_tag_this_code_actually_matches(): void
    {
        // The far end's only readable source for the spelling. Read out of the constant, so
        // renaming the tag reds HERE rather than silently desynchronising two runtimes.
        $contract = File::get(base_path('docs/kanban-integration-contract.md'));
        $row = collect(explode("\n", $contract))
            ->first(fn (string $line): bool => str_starts_with($line, '|') && str_contains($line, ProgramCardGuard::REASON));

        $this->assertNotNull($row, 'docs/kanban-integration-contract.md § 3 declares no row for '.ProgramCardGuard::REASON
            .' — the far end this rule is asking for has nothing to match its spelling against');
        $this->assertStringContainsString('`'.ProgramCardGuard::TAG.'`', $row);
        // The remainders the row must keep carrying: a check whose condition it cannot
        // establish locally owes the far end the NAME of what is unverified, not silence.
        $this->assertStringContainsString('EXACTLY', $row);
        $this->assertStringContainsString('neither end can check', $row);
        // ⛔ AND THE TWO THINGS THAT WERE ASSERTED FALSELY BEFORE PR #762 R2, pinned here so
        // they cannot be edited back out by a hand that finds the row's hedging untidy. (1) The
        // row must not claim a far-end counterpart: none exists at any published framework
        // version, and a row asserting one hands its reader confidence where it owes a
        // question. (2) The refusal is the EVENT path's; the row must keep naming the mover
        // that bypasses it, because a far end relying on "a program card is never moved by
        // this bridge on a PR outcome" would be relying on something untrue.
        $this->assertStringContainsString('no far-end counterpart exists today', strtolower($row));
        $this->assertStringContainsString('bridge:reconcile --fix', $row);
    }
}
