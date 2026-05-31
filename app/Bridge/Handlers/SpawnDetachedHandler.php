<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\PathHelper;

/**
 * Fire-and-forget a detached child process for payload['cmd'] (an argv list),
 * the escape hatch for slow external reactions (board syncs, regen scripts).
 * The synchronous request must not block on it.
 *
 * SECURITY (DL-011): this is the highest-blast-radius handler (RCE as the
 * install user). It is opt-in (HandlerRegistry only registers it when
 * config('bridge.spawn.enabled')), and the program it runs — cmd[0] — must be
 * one of config('bridge.spawn.allowlist') (absolute paths). "cmd is operator-
 * authored" is a convention, not an invariant: a passthrough custom classifier
 * could hand an attacker the argv, so which program runs is constrained to an
 * explicit list, not trusted by source.
 *
 * Execution is SHELL-FREE: proc_open with an argv array execs directly (no
 * `/bin/sh -c`, so no metacharacter surface at all — escapeshellarg only ever
 * stopped breakout *within* a shell we no longer invoke). `setsid -f` puts the
 * child in a new session and forks it into the background, so it outlives the
 * FPM request and proc_open's immediate child returns at once. cwd + env are
 * proc_open parameters, not a `cd … && env …` shell prefix.
 *
 * Payload: cmd (required, list<string>; cmd[0] must be allowlisted), log_path
 * (optional), env (optional map<string,scalar> merged over the inherited env),
 * cwd (optional).
 */
final class SpawnDetachedHandler implements Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $payload = $target->payload;

        $cmd = $payload['cmd'] ?? null;
        if (! is_array($cmd) || $cmd === []) {
            throw new HandlerException('spawn_detached: payload.cmd must be a non-empty list');
        }
        foreach ($cmd as $arg) {
            if (! is_string($arg)) {
                throw new HandlerException('spawn_detached: payload.cmd entries must all be strings');
            }
        }
        /** @var list<string> $cmd */
        $cmd = array_values($cmd);

        // Allowlist gate: the program (cmd[0]) must be an operator-configured
        // absolute path. An empty/unset allowlist rejects everything.
        $allowlist = config('bridge.spawn.allowlist');
        $allowlist = is_array($allowlist) ? $allowlist : [];
        if (! in_array($cmd[0], $allowlist, true)) {
            throw new HandlerException(sprintf(
                'spawn_detached: program "%s" is not in bridge.spawn.allowlist (set BRIDGE_SPAWN_ALLOWLIST to its absolute path)',
                $cmd[0],
            ));
        }

        $logPathRaw = $payload['log_path'] ?? null;
        $logPath = is_string($logPathRaw) && $logPathRaw !== ''
            ? $logPathRaw
            : BridgePaths::stateDir().'/spawn-'.PathHelper::sanitizeSegment($target->targetId).'.log';

        BridgePaths::ensureDir(dirname($logPath));

        $cwdRaw = $payload['cwd'] ?? null;
        $cwd = is_string($cwdRaw) && $cwdRaw !== '' ? $cwdRaw : null;

        $procEnv = null;
        $env = $payload['env'] ?? null;
        if (is_array($env) && $env !== []) {
            $procEnv = $this->inheritedEnv();
            foreach ($env as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $procEnv[$key] = (string) $value;
                }
            }
        }

        file_put_contents(
            $logPath,
            "\n=== ".microtime(true).' spawn target_id='.$target->targetId." ===\n",
            FILE_APPEND | LOCK_EX,
        );

        // `setsid -f` (util-linux) detaches the child; proc_open with the argv
        // ARRAY execs it directly — no shell anywhere on the path.
        $argv = array_merge(['setsid', '-f'], $cmd);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $proc = @proc_open($argv, $descriptors, $pipes, $cwd, $procEnv);
        if (! is_resource($proc)) {
            throw new HandlerException('spawn_detached: proc_open failed for '.$cmd[0]);
        }
        proc_close($proc);   // setsid -f has already forked + detached → returns at once
    }

    /**
     * The current environment as a name=>value map, so a payload `env` merges
     * OVER the inherited environment (proc_open's $env replaces wholesale when
     * non-null).
     *
     * @return array<string, string>
     */
    private function inheritedEnv(): array
    {
        return getenv();   // no-arg form returns the full name=>value map
    }
}
