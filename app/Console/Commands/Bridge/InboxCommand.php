<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\BridgePaths;
use Illuminate\Console\Command;

/**
 * Surface unseen inbox intents to the agent's context. Dedups on the stable
 * per-line `id` (NOT a wall-clock cursor — req 3), so a redelivered/re-staged
 * line never re-surfaces. When invoked as a Claude Code hook (the hook payload
 * arrives on stdin with a hook_event_name that supports additionalContext), the
 * markdown is wrapped in the hookSpecificOutput envelope; otherwise it
 * prints plain markdown. Silent when there's nothing new.
 */
class InboxCommand extends Command
{
    protected $signature = 'bridge:inbox {--hook-format=auto : auto|claude-code|plain}';

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
        $stateDir = BridgePaths::stateDir();
        $seenPath = $stateDir.'/inbox-seen.json';

        $lines = $this->readInbox($stateDir.'/inbox.jsonl');
        $seen = $this->readSeen($seenPath);

        $unseen = array_values(array_filter(
            $lines,
            fn (array $line) => isset($line['id']) && is_string($line['id']) && ! in_array($line['id'], $seen, true),
        ));

        if ($unseen === []) {
            return self::SUCCESS;   // silent-when-empty discipline
        }

        $format = (string) $this->option('hook-format');
        $this->output->writeln($this->buildOutput($unseen, $format, $this->readHookEvent()));

        $newIds = array_map(fn (array $line) => (string) $line['id'], $unseen);
        $this->writeSeen($seenPath, array_values(array_unique([...$seen, ...$newIds])));

        return self::SUCCESS;
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
     * @return list<array<string, mixed>>
     */
    private function readInbox(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $lines = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $row = json_decode($raw, true);
            if (is_array($row)) {
                $lines[] = $row;
            }
        }

        return $lines;
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
