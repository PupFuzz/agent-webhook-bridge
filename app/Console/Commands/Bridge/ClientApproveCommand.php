<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackRefused;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\ClientUpdate\SeatClientLedger;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Support\UntrustedText;
use App\Models\SeatClientEvent;

/**
 * `bridge:client-approve <agent> <bridge_release> --reason=…` — approve the published client pack
 * for one agent whose `board_tools.client_update.approval_required` is true, so the client-update
 * door offers it (card#10567 B4, DL-433).
 *
 * ⭐ AN APPROVAL IS OF CONTENT, NOT OF A RELEASE (operator ruling, card#10567 comment 6774): it is
 * recorded against the published pack's `files_json_sha256`, so a later release whose client bytes
 * are unchanged is already approved. `<bridge_release>` must name the release published NOW —
 * the operator states which pack they mean, and a publication that moved underneath them is
 * refused rather than approved unseen.
 *
 * ⛔ A RECORD AND A GATE ON THE NORMAL PATH, NEVER ENFORCEMENT (operator ruling 6, comment 6757):
 * the seat's own agent can install anything on its own account, and a bypass is DETECTED
 * (`unapproved_install` in `bridge:client-fleet` and `bridge:check`), never prevented. Anyone who
 * can run this command on the bridge host can approve — the PM agent included — and every
 * approval is logged with the OS user who ran it and the reason given.
 *
 * EXIT: 0 approved, or already approved · 1 refused, nothing recorded · 2 could not read the
 * agent YAMLs or the publication record.
 */
class ClientApproveCommand extends BridgeCommand
{
    protected $signature = 'bridge:client-approve {agent : the agent whose seat may install the published client pack} {bridge_release : the published release, bare X.Y.Z — must be the one published now} {--reason= : why, recorded with the approval}';

    protected $description = 'Approve the published channel-server client pack\'s content for one agent that requires approval; logged (card#10567)';

    public function handle(ClientPackStore $store): int
    {
        $agent = (string) $this->argument('agent');
        $release = (string) $this->argument('bridge_release');
        $reason = trim((string) $this->strOption('reason'));
        if ($reason === '') {
            $this->error('bridge:client-approve: --reason is required — it is recorded with the approval and shown beside it. Nothing was recorded.');

            return 1;
        }
        if (mb_strlen($reason) > SeatClientEvent::REASON_MAX_CHARS) {
            $this->error('bridge:client-approve: --reason is '.mb_strlen($reason).' characters; the approval record holds at most '.SeatClientEvent::REASON_MAX_CHARS.'. Shorten it. Nothing was recorded.');

            return 1;
        }
        if (preg_match(ClientPackManifest::STRICT_VERSION, $release) !== 1) {
            $this->error('bridge:client-approve: the release must be bare X.Y.Z (e.g. 0.91.0), not '.UntrustedText::forOperator($release).'. Nothing was recorded.');

            return 1;
        }

        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            $this->error('bridge:client-approve: the agent YAMLs could not be loaded ('.RedactedErrorText::of($e).'). Nothing was recorded.');

            return 2;
        }
        $bt = null;
        foreach ($configs as $cfg) {
            if ($cfg->agentName === $agent) {
                $bt = $cfg->boardTools;
            }
        }
        if ($bt === null || ! $bt->enabled) {
            $this->error('bridge:client-approve: '.UntrustedText::forOperator($agent).' is not an agent with an enabled board_tools block on this bridge, so it has no client to approve. Nothing was recorded.');

            return 1;
        }

        try {
            $published = $store->published();
        } catch (ClientPackRefused $e) {
            $this->error('bridge:client-approve: the published client pack record cannot be read ('.UntrustedText::forOperator(RedactedErrorText::of($e)).'). Nothing was recorded.');

            return 2;
        }
        if ($published === null) {
            $this->error('bridge:client-approve: this bridge publishes no client pack yet (`php artisan bridge:client-pack:install`), so there is nothing to approve. Nothing was recorded.');

            return 1;
        }
        if ($published->bridgeRelease !== $release) {
            $this->error("bridge:client-approve: this bridge publishes release {$published->bridgeRelease}, not {$release} — an approval is of what is published now. Nothing was recorded.");

            return 1;
        }

        // A database that cannot be read or written is "could not read" — exit 2, as documented above.
        $rc = $this->guardDatabase(function () use ($agent, $published, $reason, $bt): int {
            $result = SeatClientLedger::approve($agent, $published, self::actor(), $reason);
            $what = "release {$published->bridgeRelease}'s client pack (client {$published->clientVersion}, content ".substr($published->filesJsonSha256, 0, 12).')';
            $event = $result['event'];
            if (! $result['new']) {
                $this->line("bridge:client-approve: {$agent} already has an approval of this content — event {$event->seq}, by ".UntrustedText::forOperator((string) $event->actor).' on '.$event->received_at->toIso8601ZuluString().': '.UntrustedText::forOperator((string) $event->reason).'. Nothing new was recorded.');

                return self::SUCCESS;
            }
            $recorded = "bridge:client-approve: approved {$what} for {$agent} — event {$event->seq}, by ".UntrustedText::forOperator((string) $event->actor).'.';
            $this->line($bt->clientUpdateApprovalRequired
                ? $recorded.' The update door now offers it to that seat.'
                : $recorded." {$agent} does not require approval (board_tools.client_update.approval_required is not true), so the door already offered it and this approval gates nothing today; it is recorded all the same.");

            return self::SUCCESS;
        });

        return $rc === self::SUCCESS ? self::SUCCESS : 2;
    }

    /** The OS user who ran this: the one identity the bridge host itself vouches for. */
    private static function actor(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && is_string($pw['name'] ?? null) && $pw['name'] !== '') {
                return $pw['name'];
            }
        }

        return get_current_user();
    }
}
