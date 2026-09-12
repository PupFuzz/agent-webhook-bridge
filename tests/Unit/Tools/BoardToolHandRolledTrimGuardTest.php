<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardToolArgs;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\Tool;
use Tests\TestCase;

/**
 * ⭐ NO BOARD TOOL MAY HAND-ROLL ITS OWN TRIM AGAIN (card#9155). Hoisting
 * {@see BoardToolArgs} fixed the four sites that had already diverged; on
 * its own that leaves the N+1th tool free to write `trim($args['whatever'])` and re-mint the
 * defect, with nothing to notice — which is the failure mode the card names by name. This is
 * the drift check that notices.
 *
 * ⚠ IT IS A CHECK, NOT THE RULE. The rule is {@see BoardToolArgs}'s
 * docblock: the two doors differ by a middleware, so a tool that normalises its own arguments
 * has to use the middleware's own primitive. This file only makes a violation loud.
 *
 * ⭐ THE POPULATION IS RE-DERIVED ON EVERY RUN — every tracked PHP file under the board-tools
 * package that READS the caller-supplied `args` object (it implements the {@see Tool}
 * interface, or it documents an `array<string, mixed> $args` parameter). A guard scoped to the
 * files this card happened to touch would go quiet exactly when a new tool appeared, which is
 * the only case it exists for. The presence witness below is what stops an empty derivation
 * from reading as a clean tree.
 *
 * ⚠ BOUNDS, stated so a green run is not read as more than it is:
 *   (a) It answers about PHP's `trim`/`ltrim`/`rtrim` only. A tool that hand-rolls the same
 *       normalisation some other way — a bespoke `preg_replace`, an `str_replace` of one
 *       codepoint — is invisible to it. The cross-door equivalence test
 *       (`Tests\Feature\AgentTools\BoardToolsBlankArgumentCrossDoorTest`) is what covers the
 *       behaviour; this covers the shape.
 *   (b) It is scoped to the files on the board-tools request path — the two front doors, the
 *       shared dispatch body, the tools, and the policy they share. Elsewhere in
 *       `app/Bridge/Tools/` and `app/Console/Commands/Bridge/` a bare `trim()` is legitimate and
 *       common — parsing sshd output, an authorized_keys line, a git ref — because none of that
 *       is caller-supplied and none of it crosses the two doors.
 */
class BoardToolHandRolledTrimGuardTest extends TestCase
{
    /**
     * Files on the board-tools request path whose subject is a caller-supplied value —
     * a board TOOL, a policy the tools share, the shared dispatch body, or a FRONT DOOR.
     *
     * ⚠ REGEXES, NOT SUBSTRINGS, AND THAT IS A FIX RATHER THAN A STYLE CHOICE. These were
     * exact substrings, so `@param array<string,mixed> $args` — no space after the comma,
     * and perfectly valid to phpstan — evaded the derivation entirely, taking the whole
     * file with it. Watched: a bare `trim($args[…])` under that spelling passed this guard.
     *
     * ⭐ THE LAST TWO MARKERS EXIST BECAUSE THE SUBJECT IS A HOP, NOT ONE END'S `args`
     * OBJECT (canon #7). The first cut derived only from `app/Bridge/Tools` +
     * `app/Http/Controllers/AgentTools` and keyed on the `args` array, so it could not see
     * {@see BoardToolDispatcher} — the one body BOTH doors funnel
     * into, and the exact site DL-367's rejected alternative (b) contemplates normalising
     * at — nor the ssh door's own envelope reader in `app/Console/Commands/Bridge`. A
     * front door is DERIVED rather than listed: a third door has to call the dispatcher to
     * be one, so naming the dispatcher is what makes this find it.
     *
     * @var list<non-empty-string>
     */
    private const ARGS_READER_MARKERS = [
        '/\bimplements\s+Tool\b/',
        '/array<\s*string\s*,\s*mixed\s*>\s*\$args\b/',
        '/\$rawArgs\b/',
        '/BoardToolDispatcher/',
    ];

    /** The git-ls-files scope the population is derived from. */
    private const SCOPE = 'app/Bridge/Tools app/Http/Controllers/AgentTools app/Console/Commands/Bridge';

