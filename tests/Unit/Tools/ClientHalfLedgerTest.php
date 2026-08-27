<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ClientHalfLedger;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeServingProcessEnvironment;
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
 * `bridge:check` naming the door it no longer uses. Since card#7836 the PROVENANCE is
 * rewritten with it, for a sharper version of the same reason: a stale `sshd` left standing
 * beside a fresh non-ssh call would make `bridge:check` print the STRONGER verdict over a
 * call that never earned it.
 */
class ClientHalfLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeat_call_advances_the_stamp_in_place_and_keeps_the_first_one(): void
    {
        $this->travelTo(now()->subDay());
        ClientHalfLedger::record('prod-agent', 'http', CallProvenance::NotSshd);
        $first = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();
        $firstCreatedAt = $first->created_at;
        $firstSuccessAt = $first->last_success_at;

        $this->travelBack();
        ClientHalfLedger::record('prod-agent', 'ssh', CallProvenance::Sshd);

        $rows = BoardToolsClientCall::query()->where('agent', 'prod-agent')->get();
        $this->assertCount(1, $rows, 'the second call minted a second row instead of rewriting the first');

        $row = $rows[0];
        $this->assertTrue(
            $row->last_success_at->greaterThan($firstSuccessAt),
            'last_success_at did not advance on the repeat call — a seat calling every hour would age into the UNREPORTED arm',
        );
        $this->assertSame('ssh', $row->transport);
        $this->assertSame(
            CallProvenance::Sshd,
            $row->call_provenance,
            'the provenance did not follow the repeat call — a stale sshd stamp would keep bridge:check on the stronger verdict after the seat stopped earning it',
        );
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

        ClientHalfLedger::record('prod-agent', 'ssh', CallProvenance::Sshd);

        $row = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();
        $this->assertSame('ssh', $row->transport);
        $this->assertSame(CallProvenance::Sshd, $row->call_provenance);
        $this->assertTrue($row->last_success_at->greaterThan(now()->subMinute()));
    }

    /**
     * ⛔ WHAT IS PERSISTED IS A NAME (card#7836 / DL-316). The row is printed VERBATIM into a
     * `bridge:check` line, and the fact behind this column is derived from `SSH_CONNECTION`,
     * which is a client IP, a client port and this host's own address and port. The
     * assertion is over the row's WHOLE stored content rather than over the provenance
     * column alone: a future writer that parked the raw value on some other column would
     * satisfy a column-scoped check and still disclose it.
     */
    public function test_the_stored_row_carries_a_name_and_no_environment_value(): void
    {
        // Driven through the REAL measurement rather than a hand-passed enum case: what is
        // asserted is that the environment value cannot reach the row, so the value has to
        // be present in the process at the moment the row is written or the test proves
        // nothing. The serving-process FACTS are a stated fixture (the suite cannot give
        // itself, or take from itself, a controlling terminal) while the ambient variable is
        // seeded for real, which is the string the assertions below hunt for.
        [$connection, $tty] = [getenv('SSH_CONNECTION'), getenv('SSH_TTY')];
        putenv('SSH_CONNECTION=203.0.113.9 53210 198.51.100.4 22');
        putenv('SSH_TTY');
        try {
            ClientHalfLedger::record('prod-agent', 'ssh', CallProvenance::of(
                new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: false),
            ));
        } finally {
            is_string($connection) ? putenv('SSH_CONNECTION='.$connection) : putenv('SSH_CONNECTION');
            is_string($tty) ? putenv('SSH_TTY='.$tty) : putenv('SSH_TTY');
        }

        $row = BoardToolsClientCall::query()->where('agent', 'prod-agent')->sole();

        // Non-vacuous: the row exists and carries the STRONG provenance, so the assertions
        // below are about a write that really happened and really read the variable.
        $this->assertSame(CallProvenance::Sshd, $row->call_provenance);
        // The WHOLE row, not the provenance column: a writer that parked the raw value on
        // some other column would satisfy a column-scoped check and still disclose it, and
        // this row is printed verbatim into a `bridge:check` line.
        $stored = (string) json_encode($row->getAttributes());
        $this->assertStringNotContainsString('203.0.113.9', $stored);
        $this->assertStringNotContainsString('53210', $stored);
        $this->assertStringNotContainsString('198.51.100.4', $stored);
    }
}
