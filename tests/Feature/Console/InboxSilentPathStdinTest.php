<?php

namespace Tests\Feature\Console;

use App\Bridge\Support\WebhookOutageRecord;
use Illuminate\Support\Facades\File;
use Tests\Support\InboxSubprocess;
use Tests\TestCase;

/**
 * What `bridge:inbox` COSTS on the path it takes on essentially every tool call (card#10158).
 *
 * ⚑ THE REGRESSION THIS PINS. The command is a documented `PreToolUse`/`PostToolUse` mount, so
 * the overwhelmingly common invocation is the one with nothing to say, which used to return in
 * microseconds without touching stdin. Reading the hook event is `stream_get_contents(STDIN)` —
 * an unbounded read to EOF — so moving it ahead of that return made every invocation's cost a
 * property of WHO STARTED THE COMMAND: a mount whose stdin writer stays open (a wrapper, a
 * supervisor, a cron line with an inherited pipe) blocks there forever, on the silent path.
 * The documented hook harness closes the pipe, which is why the floor tests never see it.
 *
 * ⛔ WHY BOTH ARMS ARE HERE. "It returned" is only evidence if the harness can SEE a process
 * that does not — so the second arm gives the command something to decide, leaves stdin open,
 * watches it NOT return, and then closes stdin and watches it finish. That also proves the
 * blocking arm was blocked ON STDIN and not merely slow to start.
 */
class InboxSilentPathStdinTest extends TestCase
{
    /** Pinned so the consumer identity — the seen cursor's file name — cannot vary with env. */
    private const AGENT = 'stdin-probe';

    /**
     * A ceiling on a return that is supposed to be immediate, not a measurement of it. Generous
     * because a false RED here would be a flaky suite on a loaded runner, while the defect it
     * guards blocks without bound — no ceiling this side of "forever" misses it.
     */
    private const RETURNS_WITHIN_SECONDS = 30.0;

    /**
     * How long the control arm watches a blocked process before concluding it is blocked. Short,
     * because it costs wall clock on every run, and it cannot produce a false RED: the assertion
     * is that the process is STILL RUNNING, which a slow start only makes more true.
     */
    private const STILL_RUNNING_AFTER_SECONDS = 3.0;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/bridge-stdin-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_silent_path_returns_without_waiting_on_an_open_stdin(): void
    {
        // A healthy install: no 5xx record has ever been written and there is nothing unseen.
        $this->assertFileDoesNotExist($this->dir.'/state/'.WebhookOutageRecord::FILE);

        [$process, $pipes] = InboxSubprocess::start($this->dir, self::AGENT);
        // ⛔ stdin is deliberately left OPEN and unwritten — the mount shape this pins.
        $returned = InboxSubprocess::endsWithin($process, self::RETURNS_WITHIN_SECONDS);

        if (! $returned) {
            proc_terminate($process);
        }

        $this->assertTrue(
            $returned,
            'bridge:inbox blocked reading stdin on the path it takes on every tool call — decide the silent return before readHookEvent()',
        );
        $this->assertSame('', stream_get_contents($pipes[1]), 'the silent path says nothing');
        $this->assertSame('', stream_get_contents($pipes[2]));
        $this->assertSame(0, $this->close($process, $pipes));
    }

    public function test_an_invocation_with_something_to_decide_does_wait_on_stdin_and_finishes_when_it_closes(): void
    {
        File::put($this->dir.'/state/'.WebhookOutageRecord::FILE, (string) json_encode([
            'failing' => ['since' => '2026-09-14T14:01:54Z', 'last_at' => '2026-09-14T14:01:54Z', 'count' => 3, 'last_status' => 500],
            'recovered' => null,
        ]));

        [$process, $pipes] = InboxSubprocess::start($this->dir, self::AGENT);
        fwrite($pipes[0], (string) json_encode(['hook_event_name' => 'PreToolUse']));   // written, NOT closed

        $this->assertFalse(
            InboxSubprocess::endsWithin($process, self::STILL_RUNNING_AFTER_SECONDS),
            'CONTROL: with a run to report, the hook event decides the output, so the command is entitled to wait for EOF — an arm that ended here would make the other test vacuous',
        );

        fclose($pipes[0]);
        $ended = InboxSubprocess::endsWithin($process, self::RETURNS_WITHIN_SECONDS);
        if (! $ended) {
            proc_terminate($process);
        }
        $this->assertTrue($ended, 'closing stdin must release it — otherwise it was not stdin it was waiting on');

        $this->assertStringContainsString('3 consecutive webhook 5xx', (string) stream_get_contents($pipes[1]));
        $this->assertSame(0, $this->close($process, $pipes, stdinOpen: false));
    }

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function close($process, array $pipes, bool $stdinOpen = true): int
    {
        if ($stdinOpen) {
            fclose($pipes[0]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
