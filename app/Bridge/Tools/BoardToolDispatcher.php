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
 *
 * SINCE card#7756 THE SUCCESS POINT ALSO WRITES A DURABLE ROW ({@see ClientHalfLedger}).
 * A call that gets here from a SEAT has exercised that seat's whole client chain, and that
 * is the only evidence the bridge can have about a half it is not entitled to read — so the
 * one place both front doors already share is where it is recorded. ⚠ The row cannot say
 * the caller WAS a seat: `bridge:check --probe-tools` and a hand-run `bridge:tools-call`
 * reach this same point, so the reading check bounds its own line. It is an observation
 * ABOUT the call and never a precondition OF it: the ledger swallows its own failures.
 *
 * ⭐ SINCE card#8973 THE ENTRY POINT ALSO WRITES A SECOND DURABLE ROW
 * ({@see ConfigSeenLedger}) — that an ENABLED `board_tools` block exists for this agent,
 * which is what lets `bridge:check` tell a block that was LOST from one that never was. It
 * is stamped at ENTRY rather than at the success point deliberately: the block's presence is
 * a fact about the config the door resolved, not about whether the tool then succeeded, and
 * a failing call must not leave the install looking un-provisioned.
 *
 * ⭐ card#7836 ADDS THE ONE THING THE DOORS DO NOT SHARE: how the serving process was
 * started ({@see CallProvenance}). It is a PARAMETER and not something this class measures,
 * because this class is transport-neutral by design and the answer is not — the ssh door
 * reads sshd's session environment, the http door has nothing to read and says so as a
 * constant. Threading it keeps the fact next to the door that can establish it, rather than
 * having the shared body infer a door from `$cfg->transport`.
 *
 * ⭐ card#8974 THREADS A SECOND SUCH FACT — the CALLER's own snapshot version, which each
 * door reads out of its own request shape ({@see ClientVersion}) and which this class only
 * carries to the ledger. ⛔ It is an OBSERVATION and never a precondition: no branch here
 * reads it, no refusal turns on it, and a call that reports no version dispatches exactly
 * as one that reports a current one.
 */
final class BoardToolDispatcher
{
    public function __construct(private BoardToolsRegistry $tools) {}

    /**
     * @param  mixed  $rawArgs  the caller-supplied argument object (already decoded); must be an array/object
     * @param  CallProvenance  $provenance  how the process serving this call was started, as
     *                                      the FRONT DOOR establishes it — required, never
     *                                      defaulted (see {@see ClientHalfLedger::record()})
     * @param  ?string  $clientVersion  the calling channel server's own snapshot version as
     *                                  its door read it off the wire, already reduced by
     *                                  {@see ClientVersion}; null for a call that reported
     *                                  none. Required for the same reason as above.
     */
    public function dispatch(string $toolName, mixed $rawArgs, BoardToolsConfig $cfg, string $agentName, CallProvenance $provenance, ?string $clientVersion): DispatchOutcome
    {
        $transport = $cfg->transport;
        // card#8973 / DL-360, AT ENTRY AND NOT AT THE SUCCESS POINT BELOW — the two rows
        // answer different questions and that is why they are stamped in different places.
        // The client-half row records that the door OPENED, so it belongs to the success;
        // this one records that an ENABLED BLOCK EXISTS for this agent, which is true the
        // moment a door hands one over, whether or not the tool then works. Both doors reach
        // here with an enabled config already in hand — `bridge:tools-call` refuses a null,
        // disabled or non-ssh block, and the HTTP resolver indexes only enabled http agents —
        // so this cannot mint a sighting for a seat that has none, nor clear a live tombstone.
        // Best-effort by construction: the ledger swallows its own failures.
        ConfigSeenLedger::recordEnabled($agentName, $cfg);

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
        // card#7756: the same success, made DURABLE. The log line answers "did the
        // board-tools door open for this agent?" only for as long as the log is retained,
        // and only to someone reading logs; `bridge:check` needs it as a fact. Best-effort by construction —
        // the ledger never throws, because the call has already happened and re-running it
        // to fix an audit row would re-do the board work.
        ClientHalfLedger::record($agentName, $transport, $provenance, $clientVersion);

        return DispatchOutcome::success($toolName, $result);
    }
}
