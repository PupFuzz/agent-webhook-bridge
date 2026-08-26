<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\ClientHalfLedger;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write primitive behind the client-half row (card#7756 / DL-313).
 *
 * ⭐ WHAT A SECOND CALL MUST DO IS THE WHOLE REASON THIS CLASS EXISTS. `bridge:check` reads
 * the row's AGE, so a repeat call that left `last_success_at` where it was would age a seat
 * calling every hour into the UNREPORTED arm — the exact failure the check is supposed to
 * distinguish from a silent seat, produced by the writer instead. Asserting only that ONE
 * row exists after two calls (the uniqueness half) would pass against precisely that
 * writer, so the advance is asserted with it, and `created_at` is asserted NOT to move: the
 * first successful call ever recorded is a different fact from the last one, and an upsert
 * that rewrote both would silently destroy it.
 *
 * The transport is rewritten on purpose — a seat that moves from http to ssh must not leave
 * `bridge:check` naming the door it no longer uses.
 */
class ClientHalfLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeat_call_advances_the_stamp_in_place_and_keeps_the_first_one(): void
    {
        $this->travelTo(now()->subDay());
        ClientHalfLedger::record('prod-agent', 'http');
        $first = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();
        $firstCreatedAt = $first->created_at;
        $firstSuccessAt = $first->last_success_at;

        $this->travelBack();
        ClientHalfLedger::record('prod-agent', 'ssh');

        $rows = BoardToolsClientCall::query()->where('agent', 'prod-agent')->get();
        $this->assertCount(1, $rows, 'the second call minted a second row instead of rewriting the first');

        $row = $rows[0];
        $this->assertTrue(
            $row->last_success_at->greaterThan($firstSuccessAt),
            'last_success_at did not advance on the repeat call — a seat calling every hour would age into the UNREPORTED arm',
        );
        $this->assertSame('ssh', $row->transport);
        $this->assertTrue(
            $row->created_at->equalTo($firstCreatedAt),
            'created_at moved: the FIRST call ever recorded is a separate fact and the upsert must not rewrite it',
        );
    }

    /**
     * The control for the assertions above: they compare a second write against a first, so
     * a `record()` that wrote nothing at all would satisfy the uniqueness half. This pins
     * that a call with no prior row creates one.
     */
    public function test_the_first_call_creates_the_row(): void
    {
        $this->assertSame(0, BoardToolsClientCall::query()->count());

        ClientHalfLedger::record('prod-agent', 'ssh');

        $row = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();
        $this->assertSame('ssh', $row->transport);
        $this->assertTrue($row->last_success_at->greaterThan(now()->subMinute()));
    }
}
