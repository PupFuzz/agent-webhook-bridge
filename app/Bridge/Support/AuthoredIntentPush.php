<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\HandlerException;

/**
 * Push an intent the BRIDGE authored — not one a classifier produced from a webhook — at a
 * named seat over that seat's own configured channel.
 *
 * ⭐ ROUTED THROUGH THE REGISTERED `channel_push` HANDLER, never `ChannelPushTransport`
 * directly. That handler owns the agent-config endpoint fallback, the fail-closed bearer
 * read, the socket validation and the DL-014 prefix gate; a second sender would be a second,
 * quietly-weaker copy of those rules. The payload carries no `socket`/`url`, which is exactly
 * what selects the agent-config branch — the only branch a token may ride. Resolving through
 * the registry (rather than constructing the handler) keeps an operator's registered
 * replacement in force.
 *
 * ⚠ LIVE PATH ONLY. Nothing here stages to the inbox: `IntentLog::stage()` derives the line
 * id and `ts` from a stored webhook event, and a bridge-authored intent has none. What the
 * push reached is reported by the handler as UNCONFIRMED (DL-370).
 */
final class AuthoredIntentPush
{
    public function __construct(private readonly HandlerRegistry $handlers) {}

    /**
     * @throws HandlerException|ConfigException
     */
    public function send(Intent $intent, string $agentName): void
    {
        $agent = AgentConfig::load($agentName, (string) config('bridge.config_dir'));

        $this->handlers->channelPush()->handle(
            ReactionTarget::make(
                handler: HandlerRegistry::CHANNEL_PUSH,
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            ),
            $agent,
        );
    }
}
