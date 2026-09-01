<?php

namespace App\Bridge\Retention;

use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

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

        $oldest = $row->oldest_received_at ?? null;

        return new RetentionFootprint(
            rows: (int) ($row->row_count ?? 0),
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
     * schema — so it is the same "did not answer" the unknown-driver arm reports, and
     * collapsing it here is what lets the consumer divide by this number without asking.
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
