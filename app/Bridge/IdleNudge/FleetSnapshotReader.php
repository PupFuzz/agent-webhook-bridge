<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The ONE request the idle nudge makes to Mezzanine, and every way it can fail to measure.
 *
 * ⛔ EVERY FAILURE IS A PASS-LEVEL {@see IdleNudgeUnmeasured}, NEVER AN EMPTY FLEET. A 401, a
 * 503, a maintenance page and a timeout all carry zero seats, and a caller that read "zero
 * seats" as "zero idle seats" would report a blind pass as a quiet one.
 *
 * ⛔ THE TOKEN (canon #20). It is read from a 0600 FILE at use, held in a local, and handed to
 * `withToken()` — it is never a property, never logged, and no message here quotes a response
 * body or a refusal's `message`: a refusal can echo what it was sent. Reasons are this class's
 * vocabulary, the HTTP status and the refusal's `error` token when it is one Mezzanine
 * documents.
 *
 * ⚑ REDIRECTS ARE NOT FOLLOWED. A 3xx is an "other non-2xx": following one would carry the
 * bearer to wherever the redirect points.
 */
final class FleetSnapshotReader
{
    public const PATH = '/api/fleet/snapshot';

    /** @var array<int, list<string>> the documented refusal `error` tokens, per status */
    private const KNOWN_ERRORS = [
        401 => ['unauthenticated', 'token_revoked', 'token_expired', 'token_wrong_surface'],
        403 => ['two_factor_required'],
        429 => ['rate_limited'],
        503 => ['fleet_unavailable'],
    ];

    public function read(IdleNudgeConfig $cfg): FleetSnapshot
    {
        $token = $this->token((string) $cfg->tokenPath);

        $started = hrtime(true);
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(2, $cfg->timeoutS))
                ->timeout($cfg->timeoutS)
                ->get($cfg->baseUrl.self::PATH);
        } catch (ConnectionException) {
            throw new IdleNudgeUnmeasured('the fleet snapshot request did not complete (connect error or timeout after '.$cfg->timeoutS.'s)');
        }
        $transitS = (hrtime(true) - $started) / 1e9;

        if ($response->status() < 200 || $response->status() > 299) {
            throw new IdleNudgeUnmeasured($this->refusal($response));
        }

        return $this->parse($response->json(), (string) $cfg->install, $transitS);
    }

    private function token(string $path): string
    {
        if (! is_file($path)) {
            throw new IdleNudgeUnmeasured("the fleet token file is absent, unreachable, or not a regular file at {$path} (BRIDGE_IDLE_NUDGE_TOKEN_PATH)");
        }
        if (SecretFile::isInsecure($path)) {
            throw new IdleNudgeUnmeasured("the fleet token file at {$path} is group/world-readable — chmod 600");
        }
        try {
            $token = TokenFile::readTrimmed($path);
        } catch (UnreadableSecretException) {
            throw new IdleNudgeUnmeasured("the fleet token file at {$path} could not be read by this process");
        }
        if ($token === null) {
            throw new IdleNudgeUnmeasured("the fleet token file at {$path} is empty");
        }

        return $token;
    }

    private function refusal(Response $response): string
    {
        $status = $response->status();
        $body = json_decode($response->body(), true);
        $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
        $named = $error !== null && in_array($error, self::KNOWN_ERRORS[$status] ?? [], true) ? $error : null;

        if ($status >= 500) {
            return 'the fleet snapshot answered HTTP '.$status.($named !== null ? " ({$named})" : ' (any 5xx body is unmeasured)');
        }
        if ($status === 429) {
            $retry = is_array($body) && is_int($body['retry_after_s'] ?? null) ? ', retry after '.$body['retry_after_s'].'s' : '';

            return 'the fleet snapshot answered HTTP 429 (rate_limited'.$retry.')';
        }

        return 'the fleet snapshot answered HTTP '.$status.' ('.($named ?? 'other').')';
    }

    private function parse(mixed $body, string $install, float $transitS): FleetSnapshot
    {
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new IdleNudgeUnmeasured('the fleet snapshot body is not a JSON object');
        }
        if (($body['api_version'] ?? null) !== 1) {
            throw new IdleNudgeUnmeasured('the fleet snapshot api_version is not 1 — this build reads only version 1');
        }
        $serverTimeMs = FleetSnapshot::instantMs($body['server_time'] ?? null);
        if ($serverTimeMs === null) {
            throw new IdleNudgeUnmeasured('the fleet snapshot server_time is absent or not an rfc3339 instant');
        }

        $fold = is_array($body['fleet'] ?? null) ? ($body['fleet']['fold'] ?? null) : null;
        if ($fold !== 'ok') {
            throw new IdleNudgeUnmeasured(in_array($fold, ['lagging', 'stalled'], true)
                ? "Mezzanine's fold is {$fold} (fleet.fold), so no seat's state can be trusted to be current"
                : 'the fleet snapshot fleet.fold is absent or unrecognised');
        }

        $installs = $body['installs'] ?? null;
        if (! is_array($installs) || ! array_is_list($installs)) {
            throw new IdleNudgeUnmeasured('the fleet snapshot installs member is not a list');
        }

        $seats = [];
        $installPresent = false;
        foreach ($installs as $group) {
            if (! is_array($group) || ! is_array($group['seats'] ?? null) || ! array_is_list($group['seats'])) {
                throw new IdleNudgeUnmeasured('an installs[] entry of the fleet snapshot carries no seats list');
            }
            if (($group['install_id'] ?? null) === $install) {
                $installPresent = true;
            }
            foreach ($group['seats'] as $seat) {
                if (! is_array($seat) || ($seat !== [] && array_is_list($seat))) {
                    throw new IdleNudgeUnmeasured('a seat in the fleet snapshot is not a JSON object');
                }
                $seats[] = $seat;
            }
        }

        if (! $installPresent) {
            throw new IdleNudgeUnmeasured('configured install not present in snapshot (BRIDGE_IDLE_NUDGE_INSTALL)');
        }

        return new FleetSnapshot($serverTimeMs, $seats, $transitS);
    }
}
