<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\ChannelSnapshotProbe;
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
     * ⭐ THE PIN'S ONE FALSIFIABLE DIRECTION. `FIRST_REPORTING_SNAPSHOT` names a historical
     * fact — the reference-snapshot release at which the channel server started sending its
     * version — so it is frozen while `examples/channel-servers/package.json` keeps moving,
     * and no test can derive it. What CAN be checked is that it never claims a release that
     * has not happened: a constant AHEAD of the bundled snapshot would make the check's
     * "client < X" sentence name a version nobody can be running, which is the one way a
     * frozen pin goes wrong (a fumbled bump, or a revert of the snapshot bump alone).
     */
    public function test_the_first_reporting_snapshot_is_not_ahead_of_the_bundled_one(): void
    {
        $bundled = ChannelSnapshotProbe::readManifest(base_path('examples/channel-servers/package.json'));

        $this->assertSame('ok', $bundled['status'], 'the bundled manifest did not read, so this test measured nothing');
        $this->assertNotSame('', $bundled['version']);
        $this->assertLessThanOrEqual(
            0,
            ChannelSnapshotProbe::compareVersions(ClientVersion::FIRST_REPORTING_SNAPSHOT, $bundled['version']),
            'ClientVersion::FIRST_REPORTING_SNAPSHOT is AHEAD of the snapshot this checkout bundles, so bridge:check tells operators their client is older than a release that does not exist',
        );
    }
}
