<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\File;

/**
 * Retention for the append-only stores (DL-012). Without it, webhook_events /
 * agent_dispatches and the inbox*.jsonl files grow forever — and inbox bloat
 * directly inflates synchronous webhook latency (the read side scans the file),
 * the one thing the DL-001 latency bet can't afford. This is the single cron
 * the synchronous design accepts: it does not run on the hot path.
 *
 *  --older-than=Nd                deletes webhook_events (cascading agent_dispatches)
 *                                 received before the cutoff, and trims inbox lines
 *                                 (+ their seen-cursor ids) older than it.
 *  --null-payloads-older-than=Md  nulls webhook_events.payload past the replay
 *                                 window (keeps the row's dedup-gate + audit
 *                                 metadata; sheds the 50–100 KB body). Use M < N.
 *  --dry-run                      reports what would be pruned, changes nothing.
 */
class PruneCommand extends BridgeCommand
{
    protected $signature = 'bridge:prune '
        .'{--older-than= : delete events/dispatches + trim inbox lines older than this (e.g. 30d)} '
        .'{--null-payloads-older-than= : null webhook_events.payload older than this, keeping the row (e.g. 7d)} '
        .'{--dry-run : report what would be pruned, change nothing}';

    protected $description = 'Prune old webhook events, dispatches, and inbox lines (retention)';

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        $olderThan = $this->strOption('older-than');
        $nullOlderThan = $this->strOption('null-payloads-older-than');
        if ($olderThan === null && $nullOlderThan === null) {
            $this->error('specify --older-than and/or --null-payloads-older-than');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $tag = $dry ? ' (dry-run)' : '';

        if ($olderThan !== null) {
            $days = $this->parseDays($olderThan, 'older-than');
            if ($days === null) {
                return self::FAILURE;
            }
            $cutoff = now()->subDays($days);

            $events = WebhookEvent::query()->where('received_at', '<', $cutoff);
            $eventCount = (clone $events)->count();
            if (! $dry) {
                $events->delete();   // cascadeOnDelete removes agent_dispatches
            }
            $this->info("events older than {$days}d: {$eventCount} deleted (+ their dispatches){$tag}");

            $cutoffTs = (float) $cutoff->format('U.u');
            [$linesRemoved, $filesTrimmed] = $this->pruneInboxFiles($cutoffTs, $dry);
            $this->info("inbox lines older than {$days}d: {$linesRemoved} removed across {$filesTrimmed} file(s){$tag}");
        }

        if ($nullOlderThan !== null) {
            $days = $this->parseDays($nullOlderThan, 'null-payloads-older-than');
            if ($days === null) {
                return self::FAILURE;
            }
            $cutoff = now()->subDays($days);
            $q = WebhookEvent::query()->where('received_at', '<', $cutoff)->whereNotNull('payload');
            $count = (clone $q)->count();
            if (! $dry) {
                $q->update(['payload' => null]);
            }
            $this->info("payloads older than {$days}d: {$count} nulled{$tag}");
        }

        return self::SUCCESS;
    }

    /**
     * Parse a retention window like "30d" or "30" into a positive integer of
     * days. Null + an error on anything else.
     */
    private function parseDays(string $value, string $optName): ?int
    {
        // Cap at 100 years: this is a destructive command, and an absurd value
        // (a fat-fingered 20-digit number) would otherwise overflow
        // now()->subDays() into a FUTURE cutoff and match — delete — everything.
        if (preg_match('/^(\d+)d?$/', $value, $m) !== 1 || (int) $m[1] < 1 || (int) $m[1] > 36500) {
            $this->error("--{$optName} must be a number of days between 1 and 36500 (e.g. 30 or 30d), got '{$value}'");

            return null;
        }

        return (int) $m[1];
    }

    /**
     * Rewrite every inbox*.jsonl in the state dir, keeping lines whose `ts` is
     * at/after the cutoff, and prune each file's paired seen-cursor to the ids
     * that remain (so the seen set is bounded by the trimmed inbox, not by all
     * activity ever — DL-012). A line with no numeric `ts` is kept (fail-open:
     * never drop something we can't age).
     *
     * @return array{0:int,1:int} [lines removed, files trimmed]
     */
    private function pruneInboxFiles(float $cutoffTs, bool $dry): array
    {
        $stateDir = BridgePaths::stateDir();
        $removed = 0;
        $filesTrimmed = 0;

        foreach (File::glob($stateDir.'/inbox*.jsonl') as $path) {
            $lines = BridgePaths::readJsonl($path);
            $kept = array_values(array_filter(
                $lines,
                fn (array $line) => ! is_numeric($line['ts'] ?? null) || (float) $line['ts'] >= $cutoffTs,
            ));
            $drop = count($lines) - count($kept);
            if ($drop === 0) {
                continue;
            }
            $removed += $drop;
            $filesTrimmed++;
            if (! $dry) {
                $this->rewriteJsonl($path, $kept);
            }

            // Bound the paired seen-cursor to the ids that still exist.
            $seenPath = $this->seenPathFor($path);
            if (is_file($seenPath)) {
                $keptIds = array_values(array_filter(array_map(
                    fn (array $l) => is_string($l['id'] ?? null) ? $l['id'] : null,
                    $kept,
                )));
                if (! $dry) {
                    $this->pruneSeen($seenPath, $keptIds);
                }
            }
        }

        return [$removed, $filesTrimmed];
    }

    /**
     * The seen-cursor file paired with an inbox file:
     * inbox.jsonl → inbox-seen.json, inbox-<agent>.jsonl → inbox-seen-<agent>.json.
     */
    private function seenPathFor(string $inboxPath): string
    {
        $dir = dirname($inboxPath);
        $base = basename($inboxPath, '.jsonl');        // "inbox" | "inbox-<agent>"
        $suffix = substr($base, strlen('inbox'));      // "" | "-<agent>"

        return $dir.'/inbox-seen'.$suffix.'.json';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function rewriteJsonl(string $path, array $lines): void
    {
        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        file_put_contents($path, $body, LOCK_EX);
    }

    /**
     * @param  list<string>  $keepIds
     */
    private function pruneSeen(string $seenPath, array $keepIds): void
    {
        $decoded = json_decode((string) file_get_contents($seenPath), true);
        if (! is_array($decoded)) {
            return;
        }
        $keep = array_values(array_intersect(
            array_values(array_filter($decoded, 'is_string')),
            $keepIds,
        ));
        file_put_contents($seenPath, (string) json_encode($keep, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