    /**
     * Names that, called as plain functions, normalise a string with something other than
     * the primitive.
     *
     * ⛔ `mb_trim` IS THE ONE THAT MATTERS, AND IT WAS MISSING FROM THE FIRST CUT. It is
     * the PLAUSIBLE WRONG FIX: a developer told "PHP's ASCII `trim()` is wrong here"
     * reaches for the Unicode-aware sibling and gets the headline case right while
     * re-minting the defect for the rest. **Measured on this runtime (PHP 8.5):**
     * `mb_trim("\u{00A0}") === ''` is TRUE — so the NBSP case in every doc and test here
     * would pass — while `mb_trim("\u{200B}") === ''` and `mb_trim("\u{FEFF}") === ''`
     * are FALSE. A zero-width space or a BOM would sail through a door that had "fixed"
     * itself, and this guard would have said nothing.
     *
     * @var list<non-empty-string>
     */
    private const HAND_ROLLED = ['trim', 'ltrim', 'rtrim', 'mb_trim', 'mb_ltrim', 'mb_rtrim'];

    public function test_no_board_tool_that_reads_caller_args_hand_rolls_a_trim(): void
    {
        $population = $this->argsReadingFiles();

        // ⚑ PRESENCE WITNESS FIRST. An empty population passes the loop below vacuously, so
        // without this the guard reports where the derivation stopped rather than the state of
        // the tree — and a renamed directory would silently retire it.
        $this->assertNotSame([], $population, 'No tracked board-tools file was derived as an args reader — the population could not be built, which is not the same as a clean one.');
        foreach ([
            'app/Bridge/Tools/BoardCreateCardTool.php',
            'app/Bridge/Tools/BoardCorrectCardTool.php',
            'app/Bridge/Tools/BoardMyCardsTool.php',
            'app/Bridge/Tools/CallerTagPolicy.php',
            // The HOP, not just the ends — the shared dispatch body and BOTH front doors.
            // Each is a surface a normalisation could be added at where it would apply to
            // one transport and not the other, which is the defect this card is about.
            'app/Bridge/Tools/BoardToolDispatcher.php',
            'app/Http/Controllers/AgentTools/AgentToolsController.php',
            'app/Console/Commands/Bridge/ToolsCallCommand.php',
        ] as $known) {
            $this->assertContains($known, $population, "the derivation lost a known args reader ({$known}) — the predicate has drifted, not the tree.");
        }

        $offenders = [];
        foreach ($population as $path) {
            foreach ($this->handRolledTrimLines((string) file_get_contents(base_path($path))) as $line) {
                $offenders[] = "{$path}:{$line}";
            }
        }

        $this->assertSame([], $offenders, "A board tool hand-rolled a trim on caller-supplied input:\n  ".implode("\n  ", $offenders)
            ."\nNeither PHP's ASCII trim() nor mb_trim() is what the HTTP door's TrimStrings middleware does. trim() leaves \u{00A0}, \u{200B} and \u{FEFF} standing; mb_trim() strips \u{00A0} but still leaves \u{200B} and \u{FEFF}, so it looks like a fix, passes the headline NBSP case, and re-mints the defect for the rest. Use App\\Bridge\\Tools\\BoardToolArgs::trimmed() / ::emptyAfterTrim(), which delegate to the framework's own Str::trim.");
    }

