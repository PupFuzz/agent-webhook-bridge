<?php

namespace App\Bridge\Retention;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs retention off the inbound webhook, after the response is already sent (DL-199).
 *
 * DL-012 shipped `bridge:prune` and scheduled it nowhere — three installs, ~45 days,
 * zero prunes. This is the fix, and it is deliberately NOT a cron: `webhook_events`
 * grows only when a webhook arrives, and this gate is evaluated when a webhook
 * arrives, so the creator is the gate-evaluator. An install that receives nothing
 * accrues nothing and needs no prune.
 *
 * Four properties are load-bearing, not niceties — each one is a way this re-creates
 * the DL-001 latency regression it must not:
 *  - AFTER-RESPONSE. Registered as a terminating callback, which Laravel runs after
 *    Symfony's Response::send() has already called fastcgi_finish_request(). The
 *    client's 200 is on the wire before any of this executes.
 *  - INTERVAL-GATED. In the drained steady state at most one request per `interval`
 *    does any work at all.
 *  - BOUNDED. One pass touches at most `batch` rows per leg, so the DB work is a
 *    small chunk rather than a 20k-row DELETE. Honest caveat: `batch` does NOT bound
 *    the inbox trim, which is a whole-file rewrite — measured ~2s on a 45MB inbox.
 *    It is streamed (peak memory is one line, not the file) and only runs on a pass
 *    that actually drops a line, so it is paid once and then stays cheap.
 *  - NON-BLOCKING LOCK. A concurrent receive that loses the lock skips instantly. A
 *    BLOCKING lock here would queue every concurrent receive behind the pruner, which
 *    is precisely the latency regression DL-001 forbids. Never make this one block.
 *
 * It also NEVER throws: a retention failure must not fail a webhook, because a 5xx
 * makes the provider redeliver, which under load makes the problem worse. (A PHP
 * FATAL is not a Throwable and would still escape — which is exactly why the inbox
 * trim streams instead of slurping: an OOM there would be uncatchable, and would
 * strand the pass AFTER its back-off marker was set.)
 *
 * Two environment constraints this design depends on, stated so they are not
 * rediscovered the hard way:
 *  - **PHP-FPM.** The after-response property is `fastcgi_finish_request()`. Without
 *    it (mod_php) Symfony flushes but does not end the request, so a keep-alive
 *    client can wait out the prune. Correctness is unaffected; `bridge:check` warns.
 *  - **Process-per-request.** `Application::terminate()` does not clear its
 *    terminating callbacks, so under a persistent-worker runtime (Octane/Swoole) this
 *    would accumulate one callback per request and re-run earlier ones. The bridge
 *    documents FPM only and ships no Octane; this is why that matters.
 */
final class RetentionGate
{
    private const LOCK_KEY = 'bridge:retention:lock';

    private const MARKER_KEY = 'bridge:retention:last-run';

    /**
     * A prune that THREW records itself here so `bridge:check` can surface it. Public
     * because the preflight reads it and the key must have exactly one home (canon #5).
     * Presence ⇒ the last attempted pass failed and nothing has pruned since; a clean
     * pass forgets it. Without this a persistently-throwing prune backs off a full
     * interval on each caught throw and drains nothing forever, leaving only a warning
     * in an untailed log while the preflight still reports `retention: on` — DL-012's
     * silent inertness rebuilt for every catchable failure.
     */
    public const ERROR_KEY = 'bridge:retention:last-error';

    /**
     * Floor on the last-error marker's lifetime. It must outlive the interval back-off
     * so `bridge:check` still sees a persistent failure between the once-per-interval
     * retries; a longer configured interval widens it (see {@see runSafely}).
     */
    private const ERROR_TTL = 2592000; // 30 days

    /**
     * Ceiling on how long one bounded pass may hold the lock before it is presumed
     * dead and released. Only relevant if a worker is killed mid-pass; a pass that
     * takes anywhere near this is already pathological.
     */
    private const LOCK_TTL = 300;

