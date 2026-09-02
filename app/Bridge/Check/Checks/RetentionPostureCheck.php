<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Retention\RetentionConfig;
use App\Bridge\Retention\RetentionFootprint;
use App\Bridge\Retention\RetentionGate;
use App\Bridge\Retention\RetentionStoreProbe;
use App\Bridge\Support\Finding;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The retention posture leg (DL-199), migrated out of `CheckCommand::handle()`
 * (DL-242 stage 2).
 *
 * Retention is on by default and runs off the receiver, so a bad window is SILENT:
 * the stores just grow, which is the exact DL-012 failure the gate replaced. This
 * check reports the posture rather than letting it go unnoticed.
 *
 * IT REPORTS THE COST, NOT ONLY THE SETTING (card#8374, DL-331). `retention: on (…)`
 * restates the config and nothing else, so a green posture could not tell a bounded
 * store from the measured case that produced the ask — 894 MB of a 1.2 GB store being
 * 30 days of full GitHub payloads, under a retention that was working correctly the
 * whole time. Three legs answer three separate questions, and each is silent about the
 * others':
 *
 *   1. WHAT IS IT HOLDING — the database's size, the payload share of it, and the oldest
 *      retained row's age against the configured delete window. `warn` when that row is
 *      PAST the window, because rows the window should have removed are still here.
 *   2. IS THE ROW LEG OFF — a payload-only install keeps every row for ever.
 *   3. IS THE PAYLOAD LEG OFF — the expensive half, and the one this card exists for:
 *      an install printing `on` while nulling is disabled is exactly the 894 MB case.
 *
 * Legs 2 and 3 are mutually exclusive by construction: both windows null is the
 * MISCONFIGURED arm above, which returns before either is reached.
 *
 * ⛔ A FIELD THE PROBE COULD NOT SOURCE IS ABSENT FROM THE LINE, never inferred and
 * never printed as a zero — DL-306's ruling on the standup digest, which governs here
 * for the same reason. The measurement itself sits behind {@see RetentionStoreProbe}:
 * its subject is the storage ENGINE, so a golden capture that read it live would print
 * a different number on the SQLite job and each MariaDB job.
 *
 * IT NEVER YIELDS `fail`, and that is a property of the subject rather than of this
 * class: every posture it can report is either healthy or an operator-fixable
 * misconfiguration that leaves the receiver serving correctly. The caller still
 * routes its report through the same exit-deciding renderer as every other check —
 * the wiring belongs to the registry's contract, not to today's severity set.
 */
final class RetentionPostureCheck implements Check
{
    public function __construct(private readonly RetentionStoreProbe $store) {}

    public function id(): string
    {
        return 'retention.posture';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $retention = RetentionConfig::fromConfig();

        if (! $retention->enabled) {
            yield Finding::warn('retention: DISABLED (BRIDGE_RETENTION_ENABLED=false) — nothing prunes webhook_events/agent_dispatches/inbox lines unless you schedule bridge:prune yourself; the append-only stores grow without bound (DL-012/DL-199).');

            return;
        }

        if (! $retention->isUsable()) {
            yield Finding::warn('retention: enabled but MISCONFIGURED — '.$retention->problem.'. Nothing is pruned (a bad window never falls back to a default cutoff). The stores grow until fixed.');

            return;
        }

        yield Finding::ok('retention: on ('.$retention->summary().')');

        yield from $this->cost($retention);

        // The whole no-latency claim rests on the response being FINISHED before
        // the terminating callback runs. Under PHP-FPM that is
        // fastcgi_finish_request(); without it (mod_php) Symfony only flushes, so
        // a keep-alive client can sit through the prune. The prune stays correct
        // either way — this degrades latency, silently, which is why it is worth
        // one preflight line.
        //
        // THE LINE IS `unvalidated` AND NOT `warn` BECAUSE NOTHING THIS PROCESS CAN SEE
        // ANSWERS THE QUESTION (card#5698 sub-shape (3)). The subject is the RECEIVER's
        // SAPI, and the only limb below that could answer for it is dead in a console
        // command — so what remains is an indicator, not a fact. See the probe's docblock.
        // It is raised only where that indicator is ABSENT, the one state worth an
        // operator's attention; a FOUND binary is no answer either, and so earns no green
        // line rather than an `ok` disclosing its own blindness (`Severity` corollary (A)).
        if (! $this->earlyFinishIndicated()) {
            yield Finding::unvalidated('retention: could NOT determine whether the receiver ends the request early — bridge:check is a console command, so the SAPI running it is not the receiver\'s and does not define fastcgi_finish_request(), and no php-fpm binary was findable on the PATH this process sees (a healthy FPM install can keep it in /usr/sbin, off a non-root PATH), so this run is no evidence either way. If the receiver is NOT served under PHP-FPM, retention runs AFTER the response is flushed but BEFORE the request ends, so a keep-alive client may wait for it. Confirm how the receiver is served (see CLAUDE_DEPLOYMENT.md); if it is not PHP-FPM, either serve it under PHP-FPM or set BRIDGE_RETENTION_ENABLED=false and run bridge:prune on a schedule.');
        }

        // Config being valid does NOT mean retention is RUNNING. A pass that throws
        // (unwritable inbox, ENOSPC, a DB fault) backs off a full interval and drains
        // nothing, leaving the posture line above reading healthy — the DL-012 blind
        // spot. The gate records its last throw here; surface it (cleared automatically
        // on the next successful pass). The catch stays with the check that needs it:
        // CheckRunner deliberately does not isolate, so hoisting this would turn an
        // unreachable cache backend into an aborted `bridge:check`.
        try {
            $lastError = Cache::get(RetentionGate::ERROR_KEY);
            if (is_array($lastError)) {
                // Deliberately does NOT assert the stores are growing: on a since-quieted
                // install nothing arrives, so nothing grows — the marker can outlive the
                // condition (webhook-driven clear, ≤30d TTL). State the fact (last pass
                // failed, nothing pruned since) and let the timestamp speak.
                yield Finding::warn('retention: the LAST PASS FAILED and nothing has pruned since ('
                    .($lastError['exception'] ?? 'error').': '.($lastError['error'] ?? '')
                    .' at '.($lastError['at'] ?? '?').'). Check DB/file permissions and disk space; if traffic has since resumed, watch the log for a clean `retention pass` (the marker clears itself on the next success).');
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated('retention: could not read the last-failure marker ('.$e->getMessage().') — the cache backend the retention gate depends on may be unreachable.');
        }
    }

    /**
     * The three cost legs, in output order. Reached only from the healthy posture path,
     * so every window this reads has already been validated.
     *
     * ONE `catch`, AT THE TOP, AND NOTHING BELOW IT RUNS. A store measurement that threw
     * leaves nothing partial worth printing: the row counts are the denominator of every
     * other clause, so an arm that reported "nulling is off" without them would be the
     * bare setting again. `CheckRunner` deliberately does not isolate, so an uncaught
     * throw here would abort the whole command over a database this install's own
     * connectivity check has already reported on.
     *
     * @return iterable<Finding>
     */
    private function cost(RetentionConfig $retention): iterable
    {
        try {
            $store = $this->store->measure();
        } catch (Throwable $e) {
            yield Finding::unvalidated('retention: could NOT measure what the store is holding ('.$e->getMessage().') — so this run says nothing about how much retention is holding back, and the posture line above is evidence about the CONFIG only.');

            return;
        }

        yield $this->storeLine($retention, $store);

        if ($retention->olderThanDays === null) {
            yield Finding::warn('retention: the ROW-DELETE leg is OFF (retention.older_than is empty) — webhook_events rows and their agent_dispatches are NEVER deleted and grow without bound; only the payload leg runs, so an old row keeps its metadata for ever. '
                .$store->rows.' row(s) retained now. Set BRIDGE_RETENTION_OLDER_THAN (e.g. 30d) and re-run `php artisan config:cache`.');
        } elseif ($retention->nullPayloadsOlderThanDays === null) {
            yield Finding::warn('retention: payload NULLING is OFF (retention.null_payloads_older_than is empty) — '
                .$this->nullingOffCost($retention->olderThanDays, $store)
                .' Set BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN (the shipped default is 7d) and re-run `php artisan config:cache`; it IS the bridge:replay window, so choose it for how long you may need to replay.');
        }
    }

    /**
     * What the store holds, and whether its oldest row is still inside the delete window.
     *
     * ONE LEG, TWO SEVERITIES, because it is one question — *is this store bounded* — and
     * the age is what answers it. Splitting the age off would print it twice on the only
     * install shape where it matters.
     */
    private function storeLine(RetentionConfig $retention, RetentionFootprint $store): Finding
    {
        $size = $store->storeBytes === null
            ? 'database size not reported by this driver'
            : 'database '.self::bytes($store->storeBytes);

        // THE EMPTY STORE IS KEYED ON THE AGE, NOT ON THE ROW COUNT, and they are the same
        // question: `received_at` is NOT NULL, so the probe's `min()` is absent exactly
        // when there is no row. Asking the age lets the rest of this method read a
        // non-null age without a fallback that would print a fabricated `0.0d`.
        $age = $store->oldestRowAgeDays;
        if ($age === null) {
            return Finding::ok('retention: '.$size.' · webhook_events is EMPTY ('.$store->rows.' rows) — retention is holding nothing back.');
        }

        $line = 'retention: '.$size.' · webhook_events '.$store->rows.' rows, '.$this->payloadShare($store)
            .' · oldest row '.self::days($age);

        if ($retention->olderThanDays === null) {
            return Finding::ok($line.' old (no delete window — see the line below).');
        }
        if ($age <= $retention->olderThanDays) {
            return Finding::ok($line.' old, inside the '.$retention->olderThanDays.'d delete window.');
        }

        return Finding::warn($line.' old, PAST the '.$retention->olderThanDays.'d delete window — rows the window should have removed are still here. A pass deletes at most '
            .$retention->batch.' rows, so a backlog drains over successive deliveries; if this age does not fall between runs the delete leg is not running, and `php artisan bridge:prune --older-than='
            .$retention->olderThanDays.'d` drains it in one unbounded pass.');
    }

    /**
     * The payload clause of the store line — the share is dropped, never guessed, when
     * either term is absent OR when the two terms disagree.
     *
     * ⛔ THE SHARE IS A QUOTIENT ACROSS TWO ACCOUNTING BASES, SO IT IS PRINTED ONLY WHERE
     * THE PROBE SAYS THEY ARE ONE. The numerator is a LIVE byte sum this app scans out of
     * its own rows; the denominator is whatever the ENGINE reports for the database. On
     * SQLite the first is inside the second by construction (`page_count * page_size` is
     * the whole file). On MariaDB it is NOT, measured rather than reasoned: off-page
     * payload bytes are in neither `data_length` nor `index_length`
     * ({@see RetentionFootprint}, DL-331). So the check consults the probe's declaration
     * instead of dividing and hoping.
     *
     * ⛔ THE DANGEROUS FAILURE IS THE PLAUSIBLE ONE, NOT THE 234%. An unbounded quotient
     * printing `~234% of the database` is at least obviously wrong. An install whose
     * payloads STRADDLE the inline-row limit gets a believable percentage computed over a
     * denominator holding only some of its own numerator — a capacity figure nothing
     * flags, on a line whose whole purpose is capacity. Both byte figures still print
     * either way, because both are measurements; it is only their ratio that is not one.
     *
     * Neither withholding clause is a `warn`: the store's posture is what the age verdict
     * decides, and how an engine accounts for its own pages says nothing about whether
     * retention is bounded.
     */
    private function payloadShare(RetentionFootprint $store): string
    {
        if ($store->payloadBytes === null) {
            return $store->rowsWithPayload.' still carry a payload (byte size not measurable on this database driver)';
        }
        $held = $store->rowsWithPayload.' still carry a payload holding '.self::bytes($store->payloadBytes);

        if ($store->storeBytes === null) {
            return $held;
        }
        if (! $store->storeBytesContainsPayloadBytes) {
            return $held.' (share of the database NOT shown: this engine reports its size without the payload bytes it stores off-page, so the two figures are not on one basis — compare the payload figure against this TABLE\'s own tablespace file, webhook_events.ibd under the default innodb_file_per_table=ON, and not against the whole database directory, which also holds redo/undo, binlogs and every other schema; CLAUDE_DEPLOYMENT.md "What bridge:check reports about retention" covers the shared-tablespace case)';
        }
        // The invariant on the OUTPUT, kept even though the declaration above should make
        // it unreachable: the size is a number an engine hands us, and no share above
        // 100% may reach an operator whatever an engine reports or a future arm declares.
        if ($store->payloadBytes > $store->storeBytes) {
            return $held.' (MORE than the database size above, so no share is shown — the payload sum is a live scan of the rows and the size is the engine\'s own accounting, and the two do not have to agree)';
        }

        return $held.' (~'.(int) round($store->payloadBytes / $store->storeBytes * 100).'% of the database)';
    }

    /** The cost half of the nulling-OFF warning — the sentence the 894 MB install never got. */
    private function nullingOffCost(int $rowWindowDays, RetentionFootprint $store): string
    {
        $horizon = 'kept until the '.$rowWindowDays.'d row-delete window removes the row.';

        if ($store->rowsWithPayload === 0) {
            return 'no retained row carries a payload yet, so nothing has accrued — but every payload from the next delivery on is '.$horizon;
        }

        return $store->rowsWithPayload.' of '.$store->rows.' retained rows still carry a full webhook payload'
            .($store->payloadBytes === null ? ' (byte size not measurable on this database driver)' : ', holding '.self::bytes($store->payloadBytes))
            .', and nothing will null them: each is '.$horizon;
    }

    /**
     * Bytes in the units an operator's `du -h` prints — 1024-based, and LABELLED as such,
     * because a `MB` that is really a MiB is the kind of quiet 5% error a capacity
     * decision is made on.
     */
    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        return $unit === 0 ? $bytes.' B' : sprintf('%.1f %s', $value, $units[$unit]);
    }

    /** One decimal, so a store younger than a day reads as an age rather than as `0d`. */
    private static function days(float $days): string
    {
        return sprintf('%.1fd', $days);
    }

    /**
     * Whether any indication is available HERE that the receiver's PHP can end a request
     * before running terminating callbacks — deliberately not whether it can.
     *
     * IT DOES NOT ANSWER THE QUESTION THE CALLER ASKS, and the name now says so. The
     * previous name (`receiverSapiFinishesEarly()`) claimed it did, and its docblock said
     * this probed "the fpm binary's own module list" — no module list has ever been read,
     * by this method or any other. The subject that matters is the RECEIVER's process,
     * and `bridge:check` is a console command that never runs as it: the same subject
     * discriminator DL-260 applied to the channel token and DL-254 to the board-tools
     * resolver.
     *
     * Two limbs, and they are not the same kind of evidence:
     *
     *  - `function_exists()` is AUTHORITATIVE WHEN TRUE, because the process asking is
     *    the process answering. It is unreachable today — every caller is `bridge:check`
     *    and the CLI SAPI never defines the function — and it is kept precisely because
     *    it is the only limb whose subject is right: a check ever run inside the
     *    receiver's own SAPI is answered correctly by it and wrongly by the one below.
     *  - `ExecutableFinder` only asks whether a php-fpm binary is FINDABLE on
     *    `getenv('PATH')`. That is an indicator about this process's environment, never a
     *    fact about the receiver, and it is weak in BOTH directions: a healthy FPM
     *    install can keep the binary in `/usr/sbin`, off a non-root login PATH (measured
     *    on the reference host — it serves the receiver under FPM and still finds nothing
     *    from a shell whose PATH omits `/usr/sbin`), and a findable binary does not mean
     *    the receiver is served by it.
     *
     * ExecutableFinder resolves from `getenv('PATH')` only, which is what makes this
     * host read pinnable from a test — the golden harness's `PinnedHost` (NAMED, never
     * imported: app code must not depend on the suite) points PATH at a fixture bin dir
     * to control the answer.
     */
    private function earlyFinishIndicated(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return true;   // running under FPM already
        }
        foreach (['php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'php-fpm'] as $bin) {
            $path = (new ExecutableFinder)->find($bin);
            if ($path !== null) {
                return true;
            }
        }

        return false;
    }
}
