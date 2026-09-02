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
 *
 * ⛔ ONE ARM OF THE PROBE HAS NO TEST HERE, AND THE ABSENCE IS DELIBERATE RATHER THAN AN
 * OVERSIGHT — the rows-but-no-answerable-`min(received_at)` throw. Reaching it needs the
 * aggregate to answer a row count beside a null timestamp, which the real schema cannot
 * produce (`received_at` is NOT NULL and DB-defaulted), and both ways to force it are
 * worse than the gap: DDL inside a test IMPLICITLY COMMITS on MariaDB and would dissolve
 * `RefreshDatabase`'s transaction for every test after it, and mocking the `DB` facade
 * would replace the live connection this class exists to exercise. The arm is
 * driver-boundary narrowing that now FAILS LOUD where it previously produced a wrong
 * verdict silently (an age of NONE reads as an empty store); it is justified by reading,
 * and this paragraph is the disclosure of that rather than a claim it is covered.
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
     * ⛔ TEMPORARY DIAGNOSTIC — TO BE REMOVED IN THE SAME PR. It exists because the only
     * MariaDB this branch can reach is the CI matrix, and the question it answers cannot
     * be answered by reading: WHY does `information_schema` report 245760 bytes for a
     * schema that just took 13107200 bytes of payload? Read-only, no DDL (an `ANALYZE
     * TABLE` would implicitly COMMIT on MariaDB and dissolve `RefreshDatabase`'s
     * transaction for every test after it), and every query is wrapped so that a
     * PRIVILEGE refusal is itself a datum rather than an abort.
     */
    public function test_zz_diagnostic_what_the_mariadb_size_source_actually_reports(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('the diagnostic subject is the MariaDB size source');
        }

        $ask = function (string $sql): mixed {
            try {
                return DB::select($sql);
            } catch (\Throwable $e) {
                return 'ERR: '.$e->getMessage();
            }
        };
        $isTables = 'select table_name, engine, row_format, table_rows, avg_row_length, data_length, index_length, data_free from information_schema.tables where table_schema = database() order by table_name';

        $facts = ['version' => $ask('select version() as v')];
        $facts['settings'] = $ask('select @@autocommit a, @@innodb_stats_on_metadata som, @@innodb_stats_persistent sp, @@innodb_stats_auto_recalc sar, @@innodb_page_size ps, @@tx_isolation_or_null is_null');
        if (is_string($facts['settings'])) {
            $facts['settings'] = $ask('select @@autocommit a, @@innodb_stats_on_metadata som, @@innodb_stats_persistent sp, @@innodb_stats_auto_recalc sar, @@innodb_page_size ps');
        }
        $facts['tx_level_before'] = DB::transactionLevel();
        $facts['is_tables_before'] = $ask($isTables);

        $written = $this->bulkPayloads(200, 65536);
        $facts['written'] = $written;
        $facts['tx_level_after'] = DB::transactionLevel();
        $facts['sum_len_payload'] = $ask('select sum(length(payload)) v, count(*) c from webhook_events');
        $facts['is_tables_after'] = $ask($isTables);
        $facts['probe_store_bytes'] = (new DatabaseRetentionStoreProbe)->measure()->storeBytes;
        // The one source that reads the tablespace FILE rather than cached statistics —
        // and the one whose refusal would confirm the PROCESS-privilege claim DL-331
        // currently makes from documentation alone.
        $facts['innodb_tablespaces'] = $ask("select name, file_size, allocated_size from information_schema.innodb_tablespaces where name like concat(database(), '/%')");
        $facts['innodb_sys_tablespaces'] = $ask("select name, file_size, allocated_size from information_schema.innodb_sys_tablespaces where name like concat(database(), '/%')");

        fwrite(STDERR, "\nDL331-DIAG-BEGIN\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\nDL331-DIAG-END\n");

        $this->assertSame($written, (new DatabaseRetentionStoreProbe)->measure()->payloadBytes);
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
