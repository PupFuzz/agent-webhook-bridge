<?php

namespace App\Bridge\Retention;

use App\Bridge\Support\BridgePaths;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\File;

/**
 * Retention for the append-only stores (DL-012): deletes aged `webhook_events`
 * (cascading `agent_dispatches`), trims aged `inbox*.jsonl` lines with their paired
 * seen-cursors, and nulls aged payloads past the replay window.
 *
 * Extracted from `bridge:prune` so the command and the event-gate (DL-199) share ONE
 * implementation — two divergent copies of a destructive routine is the defect this
 * extraction exists to prevent.
 *
 * SILENT BY CONTRACT. Nothing here prints or throws for control flow: it returns a
 * {@see RetentionResult} and lets the caller decide how to report. A service that
 * called `$this->info()` could not be invoked from a webhook, which is precisely
 * what the gate needs to do.
 */
final class RetentionService
{
    /**
     * The valid retention window, in days. The upper bound is not decoration: this
     * is a destructive routine, and an absurd value (a fat-fingered 20-digit number)
     * would otherwise overflow `now()->subDays()` into a FUTURE cutoff and match —
     * delete — everything.
     */
    public const MIN_DAYS = 1;

    public const MAX_DAYS = 36500;   // 100 years

    /**
     * Parse a retention window like "30d" or "30" into a day count, or null when it
     * is not a valid window. Pure: the caller owns the error message, because the
     * name of the thing that was invalid (`--older-than` vs a config key) is the
     * caller's vocabulary, not the service's.
     */
    public static function parseDays(string $value): ?int
    {
        if (preg_match('/^(\d+)d?$/', $value, $m) !== 1) {
            return null;
        }
        $days = (int) $m[1];

        return $days >= self::MIN_DAYS && $days <= self::MAX_DAYS ? $days : null;
    }

    /**
     * Run one retention pass. Each leg is independent and skipped when its window is
     * null, mirroring the two independent `--older-than` / `--null-payloads-older-than`
     * options. `$dry` computes every count but writes nothing.
     *
     * `$batch` caps how many rows EACH DB leg touches, so one pass is bounded work:
     * the DL-199 gate runs inside a PHP-FPM worker (after the response, but still
     * holding it), where an unbounded 20k-row DELETE is exactly what must not happen.
     * Null ⇒ unbounded, which is what `bridge:prune` passes: an operator running the
     * command wants the whole backlog gone in one pass, and is not on a worker.
     *
     * `$batch` does NOT bound the inbox leg — trimming a jsonl file is a whole-file
     * rewrite, and a half-rewritten file is not a meaningful unit. The cost is paid
     * once: a pass that drops no lines skips the rewrite, so a trimmed inbox is free
     * thereafter.
     */
    public function prune(?int $olderThanDays, ?int $nullPayloadsOlderThanDays, bool $dry = false, ?int $batch = null): RetentionResult
    {
        $eventsDeleted = null;
        $linesRemoved = null;
        $filesTrimmed = null;
        $payloadsNulled = null;

        if ($olderThanDays !== null) {
            $cutoff = now()->subDays($olderThanDays);

            $events = WebhookEvent::query()->where('received_at', '<', $cutoff);
            if ($batch === null) {
                $eventsDeleted = (clone $events)->count();
                if (! $dry) {
                    $events->delete();   // cascadeOnDelete removes agent_dispatches
                }
            } else {
                // Oldest-first so successive bounded passes drain deterministically
                // (received_at is indexed for exactly this). Delete by id rather than
                // `->limit()->delete()`: DELETE ... LIMIT is not portable (SQLite
                // needs a non-default compile flag), and the ids are already read.
                $ids = (clone $events)->orderBy('received_at')->limit($batch)->pluck('id')->all();
                $eventsDeleted = count($ids);
                if (! $dry && $ids !== []) {
                    WebhookEvent::query()->whereIn('id', $ids)->delete();   // cascadeOnDelete removes agent_dispatches
                }
            }

            [$linesRemoved, $filesTrimmed] = $this->pruneInboxFiles((float) $cutoff->format('U.u'), $dry);
        }

        if ($nullPayloadsOlderThanDays !== null) {
            $cutoff = now()->subDays($nullPayloadsOlderThanDays);
            $q = WebhookEvent::query()->where('received_at', '<', $cutoff)->whereNotNull('payload');
            if ($batch === null) {
                $payloadsNulled = (clone $q)->count();
                if (! $dry) {
                    $q->update(['payload' => null]);
                }
            } else {
                $ids = (clone $q)->orderBy('received_at')->limit($batch)->pluck('id')->all();
                $payloadsNulled = count($ids);
                if (! $dry && $ids !== []) {
                    WebhookEvent::query()->whereIn('id', $ids)->update(['payload' => null]);
                }
            }
        }

        return new RetentionResult($eventsDeleted, $linesRemoved, $filesTrimmed, $payloadsNulled);
    }

