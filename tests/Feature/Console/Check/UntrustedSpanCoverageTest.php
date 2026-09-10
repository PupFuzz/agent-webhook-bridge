<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\ChannelSnapshotProbe;
use App\Bridge\Support\Finding;
use App\Bridge\Support\UntrustedText;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * ⛔ EVERY DECLARED FOREIGN SPAN IS COVERED BY THE TERMINAL RENDER — the invariant two
 * previous cuts of this change both shipped WITHOUT, each time with a green suite
 * (card#9121, DL-366).
 *
 * ⭐ WHY THIS CLASS DRIVES THE REAL PRODUCER AND THE REAL COMMAND, AND NEVER THE RENDERER
 * IN ISOLATION. Both defects lived in the join between a call site that declared a span and
 * a renderer that had to FIND it again, so a test that hands the renderer a message and a
 * span list it composed ITSELF asserts over a declaration the test wrote — which is how a
 * suite of thirty passing assertions certified a renderer that emitted a live erase-line.
 * Everything below starts at `ChannelSnapshotProbe::probe()` over a REAL directory on disk
 * whose own name carries the payload, and ends at `CheckCommand::emitFinding()`, the one
 * boundary where a finding becomes an operator's line. Both ends survive a redesign of the
 * declaration API between them; that is the point of anchoring here.
 *
 * ⚠ THE ASSERTION IS A CENSUS OVER THE RENDERED BYTES, NOT A SEARCH FOR ONE KNOWN PAYLOAD.
 * Round 1 leaked through span CONTAINMENT and round 2 through a longest-key STRADDLE; the
 * shape that leaks next is by definition not one of those two, so what is asserted is that
 * NO live member of the escaped class survives anywhere on the line. A presence witness for
 * the ESCAPED form sits beside every absence assertion, because "the payload is not there"
 * is equally satisfied by a renderer that dropped the span entirely.
 */
class UntrustedSpanCoverageTest extends TestCase
{
    /**
     * The class {@see UntrustedText::forOperator()} escapes, MINUS the
     * newline the console itself writes between findings.
     *
     * `\n` is the one member a rendered buffer legitimately contains — `line()`/`warn()`
     * terminate every finding with one — so it is excluded here and NOWHERE ELSE. `\r` and
     * `\t` are NOT excluded: neither appears in any sentence this install wrote, and `\r`
     * alone returns the cursor to column 0 and overwrites the line above it.
     */
    private const LIVE_CONTROL = '/[\x00-\x09\x0B-\x1F\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u';

    private string $tmp;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/untrusted-span-coverage-'.uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /**
     * ⛔ THE STRADDLE — round 2's live leak, and the reason value-matching was abandoned.
     *
     * `strtr()` genuinely never re-processes its own output, so span CONTAINMENT (round 1's
     * defect, below) is closed. What it does NOT give is COVERAGE. It takes the longest key
     * matching at each position, and a longer key can match at a position that starts inside
     * the bridge's OWN PROSE and ends inside a later span's real occurrence. The scanner
     * consumes that span's prefix as part of the other key, resumes INSIDE the span, and the
     * span's own key can never match from there — so its tail reaches the terminal raw.
     *
     * The prose is public, because it is in the message the attacker's bytes are going into.
     * Here the deployed `package.json`'s `version` is `snapshot at <path up to the ESC>`,
     * which occurs in `channel server |snapshot at /…/ch|<ESC>[2Kx is STALE`: the version's
     * key wins at `snapshot`, eats the path's ESC-free prefix, and `<ESC>[2Kx` — a live
     * ERASE-LINE — is emitted verbatim onto root's own security-diagnostic output.
     *
     * ⚑ BOTH VALUES ARE CHOSEN BY ONE PRINCIPAL AND THE FIXTURE IS THE LIVE SHAPE: the
     * account being inspected owns the deployment directory's name (`mkdir $'ch\x1bx'`
     * succeeds — a path component may hold any byte but NUL and `/`) and owns the `version`
     * string inside it. Nothing here is reachable only from a test.
     */
    public function test_a_version_straddling_the_prose_into_the_deployment_path_leaves_no_live_control_byte(): void
    {
        [$deployed, $rendered] = $this->probeStraddle();

        $this->assertStringContainsString('is STALE', $rendered, 'the fixture must reach the drift leg');
        $this->assertNoLiveControlByte($rendered);
        // PRESENCE WITNESS. Absence alone is satisfied by a renderer that dropped the span.
        $this->assertStringContainsString('ch\x1B[2Kx', $rendered);
        // And the FINDING itself still carries the raw bytes, which is the json contract.
        $this->assertStringContainsString("\x1b", $deployed, 'the fixture must actually plant the bytes');
    }

    /**
     * THE SAME STRADDLE WITH THE TWO SPANS' ROLES SWAPPED — the ESC in the `version` and the
     * deployment path quoting the prose in front of it.
     *
     * ⚑ GREEN AT THE HEAD IT WAS WRITTEN AGAINST, and saying so is the point — it is a
     * COVERAGE case, not a witness. `strtr()` consumes the message left to right, so the
     * EARLIER span is the one a later span's key can reach back over; the later span is
     * structurally out of reach of this attack, which is a fact about the scan direction and
     * not a property anyone chose. Recorded because a reader who assumes symmetry here would
     * mis-scope the defect.
     *
     * ⚑ WHY IT IS NOT THE LITERAL MIRROR OF THE TEST ABOVE, stated rather than left as a
     * silent asymmetry: a mirrored straddle would need the deployment PATH to equal
     * `<suffix of ' is STALE (deployed '> + <prefix of the version>`, and a realpath begins
     * with `/` while that prose contains none — so it is not constructible against the real
     * producer, and building it would mean hand-composing a message no check emits. What IS
     * constructible, and is what this asserts, is the same defect class with the payload on
     * the OTHER span: the path straddles out of its own occurrence into the prose that
     * follows it, and the version carries the control bytes.
     */
    public function test_the_straddle_with_the_payload_on_the_other_span_leaves_no_live_control_byte(): void
    {
        // The path's own tail plus the prose that follows it in the message. Its key
        // therefore matches at a position INSIDE the path's occurrence as well as at the
        // path's own start, which is the same coverage question from the other side.
        $deployed = $this->deployment('cx', '0.0.0');
        $version = substr($deployed, -2)." is STALE (deployed \x1b[2K\u{202E}";
        $this->writeVersion($deployed, $version);

        $rendered = $this->render($deployed);

        $this->assertStringContainsString('is STALE', $rendered, 'the fixture must reach the drift leg');
        $this->assertNoLiveControlByte($rendered);
        $this->assertStringContainsString('\x1B[2K\x{202E}', $rendered);
    }

    /**
     * ⛔ THE CONTAINMENT CASE — round 1's live leak, kept as a regression pin.
     *
     * A per-span `str_replace` loop rewrites span A's occurrence INSIDE span B, B's exact
     * match then fails, and B reaches the terminal ENTIRELY unescaped.
     *
     * ⚑ THIS ONE IS GREEN AT THE HEAD IT WAS WRITTEN AGAINST, and saying so is the point:
     * round 2 closed it, and it is here so round 3's redesign cannot re-open it. Its
     * red-once witness is a MUTANT, not this head — see the class docblock of
     * `UntrustedTextTest` and the DL entry.
     */
    public function test_a_version_containing_the_deployment_path_leaves_no_live_control_byte(): void
    {
        $deployed = $this->deployment("ch\x1bx", '0.0.0');
        $this->writeVersion($deployed, "0.0 from {$deployed} \x1b[2K\x1b[1;31m");

        $rendered = $this->render($deployed);

        $this->assertStringContainsString('is STALE', $rendered, 'the fixture must reach the drift leg');
        $this->assertNoLiveControlByte($rendered);
        // Presence witnesses for BOTH spans, so this cannot be satisfied by dropping either.
        $this->assertStringContainsString('ch\x1Bx is STALE', $rendered);
        $this->assertStringContainsString('0.0 from ', $rendered);
        $this->assertStringContainsString('\x1B[2K\x1B[1;31m', $rendered);
    }

    /**
     * ⭐ THE PROPERTY, over every relation two declared spans can stand in — the leg that is
     * meant to stop the THIRD shape rather than the two that already shipped.
     *
     * The population is the RELATION between the two spans a single `bridge:check` leg
     * declares (the deployment path and the deployed `version`, both chosen by one
     * principal), enumerated by that relation rather than by payload: identical, prefix,
     * suffix, containment either way, adjacency, a straddle into the prose on each side, a
     * span that renders empty, and control bytes on one span, the other, or both. Each case
     * is a REAL directory and a REAL manifest driven through `probe()`.
     *
     * ⚠ WHAT IT DOES NOT CLOSE: it bounds two spans in one message, which is what every
     * live producer emits today. A leg declaring three would be a new population.
     *
     * @param  string  $component  the deployment directory's own NAME
     * @param  string  $version  `{DIR}` interpolates the realpath, `{PRE}` its ESC-free head
     */
    #[DataProvider('spanRelations')]
    public function test_no_live_control_byte_survives_any_relation_between_two_declared_spans(
        string $case,
        string $component,
        string $version,
    ): void {
        $deployed = $this->deployment($component, '0.0.0');
        $head = str_contains($deployed, "\x1b") ? substr($deployed, 0, (int) strpos($deployed, "\x1b")) : $deployed;
        $this->writeVersion($deployed, strtr($version, ['{DIR}' => $deployed, '{PRE}' => $head]));

        $rendered = $this->render($deployed);

        $this->assertStringContainsString('is STALE', $rendered, "[{$case}] the fixture must reach the drift leg");
        $this->assertNoLiveControlByte($rendered, $case);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function spanRelations(): iterable
    {
        $esc = "\x1b[2K";              // erase-line: a control SEQUENCE, not a lone byte
        $c1 = "\u{009B}";              // CSI as one codepoint — the way past a guard watching for \x1B[
        $bidi = "\u{202E}";            // RTL override: reorders the rest of the line, no control byte at all

        foreach ([
            'identical spans' => ["ch{$esc}x", '{DIR}'],
            'version is a prefix of the path' => ["ch{$esc}x", '{PRE}'],
            'version is a suffix of the path' => ["ch{$esc}x", "{$esc}x"],
            'version contains the path' => ["ch{$esc}x", 'deployed from {DIR} now'],
            'path prefix plus prose in front of it' => ["ch{$esc}x", 'snapshot at {PRE}'],
            'path prefix plus one prose word' => ["ch{$esc}x", 'at {PRE}'],
            'path prefix plus one prose character' => ["ch{$esc}x", 't {PRE}'],
            'the whole prose head plus the path prefix' => ["ch{$esc}x", 'channel server snapshot at {PRE}'],
            'the resync prose plus the path prefix' => ["ch{$esc}x", '/. {PRE}'],
            'adjacent — the prose between the two spans' => ["ch{$esc}x", 'is STALE (deployed'],
            'payload on the version only' => ['plain', "0.0{$esc}{$bidi}"],
            'payload on both spans' => ["ch{$esc}x", "0.0{$c1}2J{$bidi}"],
            'C1 introducer on the path' => ["ch{$c1}2Jx", '0.0-x'],
            'bidi override on the path' => ["ch{$bidi}x", '0.0-x'],
            'a version that renders empty' => ["ch{$esc}x", ' '],
            'a version that renders empty and the prose it would delete' => ["ch{$esc}x", "\n\t "],
            'a version quoting the prose after the path' => ["ch{$esc}x", ' is STALE (deployed '],
            'a version quoting the trailing prose' => ["ch{$esc}x", ') — the next session starts on the older copy'],
        ] as $case => [$component, $version]) {
            yield $case => [$case, $component, $version];
        }
    }

    // ---- plumbing ----

    /**
     * ⛔ THE ASSERTION, spelled once. It is a CENSUS over the rendered line, and it reports
     * the offending byte by name — a bare `assertDoesNotMatchRegularExpression` on a buffer
     * carrying an erase-line prints a mangled diagnostic on the terminal reading it.
     */
    private function assertNoLiveControlByte(string $rendered, string $case = ''): void
    {
        $hits = [];
        if (preg_match_all(self::LIVE_CONTROL, $rendered, $m) > 0) {
            foreach ($m[0] as $byte) {
                $hits[] = sprintf('U+%04X', (int) mb_ord($byte, 'UTF-8'));
            }
        }

        $this->assertSame([], $hits, trim(
            ($case === '' ? '' : "[{$case}] ")
            .'live control codepoints reached the operator terminal: '.implode(' ', $hits)
            .' — in: '.addcslashes($rendered, "\0..\37\177..\377")
        ));
    }

    /** @return array{string, string} the deployment realpath and the rendered report */
    private function probeStraddle(): array
    {
        $deployed = $this->deployment("ch\x1b[2Kx", '0.0.0');
        $head = substr($deployed, 0, (int) strpos($deployed, "\x1b"));
        $this->writeVersion($deployed, 'snapshot at '.$head);

        return [$deployed, $this->render($deployed)];
    }

    /** Every finding the real probe emits for `$deployed`, rendered as the operator sees it. */
    private function render(string $deployed): string
    {
        $buffer = new BufferedOutput;
        $command = new CheckCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $emit = new ReflectionMethod(CheckCommand::class, 'emitFinding');

        foreach (ChannelSnapshotProbe::probe($deployed, $this->reference('9.9.9')) as $finding) {
            $this->assertInstanceOf(Finding::class, $finding);
            $emit->invoke($command, $finding);
        }

        return $buffer->fetch();
    }

    /** A REAL deployment whose directory NAME is `$component`. Returns its realpath. */
    private function deployment(string $component, string $version): string
    {
        $dir = $this->tmp.'/d'.($this->seq++).'/'.$component;
        mkdir($dir, 0755, true);
        mkdir($dir.'/node_modules');
        file_put_contents($dir.'/'.ChannelSnapshotProbe::ENTRY_FILE, "export default {};\n");
        $this->writeVersion($dir, $version);

        return (string) realpath($dir);
    }

    private function writeVersion(string $dir, string $version): void
    {
        file_put_contents($dir.'/package.json', (string) json_encode(['name' => 'd', 'version' => $version]));
    }

    /** This checkout's side of the compare — always this install's own bytes. */
    private function reference(string $version): string
    {
        $dir = $this->tmp.'/reference';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir.'/'.ChannelSnapshotProbe::ENTRY_FILE, "export default {};\n");
            $this->writeVersion($dir, $version);
        }

        return $dir;
    }
}