    /**
     * ⚑ THE CONTROL. The scanner works on PHP TOKENS rather than a regex because these files
     * discuss `trim()` at length in their docblocks — a text search would fire on the prose
     * that explains why the prose's subject is banned, and the fix for that noise is what
     * would have made the guard blind. Each near-miss below pins one thing the scanner must
     * NOT count, and the first case pins the one it must.
     */
    public function test_the_scanner_discriminates(): void
    {
        $this->assertSame([1], $this->handRolledTrimLines('<?php $a = trim($x);'), 'the scanner missed a bare trim() — every assertion above is vacuous');
        $this->assertSame([1], $this->handRolledTrimLines('<?php $a = \trim($x);'), 'a root-namespaced \trim() is still PHP\'s trim');
        $this->assertSame([1, 1], $this->handRolledTrimLines('<?php $a = ltrim($x) . rtrim($y);'));
        // ⛔ THE PLAUSIBLE WRONG FIX — the HAND_ROLLED docblock owns why mb_trim is the
        // dangerous sibling rather than a pedantic addition.
        $this->assertSame([1], $this->handRolledTrimLines('<?php $a = mb_trim($x);'), 'mb_trim() is the fix a developer reaches for next, and it is still wrong for ZWSP and BOM');
        $this->assertSame([1, 1], $this->handRolledTrimLines('<?php $a = mb_ltrim($x) . mb_rtrim($y);'));
        $this->assertSame([1], $this->handRolledTrimLines('<?php $a = \mb_trim($x);'));

        $this->assertSame([], $this->handRolledTrimLines('<?php $a = Str::trim($x);'), 'a static call is not a bare call');
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = BoardToolArgs::trimmed($x);'));
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = $obj->trim($x);'), 'a method call is not a bare call');
        $this->assertSame([], $this->handRolledTrimLines('<?php // PHP\'s trim($x) is wrong here'), 'a line comment is prose');
        $this->assertSame([], $this->handRolledTrimLines("<?php\n/** NOT PHP's ASCII `trim()`. */"), 'a docblock is prose — the real files are full of this');
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = "trim($x)";'), 'a string literal is not a call');
        $this->assertSame([], $this->handRolledTrimLines('<?php function trim($x) {}'), 'a declaration is not a call');
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = Str::mb_trim($x);'), 'a static call is not a bare call, mb_ variants included');
    }

    /**
     * ⚑ THE CONTROL ON THE OTHER HALF — that the POPULATION predicate matches what it
     * claims to. The markers were exact substrings until this round, so one legal
     * whitespace variant of the `@param` spelling took a whole file out of the derivation
     * with nothing to say so. A predicate that silently matches nothing reports where the
     * search stopped, not the state of the tree.
     */
    public function test_the_population_predicate_is_not_whitespace_sensitive(): void
    {
        foreach ([
            'the house spelling (two spaces, phpstan style)' => '     * @param  array<string, mixed>  $args',
            'one space' => '     * @param array<string, mixed> $args',
            'no space after the comma' => '     * @param array<string,mixed> $args',
            'spaces around the comma, none before the variable' => '     * @param array<string , mixed>$args',
        ] as $label => $spelling) {
            $this->assertTrue($this->matchesAnyMarker("<?php\n/**\n{$spelling}\n */"), "the population predicate missed {$label} — a file written that way would leave the census silently");
        }

        // And it still discriminates: a file naming none of the markers stays out.
        $this->assertFalse($this->matchesAnyMarker('<?php class Unrelated { public function f(array $rows): void {} }'));
    }

    private function matchesAnyMarker(string $source): bool
    {
        foreach (self::ARGS_READER_MARKERS as $marker) {
            if (preg_match($marker, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 1-based lines carrying a plain call to PHP's trim family.
     *
     * @return list<int>
     */
    private function handRolledTrimLines(string $php): array
    {
        $tokens = token_get_all($php);
        $count = count($tokens);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (! in_array(strtolower(ltrim($token[1], '\\')), self::HAND_ROLLED, true)) {
                continue;
            }
            // A qualified name (`Some\trim`) is somebody else's function, and a `::`/`->`
            // predecessor makes it a method — neither is PHP's global trim.
            $before = $i - 1;
            while ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], $skip, true)) {
                $before--;
            }
            if ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            $after = $i + 1;
            while ($after < $count && is_array($tokens[$after]) && in_array($tokens[$after][0], $skip, true)) {
                $after++;
            }
            if ($after < $count && $tokens[$after] === '(') {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    /**
     * Every tracked board-tools file whose subject is the caller's argument object.
     *
     * @return list<string>
     */
    private function argsReadingFiles(): array
    {
        $output = [];
        exec('cd '.escapeshellarg(base_path()).' && git ls-files '.self::SCOPE, $output, $status);
        $this->assertSame(0, $status, 'git ls-files failed — the population could not be derived, which is not the same as an empty one.');

        $kept = [];
        foreach ($output as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $source = (string) file_get_contents(base_path($path));
            if ($this->matchesAnyMarker($source)) {
                $kept[] = $path;
            }
        }

        return $kept;
    }
}
