<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\WritebackClientFactory;
use Throwable;

/**
 * DL-031: is by-ref correlation actually reachable on this kanban? (DL-242 stage 3b)
 *
 * `ref` is the default correlation mode, but a kanban predating the by-ref route
 * (< v0.17.2) 404s EVERY correlation silently — no error, no retry, no card movement.
 * One instance-wide probe against the first mapped board names that state.
 *
 * THE MODE GATE IS READ THE SAME DEFAULTED WAY {@see WritebackClientFactory}
 * READS IT, so the gate and the client's actual mode can never diverge (DL-031). It is a
 * config read, not a probe, so it is cheap enough to repeat in each check that needs it —
 * hoisting it onto {@see CheckContext} would put a derived duplicate of the same
 * `config()` value one refactor away from disagreeing with the client.
 *
 * THE PROBE CALL IS WRAPPED, AND THAT IS STRUCTURAL, NOT DEFENSIVE. Unlike stage 3a's
 * config-plane checks, this one's callee reaches the network and DOES throw.
 * {@see CheckRunner} materializes a check's findings before the caller
 * renders any of them, so an escaping throw would discard findings this check had already
 * yielded — where the inline code it replaces had already PRINTED them. The inline leg
 * carried this same inner catch; keeping it is what makes the migration byte-identical
 * rather than merely similar.
 */
final class WritebackByRefCheck implements Check
{
    public function id(): string
    {
        return 'writeback.by_ref';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $writeback = $ctx->writeback;
        $client = $ctx->client;
        if ($writeback === null || $client === null || $writeback->mappings === []) {
            yield Silence::because('there is no writeback config, no constructed client, or no repo mapping — so there is no board to probe by-ref reachability against');

            return;
        }
        if (config('bridge.writeback.correlation', 'ref') !== 'ref') {
            yield Silence::because('correlation is scan, which never calls the by-ref route, so its reachability decides nothing for this install');

            return;
        }
        // NO TRAILING DECLARATION: every path past the guards yields — both arms of the
        // probe result, and the `catch`.

        $firstBoard = (int) array_values($writeback->mappings)[0]->boardId;
        try {
            if (! $client->byRefAvailable($firstBoard)) {
                yield Finding::warn("writeback: correlation=ref but by-ref returned 404 on board {$firstBoard} — either this kanban predates by-ref (< v0.17.2) or board {$firstBoard} isn't accessible to the token; EVERY correlation will 404 and no card will move. Upgrade kanban / fix board_id+membership, or set BRIDGE_WRITEBACK_CORRELATION=scan");
            } else {
                yield Finding::ok('writeback: by-ref reachable (correlation=ref)');
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated('writeback: could not probe by-ref reachability — '.$e->getMessage());
        }
    }
}
