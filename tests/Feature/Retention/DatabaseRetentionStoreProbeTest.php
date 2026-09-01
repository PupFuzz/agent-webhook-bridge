<?php

namespace Tests\Feature\Retention;

use App\Bridge\Retention\DatabaseRetentionStoreProbe;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The live half of card#8374 — the queries the golden corpus deliberately cannot reach.
 *
 * ⛔ THIS CLASS IS THE ONLY PLACE THE DRIVER-SPECIFIC SQL IS EXERCISED, and that is the
 * whole reason it exists: `bridge:check`'s cost line runs against a PINNED probe in the
 * golden harness (a live size read prints a different number on the SQLite job and on
 * each MariaDB job), so a byte-identical corpus is no evidence at all that the real
 * queries parse, let alone that they answer the same question on both engines. These
 * assertions run under whichever driver the job is configured for — SQLite in the default
 * suite, MariaDB 10.6 and 11 in the matrix — so the cross-engine claim is made by the
 * matrix rather than by reading.
 *
 * WHAT IS ASSERTED IS THE RELATION, NOT THE FIGURE, wherever the figure is the engine's
 * (a database's own size is not a property of these three rows). The one exact figure
 * asserted is the payload byte count, because it IS portable — the stored bytes are what
 * this app wrote — and because it is the assertion that catches SQLite's `length()`
 * counting CHARACTERS: a payload with one two-byte character is the discriminator, and
 * the naive spelling under-reports it by exactly that one byte.
 */
class DatabaseRetentionStoreProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_measures_the_rows_the_payload_bytes_and_the_oldest_age(): void
    {
        $this->event('d-1', ['x' => 1], '-40 days');
        $this->event('d-2', ['x' => 2], '-2 days');

        $footprint = (new DatabaseRetentionStoreProbe)->measure();

        $this->assertSame(2, $footprint->rows);
        $this->assertSame(2, $footprint->rowsWithPayload);
        $this->assertSame(14, $footprint->payloadBytes, 'two 7-byte payloads: {"x":1} and {"x":2}');
        $this->assertNotNull($footprint->oldestRowAgeDays);
        $this->assertEqualsWithDelta(40.0, $footprint->oldestRowAgeDays, 0.1);
    }

    /**
     * The control the card requires: an empty store reports itself as empty, and reports
     * NO age at all rather than a zero. The database's own size is unaffected by there
     * being no rows, which is why the empty verdict may not be read off it.
     */
    public function test_an_empty_store_reports_no_rows_and_no_age(): void
    {
        $footprint = (new DatabaseRetentionStoreProbe)->measure();

        $this->assertSame(0, $footprint->rows);
        $this->assertSame(0, $footprint->rowsWithPayload);
        $this->assertSame(0, $footprint->payloadBytes);
        $this->assertNull($footprint->oldestRowAgeDays);
        $this->assertNotNull($footprint->storeBytes);
    }

    /**
     * The byte count is BYTES on both engines. SQLite's `length()` returns characters for
     * a TEXT value, so a payload carrying one two-byte character is what tells the correct
     * spelling from the one that merely runs everywhere: 'é' costs 2 bytes and 1
     * character, and Laravel's JSON cast escapes it to `é` — 6 ASCII bytes — so the
     * discriminator has to be a character the cast leaves alone.
     */
    public function test_the_payload_byte_count_is_bytes_and_not_characters(): void
    {
        // json_encode escapes non-ASCII, so the stored bytes are ASCII either way; the
        // multi-byte discriminator is applied to the STORED value directly, below.
        $this->event('d-1', ['x' => 1], '-1 day');
        DB::table((new WebhookEvent)->getTable())
            ->where('delivery_id', 'd-1')
            ->update(['payload' => '{"x":"é"}']);

        // 9 characters, 10 bytes — `length()` alone answers 9 on SQLite.
        $this->assertSame(10, (new DatabaseRetentionStoreProbe)->measure()->payloadBytes);
    }

    /** A nulled payload is a row retention KEPT and a payload it reclaimed; both must show. */
    public function test_a_nulled_payload_leaves_the_row_and_drops_out_of_the_byte_count(): void
    {
        $this->event('d-1', ['x' => 1], '-40 days');
        $this->event('d-2', ['x' => 2], '-2 days');
        DB::table((new WebhookEvent)->getTable())->where('delivery_id', 'd-1')->update(['payload' => null]);

        $footprint = (new DatabaseRetentionStoreProbe)->measure();

        $this->assertSame(2, $footprint->rows);
        $this->assertSame(1, $footprint->rowsWithPayload);
        $this->assertSame(7, $footprint->payloadBytes);
        // The row is still there, so the age still answers for it — which is the whole
        // point of the payload leg: it reclaims bytes without shortening the row window.
        $this->assertEqualsWithDelta(40.0, $footprint->oldestRowAgeDays ?? 0.0, 0.1);
    }

    /**
     * The engine-size read, whose SQL is different on every driver and is the one field
     * with no portable spelling at all. The claim is bounded to what a size can support:
     * the number is the engine's own accounting, so what is asserted is that this driver
     * ANSWERED — a `null` here is the check printing "database size not reported by this
     * driver" on a driver the suite actually runs on.
     */
    public function test_this_driver_reports_a_database_size(): void
    {
        $this->assertContains(
            DB::connection()->getDriverName(),
            ['sqlite', 'mysql', 'mariadb'],
            'this test is the size read\'s only exercise; a driver outside this set needs its own arm in DatabaseRetentionStoreProbe',
        );

        $bytes = (new DatabaseRetentionStoreProbe)->measure()->storeBytes;

        $this->assertNotNull($bytes);
        $this->assertGreaterThan(0, $bytes);
    }

    /** @param  array<string, mixed>  $payload */
    private function event(string $delivery, array $payload, string $age): void
    {
        $event = WebhookEvent::create([
            'delivery_id' => $delivery,
            'provider' => 'github',
            'scope_id' => 'owner/repo',
            'event_type' => 'push',
            'actor_id' => '1',
            'payload' => $payload,
        ]);
        // `received_at` is not fillable — it defaults to the insert clock, and every age
        // assertion here is about a row planted at a chosen distance from now.
        $event->forceFill(['received_at' => now()->modify($age)])->save();
    }
}