    /**
     * Rewrite every inbox*.jsonl in the state dir, keeping lines whose `ts` is
     * at/after the cutoff, then bound EVERY seen-cursor to the ids that survive
     * (so the seen set is bounded by the trimmed inbox, not by all activity ever
     * — DL-012). A line with no numeric `ts` is kept (fail-open: never drop
     * something we can't age).
     *
     * Cursors are swept independently of inbox files (glob inbox-seen*.json), NOT
     * paired to an inbox file (DL-212): under `shared` layout bridge:inbox advances
     * a per-agent cursor (inbox-seen-<agent>.json) while reading the shared
     * inbox.jsonl, so no inbox-<agent>.jsonl exists to pair with — the old
     * file-paired sweep left that cursor unbounded. A cursor id is agent-scoped
     * (id = delivery_id:agentName:index, IntentLog), so an id in agent A's cursor
     * can only match an A-tagged line; intersecting each cursor against the union
     * of all surviving ids is therefore exactly the per-agent bound.
     *
     * @return array{0:int,1:int} [lines removed, files trimmed]
     */
    private function pruneInboxFiles(float $cutoffTs, bool $dry): array
    {
        $stateDir = BridgePaths::stateDir();
        $removed = 0;
        $filesTrimmed = 0;
        $survivingIds = [];

        foreach (File::glob($stateDir.'/inbox*.jsonl') as $path) {
            $keep = fn (array $line) => ! is_numeric($line['ts'] ?? null) || (float) $line['ts'] >= $cutoffTs;

            if ($dry) {
                // Read-only: never take the write lock just to count.
                $lines = BridgePaths::readJsonl($path);
                $drop = count($lines) - count(array_filter($lines, $keep));
                if ($drop > 0) {
                    $removed += $drop;
                    $filesTrimmed++;
                }

                continue;
            }

            // The filter runs INSIDE the lock. Reading first and writing after would
            // truncate away any intent appended in between — acked 200, never
            // delivered (DL-199; see BridgePaths::filterJsonlLocked). The callback
            // also accumulates the UNION of surviving ids across every inbox file,
            // which the independent cursor sweep below intersects against.
            [$before, $after] = BridgePaths::filterJsonlLocked($path, function (array $line) use ($keep, &$survivingIds) {
                if (! $keep($line)) {
                    return false;
                }
                if (is_string($line['id'] ?? null)) {
                    $survivingIds[$line['id']] = true;
                }

                return true;
            });

            $drop = $before - $after;
            if ($drop === 0) {
                continue;
            }
            $removed += $drop;
            $filesTrimmed++;
        }

        if (! $dry) {
            $keepIds = array_keys($survivingIds);
            foreach (File::glob($stateDir.'/inbox-seen*.json') as $seenPath) {
                $this->pruneSeen($seenPath, $keepIds);
            }
        }

        return [$removed, $filesTrimmed];
    }

    /**
     * Bound one seen-cursor to the ids that still exist, through the canonical
     * BridgePaths cursor primitives so the writer (bridge:inbox) and this sweep
     * agree on the shape (DL-212). Skips the write when nothing aged out, so the
     * every-run sweep doesn't churn an unchanged cursor's mtime.
     *
     * @param  list<string>  $keepIds
     */
    private function pruneSeen(string $seenPath, array $keepIds): void
    {
        $current = BridgePaths::readSeen($seenPath);
        $keep = array_values(array_intersect($current, $keepIds));
        if ($keep === $current) {
            return;
        }
        BridgePaths::writeSeen($seenPath, $keep);
    }
}
