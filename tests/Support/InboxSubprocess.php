<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * One `php artisan bridge:inbox` CHILD PROCESS, started the way a mount starts it.
 *
 * ⚑ WHY A SUBPROCESS, AND WHY SHARED RATHER THAN COPIED. Everything the command decides from
 * STDIN is a property of the PROCESS: which hook event (if any) is driving the invocation, and
 * whether the invocation reads stdin at all. In-process (`Artisan::call`) stdin is the runner's,
 * so every call reads as the non-hook arm and neither question is reachable. Different classes
 * ask different things of this mount — the warning repeat floor, and whether the silent path
 * touches stdin — and they have to start the SAME process or they are measuring two commands.
 *
 * ⛔ THE CALLER OWNS STDIN, deliberately: closing it is a hook harness that has finished writing
 * (the documented mount), and LEAVING IT OPEN is every other shape — a wrapper, a supervisor, a
 * cron line with an inherited pipe. The difference between those two is itself under test, so
 * this class must not decide it.
 */
final class InboxSubprocess
{
    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    public static function start(string $dir, string $agent): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('artisan'), 'bridge:inbox', '--hook-format=plain', '--agent='.$agent],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            [
                'PATH' => getenv('PATH'),
                'HOME' => getenv('HOME'),
                'BRIDGE_DIR' => $dir,
                'BRIDGE_STATE_DIR' => $dir.'/state',
            ],
        );
        Assert::assertIsResource($process, 'could not start the artisan subprocess');

        return [$process, $pipes];
    }

    /**
     * Whether $process ended within $seconds, polled rather than waited on: `proc_close()` blocks
     * forever on a child that is itself blocked, which is the state under test.
     *
     * @param  resource  $process
     */
    public static function endsWithin($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (proc_get_status($process)['running'] !== true) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
