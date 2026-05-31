<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;

/**
 * Surface unseen inbox intents to the agent's context. Dedups on the stable
 * per-line `id` (NOT a wall-clock cursor — req 3), so a redelivered/re-staged
 * line never re-surfaces. When invoked as a Claude Code hook (the hook payload
 * arrives on stdin with a hook_event_name that supports additionalContext), the
 * markdown is wrapped in the hookSpecificOutput envelope; otherwise it
 * prints plain markdown. Silent when there's nothing new.
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
        $seenPath = $this->seenPath($agent);

        $lines = BridgePaths::agentInboxLines($agent);
        $seen = $this->readSeen($seenPath);

        // Collapse duplicate ids among the unseen lines (keep first): the
        // writer no longer dedups before appending (DL-012), so a partial-
        // staging redelivery can leave two lines with the same id — surface it
        // once. Already-seen ids are filtered by $seen as before.
        $unseen = [];
        $unseenIds = [];
        foreach ($lines as $line) {
            $id = $line['id'] ?? null;
            if (! is_string($id) || in_array($id, $seen, true) || isset($unseenIds[$id])) {
                continue;
            }
            $unseenIds[$id] = true;
            $unseen[] = $line;
        }

        if ($unseen === []) {
            return self::SUCCESS;   // silent-when-empty discipline
        }

        $format = (string) $this->option('hook-format');
        $hookEvent = $this->readHookEvent();
        $this->output->writeln($this->buildOutput($unseen, $format, $hookEvent));

        // Only advance the seen cursor when the output can actually reach a
        // consumer. On a hook event WITHOUT additionalContext (Stop,
        // Notification, …) stdout never reaches the model — advancing there
        // would silently eat the intents; leave them unseen so the next
        // SessionStart/PreToolUse surfaces them. A manual (non-hook) run reaches
        // the operator/terminal, so it advances. --no-cursor-advance forces a
        // peek that never marks seen.
        $reachesConsumer = $hookEvent === null || in_array($hookEvent, self::ADDITIONAL_CONTEXT_EVENTS, true);
        if ($reachesConsumer && ! $this->option('no-cursor-advance')) {
            $newIds = array_map(fn (array $line) => (string) $line['id'], $unseen);
            // Seen-cursors stay install-user-owned (not group-shared) — only the
            // process running bridge:inbox writes them (DL-006).
            $this->writeSeen($seenPath, array_values(array_unique([...$seen, ...$newIds])));
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

    private function seenPath(?string $agent): string
    {
        return $agent === null
            ? BridgePaths::stateDir().'/inbox-seen.json'
            : BridgePaths::agentSeenPath($agent);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function buildOutput(array $lines, string $format, ?string $hookEvent): string
    {
        $markdown = $this->renderMarkdown($lines);

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

    /**
     * @return list<string>
     */
    private function readSeen(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $ids
     */
    private function writeSeen(string $path, array $ids): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, (string) json_encode($ids, JSON_UNESCAPED_SLASHES), LOCK_EX);
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
