<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\ToolsCallStdio;

/**
 * `bridge:tools-call` — the SSH-forced-command front door for board tools (Finding
 * C, card 4952). It is the exact dual of the loopback HTTP controller: it resolves
 * the caller's identity (from the PINNED `--agent`, never the wire), reads a
 * `{tool, args}` request from STDIN, and dispatches it through the SAME
 * {@see BoardToolDispatcher} the HTTP door uses — so the response body is
 * byte-identical between transports.
 *
 * The pinned `authorized_keys` line is
 *   command="php artisan bridge:tools-call --agent=<name>",restrict …
 * so `--agent` is set BY THE BRIDGE HOST, not the remote caller: it is trusted.
 * sshd substitutes the forced command and puts the client's requested command in
 * SSH_ORIGINAL_COMMAND — which this command NEVER reads (identity is `--agent`,
 * action is STDIN, full stop).
 *
 * Stdout purity is load-bearing: the ssh channel captures this process's fd 1 as
 * the tool result, so the command writes NOTHING to stdout but the one JSON
 * envelope (raw {@see fwrite}, never `$this->info/line/warn/error`, which the
 * OutputStyle targets at fd 1 and would decorate). Every diagnostic goes to STDERR
 * or the log. `display_errors` is pinned to stderr as early as the command runs so
 * a post-boot notice cannot prepend to the envelope (a true php STARTUP error
 * before userland is uncatchable here — the client-side JSON.parse in the .mjs is
 * the real backstop, F2).
 *
 * Exit code: 0 iff ok; 1 for a CALLER-fixable fault (empty --agent, malformed /
 * oversize stdin, missing tool, a dispatch 4xx); 2 for a BRIDGE-side config/service
 * fault (unknown agent, malformed agent YAML, an agent that is not a live ssh
 * board-tools agent, a dispatch 5xx) — so the ssh client can tell "fix your call"
 * from "the bridge is misconfigured / retry".
 */
class ToolsCallCommand extends BridgeCommand
{
    protected $signature = 'bridge:tools-call {--agent= : the identity, forced from the pinned authorized_keys command (trusted; NOT read from the caller)}';

    protected $description = 'SSH-forced-command board-tools front door: read {tool, args} from STDIN, write one JSON envelope to STDOUT (card 4952)';

    /** Refuse a stdin flood: a booted Laravel process must not buffer unbounded input. */
    private const MAX_STDIN_BYTES = 65536;   // 64 KiB

    /** A client that opens the channel but never sends EOF must not pin the process. */
    private const STDIN_TIMEOUT_SECS = 30;

    public function handle(BoardToolDispatcher $dispatcher, ToolsCallStdio $io): int
    {
        // Earliest userland point — keep any post-boot notice off fd 1 (the envelope
        // channel). Cannot cover a true startup error; the client parse is that guard.
        ini_set('display_errors', 'stderr');

        $agentName = $this->strOption('agent');
        if ($agentName === null) {
            return $this->emit($io, ['ok' => false, 'error' => 'bridge:tools-call requires a non-empty --agent (set by the pinned forced command)'], 1);
        }

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            // A malformed agent YAML is a bridge-side fault (service class) — exit 2.
            $this->diag($io, 'agent config error: '.$e->getMessage());

            return $this->emit($io, ['ok' => false, 'error' => 'agent config error'], 2);
        }

        $agent = null;
        foreach ($configs as $cfg) {
            if ($cfg->agentName === $agentName) {
                $agent = $cfg;
                break;
            }
        }
        if ($agent === null) {
            return $this->emit($io, ['ok' => false, 'error' => "unknown agent `{$agentName}`"], 2);
        }

        // The named agent must be a LIVE ssh board-tools agent. Every other state —
        // no block, disabled, default-suppressed, or an HTTP-transport agent — is a
        // bridge-side config fault the remote caller cannot fix, so exit 2 (DR2-6:
        // consistent with unknown-agent=2; the caller is never told to "fix its call"
        // for the bridge's misconfiguration).
        $bt = $agent->boardTools;
        if ($bt === null || ! $bt->enabled || $bt->transport !== 'ssh') {
            return $this->emit($io, ['ok' => false, 'error' => "agent `{$agentName}` is not a live ssh board-tools agent (transport must be ssh, enabled)"], 2);
        }

        [$raw, $stdinError] = $this->readStdin($io);
        if ($stdinError !== null) {
            return $this->emit($io, ['ok' => false, 'error' => $stdinError], 1);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->emit($io, ['ok' => false, 'error' => 'STDIN must be a JSON object {tool, args?}'], 1);
        }
        $tool = $decoded['tool'] ?? null;
        if (! is_string($tool) || $tool === '') {
            return $this->emit($io, ['ok' => false, 'error' => 'request must carry a non-empty `tool`'], 1);
        }
        $args = $decoded['args'] ?? [];

        $outcome = $dispatcher->dispatch($tool, $args, $bt, $agent->agentName);

        return $this->emit($io, $outcome->body(), $outcome->exitCode());
    }

    /**
     * Bounded, deadline-guarded STDIN read. Returns [raw, errorMessage] — errorMessage
     * non-null on oversize / read-timeout / read-failure (all caller-fixable ⇒ exit 1).
     *
     * @return array{0: string, 1: ?string}
     */
    private function readStdin(ToolsCallStdio $io): array
    {
        $in = $io->in();
        stream_set_timeout($in, self::STDIN_TIMEOUT_SECS);
        // Read one byte past the cap so an at-cap-plus-one body is detectable as oversize.
        $raw = stream_get_contents($in, self::MAX_STDIN_BYTES + 1);
        if (! is_string($raw)) {
            return ['', 'could not read STDIN'];
        }
        // `timed_out` is socket-reliable but UNVERIFIED on the plain pipe sshd hands
        // a forced command on fd 0 — this deadline may be a no-op there. The
        // authoritative idle/concurrency backstop is sshd itself (ClientAliveInterval/
        // ClientAliveCountMax + MaxSessions/MaxStartups); see docs/multi-host.md.
        $meta = stream_get_meta_data($in);
        if (! empty($meta['timed_out'])) {
            return ['', 'STDIN read timed out (no EOF within '.self::STDIN_TIMEOUT_SECS.'s)'];
        }
        if (strlen($raw) > self::MAX_STDIN_BYTES) {
            return ['', 'STDIN exceeds the '.self::MAX_STDIN_BYTES.'-byte cap'];
        }

        return [$raw, null];
    }

    /**
     * Write the ONE JSON envelope to raw STDOUT and return the exit code. Nothing
     * else is ever written to fd 1.
     *
     * @param  array<string, mixed>  $body
     */
    private function emit(ToolsCallStdio $io, array $body, int $exit): int
    {
        fwrite($io->out(), (string) json_encode($body));

        return $exit;
    }

    /** A diagnostic — STDERR only, never fd 1. */
    private function diag(ToolsCallStdio $io, string $message): void
    {
        fwrite($io->err(), '[bridge:tools-call] '.$message."\n");
    }
}
