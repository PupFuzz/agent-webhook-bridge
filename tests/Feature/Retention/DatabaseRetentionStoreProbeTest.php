<?php

namespace Tests\Feature\Retention;

use App\Bridge\Retention\DatabaseRetentionStoreProbe;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
 *
 * ⛔ THE ROWS-BUT-NO-ANSWERABLE-`min(received_at)` THROW IS EXERCISED HERE, ON SQLite
 * ONLY: A PORTABLE TEST DOES NOT EXIST, AND THE BOUND IS THE ENGINE RATHER THAN THE
 * HARNESS. SQLite's NUMERIC affinity keeps what it is handed, so an integer written into
 * the `timestamp(3)` column comes back out of `min()` as an integer and the aggregate
 * answers a row count beside a non-string timestamp — no DDL, no mocked facade, the live
 * connection this class exists for. MariaDB's strict mode REFUSES that insert, so the arm
 * SKIPS on the matrix rather than being forced: the two ways to force it there are worse
 * than the skip (DDL inside a test IMPLICITLY COMMITS and would dissolve
 * `RefreshDatabase`'s transaction for every test after it; mocking the `DB` facade would
 * replace the live connection). The arm is driver-boundary narrowing that FAILS LOUD
 * where it previously produced a wrong verdict silently (an age of NONE reads as an empty
 * store), and it is now seen to fire rather than justified by reading.
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

    /**
     * ⛔ THE PROBE'S `storeBytesContainsPayloadBytes` DECLARATION IS A CLAIM ABOUT THE
     * LIVE ENGINE, AND THIS IS WHERE IT IS HELD TO IT. The check divides the payload sum
     * by the store size only where the probe says the first is inside the second, so a
     * wrong declaration would silently restore the defect the declaration exists to
     * remove — and no amount of reading settles it, because it is a property of how the
     * engine accounts for its own pages.
     *
     * 200 rows of 64 KiB is chosen to put the question at full magnitude: it is far above
     * the InnoDB inline-row limit, so every payload is stored OFF-PAGE.
     *
     * ⛤ WHAT CI MEASURED (card#8374, run 33576949649), and why each arm asserts what it
     * does. On **MariaDB 10.6.28 and 11.8.9** the 13107200 bytes written left
     * `webhook_events.data_length` at **16384 — one page** — and `index_length`
     * unchanged, while `table_rows` refreshed 0 → 200 and `avg_row_length` became 81:
     * off-page bytes are in NEITHER figure, and it is the ACCOUNTING, not stale
     * statistics. The whole schema still reported 245760 bytes against 13107200 of
     * payload. On **SQLite** the size is `page_count * page_size` — the whole file — so
     * the payload is inside it by construction.
     *
     * ⚠ A RED IN EITHER ARM IS A FINDING ABOUT THE ENGINE, NEVER A REASON TO RELAX THE
     * ASSERTION: it would say that engine's accounting has changed basis, and the fix is
     * to move the declaration in `DatabaseRetentionStoreProbe::storeSize()` and record
     * the new measurement in DL-331.
     */
    public function test_the_probes_declaration_about_the_two_figures_holds_on_this_engine(): void
    {
        $written = $this->bulkPayloads(200, 65536);

        $footprint = (new DatabaseRetentionStoreProbe)->measure();

        // The numerator first, and exactly: `length()` over an off-page LOB must still
        // answer for the whole value on every engine. Without it, either arm below could
        // be satisfied by a numerator that silently lost the off-page bytes.
        $this->assertSame(200, $footprint->rows);
        $this->assertSame($written, $footprint->payloadBytes, '200 payloads of exactly 64 KiB each');
        $this->assertNotNull($footprint->storeBytes);

        if ($footprint->storeBytesContainsPayloadBytes) {
            $this->assertSame('sqlite', DB::connection()->getDriverName(), 'only the whole-file size read may declare the two figures comparable');
            $this->assertLessThanOrEqual(
                $footprint->storeBytes,
                $footprint->payloadBytes,
                'this driver declares its size CONTAINS the payload bytes, and it does not',
            );
        } else {
            $this->assertContains(DB::connection()->getDriverName(), ['mysql', 'mariadb']);
            $this->assertGreaterThan(
                $footprint->storeBytes,
                $footprint->payloadBytes,
                'this engine no longer excludes off-page payload bytes from its size figure — re-measure and move the declaration in DatabaseRetentionStoreProbe::storeSize() (DL-331)',
            );
        }
    }

    /**
     * ⛤ THE C2 NARROWING, SEEN TO FIRE. A driver that answers a row count beside a
     * non-string `min(received_at)` must THROW rather than hand the consumer the `null`
     * age that means *there is no row to be old* — `RetentionPostureCheck` keys its EMPTY
     * verdict on exactly that field, so the collapse would print a loaded store as one
     * holding nothing.
     *
     * ⛔ SQLite ONLY, AND THE GATE IS THE ENGINE, NOT A HARNESS LIMITATION. Column types
     * are affinities here, so the integer below survives the write and `min()` returns it
     * as an integer; MariaDB's strict mode rejects an integer for a `timestamp(3)` column
     * outright, which is why the matrix skips this rather than asserting a different
     * thing. The write is an ordinary insert on the live connection — no DDL and no mock.
     */
    public function test_a_row_count_beside_a_non_string_timestamp_throws(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MariaDB strict mode rejects an integer for a timestamp(3) column, so the non-string min(received_at) state cannot be written on this driver; the throw is exercised on the SQLite job');
        }

        DB::table((new WebhookEvent)->getTable())->insert([
            'delivery_id' => 'd-1',
            'provider' => 'github',
            'scope_id' => 'owner/repo',
            'event_type' => 'push',
            'actor_id' => '1',
            'payload' => '{"x":1}',
            // NUMERIC affinity: stored as an INTEGER, and returned as one.
            'received_at' => 12345,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not answer a portable timestamp');

        (new DatabaseRetentionStoreProbe)->measure();
    }

    /**
     * Write `$rows` payloads of exactly `$bytesEach` bytes and return the total.
     *
     * THE FILLER IS VALID JSON, not an arbitrary blob: `$table->json()` is a `longtext`
     * carrying a `json_valid()` CHECK on MariaDB, so a raw filler string is accepted on
     * SQLite and REJECTED on the two jobs this test exists for.
     */
    private function bulkPayloads(int $rows, int $bytesEach): int
    {
        $payload = '{"blob":"'.str_repeat('a', $bytesEach - 11).'"}';
        // Self-checking arithmetic: the assertion above is exact, so a wrong envelope
        // width here would read as a probe defect.
        $this->assertSame($bytesEach, strlen($payload));

        $table = (new WebhookEvent)->getTable();
        $now = now()->format('Y-m-d H:i:s.v');
        foreach (array_chunk(range(1, $rows), 50) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $n) => [
                'delivery_id' => 'bulk-'.$n,
                'provider' => 'github',
                'scope_id' => 'owner/repo',
                'event_type' => 'push',
                'actor_id' => '1',
                'payload' => $payload,
                'received_at' => $now,
            ], $chunk));
        }

        return $rows * $bytesEach;
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
