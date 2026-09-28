<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\BoardToolDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The client-update door (DL-430): what a seat's channel-server updater asks its own bridge at
 * launch. One service behind both transports — `POST /agent-tools/client` (loopback + the agent's
 * bearer) and `bridge:tools-call` given a body carrying `op` (the pinned ssh forced command) — so
 * the answer is the same bytes whichever door served it.
 *
 * ⛔ IT NEVER GOES THROUGH {@see BoardToolDispatcher}. The update path must stay
 * reachable whatever a board-tools call would answer, so nothing a tool refusal, a board outage or
 * a client-version rule decides can stand between a seat and the pack that fixes it.
 *
 * THE OPS:
 *   client_manifest   {op}  →  {ok, op, published: {bridge_release, client_version,
 *                     files_json_sha256, manifest_sha256, manifest_b64}, offer, approval:
 *                     {required, owed}, log_head}
 *                     `offer` is the release a seat on the normal path should install: the published
 *                     one, or null for an agent whose `board_tools.client_update.approval_required`
 *                     is true and whose approvals do not cover the published pack's content — then
 *                     `approval.owed` names that release (card#10567 B4). `log_head` is the last
 *                     install-log entry this bridge holds for the agent ({install_id, seq, sha256})
 *                     or null. 503 when nothing is published.
 *   client_pack       {op, bridge_release}  →  {ok, op, bridge_release, sha256, size,
 *                     encoding: "base64", data}
 *                     404 naming the published release when asked for any other; 422 when
 *                     `bridge_release` is not bare X.Y.Z; 503 when nothing is published. Served to
 *                     any authenticated agent whatever `offer` says: approval gates the offer on the
 *                     normal path and is detected when bypassed, never enforced here (operator
 *                     ruling 6, card#10567 comment 6757).
 *   client_report     {op, install_id, entries: [<install-log line>, …]}  →  {ok, op, log_head,
 *                     stored, discontinuity}. {@see SeatClientLedger::report()} owns the chain
 *                     check; a malformed report is a 422 and stores nothing.
 *   client_fleet      {op}  →  {ok, op, published, published_error, spread, seats} —
 *                     {@see ClientFleet::toArray()}. 403 unless the calling agent's
 *                     `board_tools.fleet_view` is true.
 * Any other `op` is a 422 naming the ones served. Every stored file is re-checked against the
 * publication record before it is served; a mismatch is a 503, never the bytes.
 */
final class ClientUpdateDoor
{
    public const OPS = ['client_manifest', 'client_pack', 'client_report', 'client_fleet'];

    public function __construct(private readonly ClientPackStore $store) {}

    /**
     * @param  array<string, mixed>  $body  the decoded request, already parsed by ToolCallBody
     */
    public function handle(array $body, string $agentName, BoardToolsConfig $cfg): ClientUpdateOutcome
    {
        $op = $body['op'] ?? null;
        $transport = $cfg->transport;
        $outcome = match ($op) {
            'client_manifest' => $this->manifest($agentName, $cfg),
            'client_pack' => $this->pack($body['bridge_release'] ?? null),
            'client_report' => $this->report($agentName, $body),
            'client_fleet' => $this->fleet($cfg),
            default => ClientUpdateOutcome::failure(422, 'unknown client-update `op` '.json_encode($op).' — this bridge serves '.implode(', ', self::OPS)),
        };
        Log::info('agent-tools: client-update', ['agent' => $agentName, 'op' => is_string($op) ? $op : null, 'transport' => $transport, 'status' => $outcome->status]);

        return $outcome;
    }

    private function manifest(string $agentName, BoardToolsConfig $cfg): ClientUpdateOutcome
    {
        $published = $this->published();
        if ($published instanceof ClientUpdateOutcome) {
            return $published;
        }
        try {
            $manifest = $this->store->manifestBytes($published);
        } catch (ClientPackRefused $e) {
            return $this->storeFault($e);
        }
        $approved = true;
        if ($cfg->clientUpdateApprovalRequired) {
            try {
                $approved = SeatClientLedger::isApproved($agentName, $published->filesJsonSha256);
            } catch (Throwable $e) {
                return $this->ledgerFault($e, 'this bridge cannot tell whether its published client pack is approved for you (see the bridge log); keep running the installed client');
            }
        }
        try {
            $logHead = SeatClientLedger::logHead($agentName);
        } catch (Throwable $e) {
            // A seat told "no head" re-sends its log from the start, and every line already held is skipped.
            Log::warning('agent-tools: the fleet ledger could not be read for a log head', ['agent' => $agentName, 'error' => RedactedErrorText::of($e)]);
            $logHead = null;
        }

        return ClientUpdateOutcome::success('client_manifest', [
            'published' => [
                'bridge_release' => $published->bridgeRelease,
                'client_version' => $published->clientVersion,
                'files_json_sha256' => $published->filesJsonSha256,
                'manifest_sha256' => $published->manifestSha256,
                'manifest_b64' => base64_encode($manifest),
            ],
            'offer' => $approved ? $published->bridgeRelease : null,
            'approval' => ['required' => $cfg->clientUpdateApprovalRequired, 'owed' => $approved ? null : $published->bridgeRelease],
            'log_head' => $logHead,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function report(string $agentName, array $body): ClientUpdateOutcome
    {
        try {
            $result = SeatClientLedger::report($agentName, $body['install_id'] ?? null, $body['entries'] ?? null);
        } catch (InstallLogRefused $e) {
            return ClientUpdateOutcome::failure(422, $e->getMessage());
        } catch (Throwable $e) {
            return $this->ledgerFault($e, 'this bridge could not store your install report (see the bridge log); nothing was stored — send it again at your next launch');
        }

        return ClientUpdateOutcome::success('client_report', $result);
    }

    private function fleet(BoardToolsConfig $cfg): ClientUpdateOutcome
    {
        if (! $cfg->fleetView) {
            return ClientUpdateOutcome::failure(403, 'client_fleet is served only to an agent whose board_tools.fleet_view is true');
        }
        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
            $fleet = ClientFleet::read($configs, $this->store);
        } catch (Throwable $e) {
            return $this->ledgerFault($e, 'this bridge could not read its fleet ledger (see the bridge log)');
        }

        return ClientUpdateOutcome::success('client_fleet', $fleet->toArray());
    }

    /** The detail is the operator's, in the log; a seat is told only what it cannot have. */
    private function ledgerFault(Throwable $e, string $told): ClientUpdateOutcome
    {
        Log::error('agent-tools: the fleet ledger is unreadable or unwritable', ['error' => RedactedErrorText::of($e)]);

        return ClientUpdateOutcome::failure(503, $told);
    }

    private function pack(mixed $release): ClientUpdateOutcome
    {
        if (! is_string($release) || preg_match(ClientPackManifest::STRICT_VERSION, $release) !== 1) {
            return ClientUpdateOutcome::failure(422, 'client_pack needs `bridge_release`, the bare X.Y.Z release named by client_manifest');
        }
        $published = $this->published();
        if ($published instanceof ClientUpdateOutcome) {
            return $published;
        }
        if ($release !== $published->bridgeRelease) {
            return ClientUpdateOutcome::failure(404, "this bridge serves the client pack for release {$published->bridgeRelease} only, not {$release}");
        }
        try {
            $pack = $this->store->packBytes($published);
        } catch (ClientPackRefused $e) {
            return $this->storeFault($e);
        }

        return ClientUpdateOutcome::success('client_pack', [
            'bridge_release' => $published->bridgeRelease,
            'sha256' => $published->packSha256,
            'size' => $published->packSize,
            'encoding' => 'base64',
            'data' => base64_encode($pack),
        ]);
    }

    private function published(): PublishedClientPack|ClientUpdateOutcome
    {
        try {
            $published = $this->store->published();
        } catch (ClientPackRefused $e) {
            return $this->storeFault($e);
        }

        return $published ?? ClientUpdateOutcome::failure(503, 'this bridge publishes no client pack yet — its operator runs `php artisan bridge:client-pack:install`; keep running the installed client');
    }

    /** The detail is the operator's, in the log; a seat is told only that it cannot be served. */
    private function storeFault(ClientPackRefused $e): ClientUpdateOutcome
    {
        Log::error('agent-tools: client pack store unreadable or inconsistent', ['error' => RedactedErrorText::of($e)]);

        return ClientUpdateOutcome::failure(503, 'this bridge\'s published client pack cannot be served (see the bridge log); keep running the installed client');
    }
}
