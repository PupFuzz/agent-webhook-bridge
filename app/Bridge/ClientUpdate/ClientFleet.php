<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Support\HumanAge;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Tools\ClientCapabilities;
use App\Models\SeatClientState;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The fleet view (card#10567 B4): for every enabled board-tools agent, what its seat reported about
 * its channel-server client, and the ONE {@see FleetState} that report derives. Read by
 * `bridge:client-fleet`, the `client_fleet` op and `bridge:check`'s `client_fleet` leg, which all
 * print what {@see self::toArray()} holds rather than deriving anything of their own.
 *
 * ⛔ IT READS REPORTS, NEVER THE SEAT. Every fact is what the seat sent ({@see SeatClientLedger}),
 * so a state is a claim about those reports: a seat that stopped calling keeps its last state
 * until {@see FleetState::Stale} says the evidence is old.
 *
 * ⚑ `warn` is decided here too, once per seat (its state's verdict plus its caveats), so the check
 * and the command cannot disagree about which seats need the operator. A seat OFF THE UPDATE PATH or NEEDING BOOTSTRAP warns only once this
 * bridge publishes a client pack: before that there is no update path for any seat to be on, and
 * a warning with no possible remedy is noise.
 */
final class ClientFleet
{
    /** A seat silent this long is {@see FleetState::Stale}: a display threshold chosen here, not taken from another leg. */
    public const STALE_AFTER_DAYS = 14;

    /** A restart owed for longer than this since publication warns (design §2.6). */
    public const RESTART_OWED_WARN_DAYS = 7;

    /**
     * @param  list<array<string, mixed>>  $seats
     */
    private function __construct(
        public readonly ?PublishedClientPack $published,
        public readonly ?string $publishedError,
        public readonly array $seats,
    ) {}

    /**
     * Read the fleet for these agents from the ledger and the pack store. The ledger read
     * PROPAGATES (a caller that could not look must not report an empty fleet); an unreadable
     * publication record or capability table is carried as unknown, not thrown.
     *
     * @param  list<AgentConfig>  $configs
     */
    public static function read(array $configs, ?ClientPackStore $store = null, ?Carbon $now = null): self
    {
        $agents = [];
        foreach ($configs as $cfg) {
            if ($cfg->boardTools !== null && $cfg->boardTools->enabled) {
                $agents[$cfg->agentName] = $cfg->boardTools;
            }
        }
        ksort($agents);

        $rows = [];
        foreach (SeatClientState::query()->whereIn('agent', array_keys($agents))->get() as $row) {
            $rows[$row->agent] = $row;
        }
        $published = null;
        $publishedError = null;
        try {
            $published = ($store ?? new ClientPackStore)->published();
        } catch (ClientPackRefused $e) {
            $publishedError = RedactedErrorText::of($e);
        }
        try {
            $caps = ClientCapabilities::bundled();
        } catch (Throwable) {
            $caps = null;
        }

        return self::derive($agents, $rows, $published, $publishedError, SeatClientLedger::approvals(), SeatClientLedger::installedDigests(), $caps, $now ?? Carbon::now());
    }

    /**
     * @param  array<string, BoardToolsConfig>  $agents
     * @param  array<string, SeatClientState>  $rows
     * @param  array<string, list<string>>  $approvals
     * @param  array<string, array<string, string>>  $installedDigests
     */
    public static function derive(array $agents, array $rows, ?PublishedClientPack $published, ?string $publishedError, array $approvals, array $installedDigests, ?ClientCapabilities $caps, Carbon $now): self
    {
        $seats = [];
        foreach ($agents as $agent => $bt) {
            $row = $rows[$agent] ?? new SeatClientState(['agent' => $agent]);
            $approved = $approvals[$agent] ?? [];
            $digestOf = static function (?string $release) use ($installedDigests, $agent, $published): ?string {
                if ($release === null) {
                    return null;
                }

                return $installedDigests[$agent][$release] ?? ($published !== null && $published->bridgeRelease === $release ? $published->filesJsonSha256 : null);
            };
            [$state, $reason] = self::stateOf($agent, $bt, $row, $published, $publishedError, $approved, $digestOf, $now);
            $seats[] = self::seat($agent, $bt, $row, $state, $reason, $published, $approved, $digestOf, $caps, $now);
        }

        return new self($published, $publishedError, $seats);
    }

    /**
     * @param  list<string>  $approved
     * @param  callable(?string): ?string  $digestOf
     * @return array{0: FleetState, 1: string}
     */
    public static function stateOf(string $agent, BoardToolsConfig $bt, SeatClientState $row, ?PublishedClientPack $published, ?string $publishedError, array $approved, callable $digestOf, Carbon $now): array
    {
        $running = $row->running_bridge_release;
        $installed = $row->installed_bridge_release;
        $isApproved = static fn (?string $release): bool => ($d = $digestOf($release)) !== null && in_array($d, $approved, true);

        if ($row->log_discontinuity) {
            return [FleetState::LogDiscontinuity, 'its install log broke — '.UntrustedText::forOperator((string) $row->log_discontinuity_reason).'. The entries were stored as received. A gap clears if the missing lines arrive and the chain is whole again; a line that does not chain to the one before it, or a seq re-sent with different content, keeps this install marked for good (a corrected re-send of that seq is itself a re-send with different content), and only re-bootstrapping the seat\'s client into a new install clears either'];
        }

        if ($bt->clientUpdateApprovalRequired) {
            $unapproved = match (true) {
                $installed !== null && ! $isApproved($installed) => ['installed', $installed],
                $running !== null && $running !== $installed && ! $isApproved($running) => ['runs', $running],
                default => null,
            };
            if ($unapproved !== null) {
                [$verb, $release] = $unapproved;
                $remedy = $published !== null && $published->bridgeRelease === $release
                    ? ". If it is wanted, `php artisan bridge:client-approve {$agent} {$release} --reason=\"…\"` records the approval"
                    : '';

                return [FleetState::UnapprovedInstall, "it requires approval (board_tools.client_update.approval_required) and {$verb} release {$release}, whose client content has no approval for this agent — it did not come through `bridge:client-approve`. Approval is detected here, never enforced on the seat: find out how that build got there{$remedy}"];
            }
        }

        if ($row->last_launch_result === 'failed' && ! self::laterLaunchSeen($row)) {
            return [FleetState::UpdateFailed, 'its last launch-time update failed ('.UntrustedText::forOperator((string) $row->last_launch_error).'); it keeps running what it had'.($running !== null ? " (release {$running})" : '')];
        }

        if ($bt->clientUpdateApprovalRequired && $published !== null && ! in_array($published->filesJsonSha256, $approved, true) && $installed !== $published->bridgeRelease) {
            return [FleetState::ApprovalOwed, "it requires approval and published release {$published->bridgeRelease} (content ".substr($published->filesJsonSha256, 0, 12).') is not approved for it, so the update door offers it nothing. Approve it with `php artisan bridge:client-approve '.$agent.' '.$published->bridgeRelease.' --reason="…"`, or leave the seat where it is'];
        }

        if ($row->last_call_at !== null && $row->last_call_launch_id === null) {
            $version = $row->last_call_client_version !== null ? "client {$row->last_call_client_version}" : 'a client that reports no version';

            return [FleetState::OffUpdatePath, 'its latest board-tools call ('.HumanAge::floored((int) $row->last_call_at->diffInSeconds($now, true))." ago) came from {$version} with no launch identity — a channel server not started by the client updater, so it will not update itself until its client is bootstrapped from this bridge's published pack: ".self::bootstrapRemedy($agent)];
        }

        if ($row->last_call_at === null && $installed === null) {
            if ($row->last_report_at !== null) {
                return [FleetState::NeedsBootstrap, 'its install log has reached this bridge but records no successful install, and no call from its client has arrived — its bootstrap did not complete; bootstrap its client again: '.self::bootstrapRemedy($agent)];
            }
            $probed = $row->last_exempt_call_at !== null
                ? '. Only a '.$row->last_exempt_caller.' call has reached the door for it ('.HumanAge::floored((int) $row->last_exempt_call_at->diffInSeconds($now, true)).' ago), which says nothing about its client'
                : '';

            return [FleetState::NeedsBootstrap, 'no board-tools call from its client and no install report has reached this bridge since it started its fleet ledger, so it has no client on the update path that this bridge knows of'.$probed.'. Bootstrap it: '.self::bootstrapRemedy($agent)];
        }

        $lastSeen = self::lastSeen($row);
        if ($lastSeen !== null && $lastSeen->lt($now->copy()->subDays(self::STALE_AFTER_DAYS))) {
            return [FleetState::Stale, 'nothing from it — no call, no report — for more than '.self::STALE_AFTER_DAYS.' days (last seen '.HumanAge::floored((int) $lastSeen->diffInSeconds($now, true)).' ago), so what it last reported is not current evidence'];
        }

        if ($published !== null) {
            $publishedAt = self::publishedAt($published);
            if ($running !== null && $running === $published->bridgeRelease) {
                return [FleetState::Current, "runs release {$running}, the published one"];
            }
            $launchSeen = self::launchFirstSeen($row);
            if ($running !== null && ChannelSnapshotManifest::compareVersions($running, $published->bridgeRelease) < 0 && $publishedAt !== null && $launchSeen !== null) {
                if ($installed === $published->bridgeRelease || $launchSeen->lte($publishedAt)) {
                    return [FleetState::AppliesNextLaunch, "runs release {$running}; published release {$published->bridgeRelease} reaches it at its next launch, because ".($installed === $published->bridgeRelease ? 'it is already installed' : 'the session it runs started before that release was published')];
                }

                return [FleetState::Behind, "it launched after release {$published->bridgeRelease} was published (that launch first seen ".HumanAge::floored((int) $launchSeen->diffInSeconds($now, true))." ago) and still runs release {$running}, with no failure and no approval reported — its updater did not apply the published release and did not say why"];
            }
        }

        // No launch has called yet: the needs_bootstrap branch above already took the seat with no install.
        if ($running === null && $row->last_call_at === null) {
            return [FleetState::AppliesNextLaunch, "it installed release {$installed} and no launch of that client has called the bridge yet"];
        }

        if ($running !== null && $published === null) {
            return [FleetState::Unverified, $publishedError !== null
                ? "it runs release {$running}, but this bridge's published client pack record cannot be read (".UntrustedText::forOperator($publishedError).'), so that release cannot be compared with anything; repair the record or run `php artisan bridge:client-pack:install` again'
                : "it runs release {$running}, installed through a client-update door, but this bridge publishes no client pack to compare it with — the publication it came from is gone from this bridge, or it came from another bridge"];
        }
        if ($running !== null && ChannelSnapshotManifest::compareVersions($running, $published->bridgeRelease) > 0) {
            return [FleetState::Unverified, "it runs release {$running}, newer than the published {$published->bridgeRelease}, so that build did not come from this bridge's current publication"];
        }

        return [FleetState::Unverified, 'the ledger holds a combination the fleet derivation names no state for (running '.($running ?? 'unknown').', installed '.($installed ?? 'unknown').', published '.($published->bridgeRelease ?? 'nothing').'). This is a gap in the derivation, not in the seat: report it'];
    }

    /**
     * Whether a seat's STATE needs the operator. Every state that is a fault warns; a restart owed
     * warns once the published release is older than {@see self::RESTART_OWED_WARN_DAYS} days; the
     * two "not on the update path" states warn only while a pack is published (see the class
     * docblock). A seat's own `warn` also carries its caveats ({@see self::caveatsOf()}).
     */
    private static function stateWarns(FleetState $state, ?PublishedClientPack $published, Carbon $now): bool
    {
        return match ($state) {
            FleetState::LogDiscontinuity, FleetState::UnapprovedInstall, FleetState::UpdateFailed,
            FleetState::ApprovalOwed, FleetState::Behind, FleetState::Unverified => true,
            FleetState::OffUpdatePath, FleetState::NeedsBootstrap => $published !== null,
            FleetState::AppliesNextLaunch => ($at = $published !== null ? self::publishedAt($published) : null) !== null
                && $at->lt($now->copy()->subDays(self::RESTART_OWED_WARN_DAYS)),
            FleetState::Stale, FleetState::Current => false,
        };
    }

    /**
     * What is true of a seat's configuration whatever its state, and needs the operator.
     *
     * An approval-required agent on the http transport has its channel server on this box. Whether
     * it can approve itself depends on the OS user it runs as, which the bridge cannot see: a
     * same-box multi-user install runs each agent as its own user (docs/board-tools.md § Same-box
     * enablement). So the caveat states the condition rather than asserting the conclusion.
     *
     * @return list<string>
     */
    private static function caveatsOf(string $agent, BoardToolsConfig $bt): array
    {
        if ($bt->clientUpdateApprovalRequired && $bt->transport === 'http') {
            return ["seat {$agent} requires client-update approval and uses the http transport, so its channel server is on this box: if it runs as an OS user that can run `php artisan` here, it can approve itself with `bridge:client-approve` — its approval is then a record, not a gate"];
        }

        return [];
    }

    /**
     * The fleet as data: what `--json` and the `client_fleet` op return.
     *
     * @return array{published: array<string, string>|null, published_error: string|null, spread: array<string, int>, seats: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $spread = [];
        foreach ($this->seats as $seat) {
            $key = $seat['running'] !== null && $seat['last_call_had_launch'] ? $seat['running']['bridge_release'] : $seat['state'];
            $spread[$key] = ($spread[$key] ?? 0) + 1;
        }
        ksort($spread);

        return [
            'published' => $this->published === null ? null : [
                'bridge_release' => $this->published->bridgeRelease,
                'client_version' => $this->published->clientVersion,
                'files_json_sha256' => $this->published->filesJsonSha256,
                'published_at' => $this->published->publishedAt,
            ],
            'published_error' => $this->publishedError,
            'spread' => $spread,
            'seats' => $this->seats,
        ];
    }

    /**
     * @param  list<string>  $approved
     * @param  callable(?string): ?string  $digestOf
     * @return array<string, mixed>
     */
    private static function seat(string $agent, BoardToolsConfig $bt, SeatClientState $row, FleetState $state, string $reason, ?PublishedClientPack $published, array $approved, callable $digestOf, ?ClientCapabilities $caps, Carbon $now): array
    {
        $iso = static fn (?Carbon $t): ?string => $t?->copy()->utc()->toIso8601ZuluString();
        $approvedFlag = static function (?string $release) use ($bt, $digestOf, $approved): ?bool {
            if (! $bt->clientUpdateApprovalRequired || $release === null) {
                return null;
            }
            $digest = $digestOf($release);

            return $digest !== null && in_array($digest, $approved, true);
        };
        // ⛔ AGAINST THIS BRIDGE'S OWN CLIENT, NEVER THE PUBLISHED PACK'S (card#11579): measured
        // against a stale pack, a seat on that same stale client read "none" while it lacked tools
        // this bridge serves — the comparison was circular. Supersedes DL-432's operand.
        $gap = null;
        if ($caps !== null && $row->last_call_at !== null) {
            $raw = $caps->gapFor($row->last_call_client_version, $caps->currentClientVersion);
            if ($raw !== null) {
                $gap = [];
                foreach ($raw as $tool => $arguments) {
                    $gap[] = ['tool' => $tool, 'arguments' => $arguments];
                }
            }
        }

        $stateWarns = self::stateWarns($state, $published, $now);
        $caveats = self::caveatsOf($agent, $bt);

        return [
            'agent' => $agent,
            'transport' => $bt->transport,
            'state' => $state->value,
            'label' => $state->label(),
            'reason' => $reason,
            // ONE verdict per seat, read by bridge:check and bridge:client-fleet alike.
            'warn' => $stateWarns || $caveats !== [],
            'state_warns' => $stateWarns,
            'caveats' => $caveats,
            'approval_required' => $bt->clientUpdateApprovalRequired,
            'running' => $row->running_bridge_release === null ? null : [
                'bridge_release' => $row->running_bridge_release,
                'client_version' => $row->running_client_version,
                'launch_first_seen_at' => $iso(self::launchFirstSeen($row)),
                'approved' => $approvedFlag($row->running_bridge_release),
            ],
            'last_call_had_launch' => $row->last_call_at !== null && $row->last_call_launch_id !== null,
            'last_call_client_version' => $row->last_call_client_version,
            'installed' => $row->installed_bridge_release === null ? null : [
                'bridge_release' => $row->installed_bridge_release,
                'client_version' => $row->installed_client_version,
                'approved' => $approvedFlag($row->installed_bridge_release),
            ],
            'last_seen' => $iso(self::lastSeen($row)),
            'last_exempt_call' => $row->last_exempt_call_at === null ? null : ['caller' => $row->last_exempt_caller, 'at' => $iso($row->last_exempt_call_at)],
            'last_launch' => $row->last_launch_result === null ? null : [
                'result' => $row->last_launch_result,
                'error' => $row->last_launch_error,
                'reported_at' => $iso($row->last_launch_first_reported_at),
            ],
            'capability_gap' => $gap,
            // The client version the gap is measured against: this checkout's own. Null when its
            // capability table did not read, which is also when the gap is null.
            'capability_gap_against' => $caps?->currentClientVersion,
            'log' => $row->install_id === null ? null : [
                'install_id' => $row->install_id,
                'seq' => $row->log_seq,
                'discontinuity' => $row->log_discontinuity,
            ],
        ];
    }

    /**
     * The exact command that moves `$agent`'s seat onto the self-updating client (DL-444), as
     * CLAUDE_DEPLOYMENT.md § Multi-agent channel-server distribution documents it. The two placeholders are the
     * seat's own and not knowable here.
     */
    public static function bootstrapCommand(string $agent): string
    {
        return "python3 bin/provision-board-tools.py --role b --bootstrap-client --agent {$agent} --project-dir <its-claude-project-dir> --channel-name <its-mcp-servers-key>";
    }

    private static function bootstrapRemedy(string $agent): string
    {
        return 'on the seat, as its own OS user, from a bridge checkout at this bridge\'s release tag, run `'.self::bootstrapCommand($agent).'`, then restart its session';
    }

    /** The seat's own evidence: its latest call or report — never a probe's. */
    private static function lastSeen(SeatClientState $row): ?Carbon
    {
        $times = array_filter([$row->last_call_at, $row->last_report_at]);

        return $times === [] ? null : max($times);
    }

    /**
     * When the launch the seat runs began, as this bridge's own clock bounds it: its first call,
     * or the first report of that launch if that arrived earlier (the updater reports before the
     * channel server makes its first call).
     */
    private static function launchFirstSeen(SeatClientState $row): ?Carbon
    {
        $first = $row->running_launch_first_seen_at;
        if ($row->running_launch_id !== null && $row->running_launch_id === $row->last_launch_id && $row->last_launch_first_reported_at !== null) {
            $first = $first === null ? $row->last_launch_first_reported_at : min($first, $row->last_launch_first_reported_at);
        }

        return $first;
    }

    /** A failed launch is superseded once a DIFFERENT launch has been seen calling after its report. */
    private static function laterLaunchSeen(SeatClientState $row): bool
    {
        return $row->running_launch_id !== null
            && $row->running_launch_id !== $row->last_launch_id
            && $row->running_launch_first_seen_at !== null
            && $row->last_launch_first_reported_at !== null
            && $row->running_launch_first_seen_at->gt($row->last_launch_first_reported_at);
    }

    private static function publishedAt(PublishedClientPack $published): ?Carbon
    {
        try {
            return Carbon::parse($published->publishedAt);
        } catch (Throwable) {
            return null;
        }
    }
}
