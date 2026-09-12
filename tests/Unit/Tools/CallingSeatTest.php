<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CallingSeat;
use LogicException;
use Tests\Support\CallingSeatSeal;
use Tests\TestCase;

/**
 * THE WRITE-ONCE SEAT SEAL (card#9170), which is what card#9170's self-only property rests on
 * since DL-372 Decision 7 was reversed. `SeatKanbanUser` has no name parameter, so the whole
 * question "which seat is this" has exactly one answer per process and this class is where it
 * is written — once.
 *
 * ⭐ WHAT HAS TO BE TRUE FOR THE DESIGN TO CARRY ANYTHING, and every arm below is one of them:
 *  - a read with nothing established THROWS (never a default, never an empty string — every
 *    default here is some OTHER seat's identity);
 *  - a second establish THROWS, whatever name it carries;
 *  - and the ALREADY-ESTABLISHED value survives the refused write, because a rogue that could
 *    overwrite by being refused would be a rogue that wins.
 *
 * ⛔ THE ORDERING ARM IS THE ONE THAT MATTERS AND IT IS NOT HERE — it is in
 * `BoardToolDispatcherTest`, driving the real door body, because the claim is about what the
 * DOOR does when a seat is already sealed, not about what this class does when called twice.
 *
 * ⚠ `Tests\Support\CallingSeatSeal` unseals between tests by reflection. That is disclosed in
 * {@see CallingSeat}'s own docblock as an open hole rather than claimed closed, and it is why
 * there is no `reset()` in `app/`.
 */
class CallingSeatTest extends TestCase
{
    public function test_reading_a_seat_nobody_established_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/no calling seat has been established/');

        CallingSeat::name();
    }

    public function test_the_established_seat_is_what_is_read_back(): void
    {
        CallingSeat::establish('me');

        $this->assertSame('me', CallingSeat::name());
    }

    /**
     * ⛔ THE REFUSED WRITE MUST LEAVE THE SEAT ALONE. A second establish that threw AFTER
     * assigning would be strictly worse than one that succeeded: the rogue's name would be
     * installed and the exception would look like the guarantee holding.
     */
    public function test_a_second_establish_with_another_name_throws_and_leaves_the_seat_unchanged(): void
    {
        CallingSeat::establish('me');

        try {
            CallingSeat::establish('other');
            $this->fail('a second establish was accepted — the seat is not write-once.');
        } catch (LogicException $e) {
            $this->assertMatchesRegularExpression('/already established for this process \(as `me`\)/', $e->getMessage());
        }

        $this->assertSame(
            'me',
            CallingSeat::name(),
            'the refused second establish MOVED the seat. A rogue that can overwrite by being refused is a '
            .'rogue that wins, and the throw would then be reporting a guarantee that had just failed.'
        );
    }

    /**
     * WRITE-ONCE IS UNCONDITIONAL, not "unless it is the same name". An idempotent-on-equality
     * rule would be a second legitimate path to the write, and every such path is a place a
     * process that reaches the door twice stops being visible.
     */
    public function test_a_second_establish_with_the_same_name_throws_too(): void
    {
        CallingSeat::establish('me');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/WRITE-ONCE/');

        CallingSeat::establish('me');
    }

    /**
     * The seal is per-PROCESS, and this suite is one process driving the doors hundreds of
     * times — so the harness's own unseal is asserted rather than assumed. If it stopped
     * working, every board-tools test after the first would fail on a second establish, which
     * is a confusing red a long way from its cause.
     */
    public function test_the_harness_can_start_a_new_serving_process(): void
    {
        CallingSeat::establish('me');
        CallingSeatSeal::forANewServingProcess();
        CallingSeat::establish('other');

        $this->assertSame('other', CallingSeat::name());
    }
}
