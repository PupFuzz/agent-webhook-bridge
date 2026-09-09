<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Support\UntrustedPathContents;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The channel-server snapshot MANIFEST reader and version comparator (card#8974 r3).
 *
 * ⚑ THIS CLASS AND ITS SUBJECT WERE BOTH LIFTED OUT OF `ChannelSnapshotProbe`, and the
 * vector table below travelled UNCHANGED — it is one half of a lockstep contract with
 * `bin/test_provision_board_tools.py` (class `VersionComparatorLockstep`), so moving its
 * home is allowed and editing its rows in a move is not. The far side's docstring names
 * this file, so both ends were re-pointed in the same change.
 *
 * WHY THE SUBJECT MOVED: the probe walks a DEPLOYED directory under another OS user's home,
 * and `bin/test_check_channel_snapshot.py` pins every `ChannelSnapshotProbe::` call in
 * `app/` to exactly `probe()` so nothing executable can be smuggled into that walk. These
 * utilities are things the probe USES, not entry points into it; sharing them FROM the probe
 * widened that surface fourfold and the guard caught it.
 */
class ChannelSnapshotManifestTest extends TestCase
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
     * why {@see ChannelSnapshotManifest::compareVersions} exists at all.
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
        $this->assertSame($expected, ChannelSnapshotManifest::compareVersions($a, $b) <=> 0);
    }

    #[DataProvider('versionVectors')]
    public function test_compare_versions_is_antisymmetric(string $a, string $b, int $expected): void
    {
        $this->assertSame(-$expected, ChannelSnapshotManifest::compareVersions($b, $a) <=> 0);
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
                ChannelSnapshotManifest::compareVersions($a, $b) <=> 0,
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
        $this->assertSame([1, 0], ChannelSnapshotManifest::versionTuple("1\u{0663}.0"));
        $this->assertSame([0, 0], ChannelSnapshotManifest::versionTuple("\u{0663}.0"));
        $this->assertSame([2, 0], ChannelSnapshotManifest::versionTuple("2\xff.0"));

        $this->assertSame(0, preg_match('/^[0-9]+/u', "\u{0663}\u{0662}"), '/u does not make [0-9] match non-ASCII digits');
        $this->assertSame(1, preg_match('/^\d+/u', "\u{0663}\u{0662}", $m), '\d + /u is the form that WOULD match them');
        $this->assertSame(0, (int) $m[0], '…and then casts to 0, so it closes nothing');
        $this->assertFalse(@preg_match('/^[0-9]+/u', "2\xff"), '/u newly returns false on invalid UTF-8');
    }

    public function test_version_tuple_takes_leading_digits_per_chunk(): void
    {
        $this->assertSame([0, 8, 0], ChannelSnapshotManifest::versionTuple('0.8.0-rc1'));
        $this->assertSame([1, 0, 0], ChannelSnapshotManifest::versionTuple('1.0.0+build5'));
        $this->assertSame([0], ChannelSnapshotManifest::versionTuple(''));
        $this->assertSame([0, 0], ChannelSnapshotManifest::versionTuple('v1.x'));
    }

    // ---- reading the manifest off a path this process does not control (card#9121) ----
    //
    // The DEPLOYED manifest sits under another OS user's home and `bridge:check` reads it
    // as the operator, so the account being inspected chooses what this process opens. The
    // four statuses are unchanged; what changed is which read produces them. The
    // command-level suite pins the OPERATOR-FACING text of each status
    // (`BridgeCommandsTest::test_check_omits_destructive_advice_when_package_json_is_unreadable`
    // and its siblings — named, never `{@see}`-linked, since pint rewrites a docblock FQCN
    // into a real `use`); these cases pin the READ itself.

    public function test_a_regular_manifest_is_read(): void
    {
        $path = $this->tmpDir().'/package.json';
        file_put_contents($path, '{"name":"snap","version":"1.2.3"}');

        $this->assertSame(['status' => 'ok', 'version' => '1.2.3'], ChannelSnapshotManifest::readManifest($path));
    }

    /**
     * A SYMLINKED manifest is not followed: the bytes it names cannot be attributed to the
     * deployment, and before the guarded reader `is_file()` followed the link and answered
     * about the TARGET — so any regular file on the box could be read as the deployment's
     * version, unboundedly.
     */
    public function test_a_symlinked_manifest_is_not_read_and_reports_unreadable(): void
    {
        $dir = $this->tmpDir();
        file_put_contents($dir.'/elsewhere.json', '{"version":"9.9.9"}');
        symlink($dir.'/elsewhere.json', $dir.'/package.json');

        $read = ChannelSnapshotManifest::readManifest($dir.'/package.json');

        $this->assertSame('unreadable', $read['status']);
        $this->assertSame('', $read['version']);
    }

    /** The read is bounded by the opened file's own `fstat`, never by trust in the path. */
    public function test_a_manifest_past_the_readers_bound_reports_unreadable(): void
    {
        $path = $this->tmpDir().'/package.json';
        file_put_contents($path, '{"version":"1.0.0","pad":"'.str_repeat('p', UntrustedPathContents::MAX_BYTES).'"}');

        $this->assertSame('unreadable', ChannelSnapshotManifest::readManifest($path)['status']);
    }

    /**
     * A path that RESOLVES TO NO FILE is the `absent` operator situation — the one that
     * carries the destructive re-copy advice — and it is exactly where `is_file()` put a
     * directory before the migration. Route the establishing refusal to `unreadable` and
     * this reds.
     */
    public function test_a_directory_at_the_manifest_path_reports_absent(): void
    {
        $dir = $this->tmpDir();
        mkdir($dir.'/package.json');

        $this->assertSame(['status' => 'absent', 'version' => ''], ChannelSnapshotManifest::readManifest($dir.'/package.json'));
    }

    public function test_an_absent_manifest_reports_absent(): void
    {
        $this->assertSame(
            ['status' => 'absent', 'version' => ''],
            ChannelSnapshotManifest::readManifest($this->tmpDir().'/package.json'),
        );
    }

    /**
     * ⚠ THE REASON SENTENCE NAMES NO CAUSE IT CANNOT ESTABLISH. It said "is not readable by
     * this user" until card#9121, which is one of several causes now reaching this status —
     * a wrong-but-specific cause is worse than an honest generic one.
     */
    public function test_the_unreadable_reason_states_only_that_the_file_was_not_read(): void
    {
        $this->assertSame('exists but was NOT read by this process', ChannelSnapshotManifest::manifestReason('unreadable'));
        $this->assertSame('is not present', ChannelSnapshotManifest::manifestReason('absent'));
        $this->assertSame('does not parse as a JSON object', ChannelSnapshotManifest::manifestReason('malformed'));
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/snap-manifest-'.uniqid();
        mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach ((array) glob($dir.'/*') as $entry) {
                is_dir((string) $entry) && ! is_link((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }
}
