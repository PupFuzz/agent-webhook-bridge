<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\OptInCheck;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretFile;
use App\Bridge\Tools\BoardToolsScopeHeader;
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
 *  - the answering agent is the one this bearer was minted for: the result HEADER
 *    (`result.configured_board_id` / `result.swimlane_id`, read through
 *    {@see BoardToolsScopeHeader}) must equal the configured scope. ⛔ That header is an
 *    identity ECHO of the resolved agent's own config, so a mismatch is a real finding — the
 *    bearer reached a DIFFERENT agent's window — while a match certifies resolution and NOT
 *    that the fail-closed row filter ran, which config compared against config cannot show.
 *    `board_my_cards` exposes no per-row swimlane_id (`projectCard` drops it), so the lane
 *    filter has no observable in this response at all; since DL-302 the BOARD axis does have
 *    one (`result.board_id` / `result.board_observed`, read off the rows), and this probe
 *    does not yet assert on it — adding a fail arm there changes what bridge:check rejects.
 *
 * IT FAILS WHERE THE OFFLINE LEGS ONLY REPORT. A connection failure, non-2xx or isolation
 * mismatch yields `fail` (→ non-zero exit): this probe CERTIFIES the enablement before an
 * operator flips traffic on, so "could not certify" is the answer it exists to refuse.
 *
 * REGISTERED UNCONDITIONALLY, RUN UNCONDITIONALLY, SILENT WHEN NOT REQUESTED (plan
 * constraint (a)) — the endpoint is a CONSTRUCTOR ARGUMENT and a null one yields nothing,
 * exactly as its ssh sibling holds `--probe-tools-ssh`. Stage 8 made that silence
 * DECLARED via {@see OptInCheck::wasRequested()}, so the inventory records
 * {@see CheckDisposition::NotRequested} — no statement about the
 * install — rather than collapsing it onto "ran and found nothing".
 *
 * THE FLAG-GIVEN-BUT-NOTHING-TO-PROBE STATE IS NOT THAT CASE and still yields a `warn`:
 * the operator asked, so an answer is owed. Only the flag's ABSENCE is a disposition.
 *
 * AN SSH-TRANSPORT AGENT IS NAMED, NEVER SKIPPED (F6, card 4952). `--probe-tools` is the
 * HTTP door; an ssh agent has no bearer or endpoint to exercise here, and passing over it
 * silently would certify NOTHING while looking like a clean run (canon #9). It gets a warn
 * naming the probe that CAN certify it.
 */
final class BoardToolsHttpProbeCheck implements OptInCheck
{
    /** @param string|null $endpoint the `--probe-tools` value; null when the flag was not passed */
    public function __construct(private readonly ?string $endpoint) {}

    public function id(): string
    {
        return 'board_tools.http_live_probe';
    }

    public function wasRequested(): bool
    {
        return $this->endpoint !== null;
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $endpoint = $this->endpoint;
        if ($endpoint === null) {
            return;   // CheckDisposition::NotRequested — declared via wasRequested() (DL-242 stage 8)
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
                // Defensive: an enabled HTTP agent ⇒ tokenPath non-null by construction
                // (ssh agents handled above). DELIBERATELY LEFT UNDECLARED (card#5596) —
                // it is the only way past this loop without yielding, and it is
                // unreachable, so if every enabled agent took it the run SHOULD disclose
                // an undeclared silence rather than have a declaration vouch for a state
                // that cannot happen.
                continue;
            }
            // An enabled agent whose bearer can't be presented IS a broken enablement —
            // the probe certifies before the operator flips traffic on, so these fail
            // (unlike the offline checks, which never do).
            try {
                $token = SecretFile::read($bt->tokenPath);
            } catch (UnreadableSecretException $e) {
                // STAYS `fail` on the same ground as the no-usable-bearer arm below — this
                // probe asserts about its OWN certification, which the operator explicitly
                // asked for — but it must not inherit the sibling arm's `chmod 600`: the
                // mode is very likely already correct and the fault is ownership, so that
                // remedy would send the operator to loosen perms on a healthy bearer.
                yield Finding::fail("board_tools probe: agent {$name}: bearer not readable — {$e->getMessage()}; re-run bridge:check as a user that can read it. Cannot certify this agent.");

                continue;
            } catch (Throwable $e) {
                yield Finding::fail("board_tools probe: agent {$name}: bearer not readable — {$e->getMessage()} (chmod 600); cannot certify this agent.");

                continue;
            }
            if ($token === null) {
                // STAYS `fail`, and does NOT adopt PathVisibility the way the resolver's
                // twin read of this same file did (DL-254) — the InstallConfigDirCheck
                // precedent, not the BoardToolAgentResolver one (DL-259, card#5698).
                // The resolver asserts about the RUNTIME's enablement while running as
                // the wrong OS user, so an unseeable token makes its claim false. This
                // leg asserts about its OWN certification, which the operator explicitly
                // asked for with --probe-tools: in BOTH worlds — absent, or present and
                // unseeable — THIS PROBE certified nothing for this agent, a fact about
                // the run known with certainty. Only the CLAIM was wrong: `is_file()`
                // (via SecretFile::read) is false for EACCES exactly as for ENOENT, so
                // naming just one sent the operator to re-mint a token that may already
                // be there and readable by the web user the runtime actually serves as.
                yield Finding::fail("board_tools probe: agent {$name}: no usable bearer at {$bt->tokenPath} — it is absent, or a directory above it denies this process traversal (the read cannot distinguish them; the bridge commonly runs as a different OS user than the agent). Run bridge:provision-tools if it is missing, or re-run bridge:check as a user that can read it; cannot certify this agent.");

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
            $gotBoard = BoardToolsScopeHeader::boardId($result);
            $gotSwimlane = BoardToolsScopeHeader::swimlaneId($result);
            if ($gotBoard !== $bt->boardId || $gotSwimlane !== $bt->swimlaneId) {
                yield Finding::fail("board_tools probe: agent {$name}: ISOLATION MISMATCH — board_my_cards answered for board=".($gotBoard ?? 'null').' swimlane='.($gotSwimlane ?? 'null').", but this agent is configured for board {$bt->boardId} / swimlane {$bt->swimlaneId}. The window is not scoped to the configured lane.");

                continue;
            }
            $stageGroups = is_array($result['cards_by_stage'] ?? null) ? count($result['cards_by_stage']) : 0;

            yield Finding::ok("board_tools probe: agent {$name}: {$endpoint} → 200; window scoped to board {$bt->boardId} / swimlane {$bt->swimlaneId} ({$stageGroups} stage group(s)). The scope header is an identity echo — matching it certifies that this bearer resolved to THIS agent, not that the bridge-side lane filter ran (config matching config is true whatever the rows held); the measured half is the response's own board_id/board_observed.");
        }
    }

    private function probeErrorDetail(Response $resp): string
    {
        $error = $resp->json('error');

        return is_string($error) && $error !== '' ? "error: {$error}" : 'body: '.substr($resp->body(), 0, 200);
    }
}
