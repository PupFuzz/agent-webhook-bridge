<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Tools\BoardTakeCardTool;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\ToolCallBody;
use App\Console\Commands\Bridge\ToolsCallCommand;
use App\Http\Controllers\AgentTools\AgentToolsController;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * The refusals `board_take_card`'s path can construct carry a machine-readable `reason` (card#11150
 * / DL-449): the framework's start hook and the card#11151 CLI branch on the code, never on the
 * wording. What this class holds is exactly what it SCANS — three derivations, each stated:
 *
 *  1. TOOL-SIDE REFUSALS. The population is the TRANSITIVE closure of `App\` classes named in the
 *     CODE (comments and docblocks excluded; `use` imports, same-namespace short names and
 *     qualified or fully-qualified names all resolved) starting from {@see BoardTakeCardTool}, plus
 *     the shared door files ({@see BoardToolDispatcher}, {@see ToolCallBody}) scanned without being
 *     followed — following the dispatcher would walk the tool registry into every OTHER tool,
 *     whose own refusals are not all coded and are not this tool's path. In every file of that
 *     population, every `new ToolRefusalException(` — short, qualified or fully-qualified spelling —
 *     must pass a `reason:` argument.
 *  2. THE DISPATCHER'S 422s. Every `DispatchOutcome::failure(422, …)` in `app/` passes a third
 *     argument, the reason.
 *  3. THE TWO DOORS. The door files are DERIVED as the `app/` files that call `ToolCallBody::parse(`,
 *     and each must be one this class has a rule for (a new door reds until it gets one): in
 *     {@see AgentToolsController} every `refuse(422, …)` passes a third argument; in
 *     {@see ToolsCallCommand} every `emit(` of a literal `'ok' => false` body with exit code 1 carries
 *     a `'reason'` key.
 *
 * ⚠ BOUNDS, named. NOT held: a 502 (`upstream board error`, one body for every cause — DL-387), the
 * HTTP door's 401/503 and the ssh door's exit-2 answers, all told apart by status or exit code. A
 * class reached only through a DYNAMIC name (a string, the container) is outside the closure. The
 * check is that a reason is PASSED, not its run-time value; the behaviour tests in
 * {@see BoardTakeCardStartTest} assert the actual codes. Argument counts are top-level commas, so a
 * trailing comma reads as one more argument. Whole files are held, not only the methods the tool
 * reaches — the stricter direction.
 */
class BoardTakeCardRefusalReasonCoverageTest extends TestCase
{
    private const REFUSAL = ToolRefusalException::class;

    public function test_every_tool_side_refusal_in_the_derived_population_passes_a_reason(): void
    {
        $files = $this->population();
        $uncoded = [];
        $sites = 0;
        foreach ($files as $file) {
            foreach ($this->refusalConstructions((string) file_get_contents($file)) as [$line, $args]) {
                $sites++;
                if (! self::passesReason($args)) {
                    $uncoded[] = SourceScan::relativeToApp($file).':'.$line;
                }
            }
        }

        $this->assertContains(self::fileOf(BoardTakeCardTool::class), $files);
        $this->assertGreaterThan(5, $sites, 'the derivation must reach the helpers\' refusals, not only the tool\'s');
        $this->assertSame([], $uncoded, 'these ToolRefusalException constructions on board_take_card\'s path pass no `reason:` — give each a code (docs/board-tools.md tables them)');
    }

    public function test_every_dispatcher_422_passes_a_reason(): void
    {
        $uncoded = [];
        $sites = 0;
        foreach (SourceScan::appFiles() as $file) {
            foreach ($this->calls((string) file_get_contents($file), 'DispatchOutcome', 'failure') as [$line, $args]) {
                if (trim($args[0] ?? '') !== '422') {
                    continue;
                }
                $sites++;
                if (count($args) < 3) {
                    $uncoded[] = SourceScan::relativeToApp($file).':'.$line;
                }
            }
        }

        $this->assertGreaterThan(0, $sites);
        $this->assertSame([], $uncoded, 'these 422 DispatchOutcome::failure calls pass no reason');
    }

    public function test_every_door_refusal_passes_a_reason(): void
    {
        $doors = [];
        foreach (SourceScan::appFiles() as $file) {
            if ($this->calls((string) file_get_contents($file), 'ToolCallBody', 'parse') !== []) {
                $doors[] = $file;
            }
        }
        $ruled = [self::fileOf(AgentToolsController::class), self::fileOf(ToolsCallCommand::class)];
        sort($doors);
        sort($ruled);
        $this->assertSame($ruled, $doors, 'the set of door files (callers of ToolCallBody::parse) moved — give the new door a rule here');

        $uncoded = [];
        $sites = 0;
        foreach ($this->calls((string) file_get_contents($ruled[array_search(self::fileOf(AgentToolsController::class), $ruled, true)]), '$this', 'refuse') as [$line, $args]) {
            if (trim($args[0] ?? '') === '422') {
                $sites++;
                if (count($args) < 3) {
                    $uncoded[] = 'AgentToolsController:'.$line;
                }
            }
        }
        foreach ($this->calls((string) file_get_contents(self::fileOf(ToolsCallCommand::class)), '$this', 'emit') as [$line, $args]) {
            if (count($args) === 3 && trim($args[2]) === '1' && preg_match("/'ok'\s*=>\s*false/", $args[1])) {
                $sites++;
                if (! str_contains($args[1], "'reason'")) {
                    $uncoded[] = 'ToolsCallCommand:'.$line;
                }
            }
        }

        $this->assertGreaterThan(2, $sites, 'the scan must find the door refusals it is about');
        $this->assertSame([], $uncoded, 'these door refusals carry no reason');
    }

    /** The CONTROL: each scanner tells a coded site from an uncoded one, in every spelling it claims. */
    public function test_the_scanners_tell_a_coded_site_from_an_uncoded_one(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Bridge\Tools;
        use App\Bridge\Exceptions\ToolRefusalException;
        throw new ToolRefusalException('no code');
        throw new ToolRefusalException("x {$y}", installFault: true, reason: 'coded');
        throw new \App\Bridge\Exceptions\ToolRefusalException('fully qualified, no code');
        throw new ToolRefusalException(sprintf('%s', foo(reason: 'nested')), true);
        $this->refuse(422, 'x', 'bad_request');
        $this->refuse(422, f(1, 2));
        PHP;

        $found = $this->refusalConstructions($source);
        $coded = array_map(fn (array $site): bool => self::passesReason($site[1]), $found);
        $this->assertSame([false, true, false, false], $coded);
        $this->assertSame([3, 2], array_map(fn (array $c): int => count($c[1]), $this->calls($source, '$this', 'refuse')));
    }

    /** @return list<string> absolute paths */
    private function population(): array
    {
        $seen = [];
        $queue = [BoardTakeCardTool::class];
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = self::fileOf($class);
            foreach ($this->namedClasses($seen[$class]) as $named) {
                if (! isset($seen[$named])) {
                    $queue[] = $named;
                }
            }
        }

        return array_values(array_unique([...array_values($seen), self::fileOf(BoardToolDispatcher::class), self::fileOf(ToolCallBody::class)]));
    }

    /**
     * A named `reason:` argument at the TOP level — one inside a nested call is not this call's.
     *
     * @param  list<string>  $args
     */
    private static function passesReason(array $args): bool
    {
        foreach ($args as $arg) {
            if (preg_match('/^\s*reason\s*:/', $arg)) {
                return true;
            }
        }

        return false;
    }

    private static function fileOf(string $class): string
    {
        return (string) (new \ReflectionClass($class))->getFileName();
    }

    /**
     * The `App\` classes, interfaces and enums a file's CODE names, resolved.
     *
     * @return list<class-string>
     */
    private function namedClasses(string $file): array
    {
        [$namespace, $uses, $tokens] = $this->parse((string) file_get_contents($file));
        $named = [];
        foreach ($tokens as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $fq = $this->resolve($t, $namespace, $uses);
            if (str_starts_with($fq, 'App\\') && (class_exists($fq) || interface_exists($fq) || enum_exists($fq))) {
                $named[] = $fq;
            }
        }

        return array_values(array_unique($named));
    }

    /**
     * @param  array{0: int, 1: string, 2: int}  $t
     * @param  array<string, string>  $uses
     */
    private function resolve(array $t, string $namespace, array $uses): string
    {
        if ($t[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($t[1], '\\');
        }
        $first = explode('\\', $t[1])[0];
        if (isset($uses[$first])) {
            return $uses[$first].substr($t[1], strlen($first));
        }

        return ($namespace === '' ? '' : $namespace.'\\').$t[1];
    }

    /**
     * The namespace, the `use` map and the code tokens (whitespace, comments and the `use` and
     * `namespace` statements themselves dropped).
     *
     * @return array{0: string, 1: array<string, string>, 2: list<mixed>}
     */
    private function parse(string $source): array
    {
        $all = token_get_all($source);
        $namespace = '';
        $uses = [];
        $code = [];
        $count = count($all);
        for ($i = 0; $i < $count; $i++) {
            $t = $all[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && ($t[0] === T_NAMESPACE || ($t[0] === T_USE && $code !== [] && end($code) === ';') || ($t[0] === T_USE && $code === []))) {
                $parts = '';
                for ($i++; $i < $count && $all[$i] !== ';' && $all[$i] !== '{'; $i++) {
                    $parts .= is_array($all[$i]) ? $all[$i][1] : $all[$i];
                }
                $parts = trim($parts);
                if ($t[0] === T_NAMESPACE) {
                    $namespace = $parts;
                } elseif (! str_starts_with($parts, 'function ') && ! str_starts_with($parts, 'const ')) {
                    [$fq, $alias] = array_pad(preg_split('/\s+as\s+/i', $parts) ?: [], 2, null);
                    $fq = ltrim((string) $fq, '\\');
                    $uses[$alias ?? substr($fq, (int) strrpos('\\'.$fq, '\\'))] = $fq;
                }
                $code[] = ';';

                continue;
            }
            $code[] = $t;
        }

        return [$namespace, $uses, $code];
    }

    /**
     * Each `new ToolRefusalException(` in $source, in any spelling that resolves to it, with its line
     * and its top-level arguments' source.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private function refusalConstructions(string $source): array
    {
        [$namespace, $uses, $tokens] = $this->parse($source);
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i + 1 < $count; $i++) {
            $name = $tokens[$i + 1];
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW || ! is_array($name)
                || ! in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                || $this->resolve($name, $namespace, $uses) !== self::REFUSAL) {
                continue;
            }
            $found[] = [$name[2], $this->argumentsAt($tokens, $i + 2)];
        }

        return $found;
    }

    /**
     * Each `<receiver>-><method>(` or `<Class>::<method>(` call, with its line and its top-level
     * arguments' source.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private function calls(string $source, string $receiver, string $method): array
    {
        [, , $tokens] = $this->parse($source);
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i + 3 < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][1] === $receiver
                && is_array($tokens[$i + 1]) && in_array($tokens[$i + 1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                && is_array($tokens[$i + 2]) && $tokens[$i + 2][1] === $method && $tokens[$i + 3] === '(') {
                $found[] = [$tokens[$i][2], $this->argumentsAt($tokens, $i + 3)];
            }
        }

        return $found;
    }

    /**
     * The top-level arguments of the call whose `(` is at $open.
     *
     * @param  list<mixed>  $tokens
     * @return list<string>
     */
    private function argumentsAt(array $tokens, int $open): array
    {
        $args = [''];
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
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

        return $args === [''] ? [] : $args;
    }
}
