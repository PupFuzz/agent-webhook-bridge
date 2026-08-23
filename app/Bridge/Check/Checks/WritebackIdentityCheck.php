<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;

/**
 * The writeback identity (echo-suppression) leg, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * `identity_id` is what auto-suppresses the `card_updated` webhook the writeback's own
 * move emits. Without it the bridge's move echoes back to the bridge — the loop the
 * global-echo gate exists to break.
 *
 * NO LONGER SILENT WHEN HEALTHY, and the change is a ruling rather than a drift
 * (card#7348 / roundtable #343). It used to print nothing for a set identity — the stage
 * 0-7 byte-identical output contract, kept because a healthy install must not gain a line
 * per check. What that silence withheld is the one fact an operator needs to answer a
 * question the board cannot: `last_stage_move.actor_id` is the ONLY writer attribution a
 * kanban card carries, so *which user does the writeback write as* decides whether a move
 * can ever be attributed at all. Measured in both directions the night this landed: on
 * one install the board CLI's token and the writeback token both resolve to kanban user
 * 3, and a card move was very nearly mis-attributed to the writeback on exactly that
 * evidence; on another they are users 7 and 10 and the same question answers cleanly.
 * The property is install-topology-dependent and nothing checked or reported it.
 *
 * ⛔ IT REPORTS, IT DOES NOT CERTIFY, and the bound is the point rather than a caveat.
 * The bridge can see the identity IT is configured with; it cannot enumerate the tokens
 * on the host. The collision that was actually measured is between the writeback token
 * and a BOARD CLI's token that lives entirely outside this install's config — invisible
 * from here, in principle and not just today. So this leg deliberately makes NO
 * separation claim: an assertion that stayed green on the very install shape that has the
 * defect would be worse than no assertion, because it would manufacture the assurance the
 * operator came for. It prints the user and names the property to verify.
 *
 * Since stage 8 the run inventory records a silent execution as
 * {@see CheckDisposition::Silent}; this check now reaches that state only when there is
 * no writeback config at all.
 */
final class WritebackIdentityCheck implements Check
{
    public function id(): string
    {
        return 'writeback.identity';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->writeback === null) {
            yield Silence::because('there is no writeback config, so there is no identity_id to report on and no writeback user for a second writer to collide with');

            return;
        }

        $identity = $ctx->writeback->identityId;
        if ($identity === null) {
            yield Finding::warn('writeback.json: no identity_id — set it so the writeback card_updated webhook is auto echo-suppressed (else it loops back)');

            return;
        }

        yield Finding::ok("writeback: the writeback writes to the board as kanban user {$identity} (writeback.json identity_id), and every card it moves records that id in `last_stage_move.actor_id` — the only writer attribution a card carries. "
            .'⛔ Mint the writeback token as its OWN kanban user, never a human\'s and never one an agent\'s own board tooling already holds: two writers on one user make `actor_type: service, actor_id: '.$identity.'` mean "some PAT", not "the bridge", and a move can no longer be attributed to anything. '
            .'This line REPORTS that user; it does not certify separation — the bridge can only see the identity it is configured with, and a board CLI\'s token on this host is invisible from here. Resolve every kanban token this seat holds against the API and check that none of them is user '.$identity.'.');
    }
}
