<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Retention\RetentionGate;
use App\Bridge\Retention\RetentionService;

/**
 * Retention for the append-only stores (DL-012). Without it, webhook_events /
 * agent_dispatches and the inbox*.jsonl files grow forever — and inbox bloat
 * directly inflates synchronous webhook latency (the read side scans the file),
 * the one thing the DL-001 latency bet can't afford.
 *
 * This command used to be documented here as "the single cron the synchronous
 * design accepts". It is not one any more: DL-012 shipped it and scheduled it
 * NOWHERE — three installs, ~45 days, zero prunes — so DL-199 moved retention
 * onto the inbound webhook itself ({@see RetentionGate}),
 * after the response and bounded. The design now has NO cron exception at all,
 * and this command is the MANUAL entry point to the same shared service: an
 * operator's one-off, and the way to drain a backlog in a single unbounded pass.
 * An install that also runs it on a cron keeps working — the two are idempotent.
 *
 *  --older-than=Nd                deletes webhook_events (cascading agent_dispatches)
 *                                 received before the cutoff, and trims inbox lines
 *                                 (+ their seen-cursor ids) older than it.
 *  --null-payloads-older-than=Md  nulls webhook_events.payload past the replay
 *                                 window (keeps the row's dedup-gate + audit
 *                                 metadata; sheds the 50–100 KB body). Use M < N.
 *  --dry-run                      reports what would be pruned, changes nothing.
 *
 * The retention logic itself lives in {@see RetentionService} — this command only
 * parses options and formats output.
 */
class PruneCommand extends BridgeCommand
{
    protected $signature = 'bridge:prune '
        .'{--older-than= : delete events/dispatches + trim inbox lines older than this (e.g. 30d)} '
        .'{--null-payloads-older-than= : null webhook_events.payload older than this, keeping the row (e.g. 7d)} '
        .'{--dry-run : report what would be pruned, change nothing}';

    protected $description = 'Prune old webhook events, dispatches, and inbox lines (retention)';

    public function __construct(private readonly RetentionService $retention)
    {
        parent::__construct();
    }

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

        // Each leg parses and runs before the next is parsed. That ordering is
        // PRE-EXISTING behavior, deliberately preserved by the DL-199 extraction:
        // a valid --older-than followed by an invalid --null-payloads-older-than
        // deletes first, then fails. Pinned by a test; see CLAUDE_GOTCHAS.md G-019.
        if ($olderThan !== null) {
            $days = $this->parseWindow($olderThan, 'older-than');
            if ($days === null) {
                return self::FAILURE;
            }
            $result = $this->retention->prune($days, null, $dry);
            $this->info("events older than {$days}d: {$result->eventsDeleted} deleted (+ their dispatches){$tag}");
            $this->info("inbox lines older than {$days}d: {$result->inboxLinesRemoved} removed across {$result->inboxFilesTrimmed} file(s){$tag}");
        }

        if ($nullOlderThan !== null) {
            $days = $this->parseWindow($nullOlderThan, 'null-payloads-older-than');
            if ($days === null) {
                return self::FAILURE;
            }
            $result = $this->retention->prune(null, $days, $dry);
            $this->info("payloads older than {$days}d: {$result->payloadsNulled} nulled{$tag}");
        }

        return self::SUCCESS;
    }

    /**
     * Parse a window option, reporting the failure in the option's own vocabulary.
     * Null + an error on anything the service rejects.
     */
    private function parseWindow(string $value, string $optName): ?int
    {
        $days = RetentionService::parseDays($value);
        if ($days === null) {
            $this->error(sprintf(
                "--%s must be a number of days between %d and %d (e.g. 30 or 30d), got '%s'",
                $optName,
                RetentionService::MIN_DAYS,
                RetentionService::MAX_DAYS,
                $value,
            ));

            return null;
        }

        return $days;
    }
}
