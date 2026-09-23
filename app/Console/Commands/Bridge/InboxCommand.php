<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\WebhookOutageRecord;

/**
 * Surface unseen inbox intents to the agent's context. Dedups on the stable
 * per-line `id` (NOT a wall-clock cursor — req 3), so a redelivered/re-staged
 * line never re-surfaces. When invoked as a Claude Code hook (the hook payload
 * arrives on stdin with a hook_event_name that supports additionalContext), the
 * markdown is wrapped in the hookSpecificOutput envelope; otherwise it
 * prints plain markdown. Silent when there's nothing new.
 *
 * It also carries the receiver's webhook 5xx record ({@see WebhookOutageRecord}) — a run in
 * progress at most once per {@see WebhookOutageRecord::WARNING_REPEAT_SECONDS} per consumer
 * ({@see self::warningIsUnconditional} for the invocations that never wait), a finished one
 * once per consumer — because this is the one hook every seat already mounts
 * and it reads only files, so it still runs while the database the outage took down is
 * unreachable (card#10158).
 */
class InboxCommand extends BridgeCommand
{
    protected $signature = 'bridge:inbox '
        .'{--agent= : surface only this agent\'s intents (per-agent file/cursor); defaults to BRIDGE_DEFAULT_AGENT} '
        .'{--hook-format=auto : auto|claude-code|plain} '
        .'{--no-cursor-advance : print without marking intents seen}';

    protected $description = 'Surface unseen inbox intents (Claude Code hook-aware)';

    /**
     * Hook events whose stdout can inject additionalContext into the model.
     * Others get plain output (it can't reach context regardless).
     *
     * @var list<string>
     */
    private const ADDITIONAL_CONTEXT_EVENTS = [
        'SessionStart', 'Setup', 'SubagentStart', 'UserPromptSubmit',
        'UserPromptExpansion', 'PreToolUse', 'PostToolUse', 'PostToolUseFailure', 'PostToolBatch',
    ];

