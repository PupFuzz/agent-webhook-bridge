<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * card#8825 — the DATA half of the connection-timezone pin.
 *
 * `config/database.php` now pins the MySQL session `time_zone` to `app.timezone`. Every row
 * written BEFORE that pin was written by PHP as a bare UTC literal into a session running the
 * host's zone, so MySQL read it as local time and stored `instant + host_offset` instead of
 * `instant`. The pin does not touch those rows — it only stops the same wrong offset being
 * re-applied on the way out, which is what made the skew invisible. This migration rewrites
 * them to the instant that was actually meant.
 *
 * ⭐ ONLY PHP-WRITTEN COLUMNS ARE CORRECTED, and the distinction is the whole card.
 * `webhook_events.received_at` is filled by the DB (`->useCurrent()`), so MySQL wrote a real
 * instant and the column is ALREADY RIGHT — it merely DISPLAYED wrong. Correcting it would
 * break the one column that never broke. Every other timestamp in this schema is written by
 * PHP: Eloquent's own `created_at` / `updated_at` (and the two models that rename it —
 * `App\Models\WritebackBoardDivergence` uses `last_seen_at`, `App\Models\BoardToolsClientCall`
 * suppresses it), plus the columns app code assigns `now()` to. `->useCurrent()` on a column
 * Eloquent also maintains is a DEFAULT THAT NEVER FIRES: the INSERT names the column, so the
 * default is not consulted, which is why `writeback_board_divergences.created_at` and
 * `board_tools_client_calls.created_at` are PHP-written despite carrying one.
 *
 * ⭐ THE OFFSET IS NOT A CONSTANT AND IS NEVER SPELLED HERE. `CONVERT_TZ(col, '+00:00',
 * 'SYSTEM')` asks the SERVER for the offset its own zone had AT THAT ROW'S INSTANT, so a table
 * spanning a DST transition is corrected per row (measured on MariaDB 10.11: a 04:30Z row maps
 * to -04:00, a 07:30Z row on the same date to -05:00) and a host in any other zone gets its own
 * offset rather than this one's. A literal `4` would have been right only here, only until
 * November.
 *
 * ⛔ IT MUST NOT BE APPLIED TWICE — a second pass would subtract the offset again and the data
 * would be silently destroyed — so run-once migration bookkeeping is NOT what is trusted here.
 * The gate is a WITNESS: `webhook_events` holds a DB-written and a PHP-written timestamp in the
 * SAME ROW, written by the same INSERT, so their difference IS the skew. No skew anywhere ⇒
 * already corrected (or never skewed, e.g. a UTC host) ⇒ this migration does nothing at all.
 * A partial failure is covered by the same gate plus the explicit transaction below: a throw
 * rolls the whole pass back and leaves no migration record, so the re-run re-measures.
 */
