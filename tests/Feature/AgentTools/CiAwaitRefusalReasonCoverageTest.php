<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\CiAwaitArgs;
use App\Bridge\Tools\CiAwaitCancelTool;
use App\Bridge\Tools\CiAwaitTool;
use Tests\TestCase;

/**
 * Every refusal `ci_await` and `ci_await_cancel` construct carries a machine-readable `reason`
 * (card#11200 / DL-452), so a hook branches on the code and never on the wording. The population
 * is every `new ToolRefusalException(` in the two tool classes and the argument class they share —
 * the files these tools' refusals are built in; the dispatcher's own refusals are held by
 * `BoardTakeCardRefusalReasonCoverageTest`. ⚠ It checks that a `reason:` is PASSED at the top level
 * of the call, not its value; the values are pinned by that same class's read of
 * `docs/board-tools.md`'s code table.
 */
class CiAwaitRefusalReasonCoverageTest extends TestCase
{
    public function test_every_ci_await_refusal_passes_a_reason(): void
    {
        $sites = 0;
        $uncoded = [];
        foreach ([CiAwaitTool::class, CiAwaitCancelTool::class, CiAwaitArgs::class] as $class) {
            $file = (string) (new \ReflectionClass($class))->getFileName();
            foreach (self::constructions((string) file_get_contents($file)) as [$line, $args]) {
                $sites++;
                if (! self::passesReason($args)) {
                    $uncoded[] = basename($file).':'.$line;
                }
            }
        }

        $this->assertGreaterThan(5, $sites, 'the scan must find the refusals it is about');
        $this->assertSame([], $uncoded, 'these ci_await refusals pass no `reason:` — give each a code and table it in docs/board-tools.md');
    }

    /** The CONTROL: the scanner tells a coded construction from an uncoded one, and ignores a nested `reason:`. */
    public function test_the_scanner_tells_a_coded_site_from_an_uncoded_one(): void
    {
        $source = <<<'PHP'
        <?php
        throw new ToolRefusalException('no code');
        throw new ToolRefusalException("x {$y}", installFault: true, reason: 'coded');
        throw new ToolRefusalException(sprintf('%s', foo(reason: 'nested')), true);
        PHP;

        $this->assertSame([false, true, false], array_map(fn (array $c): bool => self::passesReason($c[1]), self::constructions($source)));
    }

    /** @param  list<string>  $args */
    private static function passesReason(array $args): bool
    {
        foreach ($args as $arg) {
            if (preg_match('/^\s*reason\s*:/', $arg) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each `new ToolRefusalException(` with its line and its top-level arguments' source.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private static function constructions(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i + 2 < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW || ! is_array($tokens[$i + 1]) || ! str_ends_with($tokens[$i + 1][1], 'ToolRefusalException') || $tokens[$i + 2] !== '(') {
                continue;
            }
            $args = [''];
            $depth = 0;
            for ($k = $i + 2; $k < $count; $k++) {
                $t = $tokens[$k];
                $text = is_array($t) ? $t[1] : $t;
                if (in_array($text, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $args[] = '';

                    continue;
                }
                $args[count($args) - 1] .= $text.' ';
            }
            $found[] = [$tokens[$i + 1][2], $args];
        }

        return $found;
    }
}