    public function handle(): int
    {
        $agent = $this->resolveAgent();
        $seenPath = BridgePaths::seenPath($agent);

        // The unseen set (already-seen filtered, duplicate ids collapsed) is
        // BridgePaths' rule, not this command's: the DL-306 standup digest counts
        // the same lines, and two spellings of "unseen" would let the two surfaces
        // disagree about one inbox.
        $unseen = BridgePaths::unseenInboxLines($agent);

        // Read once — it consumes stdin — and before the health lines, whose repeat floor is
        // decided on which hook event (if any) is driving this invocation.
        $hookEvent = $this->readHookEvent();

        // The seen-cursor's own file name IS the consumer identity, so "once per consumer"
        // for a recovery, and "not again for an hour" for a run still failing, mean exactly
        // what "seen" means for this consumer's intents.
        $consumer = basename($seenPath);
        [$health, $marks] = $this->deliveryHealth($consumer, $hookEvent);

        if ($unseen === [] && $health === []) {
            return self::SUCCESS;   // silent-when-empty discipline
        }

        $format = (string) $this->option('hook-format');
        $this->output->writeln($this->buildOutput($unseen, $format, $hookEvent, $health));

        // Only advance the seen cursor when the output can actually reach a
        // consumer. On a hook event WITHOUT additionalContext (Stop,
        // Notification, …) stdout never reaches the model — advancing there
        // would silently eat the intents; leave them unseen so the next
        // SessionStart/PreToolUse surfaces them. A manual (non-hook) run reaches
        // the operator/terminal, so it advances. --no-cursor-advance forces a
        // peek that never marks seen.
        $reachesConsumer = $hookEvent === null || in_array($hookEvent, self::ADDITIONAL_CONTEXT_EVENTS, true);
        if ($reachesConsumer && ! $this->option('no-cursor-advance')) {
            foreach ($marks as [$noticeId, $shownAt]) {
                WebhookOutageRecord::markNoticeSeen($consumer, $noticeId, $shownAt);
            }
            if ($unseen === []) {
                return self::SUCCESS;
            }
            $newIds = array_map(fn (array $line) => (string) $line['id'], $unseen);
            // Merge onto the cursor read UNDER the lock, not an earlier read — a
            // prune sweep between that read and here must not be clobbered (card #4630).
            BridgePaths::updateSeenLocked(
                $seenPath,
                fn (array $seen) => array_values(array_unique([...$seen, ...$newIds])),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Effective serving agent: --agent wins, else BRIDGE_DEFAULT_AGENT, else
     * null (the shared inbox — unchanged single-agent behavior).
     */
    private function resolveAgent(): ?string
    {
        $opt = $this->strOption('agent');
        if ($opt !== null) {
            return $opt;
        }
        $default = config('bridge.default_agent');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Invocations the still-failing warning's repeat floor never silences: a `SessionStart`
     * hook, because that consumer's context is starting empty and a throttled warning would
     * leave a whole session unaware of a live outage; and a non-hook run, because an operator
     * asked at a terminal and answering nothing during an outage is the silence DL-409 exists
     * to end. Every other mount — `PreToolUse` above all, which fires per tool call — waits
     * out {@see WebhookOutageRecord::WARNING_REPEAT_SECONDS}.
     */
    private function warningIsUnconditional(?string $hookEvent): bool
    {
        return $hookEvent === null || $hookEvent === 'SessionStart';
    }

    /**
     * The webhook 5xx lines for this consumer, and the notices among them to mark — each as
     * `[id, shown-at-or-null]` — so the caller records them under the cursor's own rule.
     *
     * An unreadable record is SAID, not read as healthy and not allowed to abort the inbox:
     * the intents below it are still deliverable.
     *
     * @return array{0: list<string>, 1: list<array{0: string, 1: int|null}>}
     */
    private function deliveryHealth(string $consumer, ?string $hookEvent): array
    {
        try {
            $record = WebhookOutageRecord::read();
        } catch (UnreadableFileException) {
            return [['- **WARNING: webhook delivery health is UNKNOWN** — `'.WebhookOutageRecord::path().'` exists but this user cannot read it.'], []];
        }

        $lines = [];
        $marks = [];
        $failing = $record['failing'] ?? null;
        if ($failing !== null) {
            $warningId = WebhookOutageRecord::warningNoticeId($failing);
            if (WebhookOutageRecord::warningIsDue($consumer, $warningId, $this->warningIsUnconditional($hookEvent))) {
                $lines[] = sprintf(
                    '- **WARNING: %d consecutive webhook 5xx since %s** (last: HTTP %d at %s). Webhook events are not being processed, so whatever the upstream does not redeliver is lost for this window. Run `php artisan bridge:check` (it tests database connectivity and the webhook secrets); an uncaught exception\'s detail is in the bridge\'s Laravel log.',
                    $failing['count'], $failing['since'], $failing['last_status'], $failing['last_at'],
                );
                $marks[] = [$warningId, now()->getTimestamp()];
            }
        }

        $recovered = $record['recovered'] ?? null;
        if ($recovered === null || ! WebhookOutageRecord::noticeIsCurrent($recovered)) {
            return [$lines, $marks];
        }
        $noticeId = WebhookOutageRecord::noticeId($recovered);
        if (WebhookOutageRecord::noticeSeenBy($consumer, $noticeId)) {
            return [$lines, $marks];
        }

        $lines[] = sprintf(
            '- **Webhook deliveries recovered at %s after %d consecutive 5xx** (%s to %s, last HTTP %d). Events delivered in that window were not processed. '
            .'Run `php artisan bridge:reconcile` (report only), then `php artisan bridge:reconcile --fix`, to move cards forward from their live PR state. '
            .'It does NOT recover everything: it reconciles only cards whose payload names their PR, so a card carrying only a `dl_number` is skipped, and so is a merge with no closing reference to its card. Check those by hand. '
            .'This notice is shown once.',
            $recovered['recovered_at'], $recovered['count'], $recovered['since'], $recovered['last_failure_at'], $recovered['last_status'],
        );
        $marks[] = [$noticeId, null];

        return [$lines, $marks];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $health  webhook 5xx lines, rendered above the intents
     */
    public function buildOutput(array $lines, string $format, ?string $hookEvent, array $health = []): string
    {
        $markdown = implode("\n\n", array_filter([
            $health === [] ? '' : implode("\n", ['## Kanban bridge — webhook delivery health', ...$health]),
            $lines === [] ? '' : $this->renderMarkdown($lines),
        ]));

        $wrap = match ($format) {
            'plain' => false,
            'claude-code' => true,
            default => $hookEvent !== null && in_array($hookEvent, self::ADDITIONAL_CONTEXT_EVENTS, true),
        };

        if (! $wrap) {
            return $markdown;
        }

        return (string) json_encode([
            'hookSpecificOutput' => [
                'hookEventName' => $hookEvent ?? 'SessionStart',
                'additionalContext' => $markdown,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function renderMarkdown(array $lines): string
    {
        $out = ['## Kanban bridge — new activity'];
        foreach ($lines as $line) {
            $kind = is_string($line['kind'] ?? null) ? $line['kind'] : 'event';
            $summary = is_string($line['summary'] ?? null) ? $line['summary'] : '';
            $out[] = "- **{$kind}** — {$summary}";
        }

        return implode("\n", $out);
    }

    private function readHookEvent(): ?string
    {
        if (! defined('STDIN') || ! function_exists('stream_isatty') || stream_isatty(STDIN)) {
            return null;
        }
        $raw = stream_get_contents(STDIN);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $payload = json_decode($raw, true);

        return is_array($payload) && isset($payload['hook_event_name']) && is_string($payload['hook_event_name'])
            ? $payload['hook_event_name']
            : null;
    }
}
