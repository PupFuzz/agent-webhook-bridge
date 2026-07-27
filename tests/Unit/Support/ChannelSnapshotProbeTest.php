<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ChannelSnapshotProbe;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChannelSnapshotProbeTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/channel-snapshot-probe-'.uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmp);
        parent::tearDown();
    }

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

    public function test_an_undeclared_server_path_is_unvalidated_and_never_ok(): void
    {
        // card 5170: the leg does not RUN here — nothing was measured. `ok` is the
        // severity that certifies measured-and-clean, so reporting a not-measured
        // finding under it makes "green because checked" and "green because nobody
        // looked" the same output. The message text is unchanged; the severity is
        // the whole fix.
        $findings = ChannelSnapshotProbe::probe(null, $this->reference('1.2.3'));

        $this->assertCount(1, $findings);
        $this->assertSame('unvalidated', $findings[0]['severity']);
        $this->assertNotSame('ok', $findings[0]['severity']);
        $this->assertStringContainsString('channel.server_path not declared', $findings[0]['message']);
        $this->assertStringContainsString('snapshot not validated', $findings[0]['message']);
    }

    public function test_an_unreadable_bundled_manifest_names_its_own_remedy(): void
    {
        // This warn and the unenumerable-reference warn are a MATCHED PAIR — both
        // say "this checkout's X could not be read" — and the reference one spells
        // its action while this one did not. A divergence inside a pair, not a
        // message lacking polish: the operator reads the silent one as unactionable
        // when the fix is the same class of thing (restore/repair a tracked file).
        // The branch had no coverage at all before this, which is how it diverged.
        $reference = $this->tree('bundled-no-manifest', [
            ChannelSnapshotProbe::ENTRY_FILE => "export const x = 1;\n",
        ]);
        $deployed = $this->deployment('1.2.3');

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $warn = $this->findingWith($findings, 'cannot be version-compared');
        $this->assertSame('warn', $warn['severity']);
        $this->assertStringContainsString('is not present', $warn['message']);
        $this->assertStringContainsString('restore or repair it', $warn['message']);
        $this->assertStringContainsString('check that this process can read it', $warn['message']);
    }

    // ---- the retired completeness leg (DL-237) ------------------------------
    // DL-230 answered "will this launch?" by enumerating files. A launch answers it
    // directly, and is more precise in BOTH directions (measured: a pruned copy
    // missing 6 of 10 reference files launches while completeness FAILs it; the
    // DL-230 shape dies on ERR_MODULE_NOT_FOUND). The launch belongs to the SEAT,
    // because the bridge's OS user is not the agent's — so what is left here is the
    // DISCLOSURE that the question went unmeasured.

    public function test_a_version_equal_snapshot_says_the_launch_was_not_measured(): void
    {
        // The branch the DL-230 incident lands in. Before DL-237 the completeness leg
        // answered here; after it, an unqualified `is current` would be a green line
        // on a deployment nobody tried to launch — "green because checked" and "green
        // because nobody looked", the same output again (DL-236 (b)).
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3', omit: ['channel-lib.mjs']);

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $notMeasured = $this->findingWith($findings, 'was NOT launch-tested');
        $this->assertSame('unvalidated', $notMeasured['severity']);
        $this->assertNotSame('ok', $notMeasured['severity']);
        // It names the check, where it is run, and AS WHOM — the last of those is the
        // whole reason this is not a bridge:check leg.
        $this->assertStringContainsString('bin/check-channel-snapshot.py', $notMeasured['message']);
        $this->assertStringContainsString('ON THAT SEAT', $notMeasured['message']);
        $this->assertStringContainsString('the OS user whose session launches the channel server', $notMeasured['message']);
        // The version leg still answers the DRIFT question it owns…
        $this->assertSame('ok', $this->findingWith($findings, 'is current (deployed 1.2.3')['severity']);
        // …and this disclosure must NOT be a fail: the deployment may be perfect, and
        // an exit-code flip here would fail every install that never declared a
        // launch assert (DL-236 (c) — `unvalidated` never touches the exit).
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_a_healthy_version_equal_snapshot_gets_the_same_disclosure(): void
    {
        // The positive control: the disclosure is a property of the BRANCH, not of a
        // defect in the fixture. A green deployment gets it too — that is the point,
        // since a green one is exactly what an operator would otherwise over-read.
        $findings = ChannelSnapshotProbe::probe($this->deployment('1.2.3'), $this->reference('1.2.3'));

        $this->assertSame('unvalidated', $this->findingWith($findings, 'was NOT launch-tested')['severity']);
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_a_stale_snapshot_reports_the_stale_warn_and_nothing_beside_it(): void
    {
        // DL-229 (h): a second line beside the STALE warn pointing at the same single
        // action is noise. The re-copy remediation the warn already carries is what
        // this operator does, and the launch question is downstream of doing it.
        $deployed = $this->deployment('1.0.0', omit: ['channel-lib.mjs']);

        $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));

        $this->assertSame('warn', $this->findingWith($findings, 'is STALE (deployed 1.0.0')['severity']);
        $this->assertNoFinding($findings, 'launch-tested');
        $this->assertNoFinding($findings, 'is MISSING');
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_a_newer_snapshot_says_nothing_about_a_leg_that_no_longer_exists(): void
    {
        // DL-230 (b) gave this branch its own `ok` line whose ENTIRE content was
        // "completeness against X SKIPPED". With no completeness leg that sentence
        // describes machinery that is gone — a stale comment in operator-facing
        // output, which is worse than no line at all.
        $findings = ChannelSnapshotProbe::probe($this->deployment('2.0.0'), $this->reference('1.2.3'));

        $this->assertSame('ok', $this->findingWith($findings, 'is current (deployed 2.0.0')['severity']);
        $this->assertNoFinding($findings, 'completeness');
        $this->assertNoFinding($findings, 'SKIPPED');
        $this->assertNoFinding($findings, 'launch-tested');
    }

    public function test_the_probe_never_enumerates_the_reference_directory(): void
    {
        // The retirement, asserted structurally rather than by absence of a message:
        // an UNREADABLE reference directory used to produce a "could not be
        // enumerated" WARN, because the leg walked it. Nothing walks it now, so the
        // version leg reads its package.json and the run is otherwise untouched.
        $this->skipAsRoot();
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3');
        chmod($reference, 0300);   // +x so package.json still stats, but not +r

        try {
            $findings = ChannelSnapshotProbe::probe($deployed, $reference);
        } finally {
            chmod($reference, 0755);
        }

        $this->assertNoFinding($findings, 'could not be enumerated');
        $this->assertNoFinding($findings, 'reference set');
        $this->assertSame('ok', $this->findingWith($findings, 'is current (deployed 1.2.3')['severity']);
    }

    public function test_extra_files_in_the_deployment_are_not_a_finding(): void
    {
        // Unchanged in substance from DL-230, and kept because it is now a property
        // of the WHOLE probe rather than of one leg: an operator's own modules,
        // scratch files and edits are their business, and nothing here looks at them.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3');
        file_put_contents($deployed.'/my-local-module.mjs', "export const x = 1;\n");
        mkdir($deployed.'/scratch');
        file_put_contents($deployed.'/scratch/notes.txt', "mine\n");

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $this->assertSame([], $this->severities($findings, 'fail'));
        $this->assertSame('ok', $this->findingWith($findings, 'has its entry file and node_modules')['severity']);
    }

    public function test_repo_direct_self_compare_runs_no_version_leg(): void
    {
        // The symlinked topology the README recommends: the "snapshot" IS the
        // reference, so the compare is a self-compare — and with no version compare
        // there is no version-EQUAL branch, hence no launch disclosure either. The
        // presence leg still runs.
        $reference = $this->reference('1.2.3');
        mkdir($reference.'/node_modules');
        $link = $this->tmp.'/link-to-checkout';
        symlink($reference, $link);

        $findings = ChannelSnapshotProbe::probe($link, $reference);

        $this->assertSame('ok', $this->findingWith($findings, 'no snapshot to drift')['severity']);
        $this->assertNoFinding($findings, 'is current (deployed');
        $this->assertNoFinding($findings, 'launch-tested');
        $this->assertSame('ok', $this->findingWith($findings, 'has its entry file and node_modules')['severity']);
    }

    /**
     * A reference directory shaped like `examples/channel-servers`: a manifest, the
     * entry, a sibling module the entry imports, a dotfile and a nested test.
     */
    private function reference(string $version): string
    {
        return $this->tree('reference', [
            'package.json' => (string) json_encode(['name' => 'ref', 'version' => $version]),
            ChannelSnapshotProbe::ENTRY_FILE => "import { deriveMeta } from './channel-lib.mjs';\n",
            'channel-lib.mjs' => "export const deriveMeta = () => ({});\n",
            '.gitignore' => "node_modules/\n",
            'tests/a.test.mjs' => "import 'node:test';\n",
        ]);
    }

    /**
     * A deployment of that reference at $version, minus $omit, with the
     * `node_modules` a prior `npm ci` left behind (never copied, per the authority's
     * `ignore_patterns`).
     *
     * @param  list<string>  $omit
     */
    private function deployment(string $version, array $omit = []): string
    {
        $files = [
            'package.json' => (string) json_encode(['name' => 'ref', 'version' => $version]),
            ChannelSnapshotProbe::ENTRY_FILE => "import { deriveMeta } from './channel-lib.mjs';\n",
            'channel-lib.mjs' => "export const deriveMeta = () => ({});\n",
            '.gitignore' => "node_modules/\n",
            'tests/a.test.mjs' => "import 'node:test';\n",
        ];
        foreach ($omit as $relative) {
            unset($files[$relative]);
        }
        $dir = $this->tree('deployed', $files);
        mkdir($dir.'/node_modules');
        mkdir($dir.'/node_modules/@modelcontextprotocol', 0755, true);
        if (in_array('tests/a.test.mjs', $omit, true)) {
            mkdir($dir.'/tests');   // the directory survives; only the file is gone
        }

        return $dir;
    }

    /**
     * @param  array<string, string>  $files  relative path => contents
     */
    private function tree(string $name, array $files): string
    {
        $root = $this->tmp.'/'.$name;
        mkdir($root, 0755, true);
        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $contents);
        }

        return $root;
    }

    /**
     * @param  list<array{severity: string, message: string}>  $findings
     * @return array{severity: string, message: string}
     */
    private function findingWith(array $findings, string $needle): array
    {
        foreach ($findings as $finding) {
            if (str_contains($finding['message'], $needle)) {
                return $finding;
            }
        }

        $this->fail("no finding contains \"{$needle}\" — got: ".implode(' | ', array_column($findings, 'message')));
    }

    /**
     * @param  list<array{severity: string, message: string}>  $findings
     */
    private function assertNoFinding(array $findings, string $needle): void
    {
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString($needle, $finding['message']);
        }
    }

    /**
     * @param  list<array{severity: string, message: string}>  $findings
     * @return list<string>
     */
    private function severities(array $findings, string $severity): array
    {
        return array_values(array_filter(
            array_column($findings, 'message'),
            fn (string $message, int $i): bool => $findings[$i]['severity'] === $severity,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function skipAsRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
    }
}
