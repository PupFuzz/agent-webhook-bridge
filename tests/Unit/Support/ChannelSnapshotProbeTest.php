<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ChannelSnapshotProbe;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChannelSnapshotProbeTest extends TestCase
{
    /**
     * The SHARED FILE-SET vector (DL-230), and the reason it is spelled as a tree
     * rather than described in prose: the reference enumeration must deliver exactly
     * what `_deploy_snapshot`'s
     * `shutil.copytree(..., ignore=shutil.ignore_patterns("node_modules"))` delivers —
     * RECURSIVE, dotfiles INCLUDED, `node_modules` excluded at ANY depth. The SAME
     * tree and the SAME expected set are run through the python authority in
     * `bin/test_provision_board_tools.py` (class `SnapshotFileSetLockstep`). Change
     * one side without the other and `bridge:check` starts calling a faithful copy
     * incomplete, or calls an incomplete one whole.
     *
     * @var list<string>
     */
    private const LOCKSTEP_TREE = [
        'package.json',
        'agent-webhook-bridge-channel.mjs',
        '.gitignore',
        'lib/helper.mjs',
        'lib/.hidden',
        'tests/a.test.mjs',
        'node_modules/pkg/index.js',
        'lib/node_modules/nested.js',
    ];

    /** @var list<string> */
    private const LOCKSTEP_EXPECTED = [
        '.gitignore',
        'agent-webhook-bridge-channel.mjs',
        'lib/.hidden',
        'lib/helper.mjs',
        'package.json',
        'tests/a.test.mjs',
    ];

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

    // ---- the VERSION-GATED completeness leg (DL-230) -------------------------
    // The DL-229 shape passed GREEN on the incident that motivated the feature: an
    // entry + package.json cherry-picked from the CURRENT reference carry the
    // version stamp with them, so the version leg reads "current" and every other
    // leg is satisfied while node dies on ERR_MODULE_NOT_FOUND.

    public function test_reference_enumeration_matches_the_python_copytree_authority(): void
    {
        // LOCKSTEP with `bin/test_provision_board_tools.py` class
        // `SnapshotFileSetLockstep`, which runs this same tree through the real
        // `shutil.copytree(..., ignore=ignore_patterns("node_modules"))`.
        $reference = $this->tree('reference', array_fill_keys(self::LOCKSTEP_TREE, "x\n"));

        $this->assertSame(self::LOCKSTEP_EXPECTED, ChannelSnapshotProbe::referenceFileSet($reference));
    }

    public function test_unreadable_reference_directory_does_not_enumerate_as_empty(): void
    {
        // A set-derived verdict off a read that never happened. An unreadable
        // reference scandirs to nothing, and "nothing is missing" would be a false
        // GREEN — the exact defect class this leg closes.
        $this->skipAsRoot();
        $reference = $this->tree('reference', ['package.json' => '{"version":"1.0.0"}']);
        chmod($reference, 0300);   // +x (stat works, so the version leg still reads) but not +r

        try {
            $this->assertNull(ChannelSnapshotProbe::referenceFileSet($reference));
        } finally {
            chmod($reference, 0755);
        }
    }

    public function test_version_equal_snapshot_missing_a_reference_file_fails(): void
    {
        // THE MOTIVATING INCIDENT, exactly: entry + package.json copied from the
        // current reference, node_modules present from a prior install, the sibling
        // module NOT copied.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3', omit: ['channel-lib.mjs']);

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $completeness = $this->findingWith($findings, 'is MISSING');
        $this->assertSame('fail', $completeness['severity']);
        $this->assertStringContainsString('claims version 1.2.3', $completeness['message']);
        $this->assertStringContainsString('is MISSING 1 of the 5 files a whole-directory copy of', $completeness['message']);
        $this->assertStringContainsString('delivers: channel-lib.mjs.', $completeness['message']);
        $this->assertStringContainsString('ERR_MODULE_NOT_FOUND', $completeness['message']);
        $this->assertStringContainsString('re-copy the WHOLE directory', $completeness['message']);
        // The legs that certified this deployment GREEN before DL-230 still do — the
        // incident was that they were the ONLY ones asked.
        $this->assertSame('ok', $this->findingWith($findings, 'is current (deployed 1.2.3')['severity']);
        $this->assertSame('ok', $this->findingWith($findings, 'has its entry file and node_modules')['severity']);
    }

    public function test_version_stale_snapshot_missing_files_does_not_reach_the_completeness_leg(): void
    {
        // The population that got the ungated leg CUT (DL-229 (f)): a whole-directory
        // copy taken at an older tag is legitimately missing everything the checkout
        // has added since. It must stay a STALE warn and nothing else — the warn
        // already carries the identical remediation.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.0.0', omit: ['channel-lib.mjs', 'tests/a.test.mjs']);

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $this->assertSame('warn', $this->findingWith($findings, 'is STALE (deployed 1.0.0')['severity']);
        $this->assertNoFinding($findings, 'is MISSING');
        $this->assertNoFinding($findings, 'SKIPPED');   // no second line beside the STALE warn
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_version_newer_snapshot_skips_completeness_and_says_so(): void
    {
        // This checkout is not authoritative for a deployment newer than itself: a
        // file it does not ship may simply not exist here yet, so "missing" would be
        // a claim about the checkout. Stated, not left to fall out of the `>=`.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('2.0.0', omit: ['channel-lib.mjs']);

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $skip = $this->findingWith($findings, 'completeness against');
        $this->assertSame('ok', $skip['severity']);
        $this->assertStringContainsString('is NEWER than this checkout (deployed 2.0.0 > bundled 1.2.3)', $skip['message']);
        $this->assertNoFinding($findings, 'is MISSING');
    }

    public function test_a_complete_version_equal_snapshot_is_ok(): void
    {
        // The positive control: the FAIL above is caused by the omission, not by the
        // fixture being generally unlike the reference.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3');

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $this->assertSame('ok', $this->findingWith($findings, 'holds every file')['severity']);
        $this->assertNoFinding($findings, 'is MISSING');
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_extra_files_in_the_deployment_are_not_a_finding(): void
    {
        // Only the reference direction is checked. An operator's own modules,
        // scratch files and edits are their business — the DL-229 (f) surface that
        // turned a customized deployment into a FAIL with destructive advice.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3');
        file_put_contents($deployed.'/my-local-module.mjs', "export const x = 1;\n");
        mkdir($deployed.'/scratch');
        file_put_contents($deployed.'/scratch/notes.txt', "mine\n");

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $this->assertSame('ok', $this->findingWith($findings, 'holds every file')['severity']);
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_repo_direct_self_compare_runs_no_completeness_leg(): void
    {
        // The symlinked topology the README recommends: the "snapshot" IS the
        // reference, so the compare is a self-compare — a no-op, not an ok verdict
        // about a deployment that does not exist.
        $reference = $this->reference('1.2.3');
        mkdir($reference.'/node_modules');
        $link = $this->tmp.'/link-to-checkout';
        symlink($reference, $link);

        $findings = ChannelSnapshotProbe::probe($link, $reference);

        $this->assertSame('ok', $this->findingWith($findings, 'no snapshot to drift')['severity']);
        $this->assertNoFinding($findings, 'holds every file');
        $this->assertNoFinding($findings, 'is MISSING');
    }

    public function test_an_untraversable_deployed_subdirectory_warns_rather_than_reporting_its_files_missing(): void
    {
        // The DL-229 round-1 blocker, one level down: the hoisted visibility gate
        // proves `+x` for DIRECT CHILDREN of the deployed directory only. A `0700`
        // `tests/` makes every reference file under it stat as absent, and this leg's
        // remediation (`cp -R` over the deployment) is destructive — so absence is
        // not a conclusion we are entitled to draw there either.
        $this->skipAsRoot();
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3', omit: ['tests/a.test.mjs']);
        chmod($deployed.'/tests', 0000);

        try {
            $findings = ChannelSnapshotProbe::probe($deployed, $reference);
        } finally {
            chmod($deployed.'/tests', 0755);
        }

        $unverified = $this->findingWith($findings, 'is not visible to this user');
        $this->assertSame('warn', $unverified['severity']);
        // Names the SUBDIRECTORY that needs +x, not the deployment root and not a file.
        $this->assertStringContainsString("channel server path {$deployed}/tests is not visible", $unverified['message']);
        $this->assertNoFinding($findings, 'is MISSING');
        $this->assertSame([], $this->severities($findings, 'fail'));
    }

    public function test_a_blocked_subdirectory_does_not_discard_a_conclusively_missing_file(): void
    {
        // DL-230 (e)'s rule is "absence is not conclusive where we could not see" —
        // NOT "one unseeable path voids every seen one". Returning on the first block
        // threw away a module proven absent through a traversable parent, so a
        // version-matched deployment genuinely missing it exited 0 behind the
        // visibility WARN: this card's own defect class, one sub-population down.
        $this->skipAsRoot();
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3', omit: ['channel-lib.mjs', 'tests/a.test.mjs']);
        chmod($deployed.'/tests', 0000);

        try {
            $findings = ChannelSnapshotProbe::probe($deployed, $reference);
        } finally {
            chmod($deployed.'/tests', 0755);
        }

        $fail = $this->findingWith($findings, 'is MISSING');
        $this->assertSame('fail', $fail['severity']);
        $this->assertStringContainsString('delivers: channel-lib.mjs.', $fail['message']);
        // The blocked path leaves the ACCOUNTING, not the verdict — and the FAIL
        // says so, so its list is never read as a complete one.
        $this->assertStringContainsString('A further 1 reference path(s) could not be checked', $fail['message']);
        // …and the visibility WARN still names the directory to chmod, alongside.
        $unverified = $this->findingWith($findings, 'is not visible to this user');
        $this->assertSame('warn', $unverified['severity']);
        $this->assertStringContainsString("channel server path {$deployed}/tests is not visible", $unverified['message']);
    }

    public function test_the_fail_states_what_was_measured_not_how_the_deployment_was_assembled(): void
    {
        // A FAITHFUL whole-directory copy, plus an UNTRACKED stray that landed in the
        // checkout afterwards — `git apply --3way` mints `.orig`/`.rej` and nothing
        // gitignores them here, while the reference set is the WORKING TREE, so the
        // DL-038 bump guard (which governs the TRACKED set) says nothing about it.
        // The FAIL itself is correct and stays; "assembled file-by-file rather than
        // copied whole" would be FALSE about this operator, which is exactly the
        // DL-229 (f) shape this leg's gate exists to avoid.
        $reference = $this->reference('1.2.3');
        $deployed = $this->deployment('1.2.3');
        file_put_contents($reference.'/README.md.orig', "<<<<<<< ours\n");

        $message = $this->findingWith(ChannelSnapshotProbe::probe($deployed, $reference), 'is MISSING')['message'];

        $this->assertStringContainsString('is MISSING 1 of the 6 files a whole-directory copy of', $message);
        $this->assertStringContainsString('delivers: README.md.orig.', $message);
        $this->assertStringNotContainsString('assembled file-by-file', $message);
        $this->assertStringNotContainsString('copied whole', $message);
        $this->assertStringNotContainsString('cannot legitimately', $message);
    }

    public function test_the_missing_list_is_capped(): void
    {
        // One console line. The reference set grows with every channel-server test
        // file, and a deployment assembled from two files would otherwise print the
        // whole reference back at the operator.
        $files = ['package.json' => '{"version":"1.2.3"}'];
        for ($i = 0; $i < 12; $i++) {
            $files["mod-{$i}.mjs"] = "export const m = {$i};\n";
        }
        $reference = $this->tree('reference', $files);
        $deployed = $this->tree('deployed', [
            'package.json' => '{"version":"1.2.3"}',
            ChannelSnapshotProbe::ENTRY_FILE => "import 'node:http';\n",
        ]);
        mkdir($deployed.'/node_modules');

        $message = $this->findingWith(ChannelSnapshotProbe::probe($deployed, $reference), 'is MISSING')['message'];

        $this->assertStringContainsString('is MISSING 12 of the 13 files a whole-directory copy of', $message);
        $this->assertStringContainsString('(+4 more)', $message);
        $this->assertStringContainsString('mod-0.mjs, mod-1.mjs', $message);
        $this->assertStringNotContainsString('mod-9.mjs', $message);
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
