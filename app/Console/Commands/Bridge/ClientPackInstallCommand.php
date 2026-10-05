<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\ClientUpdate\BridgeRelease;
use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackRefused;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\ClientUpdate\ClientPackStoreFault;
use App\Bridge\ClientUpdate\ClientUpdateDoor;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use Throwable;

/**
 * `bridge:client-pack:install` — publish THIS bridge release's channel-server client pack, so the
 * client-update door ({@see ClientUpdateDoor}) can serve it (DL-430).
 *
 * It takes no arguments. The release is this checkout's `VERSION`; the pack is the one the GitHub
 * release `v<VERSION>` of `bridge.client_pack.repo` carries, fetched over the GitHub API with this
 * install's own GitHub read token. Nothing is signed (operator ruling, card#10567): the trust root
 * is the tagged release over TLS, and integrity is the sha256 values checked below.
 *
 * CHECKED before anything is published, each a refusal naming what failed:
 *   - the release carries all three assets — the pack, its manifest and `SHA256SUMS` — or none
 *     (none: the release shipped without a pack, and whatever was published before stays);
 *   - each downloaded asset's size, and its GitHub-recorded `digest` where GitHub reports one;
 *   - `SHA256SUMS` lists the pack and the manifest, with their sha256;
 *   - the manifest is exactly the DL-428 format and names this release;
 *   - the pack's size and sha256 are the manifest's;
 *   - the publication rules {@see ClientPackStore::publish()} owns (never lower; one release,
 *     one pack).
 *
 * EXIT: 0 published, or this exact pack was already published · 1 refused, nothing changed ·
 * 2 could not measure or could not write, nothing changed: no or malformed `VERSION`, a malformed
 * `bridge.client_pack.repo`, no GitHub token, GitHub unreachable or unreadable, a publication
 * record it cannot read, or a store this process may not or could not write
 * ({@see ClientPackStore::writerRefusal()}, another run holding its lock, a failed write — a
 * failure part-way leaves the previous publication in service).
 *
 * Run it as the receiver's OS user, like every command here that writes state
 * (CLAUDE_DEPLOYMENT.md § Where things land).
 */
class ClientPackInstallCommand extends BridgeCommand
{
    protected $signature = 'bridge:client-pack:install';

    protected $description = "Publish this bridge release's channel-server client pack from its GitHub release, verified, for seats to install (DL-430)";

    public const SUMS_ASSET = 'SHA256SUMS';

    /** A pack is a few MiB; the default read timeout is sized for a JSON answer. */
    private const DOWNLOAD_TIMEOUT_SECONDS = 120;

    public function handle(ClientPackStore $store): int
    {
        $release = BridgeRelease::of(base_path('VERSION'));
        if ($release === null) {
            $this->error('bridge:client-pack:install: this checkout\'s VERSION file is missing or is not bare X.Y.Z, so there is no release to publish a pack for. Nothing was changed.');

            return 2;
        }
        $repo = config('bridge.client_pack.repo');
        if (! is_string($repo) || preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repo) !== 1) {
            $this->error('bridge:client-pack:install: bridge.client_pack.repo (BRIDGE_CLIENT_PACK_REPO) is not an owner/repo name. Nothing was changed.');

            return 2;
        }

        $unwritable = $store->writerRefusal();
        if ($unwritable !== null) {
            // Composed from this install's own paths and account names, as `bridge:github-owed` prints the
            // same rule's sentence: not foreign, and the escape's length bound would cut the remedy.
            $this->error("bridge:client-pack:install: will not write the client pack store — {$unwritable}. Nothing was changed.");

            return 2;
        }

        $token = (new GitHubTokenResolver)->resolveForCli($repo);
        if ($token->token === null) {
            $this->error("bridge:client-pack:install: no GitHub read token for {$repo} (".UntrustedText::forOperator((string) $token->problem).'). Nothing was changed.');

            return 2;
        }
        $github = new GitHubReadClient($token->token, self::DOWNLOAD_TIMEOUT_SECONDS);
        $tag = 'v'.$release;
        $current = $this->currentlyPublished($store);

        try {
            $assets = $github->releaseAssets($repo, $tag);
        } catch (Throwable $e) {
            $this->error("bridge:client-pack:install: could not read release {$tag} of {$repo} (".UntrustedText::forOperator(RedactedErrorText::of($e)).'). Nothing was changed.');

            return 2;
        }
        if ($assets === null) {
            $this->error("bridge:client-pack:install: GitHub has no published release {$tag} on {$repo} (or this token cannot see the repo). Nothing was changed; {$current}.");

            return 1;
        }

