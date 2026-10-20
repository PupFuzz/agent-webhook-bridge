<?php

namespace App\Bridge\Contracts;

/**
 * Marker for an {@see EmitsWritebackReactions} classifier that drives the PULL-REQUEST leg
 * of a `writeback.json` mapping: the card moves on PR events keyed by the mapping's `stages` /
 * `boards`, and the dependabot cards keyed by `create_dependabot_cards` (card#12050). Those
 * keys have other readers too (the board tools, `bridge:reconcile`) that need no classifier.
 *
 * WHY A LEG MARKER AND NOT THE PARENT ALONE. The parent says only that a classifier emits
 * SOME writeback reaction, and the two shipped emitters drive disjoint legs:
 * `GitHubPrCardMoveClassifier` moves cards on PR events and never cards a coordination
 * issue; `CoordinationClassifier` cards coordination issues and never moves a card on a PR.
 * Judged by the parent, a mapping whose repo was served only by the second read as covered
 * while no merged PR moved its card as it happened — and neither runtime path says so: the
 * unsubscribed PR mover never sees the event. `bridge:check` asks this marker per leg.
 *
 * Extends the parent, so every reader of the parent (the dispatcher's DL-203
 * classify-then-strip gate included) sees an implementer exactly as before. Detected OUT
 * OF PROCESS like the parent (DL-025), never instantiated.
 */
interface DrivesPrCardMoves extends EmitsWritebackReactions {}
