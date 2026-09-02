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
 * ⛔ THOSE TWO FIELDS ARE NOT ONE MEASUREMENT AND ARE NOT COMPARABLE ON EVERY ENGINE —
 * see {@see storeBytes()} for which source each engine answers from and why the MariaDB
 * one may not be treated as containing the payload sum (DL-331).
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
        // unreachable (`received_at` is NOT NULL, DB-defaulted and never written by app
        // code), and this is driver-boundary narrowing of a `selectRaw` value in the same
        // sense as `EventConsumerReconciler`'s `is_scalar` guard — but that one falls
        // back to an inert `''`, and this one would fall back INTO a verdict.
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
            storeBytes: self::storeBytes($connection, $driver),
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
     * The database's size in bytes as the engine accounts for it, or null where it
     * reports none.
     *
     * ZERO IS RETURNED AS NULL. A store the engine says is zero bytes is not a
     * measurement of an empty database — every engine here allocates pages for its own
     * schema — so it is the same "did not answer" the unknown-driver arm reports.
     *
     * ⛔ THIS NUMBER IS NOT ON THE SAME BASIS AS THE PAYLOAD SUM ABOVE, AND THE CONSUMER
     * MAY NOT ASSUME IT CONTAINS IT (DL-331, {@see RetentionFootprint}). SQLite's arm is
     * the whole file, so it does. MariaDB's is the engine's ALLOCATION accounting for
     * this schema, which is a different question from "how many bytes of text are in
     * these rows".
     *
     * `data_length + index_length` IS KEPT AS THE MariaDB SOURCE, and the alternatives
     * were rejected rather than overlooked. `avg_row_length * table_rows` is derived from
     * the same per-table statistics, so it answers no differently. The tablespace's own
     * file size (`information_schema.innodb_tablespaces` / `innodb_sys_tablespaces`,
     * `file_size` / `allocated_size`) is the one source that could not miss anything the
     * engine has allocated — and every `INNODB_*` information_schema table is documented
     * as requiring the GLOBAL `PROCESS` privilege, which no other part of this app needs
     * and which `CLAUDE_DEPLOYMENT.md` never asks an operator to grant, so reading it
     * would make the cost line fail on a correctly-provisioned least-privilege install.
     * ⚠ THAT PRIVILEGE REQUIREMENT IS DOCUMENTATION, NOT A MEASUREMENT THIS BRANCH MADE —
     * no MariaDB was queried for it here. What the branch does instead is refuse to
     * DEPEND on the relation: the consumer drops the share when the two disagree.
     */
    private static function storeBytes(ConnectionInterface $connection, string $driver): ?int
    {
        $sql = match ($driver) {
            'sqlite' => 'select (select * from pragma_page_count()) * (select * from pragma_page_size()) as bytes',
            'mysql', 'mariadb' => 'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()',
            default => null,
        };
        if ($sql === null) {
            return null;
        }

        $bytes = (int) ($connection->selectOne($sql)->bytes ?? 0);

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
