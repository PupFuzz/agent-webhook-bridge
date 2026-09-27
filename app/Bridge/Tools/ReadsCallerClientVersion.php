<?php

namespace App\Bridge\Tools;

/**
 * A {@see Tool} whose SUCCESS body can advise an argument, and so needs the caller's reported
 * channel-client version to say when that client does not declare it (card#10566 / DL-426).
 * {@see BoardToolDispatcher} hands the version over before `call()`; a tool that does not
 * implement this never sees it, so {@see Tool::call()} keeps its signature for operator tools.
 *
 * ⛔ TEXT ONLY: the version may add {@see ClientUpdateClause}'s sentence and nothing else — no
 * status, key or accepted value may turn on it (DL-364 Decision 2).
 */
interface ReadsCallerClientVersion
{
    /**
     * A copy of this tool that answers for a caller at `$clientVersion` — null when the call
     * reported no usable version. The registry's instance is left unchanged.
     */
    public function forCallerClientVersion(?string $clientVersion): static;
}
