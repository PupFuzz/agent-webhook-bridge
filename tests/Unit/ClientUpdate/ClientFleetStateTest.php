<?php

namespace Tests\Unit\ClientUpdate;

use App\Bridge\ClientUpdate\ClientFleet;
use App\Bridge\ClientUpdate\FleetState;
use App\Bridge\ClientUpdate\PublishedClientPack;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\ClientCapabilities;
use App\Models\SeatClientState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The fleet's ordered, total state list (card#10567 B4): one seat in EVERY state, each state's
 * precedence over the ones after it, and which states warn. {@see ClientFleet::derive()} is pure
 * over the ledger rows, so the rows are built by hand here.
 */
class ClientFleetStateTest extends TestCase
{
    private const DIGEST = 'b1b2b3b4b5b6b7b8b9b0c1c2c3c4c5c6c7c8c9c0d1d2d3d4d5d6d7d8d9d0e1e2';

    private const OTHER_DIGEST = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-09-28T12:00:00Z');
    }

    private function published(string $release = '0.91.0', string $digest = self::DIGEST, string $at = '2026-09-27T12:00:00Z'): PublishedClientPack
    {
        return new PublishedClientPack($release, '0.9.28', $digest, str_repeat('a', 64), 10, str_repeat('c', 64), $at);
    }

    private static function bt(bool $approval = false, string $transport = 'ssh'): BoardToolsConfig
    {
        return new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: $transport,
            clientUpdateApprovalRequired: $approval,
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function row(array $attrs): SeatClientState
    {
        $row = new SeatClientState(['agent' => 'seat']);
        foreach ($attrs as $k => $v) {
            $row->{$k} = is_string($v) && str_ends_with($k, '_at') ? Carbon::parse($v) : $v;
        }

        return $row;
    }

    /** A seat whose current launch called an hour ago and reported its install at launch. */
    private function launched(string $running, ?string $installed = null, string $launchSeen = '2026-09-28T11:00:00Z'): array
    {
        return [
            'last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.28', 'last_call_launch_id' => 'L2',
            'running_launch_id' => 'L2', 'running_bridge_release' => $running, 'running_client_version' => '0.9.28',
            'running_launch_first_seen_at' => $launchSeen, 'running_seen_at' => '2026-09-28T11:30:00Z',
            'installed_bridge_release' => $installed ?? $running, 'installed_client_version' => '0.9.28',
            'last_report_at' => $launchSeen,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $approved
     * @param  array<string, string>  $installedDigests
     * @return array<string, mixed>
     */
    private function derive(array $row, ?PublishedClientPack $published, bool $approval = false, array $approved = [], array $installedDigests = [], string $transport = 'ssh', ?string $publishedError = null): array
    {
        $fleet = ClientFleet::derive(['seat' => self::bt($approval, $transport)], ['seat' => $this->row($row)], $published, $publishedError, ['seat' => $approved], ['seat' => $installedDigests], ClientCapabilities::bundled(), $this->now);
        $seat = $fleet->toArray()['seats'][0];
        $seat['warns'] = $seat['warn'];

        return $seat;
    }

    /**
     * @return array<string, array{0: FleetState, 1: array<string, mixed>, 2: bool, 3: bool, 4: list<string>, 5: bool}>
     */
    public static function everyState(): array
    {
        $launched = static fn (string $running, ?string $installed = null, string $seen = '2026-09-28T11:00:00Z'): array => [
            'last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.28', 'last_call_launch_id' => 'L2',
            'running_launch_id' => 'L2', 'running_bridge_release' => $running, 'running_client_version' => '0.9.28',
            'running_launch_first_seen_at' => $seen, 'running_seen_at' => '2026-09-28T11:30:00Z',
            'installed_bridge_release' => $installed ?? $running, 'installed_client_version' => '0.9.28',
            'last_report_at' => $seen,
        ];

        // [expected state, row, published?, approval required?, approved digests, warns?]
        return [
            'log_discontinuity' => [FleetState::LogDiscontinuity, $launched('0.91.0') + ['log_discontinuity' => true, 'log_discontinuity_reason' => 'seq 3–4 never arrived'], true, false, [], true],
            'unapproved_install' => [FleetState::UnapprovedInstall, $launched('0.91.0'), true, true, [], true],
            'update_failed' => [FleetState::UpdateFailed, $launched('0.90.0') + ['last_launch_id' => 'L2', 'last_launch_result' => 'failed', 'last_launch_error' => 'bridge unreachable', 'last_launch_first_reported_at' => '2026-09-28T11:00:00Z'], true, false, [], true],
            // A seat still on a legacy client: nothing installed through the door, so nothing unapproved.
            'approval_owed' => [FleetState::ApprovalOwed, ['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.27'], true, true, [self::OTHER_DIGEST], true],
            'off_update_path' => [FleetState::OffUpdatePath, ['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.27'], true, false, [], true],
            'needs_bootstrap (never seen)' => [FleetState::NeedsBootstrap, [], true, false, [], true],
            'needs_bootstrap (a bootstrap that reported and did not complete)' => [FleetState::NeedsBootstrap, ['last_report_at' => '2026-09-28T11:00:00Z', 'install_id' => 'I1'], true, false, [], true],
            'needs_bootstrap (probes only)' => [FleetState::NeedsBootstrap, ['last_exempt_call_at' => '2026-09-28T11:00:00Z', 'last_exempt_caller' => 'probe'], true, false, [], true],
            'stale' => [FleetState::Stale, array_merge($launched('0.91.0', null, '2026-09-01T00:00:00Z'), ['last_call_at' => '2026-09-01T00:00:00Z']), true, false, [], false],
            'current' => [FleetState::Current, $launched('0.91.0'), true, false, [], false],
            'applies_next_launch (launched before publication)' => [FleetState::AppliesNextLaunch, $launched('0.90.0', null, '2026-09-27T00:00:00Z'), true, false, [], false],
            'applies_next_launch (already installed)' => [FleetState::AppliesNextLaunch, $launched('0.90.0', '0.91.0'), true, false, [], false],
            'applies_next_launch (bootstrapped, never launched)' => [FleetState::AppliesNextLaunch, ['installed_bridge_release' => '0.91.0', 'last_report_at' => '2026-09-28T11:00:00Z'], true, false, [], false],
            'behind' => [FleetState::Behind, $launched('0.90.0'), true, false, [], true],
            'unverified (running ahead of the published release)' => [FleetState::Unverified, $launched('0.92.0'), true, false, [], true],
            'unverified (a launched seat on a bridge that publishes nothing)' => [FleetState::Unverified, $launched('0.91.0'), false, false, [], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $approved
     */
    #[DataProvider('everyState')]
    public function test_every_state_is_reachable(FleetState $expected, array $row, bool $published, bool $approval, array $approved, bool $warns): void
    {
        $seat = $this->derive($row, $published ? $this->published() : null, $approval, $approved);

        $this->assertSame($expected->value, $seat['state'], $seat['reason']);
        $this->assertSame($expected->label(), $seat['label']);
        $this->assertSame($warns, $seat['warns'], "whether {$expected->value} warns");
    }

    /**
     * The reachable `unverified` seats name their own cause; only a combination nothing names reads
     * as a gap in the derivation.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: ?string, 3: string}>
     */
    public static function unverifiedCauses(): array
    {
        $launched = ['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_launch_id' => 'L2', 'running_launch_id' => 'L2', 'running_launch_first_seen_at' => '2026-09-28T11:00:00Z', 'installed_bridge_release' => '0.91.0'];

        return [
            'the publication record cannot be read' => [$launched + ['running_bridge_release' => '0.91.0'], false, 'bad json', "it runs release 0.91.0, but this bridge's published client pack record cannot be read (bad json)"],
            'nothing is published' => [$launched + ['running_bridge_release' => '0.91.0'], false, null, 'but this bridge publishes no client pack to compare it with'],
            'running ahead of the published release' => [$launched + ['running_bridge_release' => '0.92.0'], true, null, 'it runs release 0.92.0, newer than the published 0.91.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('unverifiedCauses')]
    public function test_a_reachable_unverified_seat_names_its_cause(array $row, bool $published, ?string $error, string $says): void
    {
        $seat = $this->derive($row, $published ? $this->published() : null, false, [], [], 'ssh', $error);

        $this->assertSame('unverified', $seat['state']);
        $this->assertStringContainsString($says, $seat['reason']);
        $this->assertStringNotContainsString('gap in the derivation', $seat['reason']);
    }

    /** No reason ends in a period: every surface appends its own, and two in a row read as a typo. */
    public function test_no_reason_ends_with_a_period(): void
    {
        foreach (self::everyState() as $name => [$state, $row, $published, $approval, $approved]) {
            $seat = $this->derive($row, $published ? $this->published() : null, $approval, $approved);
            $this->assertStringEndsNotWith('.', $seat['reason'], $name);
        }
        $probed = $this->derive(['last_exempt_call_at' => '2026-09-28T11:00:00Z', 'last_exempt_caller' => 'probe'], null);
        $this->assertStringEndsNotWith('.', $probed['reason']);
    }

    public function test_the_provider_reaches_every_state(): void
    {
        $reached = array_unique(array_map(static fn (array $case): string => $case[0]->value, self::everyState()));
        sort($reached);
        $all = array_map(static fn (FleetState $s): string => $s->value, FleetState::cases());
        sort($all);

        $this->assertSame($all, $reached, 'a state with no row above is a state nothing proves reachable');
    }

    /**
     * Each state outranks the next: a seat meeting several conditions shows the first. Built by
     * stacking every condition on one seat and removing them in precedence order.
     */
    public function test_precedence_is_the_declaration_order(): void
    {
        $row = $this->launched('0.90.0') + [
            'log_discontinuity' => true, 'log_discontinuity_reason' => 'broken',
            'last_launch_id' => 'L2', 'last_launch_result' => 'failed', 'last_launch_error' => 'boom', 'last_launch_first_reported_at' => '2026-09-28T11:00:00Z',
        ];
        $published = $this->published();

        $this->assertSame('log_discontinuity', $this->derive($row, $published, true)['state']);
        $row['log_discontinuity'] = false;
        $this->assertSame('unapproved_install', $this->derive($row, $published, true)['state']);
        $this->assertSame('update_failed', $this->derive($row, $published, true, [self::OTHER_DIGEST], ['0.90.0' => self::OTHER_DIGEST])['state']);
        $row['last_launch_result'] = 'ok';
        $this->assertSame('approval_owed', $this->derive($row, $published, true, [self::OTHER_DIGEST], ['0.90.0' => self::OTHER_DIGEST])['state']);
        $row['last_call_launch_id'] = null;
        $this->assertSame('off_update_path', $this->derive($row, $published, false)['state']);
    }

    /**
     * r2 M-3 at the reader: a probe that called after the seat did changes nothing the seat reported.
     */
    public function test_a_probe_call_does_not_move_a_seats_state(): void
    {
        $row = $this->launched('0.91.0');
        $before = $this->derive($row, $this->published());
        $after = $this->derive($row + ['last_exempt_call_at' => '2026-09-28T11:59:00Z', 'last_exempt_caller' => 'probe'], $this->published());

        $this->assertSame('current', $before['state']);
        $this->assertSame('current', $after['state']);
        $this->assertSame($before['last_seen'], $after['last_seen'], 'a probe is not the seat being seen');
    }

    /** A failure stops counting once a different, later launch has been seen calling. */
    public function test_a_later_launch_supersedes_a_failed_one(): void
    {
        $row = $this->launched('0.91.0') + ['last_launch_id' => 'L1', 'last_launch_result' => 'failed', 'last_launch_error' => 'boom', 'last_launch_first_reported_at' => '2026-09-28T10:00:00Z'];

        $this->assertSame('current', $this->derive($row, $this->published())['state']);
    }

    /** Approval is keyed on content: an unchanged client in a new release is already approved. */
    public function test_an_approval_of_the_same_content_covers_a_later_release(): void
    {
        $published = $this->published('0.92.0');
        $row = $this->launched('0.92.0');

        $this->assertSame('current', $this->derive($row, $published, true, [self::DIGEST])['state']);
        $this->assertTrue($this->derive($row, $published, true, [self::DIGEST])['installed']['approved']);
    }

    public function test_a_restart_owed_for_more_than_a_week_warns(): void
    {
        $old = $this->published('0.91.0', self::DIGEST, '2026-09-20T00:00:00Z');
        $row = $this->launched('0.90.0', null, '2026-09-19T00:00:00Z');
        $seat = $this->derive($row, $old);

        $this->assertSame('applies_next_launch', $seat['state']);
        $this->assertTrue($seat['warns']);
    }

    /** Before anything is published there is no update path, so being off it is not a fault yet. */
    public function test_off_the_update_path_does_not_warn_while_nothing_is_published(): void
    {
        $seat = $this->derive(['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.27'], null);

        $this->assertSame('off_update_path', $seat['state']);
        $this->assertFalse($seat['warns']);
        $this->assertSame('needs_bootstrap', $this->derive([], null)['state']);
        $this->assertFalse($this->derive([], null)['warns']);
    }

    /**
     * card#11579 ask 4: the gap is against THIS BRIDGE'S OWN client (the checkout's capability
     * table), never the published pack's. Measured against a stale pack, a seat on that same stale
     * client read "capability gap: none" while it lacked ci_await — the check was circular.
     */
    public function test_the_capability_gap_is_against_this_bridges_own_client_not_the_published_one(): void
    {
        $own = ClientCapabilities::bundled()->currentClientVersion;

        $onThePack = $this->derive($this->launched('0.91.0'), $this->published());
        $this->assertIsArray($onThePack['capability_gap']);
        $this->assertContains('ci_await', array_column($onThePack['capability_gap'], 'tool'), 'a seat on the published 0.9.28 client lacks what this bridge\'s own client declares');
        $this->assertSame($own, $onThePack['capability_gap_against']);

        $atOwn = $this->derive(['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => $own], $this->published());
        $this->assertSame([], $atOwn['capability_gap']);

        $nothingPublished = $this->derive(['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.15'], null);
        $this->assertNotSame([], $nothingPublished['capability_gap'], 'the gap needs no publication: it is measured against this bridge\'s own client');

        $unreported = $this->derive(['last_call_at' => '2026-09-28T11:30:00Z'], $this->published());
        $this->assertNull($unreported['capability_gap'], 'no version reported ⇒ the gap is unknown, never empty');
    }

    /** card#11579 ask 5: a seat that must be bootstrapped is told the exact command, with its own agent name. */
    public function test_a_seat_needing_bootstrap_is_given_the_exact_command(): void
    {
        $command = '`python3 bin/provision-board-tools.py --role b --bootstrap-client --agent seat --project-dir <its-claude-project-dir> --channel-name <its-mcp-servers-key>`';

        $off = $this->derive(['last_call_at' => '2026-09-28T11:30:00Z', 'last_call_client_version' => '0.9.27'], $this->published());
        $this->assertSame('off_update_path', $off['state']);
        $this->assertStringContainsString($command, $off['reason']);

        foreach ([[], ['last_report_at' => '2026-09-28T11:00:00Z', 'install_id' => 'I1']] as $row) {
            $never = $this->derive($row, $this->published());
            $this->assertSame('needs_bootstrap', $never['state']);
            $this->assertStringContainsString($command, $never['reason']);
        }
    }

    public function test_a_seat_supplied_reason_is_escaped_before_it_is_printed(): void
    {
        $row = $this->launched('0.90.0') + ['last_launch_id' => 'L2', 'last_launch_result' => 'failed', 'last_launch_error' => "boom\n\e[31mFAKE OK", 'last_launch_first_reported_at' => '2026-09-28T11:00:00Z'];
        $seat = $this->derive($row, $this->published());

        $this->assertSame('update_failed', $seat['state']);
        $this->assertStringNotContainsString("\n", $seat['reason']);
        $this->assertStringNotContainsString("\e", $seat['reason']);
    }
}
