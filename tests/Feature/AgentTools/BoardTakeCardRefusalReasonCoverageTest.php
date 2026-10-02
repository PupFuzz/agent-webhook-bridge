<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Tools\BoardTakeCardTool;
use App\Bridge\Tools\BoardToolDispatcher;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Every refusal `board_take_card` can reach carries a machine-readable `reason` (card#11150 /
 * DL-449): the framework's start hook and the card#11151 CLI branch on the code, never on the
 * wording, so an uncoded refusal is one they cannot route.
 *
 * ⭐ THE POPULATION IS DERIVED, NOT LISTED. The files scanned are the tool's own, the dispatcher
 * (which refuses an undeclared argument before the tool runs), and every class the tool's CODE
 * names with `::` that constructs a {@see ToolRefusalException} — resolved through PHP's own
 * autoloader, so a new helper the tool starts calling joins the scan with no edit here. In each,
 * every `new ToolRefusalException(` must pass a `reason:` argument.
 *
 * ⚠ BOUNDS. A helper reached only through an INSTANCE (not `Class::`) is outside the derivation —
 * the tool reaches none today. A whole helper file is held, not only the methods the tool calls,
 * so a helper's refusal another tool reaches is held too; that is the stricter direction. And the
 * check is that a `reason:` is PASSED, not that it is non-empty at run time — a refusal built with a
 * variable reason is read as coded. The behaviour tests in {@see BoardTakeCardStartTest} assert the
 * actual codes. The door-failure scan counts top-level arguments, so a trailing comma reads as
 * one more argument.
 */
class BoardTakeCardRefusalReasonCoverageTest extends TestCase
{
    public function test_every_refusal_the_take_tool_can_reach_passes_a_reason(): void
    {
        $files = $this->population();
        $uncoded = [];
        foreach ($files as $file) {
            foreach ($this->refusalConstructions((string) file_get_contents($file)) as [$line, $args]) {
                if (! preg_match('/(^|[\s,(])reason\s*:/', $args)) {
                    $uncoded[] = basename($file).':'.$line;
                }
            }
        }

        $this->assertContains((new \ReflectionClass(BoardTakeCardTool::class))->getFileName(), $files);
        $this->assertGreaterThan(2, count($files), 'the derivation must reach the helper classes, not only the tool');
        $this->assertSame([], $uncoded, 'these ToolRefusalException constructions on board_take_card\'s path pass no `reason:` — give each a code (docs/board-tools.md lists them)');
    }

    /**
     * The door's own refusals — the body that does not parse, the unknown tool, the undeclared
     * argument — are reached before any tool runs, so a start call can end in one. Every 422
     * `DispatchOutcome::failure(` in `app/` passes a third argument, the reason; a 4xx/5xx whose
     * STATUS is the answer (the 502 upstream error DL-387 keeps byte-identical across every
     * cause, the 503 unconfigured door) is out of this population and named so. The population is
     * every file under `app/` that makes such a call.
     */
    public function test_every_door_failure_passes_a_reason(): void
    {
        $uncoded = [];
        $sites = 0;
        foreach (SourceScan::appFiles() as $file) {
            foreach ($this->failureCalls((string) file_get_contents($file)) as [$line, $arity, $status]) {
                if ($status !== '422') {
                    continue;
                }
                $sites++;
                if ($arity < 3) {
                    $uncoded[] = SourceScan::relativeToApp($file).':'.$line;
                }
            }
        }

        $this->assertGreaterThan(0, $sites, 'the scan must find the door failures it is about');
        $this->assertSame([], $uncoded, 'these DispatchOutcome::failure calls pass no reason');
    }

    /**
     * @return list<array{0: int, 1: int, 2: string}> each call's line, its top-level argument count and its first argument's source
     */
    private function failureCalls(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i + 3 < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][1] !== 'DispatchOutcome' || ! is_array($tokens[$i + 2]) || $tokens[$i + 2][1] !== 'failure' || $tokens[$i + 3] !== '(') {
                continue;
            }
            $depth = 0;
            $arity = 1;
            $first = '';
            for ($k = $i + 3; $k < $count; $k++) {
                $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if (in_array($text, ['(', '[', '{'], true) || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $arity++;
                } elseif ($arity === 1 && $depth === 1) {
                    $first .= $text;
                }
            }
            $found[] = [$tokens[$i][2], $arity, $first];
        }

        return $found;
    }

    /**
     * The CONTROL: the scanner sees an uncoded construction and a coded one, so a clean run is a
     * measurement rather than a scan that matched nothing.
     */
    public function test_the_scanner_tells_a_coded_refusal_from_an_uncoded_one(): void
    {
        $source = <<<'PHP'
        <?php
        throw new ToolRefusalException('no code');
        throw new ToolRefusalException("x {$y}", installFault: true, reason: 'coded');
        throw new ToolRefusalException(sprintf('%s', foo('reason')), true);
        PHP;

        $found = $this->refusalConstructions($source);

        $this->assertCount(3, $found);
        $coded = array_map(fn (array $site): bool => (bool) preg_match('/(^|[\s,(])reason\s*:/', $site[1]), $found);
        $this->assertSame([false, true, false], $coded);
        $this->assertSame([[2, 2, '422'], [3, 3, '502']], $this->failureCalls('<?php'."\n".'DispatchOutcome::failure(422, f(1, 2));'."\n".'DispatchOutcome::failure(502, "x {$y}", \'upstream_error\');'));
    }

    /** @return list<string> absolute paths */
    private function population(): array
    {
        $tool = (string) (new \ReflectionClass(BoardTakeCardTool::class))->getFileName();
        $files = [$tool, (string) (new \ReflectionClass(BoardToolDispatcher::class))->getFileName()];

        foreach ($this->staticallyNamedClasses($tool) as $class) {
            if (! class_exists($class) && ! enum_exists($class) && ! interface_exists($class)) {
                continue;
            }
            $file = (new \ReflectionClass($class))->getFileName();
            if (is_string($file) && str_contains((string) file_get_contents($file), 'new ToolRefusalException(')) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Fully-qualified names of the classes the file's CODE (comments excluded) names before `::`.
     *
     * @return list<string>
     */
    private function staticallyNamedClasses(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $uses = [];
        $named = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (! is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = trim((string) ($tokens[$i + 2][1] ?? ''));
            } elseif ($t[0] === T_USE && $namespace !== '' && ($tokens[$i + 2][0] ?? null) === T_NAME_QUALIFIED) {
                $fq = $tokens[$i + 2][1];
                $uses[substr($fq, (int) strrpos($fq, '\\') + 1)] = $fq;
            } elseif ($t[0] === T_STRING && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                $named[] = $t[1];
            }
        }

        return array_values(array_unique(array_map(
            fn (string $short): string => $uses[$short] ?? $namespace.'\\'.$short,
            array_diff($named, ['self', 'static', 'parent']),
        )));
    }

    /**
     * Each `new ToolRefusalException(` in $source, with its line and its argument list's source.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function refusalConstructions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (! is_array($tokens[$j]) || $tokens[$j][1] !== 'ToolRefusalException') {
                continue;
            }
            $line = $tokens[$j][2];
            $depth = 0;
            $args = '';
            for ($k = $j + 1; $k < $count; $k++) {
                $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($text === '(') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                // Only the TOP-LEVEL argument list: a `reason:` inside a nested call is not this one's.
                $args .= $depth === 1 ? $text : ' ';
            }
            $found[] = [$line, $args];
        }

        return $found;
    }
}
