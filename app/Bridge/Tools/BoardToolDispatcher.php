<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\WritebackClientFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * The post-agent-resolution body of a board-tools call (Finding A, card 4952),
 * extracted from AgentToolsController so BOTH front doors — the HTTP controller
 * and `bridge:tools-call` (the ssh-forced-command transport) — single-source the
 * tool-name validation, args validation, writeback-client build, tool invocation,
 * and exception→status mapping. Returns a transport-neutral {@see DispatchOutcome},
 * NOT an HTTP response; each door maps that outcome to its own signal (JsonResponse
 * status vs process exit code) and serializes the identical body from {@see DispatchOutcome::body}.
 *
 * Exception→status mapping is UNCHANGED from the controller's original inline form:
 *  - {@see ToolRefusalException} → 422 (caller-fixable, deterministic).
 *  - {@see RequestException} (an upstream kanban 4xx/5xx) → 502; the upstream body is not leaked.
 *  - {@see ConfigException} from {@see WritebackClientFactory::make} → 503 (install/provisioning fault).
 *
 * The one structured audit line per call moves here too, now carrying a
 * `transport` field (the resolved agent's `board_tools.transport`) so http and
 * ssh calls are distinguishable in a single log.
 */
final class BoardToolDispatcher
{
    public function __construct(private BoardToolsRegistry $tools) {}

    /**
     * @param  mixed  $rawArgs  the caller-supplied argument object (already decoded); must be an array/object
     */
    public function dispatch(string $toolName, mixed $rawArgs, BoardToolsConfig $cfg, string $agentName): DispatchOutcome
    {
        $transport = $cfg->transport;

        if ($toolName === '') {
            return DispatchOutcome::failure(422, 'request must carry a non-empty `tool`');
        }
        $tool = $this->tools->resolve($toolName);
        if ($tool === null) {
            return DispatchOutcome::failure(422, "unknown tool `{$toolName}` (known: ".implode(', ', $this->tools->known()).')');
        }

        if (! is_array($rawArgs)) {
            return DispatchOutcome::failure(422, '`args` must be an object');
        }

        try {
            $client = WritebackClientFactory::make();   // ConfigException on a missing/insecure writeback token
        } catch (ConfigException $e) {
            Log::warning('agent-tools: writeback client unavailable', ['agent' => $agentName, 'tool' => $toolName, 'transport' => $transport, 'error' => $e->getMessage()]);

            return DispatchOutcome::failure(503, 'board tools are not fully configured on this bridge (writeback token)');
        }

        try {
            $result = $tool->call($rawArgs, $cfg, $client, $agentName);
        } catch (ToolRefusalException $e) {
            Log::info('agent-tools: refused', ['agent' => $agentName, 'tool' => $toolName, 'transport' => $transport, 'reason' => $e->getMessage()]);

            return DispatchOutcome::failure(422, $e->getMessage());
        } catch (RequestException $e) {
            // A kanban error (4xx/5xx from upstream) — the caller may retry; do not
            // leak the upstream body.
            Log::warning('agent-tools: upstream kanban error', ['agent' => $agentName, 'tool' => $toolName, 'transport' => $transport, 'status' => $e->response->status()]);

            return DispatchOutcome::failure(502, 'upstream board error');
        }

        Log::info('agent-tools: ok', ['agent' => $agentName, 'tool' => $toolName, 'transport' => $transport]);

        return DispatchOutcome::success($toolName, $result);
    }
}
