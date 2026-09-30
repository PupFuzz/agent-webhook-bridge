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

/**
 * `board_tools.client_pack_source` (card#10567 B2, design review r3-M7): does this bridge publish
 * the client pack of the release its checkout IS? Seats install and update their channel server
 * only from what this bridge publishes (DL-430), so a release whose pack was never published leaves
 * every seat on the previous release's client, silently, until this says so.
 *
 * ⭐ KEYED ON `bridge_release` AGAINST `VERSION`, NEVER ON THE CLIENT VERSION. Most releases do not
 * change the client, so a client-version compare is silent on exactly the release whose pack build
 * failed or whose install never ran `bridge:client-pack:install` (r3-M7).
 *
 * The causes it cannot tell apart from here — the install never ran the command, or the release
 * carries no pack because its release-time build failed (the fail-soft path, DL-442) — are both
 * named, with the command that tells them apart: `bridge:client-pack:install` says which.
 *
 * NEVER `fail`: the bridge itself works without a published pack; its seats keep what they run.
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

    public function __construct(private readonly string $versionFile) {}

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

        $publish = 'Run `php artisan bridge:client-pack:install` as the receiver\'s user. If it answers that the release carries no client pack, the release\'s pack build failed: re-run that release\'s `'.self::RELEASE_WORKFLOW.'` workflow run, which attaches a missing pack, then run the command again.';

        if ($published === null) {
            yield Finding::warn("client_pack_source: this bridge publishes no client pack, so no seat can install or update its channel server from it. {$publish}");

            return;
        }

        $order = ChannelSnapshotManifest::compareVersions($published->bridgeRelease, $release);
        if ($order < 0) {
            yield Finding::warn("client_pack_source: this bridge publishes release {$published->bridgeRelease}'s client pack, but this checkout is release {$release}, so seats stay on release {$published->bridgeRelease}'s client. {$publish}");

            return;
        }
        if ($order > 0) {
            yield Finding::warn("client_pack_source: this bridge publishes release {$published->bridgeRelease}'s client pack, newer than release {$release} this checkout is. Publication never moves down, so rolling this bridge back did not roll its seats' client back: they keep release {$published->bridgeRelease}'s. This clears when this checkout is release {$published->bridgeRelease} or later.");

            return;
        }

        yield Finding::ok("client_pack_source: this bridge publishes release {$release}'s client pack (client {$published->clientVersion}), the release this checkout is.");
    }
}
