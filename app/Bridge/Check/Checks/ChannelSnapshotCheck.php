<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelSnapshotProbe;
use App\Bridge\Support\Finding;

/**
 * The DEPLOYED channel-server snapshot leg (DL-229), migrated out of
 * `CheckCommand::handle()`'s per-agent loop (DL-242 stage 1).
 *
 * Everything else in that loop certifies the RUNNING process; a stale or unloadable
 * deployment reports `channel socket live` and exits 0, and only fails at the NEXT
 * session start — as live-wake silently never coming back.
 *
 * IT RUNS FOR ANY AGENT WITH A CHANNEL ENDPOINT (the population that respawns a server)
 * AND FOR ANY AGENT THAT DECLARED `server_path` WITHOUT ONE, so a declared key is never
 * silently dead. That applicability test moved INTO this check rather than staying an
 * `if` around its invocation: plan constraint (a) wants the verdict owned by the check,
 * which gave stage 8 one place to account for "no channel configured" rather than an
 * absent invocation it could not see.
 */
final class ChannelSnapshotCheck implements PerAgentCheck
{
    /** @param string $bundledDir the repo's reference channel-server tree, the version floor probed against */
    public function __construct(private readonly string $bundledDir) {}

    public function id(): string
    {
        return 'channel.server_snapshot';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $channel = $config->channel;
        if ($channel->socket === null && $channel->url === null && $channel->serverPath === null) {
            yield Silence::because('this agent declares no channel at all — no socket, no url, no server_path — so there is no deployed snapshot to certify');

            return;
        }

        // The `agent <name>: ` prefix was the caller's argument to emitFinding() and is
        // folded in here. A Finding carries no scope field, so the prefix is part of the
        // message; the structured identity a renderer keys on is id() plus
        // CheckResult::$agent, not this string.
        // NO TRAILING DECLARATION (card#5596): every `ChannelSnapshotProbe::probe()` path
        // returns at least one finding — an undeclared `server_path` is answered with an
        // `unvalidated`, not with an empty array — so the guard above is this method's only
        // silent exit, and a trailing declaration would name a state it cannot reach.
        foreach (ChannelSnapshotProbe::probe($channel->serverPath, $this->bundledDir) as $finding) {
            yield $finding->scoped("agent {$config->agentName}");
        }
    }
}