        $wanted = [ClientPackManifest::packFileName($release), ClientPackManifest::manifestFileName($release), self::SUMS_ASSET];
        $byName = [];
        foreach ($assets as $asset) {
            if (in_array($asset['name'], $wanted, true)) {
                $byName[$asset['name']] = $asset;
            }
        }
        if ($byName === []) {
            $this->error("bridge:client-pack:install: release {$tag} carries no client pack — its pack build failed at release time and the release shipped without one. Nothing was changed; {$current}.");

            return 1;
        }
        $missing = array_values(array_diff($wanted, array_keys($byName)));
        if ($missing !== []) {
            $this->error("bridge:client-pack:install: release {$tag} carries only part of its client pack; missing: ".implode(', ', $missing).". A pack is published from all three assets or not at all. Nothing was changed; {$current}.");

            return 1;
        }

        $bytes = [];
        foreach ($wanted as $name) {
            try {
                $bytes[$name] = $github->releaseAssetBytes($repo, $byName[$name]['id']);
            } catch (Throwable $e) {
                $this->error("bridge:client-pack:install: could not download {$name} from release {$tag} (".UntrustedText::forOperator(RedactedErrorText::of($e)).'). Nothing was changed.');

                return 2;
            }
        }

        try {
            foreach ($wanted as $name) {
                $this->checkAgainstGitHub($name, $bytes[$name], $byName[$name]);
            }
            $this->checkSums($bytes[self::SUMS_ASSET], [$wanted[0] => $bytes[$wanted[0]], $wanted[1] => $bytes[$wanted[1]]]);
            $manifest = ClientPackManifest::parse($bytes[$wanted[1]]);
            if ($manifest->bridgeRelease !== $release) {
                throw new ClientPackRefused("the manifest on release {$tag} names release {$manifest->bridgeRelease}");
            }
            $published = $store->publish($manifest, $bytes[$wanted[1]], $bytes[$wanted[0]], now()->toIso8601ZuluString());
        } catch (ClientPackRefused $e) {
            $this->error('bridge:client-pack:install: refused — '.UntrustedText::forOperator(RedactedErrorText::of($e)).". Nothing was changed; {$current}.");

            return 1;
        } catch (ClientPackStoreFault $e) {
            // The fault can carry a store file's parse error, so it is escaped; the recovery is composed
            // only from this install's own paths and account names, and is printed whole (ClientPackStoreFault).
            $this->error('bridge:client-pack:install: the client pack store could not be used — '.UntrustedText::forOperator(RedactedErrorText::of($e)).'. '
                .($e->recovery === null ? '' : ucfirst($e->recovery).'. ')
                ."What seats are served is unchanged; {$current}.");

            return 2;
        }

        $what = "release {$release} (client {$manifest->clientVersion}, pack sha256 {$manifest->packSha256}, {$manifest->packSize} bytes)";
        $this->line($published
            ? "bridge:client-pack:install: published the client pack for {$what}. Seats pick it up at their next launch."
            : "bridge:client-pack:install: the client pack for {$what} was already published; nothing changed.");

        return 0;
    }

    /** What stays in service when this run publishes nothing — for the refusal text only. */
    private function currentlyPublished(ClientPackStore $store): string
    {
        try {
            $published = $store->published();
        } catch (ClientPackRefused $e) {
            return 'seats are answered 503 until the published client pack record is repaired ('.UntrustedText::forOperator(RedactedErrorText::of($e)).')';
        }

        return $published === null
            ? 'this bridge still publishes no client pack'
            : "release {$published->bridgeRelease}'s client pack stays in service";
    }

    /**
     * @param  array{id: int, name: string, size: int, digest: ?string}  $asset
     */
    private function checkAgainstGitHub(string $name, string $bytes, array $asset): void
    {
        if (strlen($bytes) !== $asset['size']) {
            throw new ClientPackRefused("{$name} downloaded as ".strlen($bytes)." bytes, and GitHub records {$asset['size']}");
        }
        if ($asset['digest'] !== null && $asset['digest'] !== 'sha256:'.hash('sha256', $bytes)) {
            throw new ClientPackRefused("{$name}'s sha256 does not match the digest GitHub records for it");
        }
    }

    /**
     * @param  array<string, string>  $files  asset name => bytes, each of which SHA256SUMS must list
     */
    private function checkSums(string $sums, array $files): void
    {
        $listed = [];
        foreach (preg_split('/\r?\n/', trim($sums)) ?: [] as $line) {
            if (preg_match('/\A([0-9a-f]{64}) [ *](\S.*)\z/', $line, $m) !== 1) {
                throw new ClientPackRefused(self::SUMS_ASSET.' has a line that is not `<sha256>  <file>`');
            }
            if (isset($listed[$m[2]]) && $listed[$m[2]] !== $m[1]) {
                throw new ClientPackRefused(self::SUMS_ASSET." lists {$m[2]} twice with different sha256");
            }
            $listed[$m[2]] = $m[1];
        }
        foreach ($files as $name => $bytes) {
            if (! isset($listed[$name])) {
                throw new ClientPackRefused(self::SUMS_ASSET." does not list {$name}");
            }
            if ($listed[$name] !== hash('sha256', $bytes)) {
                throw new ClientPackRefused("{$name}'s sha256 does not match ".self::SUMS_ASSET);
            }
        }
    }
}
