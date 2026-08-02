<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Exceptions\ChannelTokenFault;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
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
 * declares a `token_path` writes a readable 0600 file. Mutating the throw arm reds
 * `ChannelTokenPathCheckTest` and nothing else in the suite — measured by mutation, not
 * inferred from a grep (CLAUDE_TESTING.md). (Named, never `{@see}`-linked: pint would turn
 * the FQCN into a real `use`.)
 *
 * BUT IT IS A PROXY READER, AND THAT BOUNDS WHAT IT MAY CONCLUDE (card#5698). This runs as
 * the operator; `channel_push` reads the same token inside the receiver request, as the OS
 * user the receiver runs as. So a fault this process alone hits — an untraversable parent,
 * a file owned by someone else — is not evidence that the push will fail, and the
 * "channel_push will FAIL until fixed" verdict is withheld for it. {@see ChannelTokenFault}
 * carries which world we are in, decided at the read.
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
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        try {
            ChannelToken::read($tokenPath);
        } catch (Throwable $e) {
            $name = $config->agentName;

            // Anything that is NOT a ChannelTokenException came from outside the token
            // contract and names no fault; it keeps the definite claim, because the reason
            // the read failed is then unknown rather than known-to-be-ours.
            yield match ($e instanceof ChannelTokenException ? $e->fault : null) {
                ChannelTokenFault::NotVisible => PathVisibility::notVisibleFinding("agent {$name}: channel auth token at {$tokenPath}"),
                ChannelTokenFault::NotReadable => Finding::unvalidated("agent {$name}: channel auth token at {$tokenPath} exists but is not readable by THIS process — bridge:check reads it as the operator while channel_push reads it as the OS user the receiver runs as, so this leg could NOT determine whether the push will authenticate; re-run bridge:check as that user, or confirm the file is mode 600 owned by it"),
                ChannelTokenFault::Missing,
                ChannelTokenFault::InsecurePerms,
                ChannelTokenFault::EmptyFile,
                null => Finding::warn("agent {$name}: ".$e->getMessage().' — channel_push will FAIL until fixed'),
            };
        }
    }
}
