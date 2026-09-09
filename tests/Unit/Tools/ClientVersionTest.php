<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Tools\ClientVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The reduction both board-tools doors apply to the caller-supplied `client_version`
 * (card#8974 / DL-364).
 *
 * ⭐ WHAT THIS HAS TO PROVE, and why the shape of the assertions matters: this value is the
 * only thing on the client-half row the FAR END supplies, and the row is printed VERBATIM
 * into a `bridge:check` line. So the reduction is a whitelist — everything it does not
 * recognise becomes NULL, which reads as "not reported" and is a state the leg already had
 * to handle. Two failure directions are therefore pinned together: a legitimate npm version
 * must SURVIVE (a reduction that refused everything would satisfy every rejection case and
 * silently make the whole feature dead), and nothing carrying a newline, an escape or a
 * shell metacharacter may.
 *
 * ⛔ THE LESSON THIS CLASS WAS RE-WRITTEN AROUND (canon #9). The first cut asserted that a
 * newline was refused using `"0.9.14\nboard_tools: …"` — content AFTER the newline — which
 * the old `$` anchor rejected correctly. The shape it was actually blind to is a newline
 * LAST with nothing after it, which `$` ACCEPTS, and that shape appeared in no test here.
 * The guard had never been able to fail on the class it names, and a test that only feeds
 * inputs the guard already rejects is not a control. Both shapes are driven now, and the
 * trailing one is the control for the ANCHOR rather than for the character class.
 *
 * ⛔ IT NEVER REFUSES A CALL, and nothing here could show that — a pure function has no
 * call to refuse. That property lives at the doors, in `ToolsCallCommandTest` and
 * `AgentToolsCallTest`, which drive a garbage value through the real ingress and assert the
 * response is the healthy one.
 */
class ClientVersionTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function acceptedVersions(): array
    {
        return [
            ['0.9.14'],
            ['0.4.4'],
            ['1.0.0-rc1'],
            ['1.0.0-alpha.1'],
            ['0.8.0+build5'],
            ['10.20.30'],
            // 32 characters exactly — the column's width, and the boundary the length
            // refusal below is measured against from the other side.
            ['1.0.0-aaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    #[DataProvider('acceptedVersions')]
    public function test_a_version_shaped_token_is_recorded_as_sent(string $version): void
    {
        $this->assertSame($version, ClientVersion::fromCall($version));
    }

    /** @return array<string, array{0: mixed}> */
    public static function refusedValues(): array
    {
        return [
            'absent' => [null],
            'an integer' => [9],
            'a float' => [0.9],
            'an array' => [['0.9.14']],
            'a bool' => [true],
            'empty' => [''],
            // The three that make this a whitelist rather than decoration: each would be
            // printed verbatim into an operator's terminal by `bridge:check`.
            // ⛔ THE SHAPE THE ANCHOR WAS BLIND TO. `$` in PCRE matches BEFORE a final
            // newline, so the original `/^[0-9A-Za-z.+-]+$/` ACCEPTED this and stored a
            // value `bridge:check` then printed as TWO lines. Measured, then fixed with
            // `\z`. It strictly dominates the case below — which the old anchor rejected
            // correctly, which is exactly why every test in this class passed while the
            // hole was open. Both stay: the pair is what proves the ANCHOR is load-bearing
            // and not just the character class.
            'a TRAILING newline' => ["0.9.14\n"],
            'a trailing carriage return + newline' => ["0.9.14\r\n"],
            'a newline forging a second line' => ["0.9.14\nboard_tools: agent x: ALL CLEAR"],
            'an ANSI escape' => ["\e[2J0.9.14"],
            'a shell metacharacter' => ['0.9.14; rm -rf /'],
            'a space' => ['0.9 .14'],
            'over the column width' => ['1.0.0-aaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_anything_else_is_recorded_as_not_reported(mixed $raw): void
    {
        $this->assertNull(ClientVersion::fromCall($raw));
    }

    /**
     * ⛔ AN OVER-LONG VALUE IS REFUSED, NEVER TRUNCATED, and the pair is the point: 32
     * characters lands whole and 33 lands as null. A truncation would store a version that
     * is not the one the seat sent, and the reading check would then COMPARE it and print a
     * staleness verdict about a version nobody runs.
     */
    public function test_the_length_boundary_refuses_rather_than_truncating(): void
    {
        $atCap = str_repeat('1', 32);
        $overCap = str_repeat('1', 33);

        $this->assertSame($atCap, ClientVersion::fromCall($atCap));
        $this->assertNull(ClientVersion::fromCall($overCap));
    }

    /**
     * ⚠ A NECESSARY LEG THAT IS NOT THE SUFFICIENT ONE — and the first cut of this class
     * claimed it was *"the one way it could become false"*, which was wrong in the exact
     * direction that then happened. A constant AHEAD of the bundled snapshot names a release
     * nobody can be running. But the failure this branch actually hit was EQUALITY: the pin
     * stood at `0.9.14` while `dev` shipped `0.9.14` for an unrelated dependency bump — a
     * release that sends NO version — and this assertion was GREEN throughout. It is kept
     * because it is cheap and still true; the leg that discriminates is the next one.
     */
    public function test_the_first_reporting_snapshot_is_not_ahead_of_the_bundled_one(): void
    {
        $bundled = ChannelSnapshotManifest::readManifest(base_path('examples/channel-servers/package.json'));

        $this->assertSame('ok', $bundled['status'], 'the bundled manifest did not read, so this test measured nothing');
        $this->assertNotSame('', $bundled['version']);
        $this->assertLessThanOrEqual(
            0,
            ChannelSnapshotManifest::compareVersions(ClientVersion::FIRST_REPORTING_SNAPSHOT, $bundled['version']),
            'ClientVersion::FIRST_REPORTING_SNAPSHOT is AHEAD of the snapshot this checkout bundles, so bridge:check tells operators their client is older than a release that does not exist',
        );
    }

    /**
     * ⭐ THE LEG THAT DISCRIMINATES, and it asserts the invariant the constant actually
     * NAMES rather than a bound on its value: **the pinned snapshot is one that SENDS the
     * field**. The pin is a claim about the shipped channel server, so the shipped channel
     * server is what has to be read — a version-to-version comparison can never see the
     * difference between a release that reports and one that does not, which is precisely
     * how the `0.9.14` collision stayed green.
     *
     * It reds if the send is reverted, refactored away, or if the manifest is bumped past a
     * server that no longer sends — the whole class of "the pin names a release that reports
     * nothing". Watched fail once by deleting the `client_version` key from the payload.
     */
    public function test_the_pinned_snapshot_is_one_that_actually_sends_the_field(): void
    {
        $entry = (string) file_get_contents(base_path('examples/channel-servers/agent-webhook-bridge-channel.mjs'));

        // Non-vacuous: the file read and is the entry point, so the absence below would be
        // an absence IN it rather than an empty string.
        $this->assertStringContainsString('CallToolRequestSchema', $entry, 'the bundled entry point did not read as the channel server, so this test measured nothing');
        $this->assertStringContainsString(
            'client_version: CLIENT_VERSION',
            $entry,
            'the bundled channel server no longer SENDS client_version, so ClientVersion::FIRST_REPORTING_SNAPSHOT names a release that reports nothing — bridge:check would tell every operator on it that their client is older than it',
        );
    }

    /**
     * ⭐ THE LANDING-WINDOW LEG — the one that would have caught the collision outright.
     * While this change is unmerged, the snapshot it bumps to IS the snapshot that first
     * sends the field, so the two must be equal. That is what makes a rebase onto a `dev`
     * that bumped the snapshot for some OTHER reason RED rather than green: the bump
     * collapses, the pin stays, and equality breaks.
     *
     * ⚠ RELAX THIS AT THE FIRST POST-MERGE SNAPSHOT BUMP. Once a later release moves
     * `package.json` for an unrelated reason the pin legitimately falls behind, and this leg
     * must be deleted rather than re-synced — re-syncing it would silently re-point the pin
     * at a release, which is the defect. The leg above is the durable one.
     */
    public function test_during_the_landing_window_the_pin_and_the_bundled_snapshot_are_the_same_release(): void
    {
        $bundled = ChannelSnapshotManifest::readManifest(base_path('examples/channel-servers/package.json'));

        $this->assertSame('ok', $bundled['status'], 'the bundled manifest did not read, so this test measured nothing');
        $this->assertSame(
            ClientVersion::FIRST_REPORTING_SNAPSHOT,
            $bundled['version'],
            'the pin and the bundled snapshot have diverged. If this branch is UNMERGED, a rebase has collapsed its snapshot bump into one dev made for another reason: bump the snapshot again and move the pin with it. If a later release has legitimately moved the snapshot, DELETE this leg rather than re-syncing it.',
        );
    }
}
