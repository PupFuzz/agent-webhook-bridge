<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Validation\EndpointValidationException;
use App\Bridge\Validation\LocalhostUrl;

/**
 * The optional `writeback.alert_channel` validation (FR-4), migrated out of
 * `CheckCommand::checkAlertChannel()` (DL-242 stage 3a).
 *
 * Warn, never fail: the alert channel is an opt-in diagnostic, so a bad value must not
 * fail the install check — at runtime a bad channel only makes the alert push fail
 * (caught), leaving the writeback move itself unaffected.
 *
 * THE URL LEG DEFERS TO THE RUNTIME SENDER'S AUTHORITY ({@see LocalhostUrl::assertValid},
 * the same gate `WritebackAlertNotifier` enforces) so the check and the sender can never
 * disagree on what a valid alert url is. A hand-rolled copy here once dropped the
 * userinfo rejection and green-lit a url the sender refused.
 *
 * ITS CATCH IS NOT A FAIL-SOFT ENVELOPE. `assertValid` throws to REJECT — the throw is
 * the verdict, and catching it is how that verdict becomes a finding. It therefore stays
 * inside this check rather than in the caller's envelope, unlike the load/client catches
 * the command keeps.
 */
final class WritebackAlertChannelCheck implements Check
{
    public function id(): string
    {
        return 'writeback.alert_channel';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $ac = $ctx->writeback?->alertChannel;
        if ($ac === null) {
            return;   // not configured; recorded in the run inventory (DL-242 stage 8)
        }

        $socket = $ac->socket;
        $url = $ac->url;
        if (($socket !== null) === ($url !== null)) {
            yield Finding::warn('writeback.json alert_channel: specify exactly one of socket or url — the alert push will fail (caught) until fixed; the writeback move is unaffected');

            return;
        }
        if ($socket !== null) {
            $dir = dirname($socket);
            if (! is_dir($dir)) {
                yield PathVisibility::unverifiedUnlessVisible($dir, "writeback.json alert_channel: socket parent dir {$dir}")
                    ?? Finding::warn("writeback.json alert_channel: socket parent dir {$dir} does not exist — the alert push will fail (caught) until the channel server creates the socket");
            } else {
                yield Finding::ok("writeback.json alert_channel: socket {$socket} (parent dir present)");
            }

            return;
        }
        $problem = null;
        try {
            LocalhostUrl::assertValid((string) $url, 'writeback.json alert_channel: url');
        } catch (EndpointValidationException $e) {
            $problem = $e->getMessage();
        }

        yield $problem === null
            ? Finding::ok("writeback.json alert_channel: url {$url} (localhost)")
            : Finding::warn($problem.' — the alert push will fail (caught) until fixed');
    }
}
