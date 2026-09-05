<?php

namespace App\Bridge\Retention;

use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The live {@see RetentionStoreProbe}: one aggregate over `webhook_events` plus one
 * engine-size read (card#8374).
 *
 * TWO OF THE FIVE FIELDS NEED DRIVER-SPECIFIC SQL, and neither has a portable spelling
 * that is CORRECT rather than merely accepted:
 *
 *  - byte length. `length()` counts BYTES on MariaDB and CHARACTERS on SQLite, so the
 *    portable-looking spelling silently answers a different question per job. SQLite's
 *    `cast(x as blob)` forces the byte count; MariaDB has no such cast and does not need
 *    one. (`octet_length()` reads as the portable answer and is not: SQLite gained it in
 *    3.43, and the version is a property of whatever PHP build the operator has.)
 *  - store size. There is no cross-engine size query at all — SQLite counts its own
 *    pages, MariaDB keeps a per-table estimate in `information_schema`.
 *
 * ⛔ THOSE TWO FIELDS ARE NOT ONE MEASUREMENT AND ARE NOT COMPARABLE ON EVERY ENGINE, so
 * the probe DECLARES which it is (`RetentionFootprint::$storeBytesContainsPayloadBytes`)
 * rather than leaving a consumer to divide and hope — see {@see storeSize()} for the
 * measurement behind each arm (DL-331).
 *
 * AN UNKNOWN DRIVER YIELDS NULL FOR BOTH AND STILL RETURNS A FOOTPRINT. Row counts and
 * the oldest row's age are portable, so a Postgres install gets the half of the cost line
 * that is true rather than nothing at all; the consumer prints the absence.
 *
 * ⚠ THE PAYLOAD SUM IS A FULL SCAN of the one table this install is measuring, and that
 * cost is deliberate: it IS the number the card exists to surface (894 MB of a 1.2 GB
 * store), and the alternatives — a sampled estimate, or `information_schema`'s table
 * size standing in for the payload share — are inferences, not measurements. It runs in
 * `bridge:check`, an operator-invoked preflight, never in the receiver's request path.
 */
final class DatabaseRetentionStoreProbe implements RetentionStoreProbe
{
    public function measure(): RetentionFootprint
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $payloadSum = self::payloadByteSum($driver);
        $size = self::storeSize($driver);

        $query = DB::table((new WebhookEvent)->getTable())
            ->selectRaw('count(*) as row_count')
            ->selectRaw('count(payload) as payload_row_count')
            ->selectRaw('min(received_at) as oldest_received_at');
        if ($payloadSum !== null) {
            $query->selectRaw($payloadSum);
        }
        $row = $query->first();

        $rows = (int) ($row->row_count ?? 0);
        $oldest = $row->oldest_received_at ?? null;

        // A MEASUREMENT FAULT IS THROWN, NOT ENCODED (the interface's contract). `null`
        // here means one thing to the consumer — there is no row to be old — and it is
        // read that way: the check's EMPTY verdict is keyed on this field and not on the
        // row count. So a driver that answered rows but did not answer a portable
        // `min()` must not reach the consumer as a drained store; the state is
        // unreachable through app code (`received_at` is NOT NULL, DB-defaulted and never
        // written by it), and this is driver-boundary narrowing of a `selectRaw` value in
        // the same sense as `EventConsumerReconciler`'s `is_scalar` guard — but that one
        // falls back to an inert `''`, and this one would fall back INTO a verdict.
        if ($rows > 0 && ! is_string($oldest)) {
            throw new RuntimeException(
                'the store reported '.$rows.' row(s) but min(received_at) came back as '
                .get_debug_type($oldest).' — this driver did not answer a portable timestamp, and reporting it as an age of NONE would print a loaded store as empty'
            );
        }

        return new RetentionFootprint(
            rows: $rows,
            rowsWithPayload: (int) ($row->payload_row_count ?? 0),
            // The sum is null on a table with no payload rows, which is NOT the
            // driver-cannot-answer null: gate on the SELECT, not on the value.
            payloadBytes: $payloadSum === null ? null : (int) ($row->payload_bytes ?? 0),
            storeBytes: self::storeBytes($connection, $size),
            storeBytesContainsPayloadBytes: $size['containsPayloadBytes'] ?? false,
            oldestRowAgeDays: is_string($oldest) ? self::ageInDays($oldest) : null,
        );
    }

    /**
     * The aggregate SELECT summing payload size in BYTES, or null where this driver has
     * no spelling this class stands behind.
     *
     * WHOLE SELECT PER ARM, not an expression interpolated into one: `selectRaw()` takes a
     * `literal-string`, which is the analyser enforcing that no fragment of this SQL can
     * come from anywhere but this file.
     *
     * @return literal-string|null
     */
    private static function payloadByteSum(string $driver): ?string
    {
        return match ($driver) {
            'sqlite' => 'sum(length(cast(payload as blob))) as payload_bytes',
            'mysql', 'mariadb' => 'sum(length(payload)) as payload_bytes',
            default => null,
        };
    }

    /**
     * WHICH SIZE QUESTION THIS ENGINE CAN ANSWER — the SQL, and whether its answer is on
     * the same basis as the payload sum. ONE `match`, returning both, because the second
     * is a property of the first: split into two `match`es they could drift, and the
     * drift would be silent (a share computed over the wrong denominator still renders).
     *
     * ⛔ `containsPayloadBytes` IS FALSE ON MariaDB, AND IT IS MEASURED. On InnoDB
     * `ROW_FORMAT=Dynamic` a payload above the inline-row limit is stored OFF-PAGE, and
     * those pages are in NEITHER `data_length` NOR `index_length`. Measured on MariaDB
     * 10.6.28 and 11.8.9 (CI, card#8374): 200 rows carrying 13107200 bytes of payload
     * left `webhook_events.data_length` at 16384 — one page — and `index_length`
     * unchanged, while `table_rows` moved 0 → 200 and `avg_row_length` to 81. So this is
     * NOT stale statistics (they refreshed); the clustered-index record simply holds the
     * inline columns plus an off-page pointer and nothing else, and `data_free` moved
     * 0 → 1048576 for the pages the payloads actually took.
     *
     * `data_length + index_length` IS STILL THE MariaDB SOURCE, because the alternatives
     * were measured and are closed:
     *  - `avg_row_length * table_rows` = 81 × 200 = 16200 ≈ `data_length`. It is derived
     *    from the same statistics and answers no differently.
     *  - `information_schema.innodb_tablespaces` — the one source that reads the
     *    tablespace FILE — does not exist on 10.6.28 or 11.8.9 (`1109 Unknown table`),
     *    and its predecessor `innodb_sys_tablespaces` answered
     *    `1227 Access denied; you need (at least one of) the PROCESS privilege(s)` to the
     *    ordinary schema user CI runs as. A GLOBAL `PROCESS` grant is not something this
     *    app needs for anything else, and `CLAUDE_DEPLOYMENT.md` never asks for it, so
     *    adopting it would trade a share that is unprintable for a leg that is
     *    UNRUNNABLE on a correctly-provisioned least-privilege install.
     *
     * @return array{sql: literal-string, containsPayloadBytes: bool}|null
     */
    private static function storeSize(string $driver): ?array
    {
        return match ($driver) {
            // The whole database FILE, so every stored payload byte is physically in it.
            'sqlite' => [
                'sql' => 'select (select * from pragma_page_count()) * (select * from pragma_page_size()) as bytes',
                'containsPayloadBytes' => true,
            ],
            'mysql', 'mariadb' => [
                'sql' => 'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()',
                'containsPayloadBytes' => false,
            ],
            default => null,
        };
    }

    /**
     * The database's size in bytes as the engine accounts for it, or null where it
     * reports none.
     *
     * ZERO IS RETURNED AS NULL. A store the engine says is zero bytes is not a
     * measurement of an empty database — every engine here allocates pages for its own
     * schema — so it is the same "did not answer" the unknown-driver arm reports.
     *
     * @param  array{sql: literal-string, containsPayloadBytes: bool}|null  $size
     */
    private static function storeBytes(ConnectionInterface $connection, ?array $size): ?int
    {
        if ($size === null) {
            return null;
        }

        $bytes = (int) ($connection->selectOne($size['sql'])->bytes ?? 0);

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * Age of a stored `received_at` in days, against the same clock and timezone
     * `RetentionService` cuts on — so "12.4d old" and "past the 30d window" are the same
     * comparison the pruner will make, not a second one that could disagree with it.
     */
    private static function ageInDays(string $receivedAt): float
    {
        return (CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($receivedAt)->getTimestamp()) / 86400;
    }
}
