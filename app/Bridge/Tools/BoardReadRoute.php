<?php

namespace App\Bridge\Tools;

/**
 * WHICH KANBAN ROUTE CLASS A BOARD TOOL'S READ WENT TO — the discriminator
 * {@see BoardCallRefusal::readCause} needs, because a 403 and a 404 do not mean the same
 * thing on the two route classes this door reads from, and the CAUSE is the whole value of
 * a named refusal (a refusal that names the wrong thing to audit is worse than the retryable
 * 502 it replaced: it rules the true cause out BY NAME).
 *
 * ⛔ THE STATUS ALONE IS NOT ENOUGH, AND THAT IS WHY THIS TYPE EXISTS. DL-326 derived the
 * cause strings for `tasks/search.json`, which is `board_correct_card`'s only read; DL-339
 * hoisted them and `board_my_cards` reached them from `boards/{id}/preload.json`, whose
 * authorization is different in exactly the place the strings make their claim. A caller
 * therefore names the route class it called, and a `try` block must not span both.
 *
 * ⚠ THESE ARE THE ROUTE CLASSES WHOSE REFUSALS THIS DOOR NAMES — NOT A CENSUS OF THE ROUTES IT
 * READS. `GET /tasks/{id}.json` is a third one, reached only by `BoardCreateCardTool`'s DL-299
 * placement read-back, which catches `\Throwable` and degrades: it composes no cause, so it has
 * no case here. A route that starts producing a named refusal needs one.
 *
 * ⚠ THE DIFFERENCE IS KANBAN'S, NOT THIS REPO'S, AND THIS REPO CANNOT CHECK IT. Both cases
 * below are source-read from the kanban-board tree, not measured against a live instance;
 * the conditions are declared for a consumer in `docs/kanban-integration-contract.md` § 2
 * (the `preload.json` and `search.json` rows), which is the surface that moves if kanban's
 * authorization does. The failure direction if kanban changes one is a refusal naming the
 * wrong thing to audit — never a silent write, and never a widening of what this door
 * accepts.
 */
enum BoardReadRoute
{
    /**
     * `GET /api/v3/tasks/search.json` — kanban FLOORS the query to the caller's own boards
     * (`Board::visibleTo($user)`, `TasksController::search`) and answers 200-with-zero-rows
     * for a board the writeback user cannot see. So membership CANNOT produce a 403 here,
     * and a 404 is a statement about the API surface rather than about a board or a card.
     */
    case Search;

    /**
     * `GET /api/v3/boards/{id}/preload.json` — kanban authorizes the BOARD itself
     * (`$this->authorize('view', $board)`, `BoardsController::preload`, declaring
     * `403 "not a board member"` and `404 "board not found (or soft-deleted — this route
     * does not resolve trashed boards)"`). So membership IS a live 403 cause here, and a
     * 404 is most likely a board id that does not resolve — the two things the `Search`
     * strings deny by name.
     */
    case BoardScoped;
}
