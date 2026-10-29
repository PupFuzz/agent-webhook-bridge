<?php

namespace App\Bridge\Dispatch;

/**
 * A classifier's statement that one subject in the result it returned can ALSO arrive through
 * another delivery, and that the agent must receive it once (card#12576). `key` names the thing
 * delivered (opaque to the dispatcher), `holder` names which source this delivery is, `subjectId`
 * is the subject the key governs — the intents and the `channel_push` targets carrying it are what
 * a lost claim removes, and nothing else the result carries — and `lostReason` is the `dropped`
 * reason the dispatcher records when nothing is left.
 *
 * The dispatcher claims the key for the serving agent only once the delivery is past every gate
 * that can still suppress it, and gives the claim back if the delivery then fails — see
 * {@see OnceLedger}.
 */
final class OnceKey
{
    public function __construct(
        public readonly string $key,
        public readonly string $holder,
        public readonly string $subjectId,
        public readonly string $lostReason,
    ) {}
}
