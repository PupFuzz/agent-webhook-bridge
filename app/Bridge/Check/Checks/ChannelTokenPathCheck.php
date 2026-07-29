<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * The `channel.auth.token_path` read (DL-008), migrated out of `CheckCommand::handle()`'s
 * per-agent loop (DL-242 stage 5b).
 *
 * IT CHECKS BY DOING THE REAL READ rather than restating its rules. `ChannelToken::read`
 * owns the token contract — readable, not group/world-readable, non-empty — and that
 * contract is fail-closed at push time; a preflight that re-derived it would be a second
 * copy to keep in step, and the copy that drifted would report green on a token the
 * handler refuses.
 *
 * THE PATH IS EXPLICIT CONFIG, NOT UNDER `secret_dir`, so unlike the webhook-secret and
 * API-token legs this one is independent of the secret dir being set at all.
 *
 * THE `catch` IS THE WHOLE POINT AND NO GOLDEN FIXTURE REACHES IT — every fixture that
 * declares a `token_path` writes a readable 0600 file, so the throw arm is measured only
 * by `ChannelTokenPathCheckTest`. (Named, never `{@see}`-linked: pint would turn the FQCN
 * into a real `use`.)
 */
final class ChannelTokenPathCheck implements PerAgentCheck
{
    public function id(): string
    {
        return 'channel.token_path';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $tokenPath = $config->channel->tokenPath;
        if ($tokenPath === null) {
            return;   // stage 8 turns this into a returned disposition
        }

        try {
            ChannelToken::read($tokenPath);
        } catch (Throwable $e) {
            yield Finding::warn("agent {$config->agentName}: ".$e->getMessage().' — channel_push will FAIL until fixed');
        }
    }
}
