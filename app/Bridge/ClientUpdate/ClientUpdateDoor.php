<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Tools\BoardToolDispatcher;
use Illuminate\Support\Facades\Log;

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
 *                     files_json_sha256, manifest_sha256, manifest_b64}, offer}
 *                     `offer` is the release a seat on the normal path should install — today
 *                     always the published one. 503 when nothing is published.
 *   client_pack       {op, bridge_release}  →  {ok, op, bridge_release, sha256, size,
 *                     encoding: "base64", data}
 *                     404 naming the published release when asked for any other; 422 when
 *                     `bridge_release` is not bare X.Y.Z; 503 when nothing is published.
 * Any other `op` is a 422 naming the ones served. Every stored file is re-checked against the
 * publication record before it is served; a mismatch is a 503, never the bytes.
 *
 * NOT HERE YET, and a caller must not assume otherwise: the approval gate on `offer`, the install
 * log (`client_report`, `log_head`) and the fleet view (`client_fleet`) — the ledger slice adds
 * them as new keys and ops; this door's existing keys do not change meaning when they arrive.
 */
final class ClientUpdateDoor
{
    public const OPS = ['client_manifest', 'client_pack'];

    public function __construct(private readonly ClientPackStore $store) {}

    /**
     * @param  array<string, mixed>  $body  the decoded request, already parsed by ToolCallBody
     */
    public function handle(array $body, string $agentName, string $transport): ClientUpdateOutcome
    {
        $op = $body['op'] ?? null;
        $outcome = match ($op) {
            'client_manifest' => $this->manifest(),
            'client_pack' => $this->pack($body['bridge_release'] ?? null),
            default => ClientUpdateOutcome::failure(422, 'unknown client-update `op` '.json_encode($op).' — this bridge serves '.implode(', ', self::OPS)),
        };
        Log::info('agent-tools: client-update', ['agent' => $agentName, 'op' => is_string($op) ? $op : null, 'transport' => $transport, 'status' => $outcome->status]);

        return $outcome;
    }

    private function manifest(): ClientUpdateOutcome
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

        return ClientUpdateOutcome::success('client_manifest', [
            'published' => [
                'bridge_release' => $published->bridgeRelease,
                'client_version' => $published->clientVersion,
                'files_json_sha256' => $published->filesJsonSha256,
                'manifest_sha256' => $published->manifestSha256,
                'manifest_b64' => base64_encode($manifest),
            ],
            'offer' => $published->bridgeRelease,
        ]);
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
