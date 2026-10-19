<?php

namespace App\Bridge\Contracts;

/**
 * Marker for an {@see EmitsWritebackReactions} classifier that drives the COORD-CARD leg of
 * a `writeback.json` mapping: the coordination issue → card writes keyed by the mapping's
 * `create_coord_cards` and `move_coord_cards` (card#12050).
 *
 * The sibling of {@see DrivesPrCardMoves}; that docblock says why the parent alone cannot
 * answer which leg a classifier drives. Extends the parent, so every reader of the parent
 * sees an implementer exactly as before. Detected OUT OF PROCESS (DL-025), never
 * instantiated.
 */
interface DrivesCoordCards extends EmitsWritebackReactions {}
