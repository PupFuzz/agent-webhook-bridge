<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
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
 * Silent when the table exists — there is nothing to say about a table that is there.
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

        yield Silence::because('the owed-write table exists — this leg speaks only when it does not');
    }
}
