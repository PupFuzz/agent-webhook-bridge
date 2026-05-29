<?php

namespace App\Bridge\Contracts;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;

/**
 * A reaction handler, dispatched by name: a classifier emits a ReactionTarget
 * with handler="<name>", and the dispatcher looks the handler up and calls it.
 * Handlers run synchronously in the request; a throw is recorded as a
 * best-effort failure (the intent is already durable in the inbox) and does
 * NOT fail the webhook.
 */
interface Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void;
}
