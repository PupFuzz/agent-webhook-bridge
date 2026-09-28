<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\ClientUpdate\ClientFleet;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `board_tools.client_fleet` (card#10567 B4, design §2.6): which board-tools seats need the
 * operator about their channel-server CLIENT — a broken install log, a client installed without
 * approval, a failed update, an approval owed, a seat off the update path, one never seen, one
 * behind, a restart owed for a week, or a state the derivation has no name for.
 *
 * ⚑ IT DERIVES NOTHING. {@see ClientFleet} owns every state and the `warn` decision, and
 * `bridge:client-fleet` prints the same seats — so the two can never disagree about who needs you.
 * One WARN per seat whose state needs you, plus one per caveat {@see ClientFleet} attaches to a
 * seat (today: an approval-required agent on the http transport, which may be able to approve
 * itself); otherwise one OK line with the spread. NEVER `fail`: a seat's client state is not the
 * bridge's fault and must not move the exit code.
 *
 * Inside the enabled-subset guard: an agent with no enabled block has no client in the fleet.
 */
final class ClientFleetCheck implements Check
{
    public function id(): string
    {
        return 'board_tools.client_fleet';
    }

    public function run(CheckContext $ctx): iterable
    {
        $now = Carbon::now();
        try {
            $fleet = ClientFleet::read($ctx->boardToolsEnabled, new ClientPackStore, $now);
        } catch (Throwable $e) {
            yield Finding::unvalidated('client_fleet: the fleet ledger could not be read ('.RedactedErrorText::of($e).') — whether any seat runs an unapproved client, failed to update or is off the update path is unknown. An install that has not run `php artisan migrate` is the usual cause.');

            return;
        }

        $doc = $fleet->toArray();
        $warned = 0;
        foreach ($doc['seats'] as $seat) {
            if (! $seat['warn']) {
                continue;
            }
            $warned++;
            if ($seat['state_warns']) {
                yield Finding::warn("client_fleet: seat {$seat['agent']} is {$seat['label']} — {$seat['reason']}. `php artisan bridge:client-fleet` shows every seat.");
            }
            foreach ($seat['caveats'] as $caveat) {
                yield Finding::warn("client_fleet: {$caveat}.");
            }
        }
        if ($warned > 0) {
            return;
        }

        $spread = [];
        foreach ($doc['spread'] as $key => $n) {
            $spread[] = "{$key} ×{$n}";
        }
        $published = $fleet->published === null
            ? ($fleet->publishedError !== null
                ? 'the published client pack record cannot be read ('.UntrustedText::forOperator($fleet->publishedError).'), so no seat was compared against a release'
                : 'this bridge publishes no client pack yet, so no seat can be on the update path')
            : "published release {$fleet->published->bridgeRelease} (client {$fleet->published->clientVersion})";
        yield Finding::ok('client_fleet: '.count($doc['seats'])." seat(s), none needing you — {$published}; ".implode(', ', $spread).'.');
    }
}
