<?php

namespace Tests\Feature\Console;

use App\Bridge\Support\WebhookOutageRecord;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The still-failing warning's repeat floor, exercised through a REAL `php artisan bridge:inbox`
 * process with a hook payload on its stdin (card#10158).
 *
 * ⚑ WHY A SUBPROCESS. Which invocations the floor silences is decided by the hook event, and
 * that arrives on the process's own STDIN. In-process (`Artisan::call`) stdin is the runner's,
 * so every invocation reads as a non-hook run — the one arm the floor never applies to. A test
 * that could only take that arm would certify the throttle without ever reaching it.
 */
class InboxWarningRepeatFloorTest extends TestCase
{
    /** Pinned so the consumer identity — the seen cursor's file name — cannot vary with env. */
    private const AGENT = 'floor-probe';

    private const SINCE = '2026-09-14T14:01:54Z';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/bridge-floor-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/'.WebhookOutageRecord::FILE, (string) json_encode([
            'failing' => ['since' => self::SINCE, 'last_at' => self::SINCE, 'count' => 3, 'last_status' => 500],
            'recovered' => null,
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_per_tool_call_hook_is_shown_a_live_outage_once_per_floor_and_not_on_every_call(): void
    {
        $this->assertStringContainsString(
            '3 consecutive webhook 5xx',
            $this->inbox('PreToolUse'),
            'the first PreToolUse of a run must carry the warning',
        );

        $this->assertSame('', $this->inbox('PreToolUse'), 'a second PreToolUse inside the floor is silent');
        $this->assertSame('', $this->inbox('PostToolUse'), 'and so is any other per-tool-call mount');

        // ⛔ THE DISCRIMINATOR: without this arm, a warning that was never shown again for any
        // reason would pass the two assertions above. Age the mark to exactly the floor and the
        // warning is due again.
        $this->ageMarkBy(WebhookOutageRecord::WARNING_REPEAT_SECONDS);

        $this->assertStringContainsString(
            '3 consecutive webhook 5xx',
            $this->inbox('PreToolUse'),
            'at the floor the same consumer is shown the same run again',
        );
    }

    public function test_a_starting_context_and_an_operator_asking_are_never_silenced(): void
    {
        $this->inbox('PreToolUse');   // arm the floor

        $this->assertSame('', $this->inbox('PreToolUse'), 'control: the floor is armed');

        $this->assertStringContainsString(
            '3 consecutive webhook 5xx',
            $this->inbox('SessionStart'),
            'a session starting empty must be told about a live outage',
        );
        $this->assertStringContainsString(
            '3 consecutive webhook 5xx',
            $this->inbox(null),
            'an operator who ran the command must not be answered with silence',
        );
    }

    /** Move this consumer's mark $seconds further into the past, in the cursor's own spelling. */
    private function ageMarkBy(int $seconds): void
    {
        $path = $this->dir.'/state/'.WebhookOutageRecord::NOTICE_SEEN_FILE;
        $keys = json_decode((string) File::get($path), true);
        $this->assertIsArray($keys);
        $this->assertCount(1, $keys, 'the floor writes exactly one mark per consumer per run');

        $parts = explode('|', (string) $keys[0]);
        $this->assertCount(3, $parts, 'a warning mark is <notice-id>|<consumer>|<shown-at>');
        $parts[2] = (string) ((int) $parts[2] - $seconds);

        File::put($path, (string) json_encode([implode('|', $parts)]));
    }

    /** Run the real command with $hookEvent on stdin (null = no hook payload), returning stdout. */
    private function inbox(?string $hookEvent): string
    {
        $process = proc_open(
            [PHP_BINARY, base_path('artisan'), 'bridge:inbox', '--hook-format=plain', '--agent='.self::AGENT],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            [
                'PATH' => getenv('PATH'),
                'HOME' => getenv('HOME'),
                'BRIDGE_DIR' => $this->dir,
                'BRIDGE_STATE_DIR' => $this->dir.'/state',
            ],
        );
        $this->assertIsResource($process, 'could not start the artisan subprocess');

        fwrite($pipes[0], $hookEvent === null ? '' : (string) json_encode(['hook_event_name' => $hookEvent]));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);
        $this->assertSame('', $stderr);

        return trim($stdout);
    }
}
