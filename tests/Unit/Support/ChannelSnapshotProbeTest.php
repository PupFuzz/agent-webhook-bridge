<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ChannelSnapshotProbe;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\UntrustedText;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReadsDeclaredSpans;
use Tests\Support\SkipsAsRoot;

class ChannelSnapshotProbeTest extends TestCase
{
    use ReadsDeclaredSpans;
    use SkipsAsRoot;

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

    public function test_an_undeclared_server_path_is_unvalidated_and_never_ok(): void
    {
        // card 5170: the leg does not RUN here — nothing was measured. `ok` is the
        // severity that certifies measured-and-clean, so reporting a not-measured
        // finding under it makes "green because checked" and "green because nobody
        // looked" the same output. The message text is unchanged; the severity is
        // the whole fix.
        $findings = ChannelSnapshotProbe::probe(null, $this->reference('1.2.3'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertNotSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('channel.server_path not declared', $findings[0]->message);
        $this->assertStringContainsString('snapshot not validated', $findings[0]->message);
    }

    /**
     * The DEPLOYED manifest's twin of the leg below, and the one arm of the pair whose
     * severity nothing pinned before DL-251 — `BridgeCommandsTest` asserts its sentence
     * over an undecorated capture, where all four severities are the same bytes.
     */
    public function test_an_unreadable_deployed_manifest_is_unvalidated_not_a_warn(): void
    {
        $deployed = $this->deployment('1.2.3', omit: ['package.json']);

        $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));

        $finding = $this->findingWith($findings, 'cannot tell whether the deployed copy is stale');
        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('package.json is not present', $finding->message);
    }

    public function test_an_unreadable_bundled_manifest_names_its_own_remedy(): void
    {
        // This finding and the unenumerable-reference one were a MATCHED PAIR — both
        // say "this checkout's X could not be read" — and the reference one spelled
        // its action while this one did not. (Both were `warn` when that divergence was
        // found; DL-251 moved this one to `unvalidated`, which changes nothing about
        // the pairing argument: the version compare did not happen.) A divergence inside a pair, not a
        // message lacking polish: the operator reads the silent one as unactionable
        // when the fix is the same class of thing (restore/repair a tracked file).
        // The branch had no coverage at all before this, which is how it diverged.
        $reference = $this->tree('bundled-no-manifest', [
            ChannelSnapshotProbe::ENTRY_FILE => "export const x = 1;\n",
        ]);
        $deployed = $this->deployment('1.2.3');

        $findings = ChannelSnapshotProbe::probe($deployed, $reference);

        $warn = $this->findingWith($findings, 'cannot be version-compared');
        $this->assertSame(Severity::Unvalidated, $warn->severity);
        $this->assertStringContainsString('is not present', $warn->message);
        $this->assertStringContainsString('restore or repair it', $warn->message);
        $this->assertStringContainsString('check that this process can read it', $warn->message);
    }

    // ---- the retired completeness leg + the UNIFORM launch disclosure (DL-237) --
    // DL-230 answered "will this launch?" by enumerating files. A launch answers it
    // directly, and is more precise in BOTH directions (measured: a pruned copy
    // missing 6 of 10 reference files launches while completeness FAILs it; the
    // DL-230 shape dies on ERR_MODULE_NOT_FOUND). The launch belongs to the SEAT,
    // because the bridge's OS user is not the agent's — so what is left here is the
    // DISCLOSURE that the question went unmeasured, emitted ONCE per AGENT whose probe
    // reached the legs, on EVERY branch that did — not once per run: `ChannelSnapshotCheck`
    // is a `PerAgentCheck`, so a two-agent install yields two.

    /**
     * Every branch that reaches the legs, and the exactly-one-disclosure property
     * asserted per branch rather than on a single representative — the first shape of
     * this change put the disclosure on the version-EQUAL branch alone and left
     * repo-direct (the topology the channel-server README recommends) fully green.
     *
     * @return array<string, array{string, string}> label => [deployed version, expected version-leg substring]
     */
    public static function reachesTheLegs(): array
    {
        return [
            'version-equal' => ['1.2.3', 'is current (deployed 1.2.3'],
            'stale' => ['1.0.0', 'is STALE (deployed 1.0.0'],
            'newer' => ['2.0.0', 'is current (deployed 2.0.0'],
        ];
    }

