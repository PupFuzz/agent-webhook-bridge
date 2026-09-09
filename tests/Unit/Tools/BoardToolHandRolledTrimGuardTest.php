<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardToolArgs;
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
 *   (b) It is scoped to the files that read `args`. Elsewhere in `app/Bridge/Tools/` a bare
 *       `trim()` is legitimate and common — parsing sshd output, an authorized_keys line, a
 *       git ref — because none of that is caller-supplied and none of it crosses the two doors.
 */
class BoardToolHandRolledTrimGuardTest extends TestCase
{
    /** Files whose subject IS the caller's argument object. */
    private const ARGS_READER_MARKERS = [
        'implements Tool',
        'array<string, mixed>  $args',
        'array<string, mixed> $args',
    ];

    /** Names that, called as plain functions, are PHP's ASCII trim rather than the primitive. */
    private const HAND_ROLLED = ['trim', 'ltrim', 'rtrim'];

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
            ."\nPHP's ASCII trim() is NOT what the HTTP door's TrimStrings middleware does — it leaves \u{00A0}, \u{200B} and \u{FEFF} standing, so the ssh door would accept a visually blank value the HTTP door refuses. Use App\\Bridge\\Tools\\BoardToolArgs::trimmed() / ::emptyAfterTrim(), which delegate to the framework's own Str::trim.");
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

        $this->assertSame([], $this->handRolledTrimLines('<?php $a = Str::trim($x);'), 'a static call is not a bare call');
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = BoardToolArgs::trimmed($x);'));
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = $obj->trim($x);'), 'a method call is not a bare call');
        $this->assertSame([], $this->handRolledTrimLines('<?php // PHP\'s trim($x) is wrong here'), 'a line comment is prose');
        $this->assertSame([], $this->handRolledTrimLines("<?php\n/** NOT PHP's ASCII `trim()`. */"), 'a docblock is prose — the real files are full of this');
        $this->assertSame([], $this->handRolledTrimLines('<?php $a = "trim($x)";'), 'a string literal is not a call');
        $this->assertSame([], $this->handRolledTrimLines('<?php function trim($x) {}'), 'a declaration is not a call');
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
        exec('cd '.escapeshellarg(base_path()).' && git ls-files app/Bridge/Tools app/Http/Controllers/AgentTools', $output, $status);
        $this->assertSame(0, $status, 'git ls-files failed — the population could not be derived, which is not the same as an empty one.');

        $kept = [];
        foreach ($output as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $source = (string) file_get_contents(base_path($path));
            foreach (self::ARGS_READER_MARKERS as $marker) {
                if (str_contains($source, $marker)) {
                    $kept[] = $path;
                    break;
                }
            }
        }

        return $kept;
    }
}
