<?php

namespace App\Bridge\Contracts;

/**
 * Marker for a {@see Classifier} that can emit writeback ReactionTargets
 * (e.g. `kanban_move_card` / `kanban_dependabot_card`) — i.e. one that actually
 * DRIVES a `writeback.json` mapping. `bridge:check` uses it to flag an orphaned
 * mapping: a `writeback.json` entry whose repo scope has no agent running a
 * writeback-emitting classifier, so the mapping is silently inert (#2162).
 *
 * Implemented by classifiers, never instantiated. Detected OUT OF PROCESS
 * (ClassifierResolver::probeImplements) so a stale/incompatible custom
 * classifier can't E_COMPILE_ERROR-kill the check (DL-025).
 */
interface EmitsWritebackReactions {}