    #[DataProvider('reachesTheLegs')]
    public function test_every_branch_that_reaches_the_legs_discloses_the_unmeasured_launch(string $version, string $versionLegText): void
    {
        $findings = ChannelSnapshotProbe::probe($this->deployment($version), $this->reference('1.2.3'));

        // The version leg still answers the DRIFT question it owns, per branch…
        $this->findingWith($findings, $versionLegText);
        // …and the launch disclosure is there regardless of what it found.
        $notMeasured = $this->findingWith($findings, 'was NOT launch-tested');
        $this->assertSame(Severity::Unvalidated, $notMeasured->severity);
        $this->assertNotSame(Severity::Ok, $notMeasured->severity);
        // EXACTLY one, never per-leg: this is a statement about the run.
        $this->assertSame(1, $this->countFindings($findings, 'was NOT launch-tested'));
        // It names the check, where it is run, and AS WHOM — the last of those is the
        // whole reason this is not a bridge:check leg.
        $this->assertStringContainsString('bin/check-channel-snapshot.py', $notMeasured->message);
        $this->assertStringContainsString('ON THAT SEAT', $notMeasured->message);
        $this->assertStringContainsString('the OS user whose session launches the channel server', $notMeasured->message);
        // And it must never flip the exit: the deployment may be perfect, and every
        // co-located install now emits one (DL-236 (c)).
        $this->assertSame([], $this->severities($findings, Severity::Fail));
    }

    public function test_the_repo_direct_branch_discloses_it_too(): void
    {
        // THE CASE THAT DECIDED THE PLACEMENT. Putting the disclosure on the
        // version-EQUAL branch alone was a like-for-like replacement for the leg that
        // used to live there — and it left the symlink topology the channel-server
        // README RECOMMENDS with a fully green run and no disclosure at all: "green
        // check, dark seat" reintroduced by the fix for it.
        $reference = $this->reference('1.2.3');
        mkdir($reference.'/node_modules');
        $link = $this->tmp.'/link-to-checkout';
        symlink($reference, $link);

        $findings = ChannelSnapshotProbe::probe($link, $reference);

        $this->assertSame(Severity::Ok, $this->findingWith($findings, 'no snapshot to drift')->severity);
        $this->assertNoFinding($findings, 'is current (deployed');   // no version leg at all
        $this->assertSame(Severity::Unvalidated, $this->findingWith($findings, 'was NOT launch-tested')->severity);
        $this->assertSame(1, $this->countFindings($findings, 'was NOT launch-tested'));
    }

