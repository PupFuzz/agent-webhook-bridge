<?php

namespace App\Bridge\Contracts;

/**
 * Marker for a {@see Classifier} that can emit writeback ReactionTargets
 * (e.g. `kanban_move_card` / `kanban_coord_card`) — i.e. one that actually
 * DRIVES a `writeback.json` mapping. `bridge:check` uses it to flag an orphaned
 * mapping: a `writeback.json` entry whose repo scope has no agent running a
 * writeback-emitting classifier, so the mapping is silently inert (#2162).
 *
 * ⚑ IT DOES NOT SAY WHICH LEG. Implement {@see DrivesPrCardMoves} and/or
 * {@see DrivesCoordCards} instead — both extend this — so `bridge:check` can ask
 * whether every leg a mapping configures has a subscribed driver (card#12050). A
 * classifier implementing only this bare marker keeps every runtime behaviour it
 * had, and `bridge:check` reports its legs as undeterminable rather than covered.
 *
 * Implemented by classifiers, never instantiated. Detected OUT OF PROCESS
 * (ClassifierResolver::probeImplements) so a stale/incompatible custom
 * classifier can't E_COMPILE_ERROR-kill the check (DL-025).
 */
interface EmitsWritebackReactions {}
