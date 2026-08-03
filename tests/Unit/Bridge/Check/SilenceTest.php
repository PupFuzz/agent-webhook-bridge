<?php

namespace Tests\Unit\Bridge\Check;

use App\Bridge\Check\Silence;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The card#5596 declaration primitive's one enforceable property.
 *
 * `Silence` cannot verify that a reason is TRUE — no primitive can — so the only thing it
 * can refuse is a reason that says nothing at all. That refusal is what stops the whole
 * mechanism being satisfiable by a blind sweep inserting `Silence::because('')` across 37
 * files: the required sentence is the per-site judgement the card exists to force, and a
 * blank one is the judgement not made.
 */
class SilenceTest extends TestCase
{
    public function test_an_empty_reason_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a declared silence must state its reason');

        Silence::because('');
    }

    public function test_a_whitespace_only_reason_is_refused(): void
    {
        // The guard trims, so the cheapest way to defeat it is a space. Asserted
        // separately because the empty-string case passes against an untrimmed `=== ''`.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a declared silence must state its reason');

        Silence::because("  \n\t ");
    }

    public function test_a_stated_reason_is_carried_verbatim(): void
    {
        // Nothing renders it (the change is output-neutral by requirement) — it is read at
        // the call site. Pinned anyway: a primitive that silently dropped its argument
        // would pass every other test in this file.
        $this->assertSame(
            'every configured token was readable and 0600',
            Silence::because('every configured token was readable and 0600')->reason,
        );
    }
}