    public function test_a_presence_fail_still_carries_the_disclosure(): void
    {
        // UNCONDITIONAL of what the legs found, deliberately. Suppressing it beside a
        // FAIL would make it depend on another leg's severity, so a later change there
        // would silently change whether it appears — and the sentence stays true: the
        // legs ran, the launch still did not.
        $deployed = $this->deployment('1.2.3');
        (new Filesystem)->deleteDirectory($deployed.'/node_modules');

        $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));

        $this->assertSame(Severity::Fail, $this->findingWith($findings, 'dependencies are not installed')->severity);
        $this->assertSame(Severity::Unvalidated, $this->findingWith($findings, 'was NOT launch-tested')->severity);
    }

    /**
     * The early returns — every path that ends BEFORE the legs run. A second
     * not-measured line beside a finding that already says the legs did not run is
     * the DL-229 (h) shape for real: two findings, one action.
     *
     * @return array<string, array{string}> label => [probe argument marker]
     */
    public static function returnsBeforeTheLegs(): array
    {
        return [
            'undeclared' => ['undeclared'],
            'dangling' => ['dangling'],
            'names-a-file' => ['file'],
        ];
    }

    #[DataProvider('returnsBeforeTheLegs')]
    public function test_a_path_that_never_reached_the_legs_gets_no_second_not_measured_line(string $shape): void
    {
        $reference = $this->reference('1.2.3');
        $path = match ($shape) {
            'undeclared' => null,
            'dangling' => $this->danglingLink(),
            default => $this->tree('wrapper', ['launch.js' => "//\n"]).'/launch.js',
        };

        $findings = ChannelSnapshotProbe::probe($path, $reference);

        $this->assertCount(1, $findings);
        $this->assertNoFinding($findings, 'was NOT launch-tested');
    }

    public function test_an_invisible_deployment_gets_no_second_not_measured_line(): void
    {
        // The fourth early return, and the one that most looks like it wants a launch
        // note: we could not even see the directory. The visibility WARN already says
        // the snapshot could NOT be validated and already names its one action (grant
        // traversal / re-run as the agent's user); a launch line beside it would be a
        // second finding for that same single action.
        $this->skipAsRoot();
        $deployed = $this->deployment('1.2.3');
        chmod($deployed, 0000);

        try {
            $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));
        } finally {
            chmod($deployed, 0755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
        $this->assertNoFinding($findings, 'was NOT launch-tested');
    }

    public function test_no_branch_says_anything_about_a_leg_that_no_longer_exists(): void
    {
        // DL-230 (b) gave the NEWER branch its own `ok` line whose ENTIRE content was
        // "completeness against X SKIPPED". With no completeness leg that sentence
        // describes machinery that is gone — a stale claim in operator-facing output,
        // which is worse than no line at all.
        $reference = $this->reference('1.2.3');
        foreach (['1.0.0', '1.2.3', '2.0.0'] as $version) {
            $deployed = $this->deployment($version, name: 'deployed-'.$version);
            $findings = ChannelSnapshotProbe::probe($deployed, $reference);

            // POSITIVE CONTROL FIRST. Three absence assertions over a fixture that
            // could early-return are vacuous — they would pass just as well on a probe
            // that produced nothing at all. Pin that this branch really did run before
            // concluding anything from what it did not say.
            $this->assertSame(Severity::Ok, $this->findingWith($findings, 'has its entry file and node_modules')->severity);
            $this->assertSame(Severity::Unvalidated, $this->findingWith($findings, 'was NOT launch-tested')->severity);

            $this->assertNoFinding($findings, 'completeness');
            $this->assertNoFinding($findings, 'SKIPPED');
            $this->assertNoFinding($findings, 'is MISSING');
        }
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
        $this->assertSame(Severity::Ok, $this->findingWith($findings, 'is current (deployed 1.2.3')->severity);
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

        $this->assertSame([], $this->severities($findings, Severity::Fail));
        $this->assertSame(Severity::Ok, $this->findingWith($findings, 'has its entry file and node_modules')->severity);
    }

    /**
     * A reference directory shaped like `examples/channel-servers`: a manifest, the
     * entry, a sibling module the entry imports, a dotfile and a nested test.
     */
    /**
     * ⛔ EVERY FINDING THAT ECHOES THE DEPLOYED MANIFEST'S `version` DECLARES IT
     * (card#9121, DL-366), and the assertion is written as a CENSUS OVER THE RUN rather than
     * against one arm on purpose: three arms of the version leg interpolate that value, the
     * two recorded reviews of this file disagreed about which lines they were, and a test
     * pinned to one arm certifies nothing about the other two. Any finding carrying the
     * payload verbatim must also declare it; a fourth arm added later joins this denominator
     * by existing.
     *
     * The value is FOREIGN: it is whatever the `version` key of a `package.json` under
     * another OS user's home decodes to, cast to string, with no shape validation anywhere
     * and only the reader's byte cap bounding it.
     */
    public function test_every_finding_echoing_the_deployed_version_declares_it_as_untrusted(): void
    {
        // The reviewer's own reproduction: an ANSI erase-display, a forged second finding
        // line, and an unterminated RTL override — none of which a version string has any
        // business containing, and all of which reached the terminal verbatim.
        $payload = "0.0\x1b[2J\nagent prod-agent: channel socket live\u{202E}";

        $findings = ChannelSnapshotProbe::probe($this->deployment($payload), $this->reference('9.9.9'));

        $echoing = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => str_contains($f->message, $payload),
        ));
        $this->assertNotEmpty($echoing, 'the fixture must actually reach an arm that echoes the version');
        foreach ($echoing as $finding) {
            $this->assertContains($payload, $this->declaredSpans($finding), "undeclared foreign span in: {$finding->message}");

            // PRESENCE WITNESS, not an absence: an absence-only assertion is satisfied by a
            // renderer that dropped the detail entirely, which would certify a regression
            // that withholds the one part of the line naming the real fault.
            $rendered = UntrustedText::render($finding->segments);
            $this->assertStringContainsString('\x1B[2J', $rendered);
            $this->assertStringContainsString('\x{202E}', $rendered);
            $this->assertStringContainsString('agent prod-agent: channel socket live', $rendered);
            $this->assertStringNotContainsString("\x1b", $rendered);
            $this->assertStringNotContainsString("\u{202E}", $rendered);
            // The forged line cannot stand alone any more: the newline is gone.
            $this->assertStringNotContainsString("\n", $rendered);
        }
    }

    /**
     * The SECOND foreign value in this file, and the one both recorded censuses missed on
     * most of its sites: the RESOLVED deployment path. The inspected account chooses the
     * directory names and the symlink target, and a path COMPONENT may hold any byte except
     * NUL and `/` — so the presence, staleness and launch-disclosure lines were echoing
     * account-chosen bytes exactly as the version arms were.
     */
    public function test_every_finding_echoing_the_resolved_path_declares_it_as_untrusted(): void
    {
        $this->skipAsRoot();
        $evil = "dep\x1b[2Jloy\u{202E}ed";
        $deployed = $this->deployment('1.2.3', name: $evil);

        $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));

        $echoing = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => str_contains($f->message, $deployed),
        ));
        $this->assertNotEmpty($echoing, 'the fixture must reach the legs that name the deployment path');
        foreach ($echoing as $finding) {
            $this->assertContains($deployed, $this->declaredSpans($finding), "undeclared foreign span in: {$finding->message}");
            $rendered = UntrustedText::render($finding->segments);
            $this->assertStringContainsString('dep\x1B[2Jloy\x{202E}ed', $rendered);
            $this->assertStringNotContainsString("\x1b", $rendered);
        }
    }

    /**
     * ⛔ THE GUARD BUILDS THE FINDING, SO THE GUARD MUST CARRY THE DECLARATION (card#9121,
     * DL-366). `PathVisibility::unverifiedUnlessVisible()` interpolates the path this file
     * hands it and returns a `?Finding` — so a `Finding::` grep over THIS file cannot see
     * it, which is exactly how the first sweep declared `$resolved` two branches below and
     * left these two sites echoing the same value raw.
     *
     * ⚑ THE SHAPE IS THE ONE `PathVisibility`'s OWN DOCBLOCK CALLS ROUTINE — an ancestor
     * denying traversal, the bridge running as a different OS user than the agent — not a
     * contrivance built to reach an arm.
     */
    public function test_the_not_visible_guard_declares_the_resolved_symlink_target(): void
    {
        $this->skipAsRoot();
        // The account being inspected chose BOTH: the link target, and the directory name
        // in it. `readlink()` hands back those bytes verbatim.
        mkdir($this->tmp.'/locked', 0700, true);
        mkdir($this->tmp."/locked/ch\x1bx\u{202E}");
        symlink($this->tmp."/locked/ch\x1bx\u{202E}", $this->tmp.'/link');
        chmod($this->tmp.'/locked', 0000);

        try {
            $findings = ChannelSnapshotProbe::probe($this->tmp.'/link', $this->reference('1.2.3'));

            $this->assertCount(1, $findings);
            $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
            $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
            $this->assertStringContainsString("\x1b", $findings[0]->message, 'the fixture must actually plant the bytes');

            $rendered = UntrustedText::render($findings[0]->segments);
            $this->assertStringContainsString('ch\x1Bx\x{202E}', $rendered);
            $this->assertSame(0, substr_count($rendered, "\x1b"), "a live ESC survived: {$rendered}");
        } finally {
            chmod($this->tmp.'/locked', 0700);
        }
    }

    /**
     * The SECOND guard call site — the one gating both legs on traversal INTO the deployed
     * directory. It names `$deployedDir` rather than `$resolved`, so it is a separate
     * declaration and a separate arm, and a fix that reached only the first would pass the
     * test above and fail this one.
     */
    public function test_the_not_visible_guard_declares_the_deployment_path(): void
    {
        $this->skipAsRoot();
        $evil = "dep\x1bloy\u{202E}";
        $deployed = $this->deployment('1.2.3', name: $evil);
        chmod($deployed, 0000);

        try {
            $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('1.2.3'));

            $this->assertCount(1, $findings);
            $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
            $rendered = UntrustedText::render($findings[0]->segments);
            $this->assertStringContainsString('dep\x1Bloy\x{202E}', $rendered);
            $this->assertSame(0, substr_count($rendered, "\x1b"), "a live ESC survived: {$rendered}");
        } finally {
            chmod($deployed, 0755);
        }
    }

    /**
     * ⛔ THE OVERLAP CASE, DRIVEN THROUGH THE REAL LEG. `versionLeg()` is where two spans
     * chosen by the SAME principal are declared on one finding, and where a version that
     * quotes the deployment path makes one a substring of the other — the shape a per-span
     * replacement loop silently half-applies. The two probe tests above this one each make
     * ONE value hostile and the other benign, which is exactly why neither caught it.
     */
    public function test_a_version_that_quotes_the_deployment_path_is_still_fully_escaped(): void
    {
        $this->skipAsRoot();
        $evil = "dep\x1bloy";
        $version = "0.0 installed at {$this->tmp}/{$evil} \x1b[2K\x1b[1;31m";
        $deployed = $this->deployment($version, name: $evil);

        $findings = ChannelSnapshotProbe::probe($deployed, $this->reference('9.9.9'));

        // Anchored on the ERASE-LINE, which lives only in the VERSION: the deployment
        // directory's own name also carries an ESC, so every leg naming the path matches a
        // bare `\x1b` filter and the two-declaration arm would not be isolated.
        $versionLeg = array_values(array_filter(
            $findings,
            static fn (Finding $f): bool => str_contains($f->message, "\x1b[2K"),
        ));
        $this->assertNotEmpty($versionLeg, 'the fixture must reach the arm that echoes both spans');
        foreach ($versionLeg as $finding) {
            // THREE POSITIONS, not two values: the deployment path lands twice (once as the
            // subject, once inside the re-sync command) and the version once. Under the
            // value-matching renderer this read 2, because a declaration was a value and a
            // replace-all covered every occurrence of it by accident; a position is
            // per-occurrence, so the count is the number of places on the operator's line.
            $this->assertSame([$deployed, $version, $deployed], $this->declaredSpans($finding));
            $rendered = UntrustedText::render($finding->segments);
            $this->assertStringContainsString('\x1B[2K\x1B[1;31m', $rendered);
        }

        // EVERY finding of the run, not just that arm: nothing on the operator's report may
        // carry a live control byte once the declarations are applied.
        foreach ($findings as $finding) {
            $rendered = UntrustedText::render($finding->segments);
            $this->assertSame(0, substr_count($rendered, "\x1b"), "a live ESC survived: {$rendered}");
        }
    }

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
    private function deployment(string $version, array $omit = [], string $name = 'deployed'): string
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
        $dir = $this->tree($name, $files);
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
     * @param  list<Finding>  $findings
     */
    private function findingWith(array $findings, string $needle): Finding
    {
        foreach ($findings as $finding) {
            if (str_contains($finding->message, $needle)) {
                return $finding;
            }
        }

        $this->fail("no finding contains \"{$needle}\" — got: ".implode(' | ', array_column($findings, 'message')));
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function countFindings(array $findings, string $needle): int
    {
        return count(array_filter(
            array_column($findings, 'message'),
            static fn (string $message): bool => str_contains($message, $needle),
        ));
    }

    /**
     * A symlink whose target existed and then did not — the branch-1 fatal.
     */
    private function danglingLink(): string
    {
        $target = $this->tmp.'/removed-deployment';
        $link = $this->tmp.'/dangling';
        mkdir($target);
        symlink($target, $link);
        rmdir($target);

        return $link;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function assertNoFinding(array $findings, string $needle): void
    {
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString($needle, $finding->message);
        }
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function severities(array $findings, Severity $severity): array
    {
        return array_values(array_filter(
            array_column($findings, 'message'),
            fn (string $message, int $i): bool => $findings[$i]->severity === $severity,
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
