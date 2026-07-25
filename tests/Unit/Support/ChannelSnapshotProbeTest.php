<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ChannelSnapshotProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChannelSnapshotProbeTest extends TestCase
{
    /**
     * The SHARED comparator vector table (DL-229). The declared authority for
     * comparison semantics is `_version_tuple` in `bin/provision-board-tools.py`;
     * these exact pairs + verdicts are asserted against it in
     * `bin/test_provision_board_tools.py` (class `VersionComparatorLockstep`).
     * Change one side without the other and the two implementations silently
     * disagree about which snapshots are stale.
     *
     * The starred rows are where PHP's `version_compare()` DIVERGES from the
     * authority (it honors pre-release/build tags the authority drops) — which is
     * why {@see ChannelSnapshotProbe::compareVersions} exists at all.
     *
     * @return list<array{string, string, int}>
     */
    public static function versionVectors(): array
    {
        return [
            ['0.8.0', '0.8.0', 0],
            ['0.8.0-rc1', '0.8.0', 0],       // * version_compare says -1
            ['0.8', '0.8.0', -1],
            ['0.10.0', '0.9.0', 1],
            ['0.8.0', '0.8.0+build5', 0],    // * version_compare says +1
            ['1.0.0-alpha', '1.0.0', 0],     // * version_compare says -1
            ['', '0.8.0', -1],
        ];
    }

    #[DataProvider('versionVectors')]
    public function test_compare_versions_matches_the_python_authority(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, ChannelSnapshotProbe::compareVersions($a, $b) <=> 0);
    }

    #[DataProvider('versionVectors')]
    public function test_compare_versions_is_antisymmetric(string $a, string $b, int $expected): void
    {
        $this->assertSame(-$expected, ChannelSnapshotProbe::compareVersions($b, $a) <=> 0);
    }

    public function test_version_compare_would_diverge_on_three_vectors(): void
    {
        // PROVE the divergence is real, not folklore: if PHP's version_compare ever
        // agreed on these, the hand-rolled comparator would be pointless. The SUT is
        // asserted on the SAME rows in the same test — comparing version_compare
        // against a literal table alone would stay green through a revert of
        // compareVersions() to version_compare(), i.e. it would not be a guard.
        $starred = [['0.8.0-rc1', '0.8.0'], ['0.8.0', '0.8.0+build5'], ['1.0.0-alpha', '1.0.0']];

        foreach (self::versionVectors() as [$a, $b, $expected]) {
            $pair = [$a, $b];
            $this->assertSame(
                in_array($pair, $starred, true),
                (version_compare($a, $b) <=> 0) !== $expected,
                "version_compare divergence changed for {$a} vs {$b}",
            );
            $this->assertSame(
                $expected,
                ChannelSnapshotProbe::compareVersions($a, $b) <=> 0,
                "the SUT must side with the python authority on {$a} vs {$b}",
            );
        }
    }

    public function test_version_tuple_is_ascii_digit_scoped(): void
    {
        // The measured conformance bound with the python authority. Python's `\d` is
        // Unicode-aware and its ints are arbitrary-precision; PHP's are neither.
        // Adding /u would NOT close the gap: `[0-9]` is an ASCII class either way,
        // so a non-ASCII digit stays unmatched (assert the mechanism, not folklore
        // — the divergence would only surface via `\d` + /u, which matches and then
        // casts to 0, still not python's value). Meanwhile /u alone REGRESSES on
        // invalid UTF-8, where preg_match returns false and the chunk collapses.
        $this->assertSame([1, 0], ChannelSnapshotProbe::versionTuple("1\u{0663}.0"));
        $this->assertSame([0, 0], ChannelSnapshotProbe::versionTuple("\u{0663}.0"));
        $this->assertSame([2, 0], ChannelSnapshotProbe::versionTuple("2\xff.0"));

        $this->assertSame(0, preg_match('/^[0-9]+/u', "\u{0663}\u{0662}"), '/u does not make [0-9] match non-ASCII digits');
        $this->assertSame(1, preg_match('/^\d+/u', "\u{0663}\u{0662}", $m), '\d + /u is the form that WOULD match them');
        $this->assertSame(0, (int) $m[0], '…and then casts to 0, so it closes nothing');
        $this->assertFalse(@preg_match('/^[0-9]+/u', "2\xff"), '/u newly returns false on invalid UTF-8');
    }

    public function test_version_tuple_takes_leading_digits_per_chunk(): void
    {
        $this->assertSame([0, 8, 0], ChannelSnapshotProbe::versionTuple('0.8.0-rc1'));
        $this->assertSame([1, 0, 0], ChannelSnapshotProbe::versionTuple('1.0.0+build5'));
        $this->assertSame([0], ChannelSnapshotProbe::versionTuple(''));
        $this->assertSame([0, 0], ChannelSnapshotProbe::versionTuple('v1.x'));
    }
}
