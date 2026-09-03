<?php

namespace App\Bridge\Standup;

use App\Bridge\Support\AfterResponseGate;
use Illuminate\Contracts\Foundation\Application;
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
 *
 * ⭐ THE GATE SHAPE ITSELF NOW LIVES IN {@see AfterResponseGate} (card#8432). The interval
 * marker, the non-blocking lock and the never-throws recording were restated by hand here,
 * in `App\Bridge\Retention\RetentionGate` and in `App\Bridge\Scheduling\JobScheduler`, so a
 * defect fixed in one copy survived in the other two. What stays here is what is genuinely
 * the digest's: the posture, the misconfig back-off, and the pass itself.
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

    /**
     * The lock, the interval marker and the fault recording, owned once for every
     * after-response subsystem in this app (card#8432). Constructed here rather than
     * injected: its arguments are this subsystem's own key strings, which the container
     * cannot resolve and no other class has any business supplying.
     */
    private readonly AfterResponseGate $gate;

    public function __construct(
        private readonly Application $app,
        private readonly StandupService $standup,
    ) {
        $this->gate = new AfterResponseGate(
            lockKey: self::LOCK_KEY,
            markerKey: self::MARKER_KEY,
            errorKey: self::ERROR_KEY,
            faultMessage: 'standup pass failed',
            cadenceSeconds: static fn (): int => (int) config('bridge.standup.interval'),
        );
    }

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
        // ⛔ RECORDING THE FAULT MUST NOT RE-RAISE IT — {@see AfterResponseGate::neverThrows()}
        // records through {@see App\Bridge\Support\FaultMarker}, which owns the order (log
        // first, marker second) and guards each leg. This arm used to write the marker to the
        // cache unguarded, so a DEAD CACHE STORE threw straight out of a terminating callback:
        // an unhandled fatal after the response, in the FPM worker, on every delivery, and not
        // one line saying why.
        $this->gate->neverThrows($this->runPass(...));
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
        // The non-blocking lock, the due-check inside it and the marker armed BEFORE the
        // push all belong to the gate primitive now. Marking before the work is what stops a
        // pass that throws retrying on every subsequent delivery, and stops a push that
        // half-succeeded being re-sent by the next one; the interval handed over is the same
        // value {@see StandupConfig::fromConfig()} resolves.
        $this->gate->oncePerInterval((int) config('bridge.standup.interval'), function (): void {
            $cfg = StandupConfig::fromConfig();

            if (! $cfg->isUsable()) {
                // Back off a full DAY rather than the ordinary interval: this runs per
                // webhook, and a config mistake must not turn every delivery into a log line.
                $this->gate->backOff(86400);
                Log::warning('standup is enabled but misconfigured; nothing pushed', [
                    'problem' => $cfg->problem,
                ]);

                return;
            }

            $digest = $this->standup->build();
            $this->standup->push($digest, (string) $cfg->agent);

            $this->gate->clearFault();

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
