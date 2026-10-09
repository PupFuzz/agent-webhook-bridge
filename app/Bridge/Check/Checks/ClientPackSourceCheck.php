<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\ClientUpdate\BridgeRelease;
use App\Bridge\ClientUpdate\ClientPackRefused;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Support\Finding;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Tools\ClientCapabilities;
use Throwable;

/**
 * `board_tools.client_pack_source` (card#10567 B2, design review r3-M7): does this bridge publish
 * the client pack of the release its checkout IS? Seats install and update their channel server
 * only from what this bridge publishes (DL-430), so a release whose pack was never published leaves
 * every seat on the previous release's client, silently, until this says so.
 *
 * ⭐ KEYED ON `bridge_release` AGAINST `VERSION`, NEVER ON THE CLIENT VERSION ALONE. Most releases do
 * not change the client, so a client-version compare is silent on exactly the release whose pack
 * build failed or whose install never ran `bridge:client-pack:install` (r3-M7). The client version
 * only decides the SEVERITY of the release-lag arm (below).
 *
 * The causes it cannot tell apart from here — the install never ran the command, or the release
 * carries no pack because its release-time build failed (the fail-soft path, DL-442) — are both
 * named, with the command that tells them apart: `bridge:client-pack:install` says which.
 *
 * ⛔ ONE ARM IS `fail` (card#11579): an OLDER release's pack whose CLIENT is also older than this
 * checkout's own client ({@see ClientCapabilities::$currentClientVersion}). Every seat that installs
 * or updates from this bridge then gets a client missing what this checkout's client declares —
 * measured on a live install: no `ci_await`, with every seat silently polling CI — and the remedy is
 * one command on this box. The client compare is ADDED to the release key, never substituted for
 * it (the r3-M7 reason above), and it fires only where the release also lags: a dev checkout ahead
 * of its last release can carry a newer client than any pack that exists, and a `fail` whose remedy
 * cannot clear it would red every such checkout. Every other arm stays `warn`: no pack, an
 * unreadable record, a rollback, or a release lag with an unchanged client — the bridge itself
 * works and its seats keep what they run. Where the capability table does not read, the client
 * compare is not made and the release-lag arm stays the `warn` it was.
 *
 * Inside the enabled-subset guard, with {@see ClientFleetCheck} — no board-tools seat, no client to
 * serve.
 */
final class ClientPackSourceCheck implements Check
{
    /**
     * The `name:` of `.github/workflows/auto-tag-version.yml`, whose run attaches a missing pack
     * when re-run. Held equal to that file by `ClientPackReleaseWorkflowTest`.
     */
    public const RELEASE_WORKFLOW = 'Auto-tag + GitHub Release on merge to main';

    /**
     * @param  ?ClientCapabilities  $capabilities  this checkout's capability table; null reads the bundled one
     */
    public function __construct(private readonly string $versionFile, private readonly ?ClientCapabilities $capabilities = null) {}

    public function id(): string
    {
        return 'board_tools.client_pack_source';
    }

    public function run(CheckContext $ctx): iterable
    {
        $release = BridgeRelease::of($this->versionFile);
        if ($release === null) {
            yield Finding::unvalidated("client_pack_source: this checkout's VERSION file ({$this->versionFile}) is missing or is not bare X.Y.Z, so which release's client pack this bridge should publish is unknown — whether its seats can get this release's channel server was not checked.");

            return;
        }

        $store = new ClientPackStore;
        try {
            $published = $store->published();
        } catch (ClientPackRefused $e) {
            yield Finding::warn('client_pack_source: the published client pack record cannot be read ('.UntrustedText::forOperator(RedactedErrorText::of($e)).'), so the client-update door answers every seat 503 and no seat can install or update its channel server. To recover, '.$store->recordRecovery().'.');

            return;
        }

        $publish = 'Run `php artisan bridge:client-pack:install` as the receiver\'s user. If it answers that the release carries no client pack: on a release built before DL-442, no re-run attaches one — ship a newer release; otherwise the release\'s pack build failed — re-run that release\'s `'.self::RELEASE_WORKFLOW.'` workflow run, which attaches a missing pack, then run the command again.';

        if ($published === null) {
            yield Finding::warn("client_pack_source: this bridge publishes no client pack, so no seat can install or update its channel server from it. {$publish}");

            return;
        }

        $order = ChannelSnapshotManifest::compareVersions($published->bridgeRelease, $release);
        if ($order < 0) {
            $own = $this->ownCapabilities();
            if ($own !== null && ChannelSnapshotManifest::compareVersions($published->clientVersion, $own->currentClientVersion) < 0) {
                $gap = $own->gapFor($published->clientVersion, $own->currentClientVersion);
                $lacks = $gap === null || $gap === []
                    ? ''
                    : ' — it lacks '.implode(', ', array_keys($gap)).', which this checkout\'s client declares';
                yield Finding::fail("client_pack_source: this bridge publishes release {$published->bridgeRelease}'s client pack (client {$published->clientVersion}), but this checkout is release {$release} with client {$own->currentClientVersion}, so every seat that installs or updates from this bridge gets client {$published->clientVersion}{$lacks}. {$publish}");

                return;
            }
            yield Finding::warn("client_pack_source: this bridge publishes release {$published->bridgeRelease}'s client pack, but this checkout is release {$release}, so seats stay on release {$published->bridgeRelease}'s client. {$publish}");

            return;
        }
        if ($order > 0) {
            yield Finding::warn("client_pack_source: this bridge publishes release {$published->bridgeRelease}'s client pack, newer than release {$release} this checkout is. Publication never moves down, so rolling this bridge back did not roll its seats' client back: they keep release {$published->bridgeRelease}'s. This clears when this checkout is release {$published->bridgeRelease} or later.");

            return;
        }

        yield Finding::ok("client_pack_source: this bridge publishes release {$release}'s client pack (client {$published->clientVersion}), the release this checkout is.");
    }

    /** This checkout's capability table, or null when it does not read (the client compare is then not made). */
    private function ownCapabilities(): ?ClientCapabilities
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        try {
            return ClientCapabilities::bundled();
        } catch (Throwable) {
            return null;
        }
    }
}