return new class extends Migration
{
    /**
     * Below this many seconds of DB-written vs PHP-written difference, a row is "aligned".
     * Generous by three orders of magnitude at both ends: the two columns are filled by one
     * INSERT so a correct pair differs by microseconds, while the smallest UTC offset any
     * zone has ever used is 15 minutes.
     */
    private const ALIGNED_TOLERANCE_S = 60;

    /**
     * The PHP-written timestamp columns, per table.
     *
     * ⛔ `webhook_events.received_at` is deliberately ABSENT — see the class docblock. Adding a
     * timestamp column to this schema does not retroactively join this list; it is a snapshot
     * of what was skewed before the pin landed, not a live census.
     *
     * @var array<string, list<string>>
     */
    private const PHP_WRITTEN = [
        'webhook_events' => ['created_at', 'updated_at'],
        'agent_dispatches' => ['processed_at', 'created_at', 'updated_at'],
        'writeback_board_divergences' => ['created_at', 'last_seen_at'],
        'board_tools_client_calls' => ['created_at', 'last_success_at'],
        'scheduled_jobs' => ['last_run_at', 'next_due_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(toUtc: true);
    }

    /**
     * ⛔ ROLLING BACK RE-INTRODUCES THE SKEW, on purpose. `down()` is the exact inverse, because
     * the alternative — leaving corrected data behind while the config pin is reverted — reads
     * every PHP-written column an offset BEHIND, which is a third state neither half of this
     * change describes. Rolling this back is only correct together with reverting the
     * `timezone` key in `config/database.php`, and its own witness gate stops it running
     * against data that is already skewed.
     */
    public function down(): void
    {
        $this->shift(toUtc: false);
    }

    /**
     * @param  bool  $toUtc  true = up (skewed → true instant), false = down (the inverse)
     */
    private function shift(bool $toUtc): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // SQLite (the test driver) stores the literal string it was handed and has no
            // session zone to reinterpret it with, so nothing was ever skewed there. Postgres
            // is not a supported backend for this app.
            return;
        }

        $expression = $toUtc
            ? "CONVERT_TZ(%s, '+00:00', 'SYSTEM')"
            : "CONVERT_TZ(%s, 'SYSTEM', '+00:00')";

        $previousZone = $this->sessionZone();

        try {
            // Pin the frame the correction is reasoned in, rather than inheriting whatever the
            // connection happens to carry: this runs at deploy time, where whether the new
            // `timezone` key is live yet depends on config-cache state the migration cannot see.
            DB::statement("SET SESSION time_zone = '+00:00'");

            if (DB::selectOne("SELECT CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', 'SYSTEM') AS v")?->v === null) {
                // CONVERT_TZ yields NULL for a zone it cannot resolve. Refusing is the only safe
                // answer: the alternative is writing NULL over every timestamp in the schema.
                throw new RuntimeException(
                    'card#8825 correction refused: this server cannot resolve its own SYSTEM time zone through '
                    ."CONVERT_TZ, so the per-row offset is not derivable. Load the server's time-zone tables "
                    .'(mariadb-tzinfo-to-sql / mysql_tzinfo_to_sql) and re-run the migration.'
                );
            }

            $this->apply($expression, $toUtc);
        } finally {
            DB::statement("SET SESSION time_zone = '".$previousZone."'");
        }
    }

    private function apply(string $expression, bool $toUtc): void
    {
        $skewPredicate = 'ABS(TIMESTAMPDIFF(SECOND, received_at, created_at)) > '.self::ALIGNED_TOLERANCE_S;
        // `up` corrects the rows that are still skewed; `down` re-skews the rows that are not.
        $wanted = $toUtc ? $skewPredicate : 'NOT ('.$skewPredicate.')';

        $witness = DB::table('webhook_events')
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN '.$wanted.' THEN 1 ELSE 0 END) AS wanted')
            ->first();

        $total = (int) ($witness?->total ?? 0);
        $subject = (int) ($witness?->wanted ?? 0);

        if ($total === 0) {
            $this->reportWitnessless($toUtc);

            return;
        }

        if ($subject === 0) {
            $this->say('card#8825: webhook_events reports no rows needing this pass — nothing written.');

            return;
        }

        // Captured BEFORE any write, because the webhook_events pass destroys the very signal
        // this reads: a dispatch is inserted in the same request as its event, so the last
        // affected event id bounds the dispatch rows written under the same connection era.
        $boundaryEventId = (int) DB::table('webhook_events')->whereRaw($wanted)->max('id');

        $touched = [];

        DB::transaction(function () use ($expression, $wanted, $boundaryEventId, &$touched): void {
            foreach (self::PHP_WRITTEN as $table => $columns) {
                $set = implode(', ', array_map(
                    fn (string $column): string => $column.' = '.sprintf($expression, $column),
                    $columns,
                ));

                $where = match ($table) {
                    // Per-row and exact: the witness lives in the row itself.
                    'webhook_events' => $wanted,
                    'agent_dispatches' => 'webhook_event_id <= '.$boundaryEventId,
                    // ⚠ No witness column and no relation to one. These three are corrected
                    // wholesale on the install-wide verdict above, which is sound for every row
                    // written before the deploy and wrong for any row this app writes BETWEEN
                    // the config pin going live and this migration running. That window is why
                    // CLAUDE_DEPLOYMENT.md tells the operator to migrate before restarting.
                    default => '1 = 1',
                };

                $touched[$table] = DB::update('UPDATE '.$table.' SET '.$set.' WHERE '.$where);
            }
        });

        $this->say('card#8825: '.($toUtc
            ? 'corrected PHP-written timestamps to UTC'
            : 'reverted PHP-written timestamps to the pre-pin representation')
            .' — '.json_encode($touched).' (webhook_events.received_at untouched).');
    }

    /**
     * No `webhook_events` row means no in-row witness, and there is no second way to tell a
     * corrected install from a never-skewed one. Guessing is the one thing that could destroy
     * data here, so the pass declines and NAMES what it left alone rather than blocking a
     * deploy over rows that carry, at worst, a freshness stamp off by the host's offset.
     */
    private function reportWitnessless(bool $toUtc): void
    {
        $remaining = [];

        foreach (array_keys(self::PHP_WRITTEN) as $table) {
            $rows = (int) DB::table($table)->count();
            if ($rows > 0) {
                $remaining[$table] = $rows;
            }
        }

        if ($remaining === []) {
            return;
        }

        $message = 'card#8825: webhook_events is empty, so this install carries no witness for whether its '
            .'PHP-written timestamps are skewed. NOT '.($toUtc ? 'corrected' : 'reverted').': '
            .json_encode($remaining).'. What is left standing is a first-seen/last-seen stamp read as an AGE '
            .'(bridge:check) and a scheduler due-time that the first pass of each job rewrites correctly — '
            .'correct them by hand if the offset matters to you.';

        $this->say($message);
    }

    /**
     * The session zone to restore, constrained to the shapes a server actually reports
     * (`SYSTEM`, `+00:00`, `Europe/Berlin`) — the value is interpolated back into a `SET`, and a
     * validated read is cheaper than trusting one.
     */
    private function sessionZone(): string
    {
        $zone = (string) (DB::selectOne('SELECT @@session.time_zone AS tz')?->tz ?? 'SYSTEM');

        return preg_match('#^[A-Za-z0-9_+:/-]{1,64}$#', $zone) === 1 ? $zone : 'SYSTEM';
    }

    /**
     * Every verdict this pass reaches goes to BOTH surfaces from one place. The log is the
     * durable record; the console copy is for the operator running `php artisan migrate`, who is
     * the one person who must see what a pass that rewrites stored data touched. ⚑ `STDOUT` is
     * defined only under the CLI SAPI, and `Artisan::call('migrate')` from a served request is a
     * real Laravel path even though nothing in this app takes it — there, the log is the whole
     * record rather than half of it.
     */
    private function say(string $line): void
    {
        Log::info($line);

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, '  '.$line.PHP_EOL);
        }
    }
};
