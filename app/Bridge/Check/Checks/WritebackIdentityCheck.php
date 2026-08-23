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
 * per check. What that silence withheld is a fact an operator cannot get from the board:
 * `last_stage_move.actor_id` is the ONLY writer attribution a kanban card carries, so
 * *which user does the writeback write as* decides whether a move can be attributed at
 * all — and `identity_id` is the config key that declares it.
 *
 * MEASURED IN BOTH DIRECTIONS, AND THE COLLIDING INSTALL HAS SINCE BEEN FIXED — recorded
 * in the past tense on purpose, because a docblock asserting a live collision that has
 * been repaired is exactly the stale claim this line exists to prevent someone making.
 * On one install the board CLI's token and the writeback token BOTH resolved to kanban
 * user 3, and a card move was very nearly mis-attributed to the writeback on exactly that
 * evidence; on another they were distinct users and the same question answered cleanly.
 * That first install cut the writeback over to its own dedicated kanban user shortly
 * afterwards, so its `actor_id` now discriminates. ⚠ THE FIX IS NOT THE ARGUMENT AGAINST
 * THE LEG — it is the argument for it. The property is install-topology-dependent, a
 * fresh install can be misconfigured the same way tomorrow, and nothing else checks or
 * reports it.
 *
 * ⛔ IT REPORTS, IT DOES NOT CERTIFY, and the bound is the point rather than a caveat.
 * The bridge can see the identity it is CONFIGURED with — a declared value, not a
 * resolved one; nobody here calls the API to ask who the token really is — and it cannot
 * enumerate the other tokens on the host. The collision that was actually measured is between the writeback token
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

        yield Finding::ok("writeback: writeback.json declares identity_id {$identity} — primarily the ECHO-SUPPRESSION key (the writeback's own card_updated is dropped for this actor, or the move loops back), and therefore also a claim about which kanban user the writeback authenticates as: every card it moves records that user in `last_stage_move.actor_id`, the only writer attribution a card carries. "
            .'⛔ Mint the writeback token as its OWN kanban user, never a human\'s and never one an agent\'s own board tooling already holds: two writers on one user make `actor_type: service, actor_id: '.$identity.'` mean "some PAT", not "the bridge", and a move can no longer be attributed to anything. '
            .'This line REPORTS the CONFIGURED id; it neither resolves the token against the API nor certifies separation — a board CLI\'s token on this host is invisible from here. Check that no other kanban token this seat holds resolves to user '.$identity.'.');
    }
}
