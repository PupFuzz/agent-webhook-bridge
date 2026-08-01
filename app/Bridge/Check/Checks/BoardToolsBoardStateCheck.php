<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * Every per-agent board-tools assertion that needs a live BOARD READ (DL-217/DL-220),
 * migrated out of `CheckCommand::checkBoardTools()` (DL-242 stage 7b).
 *
 * NEVER FAIL — the DL-220 split, and the structural twin of the writeback
 * board-state check: a temporarily-unreachable kanban or a genuinely-empty board must not
 * FAIL the install check (DL-026), while the enablement-breaking conditions above it (a
 * suppressed default block, a dead or ambiguous bearer) do. It reports `warn` where a leg
 * ANSWERED badly and `unvalidated` where it could not answer — the board read threw, or
 * the stage list came back empty so `create_stage_id` had nothing to compare against
 * (DL-251). "WARN, NEVER FAIL" was the whole story here until then.
 *
 * PER-AGENT, WHERE ITS WRITEBACK TWIN IS PER-MAPPING, and the difference is the whole
 * reason both exist: board tools are scoped by the AGENT's own `board_tools` block
 * (`swimlane_id` there IS the write scope), so two agents on one board are two windows to
 * certify, not one.
 *
 * THE CATCH IS INSIDE THIS GENERATOR, AND THAT IS THE STAGE-3b CONSTRAINT, NOT A STYLE
 * CHOICE. {@see CheckRunner} materializes a check's findings before the caller renders any
 * of them, whereas the inline code had already PRINTED the lines above the throw. A catch
 * left in the caller would therefore discard the findings this check had already yielded —
 * the visibility line before a swimlane read failed. The inline code already carried this
 * catch per agent; keeping it here is what preserves both the degradation AND the lines
 * that preceded it.
 */
final class BoardToolsBoardStateCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'board_tools.board_state';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $client = $ctx->boardToolsClient;
        $bt = $config->boardTools;
        // The client is the slot's own guard, so `CheckCommand` never runs this without
        // one; the `boardId` arm is the inline code's defensive `continue` (an enabled
        // block ⇒ boardId non-null by construction), preserved rather than dropped so the
        // migration changes nothing in either direction.
        if ($client === null || $bt === null || $bt->boardId === null) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        $name = $config->agentName;
        try {
            $vis = $client->visibility($bt->boardId);
            if ($vis['total'] === 0) {
                // 0 cards on a 200 is AMBIGUOUS — an empty board, or a token whose user
                // is not a member of it (then the read window is empty and the create
                // leg's correlation reads blind). Present both; assert neither.
                yield Finding::warn("board_tools: agent {$name}: the writeback token sees 0 cards on board {$bt->boardId} — EITHER the board is empty (fine) OR the service user is not a member / board_id is wrong (then board_my_cards returns an empty window and board_create_card's correlation reads blind). Verify membership + board_id if you expect cards.");
            } else {
                yield Finding::ok("board_tools: agent {$name}: writeback token can see board {$bt->boardId}");
            }

            $swimlaneIds = $client->boardSwimlaneIds($bt->boardId);
            // Never empty for any config that reaches here, so this leg always has a question
            // to answer: an enabled block REQUIRES swimlane_id, and the two constructions that
            // leave it null (disabled / suppressed) also null boardId, which the guard above
            // already returned on. `sharedSwimlaneId` is the only genuinely optional one.
            $configuredLanes = array_values(array_filter([$bt->swimlaneId, $bt->sharedSwimlaneId], fn ($id) => $id !== null));
            if ($swimlaneIds === null) {
                // card#5698, the twin of `WritebackBoardStateCheck`'s swimlane leg. ONE
                // finding, not one per configured lane: a single read failed, so there is a
                // single thing this run could not do — per-lane lines would report one fault
                // N times.
                yield Finding::unvalidated("board_tools: agent {$name}: could NOT check swimlane_id(s) ".implode(', ', $configuredLanes)." — board {$bt->boardId}'s preload read carried no swimlane collection at all (an empty one would have been an answer), so there was nothing to look them up in; a deleted lane would look exactly like this. Verify board_id + the service user's membership and re-run.");
            } else {
                foreach ($configuredLanes as $swimlaneId) {
                    if (! in_array($swimlaneId, $swimlaneIds, true)) {
                        yield Finding::warn("board_tools: agent {$name}: swimlane_id {$swimlaneId} is not on board {$bt->boardId} — board_create_card will 422 (create) or board_my_cards will read empty until fixed.");
                    }
                }
            }

            $stageIds = array_keys($client->boardStageOrder($bt->boardId));
            if ($bt->createStageId !== null) {
                if ($stageIds === []) {
                    // DL-251 §2b, the twin of `WritebackBoardStateCheck`'s. The empty read
                    // was folded into the same conjunction as the mismatch, so it produced
                    // the identical output as a create_stage_id that IS on the board —
                    // silence. This leg only speaks on a problem, which is fine for an
                    // ANSWERED question; it is not fine for one that was never asked.
                    // UNVALIDATED rather than warn: the comparand is missing, so nothing
                    // here says the configured id is wrong.
                    yield Finding::unvalidated("board_tools: agent {$name}: could NOT check create_stage_id {$bt->createStageId} — board {$bt->boardId} returned no workflow stages, so there was nothing to compare it against; a typo'd id would look exactly like this. Verify board_id + the service user's membership and re-run.");
                } elseif (! in_array($bt->createStageId, $stageIds, true)) {
                    yield Finding::warn("board_tools: agent {$name}: create_stage_id {$bt->createStageId} is not a stage on board {$bt->boardId} — every board_create_card will 422 until fixed.");
                }
            }

            if ($bt->coordBoardId !== null) {
                $coordVis = $client->visibility($bt->coordBoardId);
                if ($coordVis['total'] === 0) {
                    yield Finding::warn("board_tools: agent {$name}: coord_board_id {$bt->coordBoardId} reads 0 cards — the coordination leg returns empty if the service user is not a member or the id is wrong.");
                }
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated("board_tools: agent {$name}: could not read board {$bt->boardId} with the writeback token — ".$e->getMessage());
        }
    }
}
