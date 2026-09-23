<?php

namespace Tests\Feature\Console;

use App\Bridge\Support\WebhookOutageRecord;
use Illuminate\Support\Facades\File;
use Tests\Support\InboxSubprocess;
use Tests\TestCase;

/**
 * The still-failing warning's repeat floor, exercised through a REAL `php artisan bridge:inbox`
 * process with a hook payload on its stdin (card#10158).
 *
 * ⚑ WHY A SUBPROCESS ({@see InboxSubprocess} owns the general reason). Which
 * invocations the floor silences is decided by the hook event, and that arrives on the process's
 * own STDIN. In-process (`Artisan::call`) stdin is the runner's, so every invocation reads as a
 * non-hook run — the one arm the floor never applies to. A test that could only take that arm
 * would certify the throttle without ever reaching it.
 *
 * ⚑ THE FLOOR IS ALSO THE ONLY BEHAVIOURAL INSTRUMENT for the RECOVERY → WARNING half of
 * `WebhookOutageRecord`'s "the two notice classes must not prune each other", for the same
 * reason: a dropped warning mark is observable only as a warning shown again inside its floor,
 * and the floor never applies where the hook event cannot be reached.
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
        $this->writeRecord();
    }

    /**
     * The install every arm here runs against: one run, still failing since {@see SINCE}, with
     * $recovered as whatever earlier run the record also carries (none, by default).
     *
     * @param  array<string, int|string>|null  $recovered
     */
    private function writeRecord(?array $recovered = null): void
    {
        File::put($this->dir.'/state/'.WebhookOutageRecord::FILE, (string) json_encode([
            'failing' => ['since' => self::SINCE, 'last_at' => self::SINCE, 'count' => 3, 'last_status' => 500],
            'recovered' => $recovered,
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

    public function test_a_recovery_notice_does_not_drop_a_live_runs_warning_mark(): void
    {
        // ⛔ THE REVERSE DIRECTION of "the two notice classes must not prune each other", and the
        // one no other test can reach. This invocation writes BOTH marks, warning first and
        // recovery second, so it is the recovery write that has the live warning mark in front
        // of it. WebhookOutageRecordTest's arm measures the other direction only: when it writes
        // its recovery there is no warning mark in the file at all, so a survivesMark() that
        // lost its class guard in THIS direction stays green there.
        $this->writeRecord([
            'since' => '2026-09-01T00:00:00Z',
            'last_failure_at' => '2026-09-01T02:00:00Z',
            'count' => 2,
            'last_status' => 503,
            'recovered_at' => gmdate('Y-m-d\TH:i:s\Z'),   // just now, so it is inside the notice window
        ]);

        $first = $this->inbox('PreToolUse');
        $this->assertStringContainsString('3 consecutive webhook 5xx', $first, 'control: the live run is warned about');
        $this->assertStringContainsString('recovered at', $first, 'control: and the earlier run is reported recovered, in the same output');

        $this->assertSame(
            '',
            $this->inbox('PreToolUse'),
            'the recovery write must not drop the live run warning mark — dropped, the very next per-tool-call hook re-shows a warning that is well inside its floor',
        );

        // ⛔ THE DISCRIMINATOR: the silence above has to be the floor holding, not the warning
        // having stopped being written at all.
        $this->ageMarkBy(WebhookOutageRecord::WARNING_REPEAT_SECONDS, besideOtherClasses: 1);

        $this->assertStringContainsString(
            '3 consecutive webhook 5xx',
            $this->inbox('PreToolUse'),
            'at the floor the same consumer is shown the same run again',
        );
    }

    /**
     * Move this consumer's WARNING mark $seconds further into the past, in the cursor's own
     * spelling, leaving every other key byte-identical.
     *
     * $besideOtherClasses is how many keys of OTHER notice classes are expected beside it: the
     * floor writes exactly one warning mark per consumer per run, so the total is asserted rather
     * than the warning key merely being found among however many are there.
     */
    private function ageMarkBy(int $seconds, int $besideOtherClasses = 0): void
    {
        $path = $this->dir.'/state/'.WebhookOutageRecord::NOTICE_SEEN_FILE;
        $keys = json_decode((string) File::get($path), true);
        $this->assertIsArray($keys);
        $this->assertCount($besideOtherClasses + 1, $keys, 'the floor writes exactly one mark per consumer per run, beside whatever the other classes hold');

        $warning = WebhookOutageRecord::warningNoticeId(['since' => self::SINCE]).'|';
        $marks = array_values(array_filter($keys, fn (mixed $k) => str_starts_with((string) $k, $warning)));
        $this->assertCount(1, $marks, 'exactly one key marks this run as shown to this consumer');

        $parts = explode('|', (string) $marks[0]);
        $this->assertCount(3, $parts, 'a warning mark is <notice-id>|<consumer>|<shown-at>');
        $parts[2] = (string) ((int) $parts[2] - $seconds);

        File::put($path, (string) json_encode(array_map(
            fn (mixed $k) => $k === $marks[0] ? implode('|', $parts) : $k,
            $keys,
        )));
    }

    /** Run the real command with $hookEvent on stdin (null = no hook payload), returning stdout. */
    private function inbox(?string $hookEvent): string
    {
        [$process, $pipes] = InboxSubprocess::start($this->dir, self::AGENT);

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
