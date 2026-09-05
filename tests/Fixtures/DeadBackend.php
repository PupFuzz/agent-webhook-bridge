<?php

namespace Tests\Fixtures;

use RuntimeException;

/**
 * A backend that is GONE: every call on it throws (card#8425 / DL-325).
 *
 * ⭐ IT EXISTS TO BREAK THE RECORDING SURFACE ITSELF, which is the one fault a
 * try/catch around the work cannot see. `Schema::drop()` breaks the DB and leaves the
 * catch arm's `Cache::put`/`Log::warning` working, so every "never throws past the pass"
 * test written that way passes while the arm is still able to re-raise. Swapped in for
 * `cache` (or `log`) it makes the RECORDER the fault, and the shell's promise becomes
 * falsifiable.
 *
 * It is deliberately not a `Repository`/`LoggerInterface` implementation: a typed stub
 * would have to enumerate the interface and would then go stale against it, and the
 * facades reach their root through `__call` anyway.
 */
final class DeadBackend
{
    public function __construct(private readonly string $what = 'cache') {}

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new RuntimeException("{$this->what} backend unreachable ({$method})");
    }
}
