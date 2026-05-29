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
 * Detachment uses `setsid` (a new session, so the child survives the FPM
 * request ending) + shell backgrounding. EVERY dynamic value — each argv
 * element, the cwd, each env assignment, the log path — is escapeshellarg'd,
 * so a classifier bug (or odd data) can't break out of the argv boundary into
 * shell injection. cmd itself is operator-authored (classifier code), not
 * attacker webhook data.
 *
 * Payload: cmd (required, list<string>), log_path (optional), env (optional
 * map<string,scalar> merged over the inherited env), cwd (optional).
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

        $logPathRaw = $payload['log_path'] ?? null;
        $logPath = is_string($logPathRaw) && $logPathRaw !== ''
            ? $logPathRaw
            : BridgePaths::stateDir().'/spawn-'.PathHelper::sanitizeSegment($target->targetId).'.log';

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }

        $segments = [];

        $cwd = $payload['cwd'] ?? null;
        if (is_string($cwd) && $cwd !== '') {
            $segments[] = 'cd '.escapeshellarg($cwd).' &&';
        }

        $env = $payload['env'] ?? null;
        if (is_array($env) && $env !== []) {
            $envArgs = [];
            foreach ($env as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $envArgs[] = escapeshellarg($key.'='.((string) $value));
                }
            }
            if ($envArgs !== []) {
                $segments[] = 'env '.implode(' ', $envArgs);
            }
        }

        $segments[] = 'setsid';
        $segments[] = implode(' ', array_map(escapeshellarg(...), $cmd));
        $segments[] = '>> '.escapeshellarg($logPath).' 2>&1 &';

        file_put_contents(
            $logPath,
            "\n=== ".microtime(true).' spawn target_id='.$target->targetId." ===\n",
            FILE_APPEND | LOCK_EX,
        );

        // The trailing `&` backgrounds inside `sh -c`, so this returns
        // immediately; `setsid` reparents the child out of the FPM process
        // group so it outlives the request.
        exec(implode(' ', $segments));
    }
}
