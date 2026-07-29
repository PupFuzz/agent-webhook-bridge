<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The opt-in live board-tools HTTP probe (DL-217, `--probe-tools=<endpoint>`), migrated out
 * of `CheckCommand::probeBoardToolsEndpoint()` (DL-242 stage 7b).
 *
 * For each agent with an enabled `board_tools` block and a readable bearer, POST a
 * `board_my_cards` over the REAL network path (TLS verify on) to the endpoint the channel
 * server will use, and certify the round trip:
 *  - the loopback gate admits the call (a 403 names the on-box public-IP peer trap);
 *  - the bearer authenticates (a 401 names the token path / collision);
 *  - the returned window is scoped to THIS agent's configured lane. `board_my_cards` does
 *    NOT expose a per-row swimlane_id (`projectCard` drops it), so the observable that the
 *    fail-closed row filter ran is the result HEADER: `result.board_id` /
 *    `result.swimlane_id` must equal the configured scope. A mismatch is an isolation
 *    violation.
 *
 * IT FAILS WHERE THE OFFLINE LEGS WARN. A connection failure, non-2xx or isolation
 * mismatch yields `fail` (→ non-zero exit): this probe CERTIFIES the enablement before an
 * operator flips traffic on, so "could not certify" is the answer it exists to refuse.
 *
 * REGISTERED UNCONDITIONALLY, RUN UNCONDITIONALLY, SILENT WHEN NOT REQUESTED (plan
 * constraint (a)) — the endpoint is a CONSTRUCTOR ARGUMENT and a null one yields nothing,
 * exactly as its ssh sibling holds `--probe-tools-ssh`. The command no longer guards the
 * call site on the flag, so "not requested" becomes a disposition stage 8/9 can render
 * without touching the command.
 *
 * AN SSH-TRANSPORT AGENT IS NAMED, NEVER SKIPPED (F6, card 4952). `--probe-tools` is the
 * HTTP door; an ssh agent has no bearer or endpoint to exercise here, and passing over it
 * silently would certify NOTHING while looking like a clean run (canon #9). It gets a warn
 * naming the probe that CAN certify it.
 */
final class BoardToolsHttpProbeCheck implements Check
{
    /** @param string|null $endpoint the `--probe-tools` value; null when the flag was not passed */
    public function __construct(private readonly ?string $endpoint) {}

    public function id(): string
    {
        return 'board_tools.http_live_probe';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $endpoint = $this->endpoint;
        if ($endpoint === null) {
            return;   // stage 8/9 turns this into the `not requested` disposition
        }
        if ($ctx->boardToolsEnabled === []) {
            yield Finding::warn('board_tools probe: --probe-tools was given but no agent has an enabled board_tools block — nothing to probe.');

            return;   // nothing to certify is not a failure
        }

        foreach ($ctx->boardToolsEnabled as $cfg) {
            $bt = $cfg->boardTools;
            $name = $cfg->agentName;
            if ($bt !== null && $bt->transport === 'ssh') {
                yield Finding::warn("board_tools probe: agent {$name}: uses the ssh transport — --probe-tools (HTTP) cannot certify it; run --probe-tools-ssh=<user@host> instead.");

                continue;
            }
            if ($bt === null || $bt->tokenPath === null) {
                continue;   // defensive: an enabled HTTP agent ⇒ tokenPath non-null by construction (ssh agents handled above)
            }
            // An enabled agent whose bearer can't be presented IS a broken enablement —
            // the probe certifies before the operator flips traffic on, so these fail
            // (unlike the offline checks, which only warn).
            try {
                $token = SecretFile::read($bt->tokenPath);
            } catch (Throwable $e) {
                yield Finding::fail("board_tools probe: agent {$name}: bearer not readable — {$e->getMessage()} (chmod 600); cannot certify this agent.");

                continue;
            }
            if ($token === null) {
                yield Finding::fail("board_tools probe: agent {$name}: no bearer at {$bt->tokenPath} — run bridge:provision-tools; cannot certify this agent.");

                continue;
            }

            try {
                $resp = Http::withToken($token)->acceptJson()->timeout(10)
                    ->post($endpoint, ['tool' => 'board_my_cards', 'args' => (object) []]);
            } catch (ConnectionException $e) {
                yield Finding::fail("board_tools probe: agent {$name}: could NOT connect to {$endpoint} ({$e->getMessage()}) — the bridge vhost/endpoint is wrong or not answering. Verify the channel server's BRIDGE_TOOLS_ENDPOINT and that the bridge vhost serves /agent-tools/call.");

                continue;
            }

            $status = $resp->status();
            if ($status === 403) {
                yield Finding::fail("board_tools probe: agent {$name}: {$endpoint} → 403 (loopback gate refused). The request's TCP peer is not loopback — an https://<public-host>/… endpoint makes the kernel pick the box's PUBLIC IP as the source. Use the /etc/hosts recipe (127.0.0.1 <bridge-hostname> + BRIDGE_TOOLS_ENDPOINT=https://<bridge-hostname>/agent-tools/call) — see docs/board-tools.md § Same-box enablement.");

                continue;
            }
            if ($status === 401) {
                yield Finding::fail("board_tools probe: agent {$name}: {$endpoint} → 401 (bearer rejected). The presented token resolves to no agent — verify the bearer at {$bt->tokenPath} matches what the channel server presents (BRIDGE_TOOLS_TOKEN / _FILE), and that it does not collide with another agent's.");

                continue;
            }
            if (! $resp->successful()) {
                yield Finding::fail("board_tools probe: agent {$name}: {$endpoint} → HTTP {$status} — the tool call did not succeed ({$this->probeErrorDetail($resp)}).");

                continue;
            }

            $result = $resp->json('result');
            if (! is_array($result)) {
                yield Finding::fail("board_tools probe: agent {$name}: 200 but the response carries no `result` object — cannot confirm board_my_cards ran ({$this->probeErrorDetail($resp)}).");

                continue;
            }
            $gotBoard = is_numeric($result['board_id'] ?? null) ? (int) $result['board_id'] : null;
            $gotSwimlane = is_numeric($result['swimlane_id'] ?? null) ? (int) $result['swimlane_id'] : null;
            if ($gotBoard !== $bt->boardId || $gotSwimlane !== $bt->swimlaneId) {
                yield Finding::fail("board_tools probe: agent {$name}: ISOLATION MISMATCH — board_my_cards returned board_id=".($gotBoard ?? 'null').' swimlane_id='.($gotSwimlane ?? 'null').", but this agent is configured for board {$bt->boardId} / swimlane {$bt->swimlaneId}. The window is not scoped to the configured lane.");

                continue;
            }
            $stageGroups = is_array($result['cards_by_stage'] ?? null) ? count($result['cards_by_stage']) : 0;

            yield Finding::ok("board_tools probe: agent {$name}: {$endpoint} → 200; window scoped to board {$bt->boardId} / swimlane {$bt->swimlaneId} ({$stageGroups} stage group(s)). board_my_cards does not expose a per-row swimlane, so the returned scope header matching config is the observable that the bridge-side isolation filter ran.");
        }
    }

    private function probeErrorDetail(Response $resp): string
    {
        $error = $resp->json('error');

        return is_string($error) && $error !== '' ? "error: {$error}" : 'body: '.substr($resp->body(), 0, 200);
    }
}
