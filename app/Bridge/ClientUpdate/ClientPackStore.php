<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Check\Checks\ClientPackSourceCheck;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\StateWriterRefusal;
use RuntimeException;

/**
 * The bridge's store of published channel-server client packs (DL-430), under
 * `<state_dir>/client-packs/`:
 *
 *   published.json                          the one pack this bridge serves ({@see PublishedClientPack})
 *   no-pack.json                            the last release `bridge:client-pack:install` found carrying no
 *                                           pack, and when ({@see recordNoPack()})
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
 * (CLAUDE_DEPLOYMENT.md § Where things land; {@see writerRefusal()}): the data files are `0600` and
 * the directories `0700`, and the doors read them as that user. The ssh door runs as the forced
 * command's account, which is that same user on a working install: it reads the agent YAMLs from
 * the owner-only config dir before it reaches the client-update branch.
 */
final class ClientPackStore
{
    public const PUBLISHED = 'published.json';

    public const NO_PACK = 'no-pack.json';

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

    public function noPackPath(): string
    {
        return $this->dir().'/'.self::NO_PACK;
    }

    /**
     * Record that GitHub release `v<$release>` carries no client pack at all — DL-442's fail-soft
     * release, whose pack build failed and which shipped without one. Only
     * {@see ClientPackSourceCheck} reads it: no remedy on this box can publish a pack that release
     * does not carry, so that check must not FAIL on it. One record, replaced atomically: only the
     * latest finding matters, because the check consults it only for the release this checkout
     * is. Nothing is served from it.
     *
     * @throws ClientPackStoreFault the write failed
     */
    public function recordNoPack(string $release, string $checkedAt): void
    {
        try {
            BridgePaths::ensureDir($this->dir());
            BridgePaths::writeFileAtomic($this->noPackPath(), json_encode(['bridge_release' => $release, 'checked_at' => $checkedAt], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (RuntimeException $e) {
            throw new ClientPackStoreFault('a store write failed: '.RedactedErrorText::of($e), previous: $e);
        }
    }

    /**
     * The release {@see recordNoPack()} last recorded as carrying no pack, and when; null when
     * none is recorded.
     *
     * @return array{release: string, checked_at: string}|null
     *
     * @throws ClientPackRefused a record that exists and cannot be read or parsed — never read as "none"
     */
    public function noPackRecorded(): ?array
    {
        $path = $this->noPackPath();
        if (! file_exists($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        $r = is_string($bytes) ? json_decode($bytes, true, 3) : null;
        if (! is_array($r)
            || ! is_string($r['bridge_release'] ?? null) || preg_match(ClientPackManifest::STRICT_VERSION, $r['bridge_release']) !== 1
            || ! is_string($r['checked_at'] ?? null)) {
            throw new ClientPackRefused("the no-pack record {$path} could not be read or is malformed");
        }

        return ['release' => $r['bridge_release'], 'checked_at' => $r['checked_at']];
    }

    /**
     * Publish a verified pack. The caller has already checked `$packBytes` against the manifest
     * and both against the release's checksums; this re-checks the pack against the manifest,
     * because it is the last step before a seat can be served these bytes.
     *
     * ONE PUBLISH AT A TIME: the read, the comparison and the writes run under `published.json.lock`
     * ({@see BridgePaths::withLockIfFree()}). A second publish while one holds it is refused, not
     * queued — it would only re-judge a publication the first is about to change.
     *
     * A FAILURE PART-WAY leaves the pack and manifest written under their release directory and
     * `published.json` unchanged, because it is written last. The doors read only the files
     * `published.json` names, so they keep serving the previous publication (or answer 503 when
     * there was none); a later publish of that release rewrites the leftover files.
     *
     * @return bool true when published, false when this exact pack was already the published one
     *
     * @throws ClientPackRefused the pack or the publication rules refuse it
     * @throws ClientPackStoreFault this process may not, or could not, write the store, or cannot read its publication record
     */
    public function publish(ClientPackManifest $manifest, string $manifestBytes, string $packBytes, string $publishedAt): bool
    {
        if (strlen($packBytes) !== $manifest->packSize || hash('sha256', $packBytes) !== $manifest->packSha256) {
            throw new ClientPackRefused("the client pack for release {$manifest->bridgeRelease} does not match its manifest's size and sha256");
        }
        $refusal = $this->writerRefusal($manifest->bridgeRelease);
        if ($refusal !== null) {
            // The refusal is the remedy, composed from this install's own paths and account names.
            throw new ClientPackStoreFault('this process may not write the client pack store', $refusal);
        }

        $published = null;
        try {
            $ran = BridgePaths::withLockIfFree($this->publishedPath(), function () use ($manifest, $manifestBytes, $packBytes, $publishedAt, &$published): void {
                $published = $this->publishLocked($manifest, $manifestBytes, $packBytes, $publishedAt);
            });
        } catch (ClientPackRefused|ClientPackStoreFault $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new ClientPackStoreFault('a store write failed: '.RedactedErrorText::of($e), previous: $e);
        }
        if (! $ran) {
            throw new ClientPackStoreFault('another bridge:client-pack:install holds '.$this->publishedPath().'.lock', 'run it again when that one has finished');
        }

        return (bool) $published;
    }

    /**
     * Why THIS process must not write the store, or null when it may — {@see StateWriterRefusal}'s
     * rule over the store's own paths. Its data files are `0600` and its directories `0700`, owned
     * by whoever wrote them, and the doors read them as the receiver's user, so a store written by
     * anyone else answers every seat 503. Checked over the store directory, `published.json`, its
     * lock and `no-pack.json`; `publish()` adds the release directory it is about to write into.
     * Files inside a release directory are not checked: the directory's owner is the only user
     * that can replace them.
     */
    public function writerRefusal(?string $release = null): ?string
    {
        $owned = [$this->dir(), $this->publishedPath(), $this->publishedPath().'.lock', $this->noPackPath()];
        if ($release !== null) {
            $owned[] = $this->dir().'/'.$release;
        }

        return StateWriterRefusal::check(
            $this->publishedPath(),
            $owned,
            'a client pack store root writes is one the receiver cannot read — every seat would be answered 503',
            'give '.$this->dir().' and everything under it back to the user the receiver runs as',
        );
    }

    /**
     * How an operator recovers a publication record that cannot be read — this install's own paths,
     * so it is printed whole wherever it is shown.
     */
    public function recordRecovery(): string
    {
        return 'restore '.$this->publishedPath().' from a backup, or, to republish from scratch, remove it and run bridge:client-pack:install again (the release it named is then not held against a lower one)';
    }

    private function publishLocked(ClientPackManifest $manifest, string $manifestBytes, string $packBytes, string $publishedAt): bool
    {
        try {
            $current = $this->published();
        } catch (ClientPackRefused $e) {
            // Not a verdict on the pack: the record that says what is published cannot be read,
            // so "never lower" and "one release, one pack" cannot be applied.
            throw new ClientPackStoreFault(RedactedErrorText::of($e), $this->recordRecovery(), $e);
        }
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
