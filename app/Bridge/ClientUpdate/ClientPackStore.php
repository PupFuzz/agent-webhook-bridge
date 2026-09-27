<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelSnapshotManifest;

/**
 * The bridge's store of published channel-server client packs (DL-430), under
 * `<state_dir>/client-packs/`:
 *
 *   published.json                          the one pack this bridge serves ({@see PublishedClientPack})
 *   <X.Y.Z>/client-pack-v<X.Y.Z>.tar.gz     a pack, as the release carried it
 *   <X.Y.Z>/client-pack-v<X.Y.Z>.manifest.json
 *
 * Each file is replaced atomically, and `published.json` is written last, so a reader either sees
 * the previous publication or the new one with its files in place.
 *
 * ⛔ PUBLICATION NEVER MOVES DOWN. A release below the published one is refused, so rolling a
 * bridge back keeps the newer pack in service rather than offering seats a downgrade. The same
 * release with different pack bytes is refused too: `auto-tag-version.yml` never rebuilds an
 * existing tag's assets, so two byte-sets under one release mean something other than the release
 * pipeline wrote one of them. (The `v*` tag ruleset pins the tag ref; it does not make release
 * assets immutable, so this is a loud check, not a guarantee — design review r3-m9.)
 *
 * Every writer runs as the receiver's OS user, as every other state file here requires
 * (CLAUDE_DEPLOYMENT.md § Where things land): the files are 0600, and the doors read them as that
 * user.
 */
final class ClientPackStore
{
    public const PUBLISHED = 'published.json';

    public function dir(): string
    {
        return BridgePaths::stateDir().'/client-packs';
    }

    public function publishedPath(): string
    {
        return $this->dir().'/'.self::PUBLISHED;
    }

    /**
     * The pack this bridge serves, or null when it has never published one.
     *
     * @throws ClientPackRefused a record that exists and cannot be read or parsed — never read as "none"
     */
    public function published(): ?PublishedClientPack
    {
        $path = $this->publishedPath();
        if (! file_exists($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            throw new ClientPackRefused("the published client pack record {$path} exists and could not be read");
        }

        return PublishedClientPack::fromJson($bytes, $path);
    }

    /**
     * Publish a verified pack. The caller has already checked `$packBytes` against the manifest
     * and both against the release's checksums; this re-checks the pack against the manifest,
     * because it is the last step before a seat can be served these bytes.
     *
     * @return bool true when published, false when this exact pack was already the published one
     *
     * @throws ClientPackRefused
     */
    public function publish(ClientPackManifest $manifest, string $manifestBytes, string $packBytes, string $publishedAt): bool
    {
        if (strlen($packBytes) !== $manifest->packSize || hash('sha256', $packBytes) !== $manifest->packSha256) {
            throw new ClientPackRefused("the client pack for release {$manifest->bridgeRelease} does not match its manifest's size and sha256");
        }

        $current = $this->published();
        if ($current !== null) {
            $order = ChannelSnapshotManifest::compareVersions($manifest->bridgeRelease, $current->bridgeRelease);
            if ($order < 0) {
                throw new ClientPackRefused("release {$manifest->bridgeRelease} is below the published client pack's release {$current->bridgeRelease}; publication never moves down, so seats keep {$current->bridgeRelease}");
            }
            if ($order === 0) {
                if ($current->packSha256 === $manifest->packSha256) {
                    return false;
                }

                throw new ClientPackRefused("release {$manifest->bridgeRelease} is already published with pack sha256 {$current->packSha256}, and this pack's sha256 is {$manifest->packSha256}: one release never carries two packs, so something other than the release pipeline wrote one of them. Nothing was changed");
            }
        }

        $releaseDir = $this->dir().'/'.$manifest->bridgeRelease;
        BridgePaths::ensureDir($releaseDir);
        BridgePaths::writeFileAtomic($releaseDir.'/'.ClientPackManifest::packFileName($manifest->bridgeRelease), $packBytes);
        BridgePaths::writeFileAtomic($releaseDir.'/'.ClientPackManifest::manifestFileName($manifest->bridgeRelease), $manifestBytes);
        BridgePaths::writeFileAtomic($this->publishedPath(), PublishedClientPack::of($manifest, $manifestBytes, $publishedAt)->toJson());

        return true;
    }

    /**
     * The published manifest's bytes, checked against the publication record.
     *
     * @throws ClientPackRefused
     */
    public function manifestBytes(PublishedClientPack $published): string
    {
        $bytes = $this->readReleaseFile($published->bridgeRelease, ClientPackManifest::manifestFileName($published->bridgeRelease));
        if (hash('sha256', $bytes) !== $published->manifestSha256) {
            throw new ClientPackRefused("the stored manifest for release {$published->bridgeRelease} no longer matches its publication record");
        }

        return $bytes;
    }

    /**
     * The published pack's bytes, checked against the publication record before they are served.
     *
     * @throws ClientPackRefused
     */
    public function packBytes(PublishedClientPack $published): string
    {
        $bytes = $this->readReleaseFile($published->bridgeRelease, ClientPackManifest::packFileName($published->bridgeRelease));
        if (strlen($bytes) !== $published->packSize || hash('sha256', $bytes) !== $published->packSha256) {
            throw new ClientPackRefused("the stored client pack for release {$published->bridgeRelease} no longer matches its publication record");
        }

        return $bytes;
    }

    private function readReleaseFile(string $release, string $name): string
    {
        $path = $this->dir().'/'.$release.'/'.$name;
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            throw new ClientPackRefused("the published client pack file {$path} could not be read");
        }

        return $bytes;
    }
}
