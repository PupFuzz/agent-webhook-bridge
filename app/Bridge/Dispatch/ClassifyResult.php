<?php

namespace App\Bridge\Dispatch;

/**
 * One classifier invocation's complete output. Both lists may be empty (the
 * event was noise / an echo / an unhandled type). intents are ordered (the
 * inbox surface preserves event order); targets are coalesced by debounceKey
 * (last-wins) at dispatch time, so several targets in one result that share a
 * debounceKey fire that handler bucket once.
 *
 * reattributedActor is the shared-upstream-identity completion (DL-005). When
 * an upstream account is shared by several agents the registry resolves
 * Actor.name = null on purpose (DL-002), so the pre-classify echo gate can only
 * match the raw id — which is all-or-nothing across every agent. A classifier
 * that recovers the true author (FROM:-line / repo-scope convention) returns it
 * here; the dispatcher re-runs the SAME per-agent echo check on it after
 * classify and suppresses the event only when it is the serving agent's own
 * write (a different shared-id agent's write still surfaces). Reporting the
 * author is content analysis (the classifier's job); deciding "is that me?" is
 * per-agent policy (the dispatcher's job, reusing the agent name /
 * treat_as_echo). Left null by every shipped classifier → no behaviour change.
 */
final class ClassifyResult
{
    /**
     * @param  list<ReactionTarget>  $targets
     * @param  list<Intent>  $intents
     */
    public function __construct(
        public readonly array $targets = [],
        public readonly array $intents = [],
        public readonly ?Actor $reattributedActor = null,
    ) {}
}
