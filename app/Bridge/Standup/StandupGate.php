<?php

namespace App\Bridge\Standup;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the PM standup digest off the inbound webhook, after the response is already sent
 * (DL-306) — the same gate shape retention uses (DL-199), and for the same reason.
 *
 * The card that asked for this called it a cron. It is not one, deliberately: DL-012
 * shipped a command and scheduled it NOWHERE — three installs, ~45 days, zero runs — and
 * DL-199's answer was to remove the cron exception rather than add a second daemon. So
 * the four properties that make that safe are inherited verbatim and each is
 * load-bearing: AFTER-RESPONSE (a terminating callback, so the 200 is on the wire first),
 * INTERVAL-GATED (at most one pass per `interval`), NON-BLOCKING LOCK (a concurrent
 * receive that loses skips instantly — a blocking lock here would queue every delivery
 * behind a board read, the DL-001 latency regression), and NEVER THROWS (a 5xx makes the
 * provider redeliver, which under load compounds whatever broke).
 *
 * ⚠ WHAT THAT COSTS, STATED RATHER THAN DISCOVERED: the pass fires on the first delivery
 * AFTER the interval lapses, so an install receiving no webhooks pushes no digest, and a
 * quiet stretch delays one. That is a delivery cadence, not a wall clock. An operator who
 * needs a wall clock runs `bridge:standup` from their own scheduler — the two are
 * idempotent, and neither infers anything about a seat's state.
 *
 * ⭐ card#8425 / DL-325 GAVE THAT COST A SECOND ANSWER, and this gate is unchanged by it.
 * The periodic-job registry ships a `standup_digest` handler
 * (`App\Bridge\Scheduling\Handlers\StandupDigestJob`) that calls {@see self::runPass()}
 * from `bridge:tick`, so an install that adopted the tick gets the digest on a wall clock
 * without a second scheduler and without this gate behaving any differently. Both ingresses
 * share `MARKER_KEY`, so the digest is still pushed at most once per `standup.interval`
 * however many things asked.
 *
 * ⚠ ONE COST THIS GATE PAYS THAT RETENTION'S DOES NOT: a pass makes OUTBOUND HTTP calls
 * (a board read per mapped board, then the channel push). Each is bounded by
 * `KanbanHttpClient::TIMEOUT_SECONDS` and the client's page ceiling, all of it after the
 * response and at most once per interval — but an unreachable kanban does hold the FPM
 * worker for that timeout before the pass gives up. It also reads the inbox once PER SEAT,
 * and under the default `shared` layout that is one whole-file read each (bounded by
 * retention, and by the fact that a multi-agent install is the one documented to run the
 * `per-agent` layout, where each read is that seat's own file). That, and not caution, is
 * why the digest is OFF by default where retention is on: it is opt-in per install.
 */
final class StandupGate
{
    private const LOCK_KEY = 'bridge:standup:lock';

    private const MARKER_KEY = 'bridge:standup:last-run';

    /**
     * A pass that THREW records itself here. Public for the same reason retention's is:
     * the key must have exactly one home, and a caught throw that left only a log line
     * would rebuild DL-012 — the marker-before-push back-off means a persistently failing
     * push retries at most once per interval, so the seat simply stops receiving digests
     * with nothing anywhere saying so. A clean pass forgets it.
     */
    public const ERROR_KEY = 'bridge:standup:last-error';

    /** Floor on the last-error marker's lifetime; a longer interval widens it. */
    private const ERROR_TTL = 2592000; // 30 days

    /** Ceiling on how long one pass may hold the lock before it is presumed dead. */
    private const LOCK_TTL = 300;

    public function __construct(
        private readonly Application $app,
        private readonly StandupService $standup,
    ) {}

    /**
     * Queue a standup pass to run after this request's response has been sent. Cheap and
     * side-effect-free when the digest is off: no callback is registered.
     */
    public function schedule(): void
    {
        if (! (bool) config('bridge.standup.enabled')) {
            return;
        }

        $this->app->terminating(fn () => $this->runSafely());
    }

    private function runSafely(): void
    {
        try {
            $this->runPass();
        } catch (\Throwable $e) {
            Cache::put(self::ERROR_KEY, [
                'at' => now()->toIso8601String(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ], max(self::ERROR_TTL, (int) config('bridge.standup.interval') + 3600));
            Log::warning('standup pass failed', [
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ONE PASS, TWO INGRESSES — public since card#8425/DL-325 so the periodic-job registry's
     * `standup_digest` handler can drive the SAME pass from `bridge:tick`, rather than a
     * second copy of it that would push a second digest.
     *
     * ⭐ THE INTERVAL MARKER IS WHAT MAKES THAT SAFE, and it is the reason this is the pass
     * and not the gate that got exposed: whichever ingress arrives first inside an interval
     * pushes, and the other no-ops on the same `MARKER_KEY`. A tick asking every 10 minutes
     * and a delivery gate asking on every webhook still produce one digest per
     * `standup.interval`.
     *
     * ⚠ IT THROWS. The caller owns the recording: the gate wraps it in
     * {@see self::runSafely()} (which records to {@see self::ERROR_KEY}), and the scheduler
     * records a throw on the job's own registry row instead — a strictly better surface,
     * because it is enumerable and per-instance.
     */
    public function runPass(): void
    {
        // The due-check lives INSIDE the lock and only there: a cheaper pre-lock check
        // buys a race where two receives both read "due" and the loser pushes a second
        // digest the moment the winner releases.
        Cache::lock(self::LOCK_KEY, self::LOCK_TTL)->get(function () {
            if (Cache::has(self::MARKER_KEY)) {
                return;
            }

            $cfg = StandupConfig::fromConfig();

            if (! $cfg->isUsable()) {
                // Back off a full day before complaining again: this runs per webhook, and
                // a config mistake must not turn every delivery into a log line.
                Cache::put(self::MARKER_KEY, true, 86400);
                Log::warning('standup is enabled but misconfigured; nothing pushed', [
                    'problem' => $cfg->problem,
                ]);

                return;
            }

            // Mark BEFORE pushing, so a pass that throws backs off a full interval instead
            // of retrying on every subsequent delivery — and so a push that half-succeeded
            // cannot be re-sent by the next delivery.
            Cache::put(self::MARKER_KEY, true, $cfg->interval);

            $digest = $this->standup->build();
            $this->standup->push($digest, (string) $cfg->agent);

            Cache::forget(self::ERROR_KEY);

            // A pass that leaves no trace cannot be caught doing nothing (DL-012). The
            // counts are of what the snapshot COVERED — never a "N stalled" reading.
            Log::info('standup pass', [
                'agent' => $cfg->agent,
                'seats' => count($digest->seats),
                'boards' => count($digest->boards),
            ]);
        });
    }
}