    public function __construct(
        private readonly Application $app,
        private readonly RetentionService $retention,
    ) {}

    /**
     * Queue a retention pass to run after this request's response has been sent.
     * Cheap and side-effect-free when retention is off: no callback is registered.
     */
    public function schedule(): void
    {
        if (! (bool) config('bridge.retention.enabled')) {
            return;
        }

        $this->app->terminating(fn () => $this->runSafely());
    }

    /**
     * The terminating callback runs after the response, but still inside the FPM
     * worker — an escaping throw would surface as a fatal in the one process nobody
     * is watching. Swallow and log; retention is never worth a failed webhook.
     */
    private function runSafely(): void
    {
        try {
            $this->run();
        } catch (\Throwable $e) {
            // A single failure may be transient (a lock timeout, a momentary DB blip),
            // so this stays a warning rather than crying wolf on the first blip. But it
            // must not merely vanish: the marker-before-prune back-off means a
            // persistently-throwing pass runs at most once per interval, so without a
            // durable record it leaves only this one line — in a log nobody tails —
            // while `bridge:check` still says `retention: on`. Record it so the
            // preflight surfaces the stuck state; a later clean pass clears it. The TTL
            // outlives the interval so the marker is still there at the next retry.
            Cache::put(self::ERROR_KEY, [
                'at' => now()->toIso8601String(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ], max(self::ERROR_TTL, (int) config('bridge.retention.interval') + 3600));
            Log::warning('retention pass failed', [
                'exception' => $e::class,
                'at' => $e->getFile().':'.$e->getLine(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function run(): void
    {
        // The due-check lives INSIDE the lock, and only there. A cheaper check before
        // the lock would be an optimization that buys a race: two receives can both
        // read "due" before either acquires, and the loser then runs a second pass the
        // moment the winner releases. One check under the lock has no such window.
        Cache::lock(self::LOCK_KEY, self::LOCK_TTL)->get(function () {
            if (Cache::has(self::MARKER_KEY)) {
                return;
            }

            $cfg = RetentionConfig::fromConfig();

            if (! $cfg->isUsable()) {
                // Back off a full day before complaining again: this runs per webhook,
                // and a config mistake must not turn every delivery into a log line.
                Cache::put(self::MARKER_KEY, true, 86400);
                Log::warning('retention is enabled but misconfigured; nothing pruned', [
                    'problem' => $cfg->problem,
                ]);

                return;
            }

            $batch = $cfg->batch;

            // Mark BEFORE pruning, so a pass that throws backs off a full interval
            // instead of retrying on every subsequent delivery.
            Cache::put(self::MARKER_KEY, true, $cfg->interval);

            $result = $this->retention->prune($cfg->olderThanDays, $cfg->nullPayloadsOlderThanDays, false, $batch);

            // The pass completed — erase any failure the preflight was surfacing.
            Cache::forget(self::ERROR_KEY);

            if ($this->backlogRemains($result, $batch)) {
                // A leg filled its batch, so there is more to drain. Clear the marker
                // so the NEXT delivery continues instead of waiting out the interval —
                // this is what drains a 20k-row backlog in hours rather than months.
                // Each pass is still individually bounded, which is the property that
                // matters for the worker.
                Cache::forget(self::MARKER_KEY);
            }

            Log::info('retention pass', [
                'events_deleted' => $result->eventsDeleted,
                'payloads_nulled' => $result->payloadsNulled,
                'inbox_lines_removed' => $result->inboxLinesRemoved,
                'inbox_files_trimmed' => $result->inboxFilesTrimmed,
            ]);
        });
    }

    /**
     * A leg that came back exactly full almost certainly left rows behind (and if it
     * did not, the next pass simply finds nothing and re-arms the interval).
     */
    private function backlogRemains(RetentionResult $result, int $batch): bool
    {
        return $result->eventsDeleted === $batch || $result->payloadsNulled === $batch;
    }
}
