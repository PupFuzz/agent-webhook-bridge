<?php

namespace App\Bridge\Tools;

use LogicException;

/**
 * THE SEAT THIS PROCESS IS SERVING, ESTABLISHED ONCE BY THE FRONT DOOR AND NEVER AGAIN
 * (card#9170, DL-372 Decision 7 as REVERSED). It is the only thing
 * {@see SeatKanbanUser::forCallingSeat} will answer about, which is what makes
 * *"a seat may claim only for itself"* a property of a STATE rather than of a census over
 * source text.
 *
 * ⛔⭐ WHY A ONE-SHOT STATE AND NOT A PARAMETER, AND WHY THAT IS NOT THE THING DL-372
 * DECLINED. That decision rejected a `DerivedAgentName` VALUE OBJECT, and it was RIGHT about
 * the mechanism it examined: PHP has no friend visibility, so a private constructor only
 * forces minting through a factory whose argument types any code in `app/` can hold, and the
 * regress does not bottom out — whatever mints the value, something can mint it again. That
 * argument is about VALUES. It does not reach a state that can be written ONCE PER PROCESS:
 * a second mint is not a second value here, it is a THROW. The forger's problem is no longer
 * "can I construct one" — it is "can I be FIRST", and being first is louder than losing,
 * because the door's own {@see establish} then throws and the call dies with nothing written.
 *
 * ⭐ BOTH ORDERINGS FAIL CLOSED, and that symmetry is the whole guarantee:
 *  - rogue SECOND → it throws; the door's seat is already established and is UNCHANGED.
 *  - rogue FIRST → the DOOR's establish throws, so the request 500s. A rogue cannot win by
 *    racing ahead of the door; it can only break its own process, loudly.
 * There is no ORDINARY CALL, in either ordering, that makes a name which did not come from a
 * door the one {@see SeatKanbanUser} answers about. ⚠ Reflection is the exception and it is
 * two paragraphs below — read that before quoting this sentence.
 *
 * ⚠ WHAT THIS DELIBERATELY DOES NOT CLAIM — REFLECTION STILL WRITES THE PRIVATE STATIC.
 * `(new ReflectionProperty(self::class, 'seat'))->setValue(null, 'other')` replaces it, and no
 * PHP construct prevents that; it is the same class of hole as
 * `newInstanceWithoutConstructor` on any value object, and it is a DELIBERATE ACT rather than
 * a forgotten branch — which is the distinction the design turns on, because what DL-372 was
 * actually complaining about was a guarantee that an ordinary, well-meant second caller could
 * defeat by accident. This is disclosed, not closed.
 *
 * ⚠ THE SEAL IS PER-PROCESS, AND THE PROCESS MODEL IS AN ASSUMPTION THIS CLASS STATES RATHER
 * THAN MEASURES. This install serves the HTTP door under Apache + PHP-FPM with no long-lived
 * application daemon and no queue worker, and `bridge:tools-call` is one process per call — so
 * one establish per process is the ordinary shape. ⛔ **FPM's static-property isolation between
 * requests on a recycled worker was NOT measured here**; it is assumed, and it is the
 * assumption a reader should attack first. ⭐ If it is wrong — or if this app is ever served
 * by Octane/Swoole/RoadRunner, where a worker DOES span requests — the failure mode is the
 * SECOND request throwing out of {@see establish}, which is a 500 and an operator's problem,
 * never a card written under the wrong seat. The unmeasured assumption is therefore bounded by
 * a fail-closed direction rather than by trust.
 *
 * ⛔ THERE IS NO `reset()` HERE, ON PURPOSE, AND ITS ABSENCE IS LOAD-BEARING. A reset in
 * `app/` would be a second way to reach the write — establish, reset, establish — which is
 * exactly the hole this class closes, dressed as an affordance. The suite drives both doors
 * many times per process and DOES need one; it takes it by reflection from `tests/`, which is
 * a deliberate act outside the application's own call graph (`Tests\Support\CallingSeatSeal`).
 */
final class CallingSeat
{
    /** The seat the front door established for this process, or null before it did. */
    private static ?string $seat = null;

    /**
     * Record the seat this process is serving — the agent name the DOOR derived (the bearer's
     * on http, the pinned forced command's on ssh).
     *
     * ⛔ ONCE. A second call THROWS and leaves the established seat exactly as it was; there
     * is no argument, and no ordering, that replaces an established seat with another name.
     *
     * @throws LogicException if a seat is already established for this process
     */
    public static function establish(string $name): void
    {
        if (self::$seat !== null) {
            throw new LogicException(
                'the calling seat is already established for this process (as `'.self::$seat.'`) and it is '
                .'WRITE-ONCE: the board-tools door records which seat it authenticated exactly once, so that the '
                .'assignee a seat writes cannot be influenced by anything downstream of the door. This second '
                .'establish is refused and the established seat is UNCHANGED. If a front door reached this point '
                .'twice in one process, that is the defect — a serving model that reuses a process across calls '
                .'(Octane, a queue worker, a daemon) is not supported by this door.'
            );
        }

        self::$seat = $name;
    }

    /**
     * The seat established for this process.
     *
     * @throws LogicException if no front door has established one
     */
    public static function name(): string
    {
        if (self::$seat === null) {
            throw new LogicException(
                'no calling seat has been established for this process, so there is no seat to resolve. Only a '
                .'front door establishes one (it is the agent name that door authenticated), which means this '
                .'code is reachable without one — read that as a missing door rather than as a value to default, '
                .'because every default here is some OTHER seat\'s identity.'
            );
        }

        return self::$seat;
    }
}
