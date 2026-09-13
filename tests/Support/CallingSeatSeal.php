<?php

namespace Tests\Support;

use App\Bridge\Tools\CallingSeat;
use ReflectionProperty;

/**
 * THE TEST-ONLY BREAK IN {@see CallingSeat}'s WRITE-ONCE SEAL, and it lives here — outside
 * `app/` — for the reason the seal exists at all.
 *
 * ⛔ WHY THERE IS NO `CallingSeat::reset()`. A reset in `app/` would be a SECOND way to reach
 * the write (establish → reset → establish), which is exactly the hole the one-shot state
 * closes, wearing the clothes of an affordance: a second caller that wanted another seat's id
 * would no longer need to win a race, only to call a public method. So the break is taken by
 * REFLECTION, from `tests/`, which is a deliberate act that no ordinary call graph can reach
 * by accident — the same distinction {@see CallingSeat}'s own docblock draws when it discloses
 * that reflection can write the private static and does NOT claim that closed.
 *
 * ⚠ WHAT EACH CALL MODELS IS A NEW SERVING PROCESS, and that is why the suite needs it at all.
 * The seal is per-process; production serves one board-tools call per process (one FPM request
 * on the HTTP door, one `bridge:tools-call` invocation on the ssh door). ONE phpunit process
 * drives both doors hundreds of times, so without this every test after the first would die on
 * a second establish — a fact about the harness, never about the door.
 *
 * ⛔ It is not an exemption from the property: nothing in `app/` calls this, `tests/` is
 * outside the census population by construction, and the guard that would red on an `app/`
 * caller of {@see CallingSeat} is `Tests\Feature\AgentTools\SeatIdentityCallSiteGuardTest`.
 */
final class CallingSeatSeal
{
    /**
     * Unseal, as if this were a freshly-started serving process that no door has reached yet.
     */
    public static function forANewServingProcess(): void
    {
        (new ReflectionProperty(CallingSeat::class, 'seat'))->setValue(null, null);
    }

    /**
     * Unseal and then establish $name THROUGH THE REAL METHOD — so a test that needs a seat
     * exercises the production write rather than a reflection-planted value.
     */
    public static function establishedAs(string $name): void
    {
        self::forANewServingProcess();
        CallingSeat::establish($name);
    }
}
