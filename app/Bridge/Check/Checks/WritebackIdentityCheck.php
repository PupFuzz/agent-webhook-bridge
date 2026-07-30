<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Support\Finding;

/**
 * The writeback identity (echo-suppression) leg, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * `identity_id` is what auto-suppresses the `card_updated` webhook the writeback's own
 * move emits. Without it the bridge's move echoes back to the bridge — the loop the
 * global-echo gate exists to break.
 *
 * SILENT WHEN HEALTHY, matching the inline code byte for byte: a set identity prints
 * nothing. That silence is the stage 0-7 output contract, not a verdict. Since stage 8 it
 * is also ACCOUNTED FOR — the run inventory records it as
 * {@see CheckDisposition::Silent} — but it still yields no finding,
 * and deliberately so: a healthy install must not gain a line per check.
 */
final class WritebackIdentityCheck implements Check
{
    public function id(): string
    {
        return 'writeback.identity';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->writeback === null || $ctx->writeback->identityId !== null) {
            return;
        }

        yield Finding::warn('writeback.json: no identity_id — set it so the writeback card_updated webhook is auto echo-suppressed (else it loops back)');
    }
}
