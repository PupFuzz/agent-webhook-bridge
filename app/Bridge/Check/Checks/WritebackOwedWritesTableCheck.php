<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Models\WritebackOwedWrite;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is the owed-write table there (card#10849 / DL-440)?
 *
 * Every durable writeback target is recorded in `writeback_owed_writes` before it is applied
 * (`App\Bridge\Writeback\OwedWriteQueue`), and a failure to record it 5xxs the delivery — the
 * existing durability contract. So an install upgraded without `php artisan migrate` answers
 * 5xx to every delivery carrying a card move, a promote or any other durable write, and a
 * GitHub delivery is never redelivered (DL-183). This is where that is found at preflight
 * rather than from the outage.
 *
 * Silent when the table exists and every currently-owed write has a clock retry AVAILABLE — this
 * asks only whether the config/instance can run, not whether a pass has actually run recently
 * (that needs live traffic or an adopted tick either way, on THIS write same as the watchdog since
 * DL-440 Decision 7). WARNs, naming
 * the key or command, when writes are owed and `OwedWriteRetryJob::clockRetryGap()` says the
 * retry sweep cannot run.
 * `unvalidated` when the database could not be asked at all: `database.connectivity` reports
 * that cause, and this leg says only that it measured nothing.
 */
final class WritebackOwedWritesTableCheck implements Check
{
    public function id(): string
    {
        return 'writeback.owed_writes_table';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        try {
            $present = Schema::hasTable('writeback_owed_writes');
        } catch (Throwable $e) {
            yield Finding::unvalidated('owed-write table: could NOT be checked — the database did not answer ('.RedactedErrorText::of($e).')');

            return;
        }

        if (! $present) {
            yield Finding::fail('owed-write table: `writeback_owed_writes` is MISSING — every delivery carrying a durable writeback (a card move, a release promote, a coord card) answers 5xx until it exists, and a GitHub delivery is never redelivered. Run `php artisan migrate`.');

            return;
        }

        // Owed writes waiting with nothing to retry them on a clock: named by the config or
        // command that stopped the sweep (card#10849 / DL-440 operator ruling — a sweep that
        // silently never runs is the failure this leg exists to report). With nothing owed, a
        // missing sweep costs nothing yet and the instance is declared at the next durable write.
        try {
            $owed = WritebackOwedWrite::query()->count();
        } catch (Throwable $e) {
            yield Finding::unvalidated('owed-write table: its rows could NOT be counted ('.RedactedErrorText::of($e).')');

            return;
        }
        $gap = $owed > 0 ? OwedWriteRetryJob::clockRetryGap() : null;
        if ($gap !== null) {
            yield Finding::warn("owed-write table: {$owed} durable write(s) are owed and nothing retries them on a clock — {$gap}. Until then each is retried only by its subject's next event, and given up loudly after OwedWriteQueue::MAX_AGE_S.");

            return;
        }

        yield Silence::because('the owed-write table exists, and every currently-owed write has a clock retry available to it');
    }
}
