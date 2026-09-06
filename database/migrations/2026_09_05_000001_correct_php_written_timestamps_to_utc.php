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
 * ⭐ THE SHIFT IS READ OUT OF THE DATA, NOT OUT OF THE SERVER'S CLOCK, and that is a correction
 * to this migration's first shape rather than a preference. It originally asked the server for
 * its own zone (`CONVERT_TZ(col, '+00:00', 'SYSTEM')`), which is exact and per-instant — and
 * which is the IDENTITY on a server whose zone is UTC. Measured on a MariaDB 11 container
 * running UTC: the pass reported "corrected" and wrote zero rows against data that was skewed
 * by four hours. The offset that matters is the one that was live WHEN EACH ROW WAS WRITTEN,
 * and the server's clock is only a proxy for it — a proxy that is silently wrong on any host
 * that has since moved to UTC, which is exactly the host an operator gets after fixing this
 * class of bug at the OS level first. `webhook_events` carries the real evidence in-row:
 * `received_at` is DB-written and true, `created_at` is PHP-written and skewed, one INSERT
 * wrote both, so their difference IS the offset that was live for that row. That is what is
 * applied, and it is right on a UTC host, an EDT host, and a host whose zone changed in between.
 *
 * ⛔ IT MUST NOT BE APPLIED TWICE — a second pass would subtract the offset again and the data
 * would be silently destroyed — so run-once migration bookkeeping is NOT what is trusted here.
 * The same witness is the gate: no disagreement anywhere ⇒ already corrected (or never skewed,
 * e.g. an install whose host always ran UTC) ⇒ this migration does nothing at all. A partial
 * failure is covered by the same gate plus the explicit transaction below: a throw rolls the
 * whole pass back and leaves no migration record, so the re-run re-measures.
 *
 * ⛔ AND IT IS NOT REVERSIBLE, because the evidence it reads is exactly what it consumes — see
 * `down()`. That is why the upgrade note in `CLAUDE_DEPLOYMENT.md` asks for a backup.
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

    /**
     * The per-row skew, in seconds, ROUNDED TO THE MINUTE.
     *
     * ⚑ The rounding is load-bearing, not tidiness. `received_at` is `timestamp(3)` and
     * `created_at` is `timestamp(0)`, so `TIMESTAMPDIFF` truncates a sub-second fraction and one
     * install's rows come back as a MIX of 14399 and 14400 — which the distinct-offset check
     * below would read as two different zone offsets and refuse on. A real UTC offset is a whole
     * number of minutes, so rounding to the minute is lossless for the quantity being measured
     * and removes the artefact.
     */
    private const SKEW_S = 'ROUND(TIMESTAMPDIFF(SECOND, received_at, created_at) / 60.0) * 60';

    public function up(): void
    {
        if (! $this->onMySql()) {
            // SQLite (the test driver) stores the literal string it was handed and has no
            // session zone to reinterpret it with, so nothing was ever skewed there. Postgres
            // is not a supported backend for this app.
            return;
        }

        $offsets = $this->observedOffsets();

        if ($offsets === []) {
            $this->reportNothingToDo();

            return;
        }

        if (count($offsets) > 1) {
            // ⛔ Refusing is the answer, not a best guess. More than one offset means this
            // install's history spans a zone transition (a DST boundary, or an operator who
            // moved the host), so no single shift is right for the whole table — and a shift
            // that is wrong for part of it destroys those rows exactly as silently as the bug
            // did. Per-row repair from the witness is possible for the two tables that carry
            // one and impossible for the other three, which is a different change and a
            // different decision; this pass says so instead of half-making it.
            throw new RuntimeException(
                'card#8825 correction refused: webhook_events reports MORE THAN ONE distinct clock offset — '
                .json_encode($offsets).' (seconds => rows) — so this install\'s history spans a time-zone '
                .'transition and no single shift is correct for all of it. Prune below the transition and '
                .'re-run, or repair per row by hand; this pass will not guess.'
            );
        }

        $shift = (int) array_key_first($offsets);
        $boundaryEventId = (int) DB::table('webhook_events')->whereRaw($this->skewedPredicate())->max('id');
        $touched = [];

        // ⚑ The arithmetic below happens in the SESSION's zone, so pin it to a zone with no DST
        // discontinuities for the duration rather than inheriting whatever the connection carries
        // — at deploy time, whether the new `timezone` key is live yet depends on config-cache
        // state this migration cannot see, and a shift that steps over a transition in local time
        // lands an hour out.
        $previousZone = $this->sessionZone();
        DB::statement("SET SESSION time_zone = '+00:00'");

        try {
            $this->rewrite($shift, $boundaryEventId, $touched);
        } finally {
            DB::statement("SET SESSION time_zone = '".$previousZone."'");
        }

        $this->say(
            'card#8825: corrected PHP-written timestamps by '.(-$shift).'s (read from the data, not from this '
            .'server\'s clock) — '.json_encode($touched).' (webhook_events.received_at untouched).'
        );
    }

    /**
     * @param  array<string, int>  $touched  filled with the rows each table actually changed
     */
    private function rewrite(int $shift, int $boundaryEventId, array &$touched): void
    {
        DB::transaction(function () use ($shift, $boundaryEventId, &$touched): void {
            foreach (self::PHP_WRITTEN as $table => $columns) {
                // Every right-hand side reads only its OWN column, so the statement is
                // independent of the order MySQL applies the assignments in.
                $set = implode(', ', array_map(
                    fn (string $c): string => $c.' = '.$c.' - INTERVAL '.$shift.' SECOND',
                    $columns,
                ));

                $where = match ($table) {
                    // Per-row and exact: the witness lives in the row itself, so a row already
                    // corrected — or written after the pin went live — is not touched.
                    'webhook_events' => $this->skewedPredicate(),
                    // A dispatch is inserted in the same request as its event, so the last
                    // skewed event id bounds the dispatch rows written under the same connection
                    // era. Captured before any write, because the pass above destroys the signal.
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
     * ⛔ NOT REVERSIBLE, AND THE REFUSAL IS THE HONEST ANSWER. `up()` reads the offset out of the
     * disagreement between a DB-written and a PHP-written timestamp, and repairing the rows is
     * what REMOVES that disagreement — so after it runs there is nothing left in the data to
     * derive the inverse from. Re-deriving it from the server's own zone is precisely the shape
     * this migration was corrected away from: it is the identity on a UTC host, so a rollback
     * would silently do nothing while reporting success. Restore from the backup the upgrade
     * note asks for, and revert the `timezone` key in `config/database.php` with it.
     */
    public function down(): void
    {
        if (! $this->onMySql()) {
            return;
        }

        throw new RuntimeException(
            'card#8825 correction cannot be rolled back: it consumed the evidence it was derived from (the '
            .'in-row disagreement between webhook_events.received_at and created_at), and re-deriving the '
            .'offset from this server\'s clock is the defect this migration was corrected away from. Restore '
            .'the database from the pre-upgrade backup and revert config/database.php\'s `timezone` key together.'
        );
    }

    /**
     * The clock offsets this install's own rows disclose, as `seconds => row count`, over the
     * rows that are still skewed. Empty when nothing is skewed; more than one entry when the
     * history spans a zone transition.
     *
     * @return array<int, int>
     */
    private function observedOffsets(): array
    {
        $rows = DB::table('webhook_events')
            ->selectRaw(self::SKEW_S.' AS shift_s, COUNT(*) AS rows_n')
            ->whereRaw($this->skewedPredicate())
            ->groupBy('shift_s')
            ->get();

        $offsets = [];

        foreach ($rows as $row) {
            $offsets[(int) $row->shift_s] = (int) $row->rows_n;
        }

        return $offsets;
    }

    private function skewedPredicate(): string
    {
        return 'ABS('.self::SKEW_S.') > '.self::ALIGNED_TOLERANCE_S;
    }

    /**
     * Nothing skewed. Either this install was never affected (its host always ran UTC), or the
     * correction has already run — the two are indistinguishable in the data and take the same
     * action, which is none. The one case worth a word is an install with no `webhook_events`
     * row at all, because then the verdict rests on no evidence and the witness-less tables keep
     * whatever they hold.
     */
    private function reportNothingToDo(): void
    {
        if ((int) DB::table('webhook_events')->count() > 0) {
            $this->say('card#8825: webhook_events discloses no clock offset — nothing written.');

            return;
        }

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

        $this->say(
            'card#8825: webhook_events is empty, so this install carries no witness for whether its PHP-written '
            .'timestamps are skewed. NOT corrected: '.json_encode($remaining).'. What is left standing is a '
            .'first-seen/last-seen stamp read as an AGE (bridge:check) and a scheduler due-time that the first '
            .'pass of each job rewrites correctly — correct them by hand if the offset matters to you.'
        );
    }

    private function onMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
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
