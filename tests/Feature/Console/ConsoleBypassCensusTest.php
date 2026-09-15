<?php

namespace Tests\Feature\Console;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * ⛔ LEG D OF card#9251 (DL-393): every place in `app/` that can put bytes on fd 1 or fd 2
 * WITHOUT going through the console output the choke wraps, each one ruled.
 *
 * The choke covers every write made through an `OutputInterface`. What it cannot cover is a
 * write that never touches one — a raw `fwrite(STDOUT)`, an `echo`, a child process that
 * inherits the descriptors, PHP's own error display. This census is the population of those,
 * derived from the token stream by {@see SourceScan::sitesInApp()} on every run and compared
 * both ways against {@see self::RULINGS}: a new site reds, and so does a ruling whose site has
 * gone. A stream name inside a string literal — code a child process runs, a `defined()` test,
 * a path handed to `fopen` — is a site too.
 *
 * ⚠ WHAT IT CANNOT DECIDE, it says by name rather than passing. A handle held in a variable
 * (`fwrite($h, …)`) is a site whose target the token stream does not carry: its descriptor
 * reads `undecidable: <the argument>`, and its ruling must be `UNDECIDABLE_RULED_BY_READING`,
 * with the reading that decided it. A dynamically-named call (`call_user_func('fwrite', …)`,
 * `$fn(…)`) is not a token this scan can see at all — DL-393's bound, not a pass.
 */
class ConsoleBypassCensusTest extends TestCase
{
    private const TERMINAL_DELIBERATE = 'TERMINAL_DELIBERATE';

    private const NOT_A_TERMINAL_WRITE = 'NOT_A_TERMINAL_WRITE';

    private const UNDECIDABLE_RULED_BY_READING = 'UNDECIDABLE_RULED_BY_READING';

    /** Functions whose Nth argument (0-based) is the stream or path written to. */
    private const HANDLE_ARGUMENT = [
        'fwrite' => 0, 'fputs' => 0, 'fprintf' => 0, 'vfprintf' => 0, 'fpassthru' => 0,
        'file_put_contents' => 0, 'stream_copy_to_stream' => 1,
    ];

    /** Functions that write to the process's own output, or hand a child its descriptors. */
    private const WRITERS = [
        'printf', 'vprintf', 'readfile', 'error_log', 'passthru', 'system', 'exec', 'shell_exec',
        'popen', 'proc_open', 'pcntl_exec', 'var_dump', 'dump', 'dd', 'debug_zval_dump', 'debug_print_backtrace',
    ];

    /** Writers that RETURN instead of printing when their second argument is `true`. */
    private const RETURN_MODE = ['var_export', 'print_r'];

    private const METHODS = ['tty', 'settty'];

    /** The constant names are case-sensitive (prose says "stdout" constantly); the paths are not. */
    private const HANDLE_LITERAL = '#\bSTD(?:OUT|ERR)\b|(?i:php://(?:stdout|stderr|output|fd/)|/dev/(?:tty|stdout|stderr|fd/))#';

    /**
     * site => [descriptor, ruling, the reading that decided it]
     *
     * @var array<string, array{string, string, string}>
     */
    private const RULINGS = [
        'Bridge/Handlers/SpawnDetachedHandler.php::handle#1' => ['proc_open', self::NOT_A_TERMINAL_WRITE,
            'the descriptor spec binds 0 to /dev/null and 1 and 2 to the spawn log file: the child inherits no terminal descriptor, from the receiver or from bridge:replay'],
        'Bridge/Support/BridgePaths.php::filterJsonlLocked#1' => ['fwrite(undecidable: $tmp)', self::UNDECIDABLE_RULED_BY_READING,
            "\$tmp is fopen('php://temp/maxmemory:…') in the same function: the rewrite buffer"],
        'Bridge/Support/BridgePaths.php::filterJsonlLocked#2' => ['stream_copy_to_stream(undecidable: $h)', self::UNDECIDABLE_RULED_BY_READING,
            "\$h is fopen(\$path, 'c+') in the same function: the JSONL file being rewritten"],
        'Bridge/Support/BridgePaths.php::updateSeenLocked#1' => ['fwrite(undecidable: $h)', self::UNDECIDABLE_RULED_BY_READING,
            "\$h is fopen(\$path, 'c+') in the same function: the seen-cursor file"],
        'Bridge/Support/BridgePaths.php::writeFile#1' => ['file_put_contents(undecidable: $path)', self::UNDECIDABLE_RULED_BY_READING,
            "every caller of writeFile() passes a file path: writeback.json, a webhook secret, a JSONL or temp file of BridgePaths' own, and spawn_detached's log file, whose log_path the operator's own classifier sets"],
        'Bridge/Support/ClassifierResolver.php::probeLoadable#1' => ['literal: STDERR', self::NOT_A_TERMINAL_WRITE,
            'inside the nowdoc a child `new Process` runs with no setTty(): its stderr is a captured pipe, read back through getErrorOutput() and printed, if at all, through the choke'],
        'Bridge/Support/SystemTerminalProbe.php::hasScreen#1' => ['literal: STDOUT', self::NOT_A_TERMINAL_WRITE,
            "defined('STDOUT'): a presence test"],
        'Bridge/Support/SystemTerminalProbe.php::hasScreen#2' => ['const STDOUT', self::NOT_A_TERMINAL_WRITE,
            'stream_isatty(STDOUT): a tty test, nothing is written'],
        'Bridge/Tools/SystemServingProcessEnvironment.php::hasControllingTerminal#1' => ['literal: /dev/tty', self::NOT_A_TERMINAL_WRITE,
            "fopen('/dev/tty', 'r') then fclose: opened read-only to test for a controlling terminal"],
        'Bridge/Tools/ToolsCallStdio.php::out#1' => ['const STDOUT', self::TERMINAL_DELIBERATE,
            "bridge:tools-call's fd 1, the ssh channel's tool result. It carries ToolsCallCommand::emit()'s json_encode with default flags, which escapes every C0 byte and every non-ASCII codepoint to \\uXXXX, so no byte of the stripped class reaches it"],
        'Bridge/Tools/ToolsCallStdio.php::err#1' => ['const STDERR', self::TERMINAL_DELIBERATE,
            "bridge:tools-call's fd 2: ToolsCallCommand::diag() lines, whose one interpolation is a ConfigException over the operator's OWN agent YAML — operator-authored text, a DL-393 bound"],
        'Console/Commands/Bridge/ProvisionToolsCommand.php::writeSecret#1' => ['file_put_contents(undecidable: $path)', self::UNDECIDABLE_RULED_BY_READING,
            "its one caller passes the agent's board-tools token path under the secret dir, touched and chmod 0600 before the write"],
        'Console/Commands/Bridge/ToolsCallCommand.php::(file scope)#1' => ['literal: STDOUT', self::NOT_A_TERMINAL_WRITE,
            'the $description prose'],
        'Console/Commands/Bridge/ToolsCallCommand.php::diag#1' => ['fwrite(undecidable: $io->err())', self::UNDECIDABLE_RULED_BY_READING,
            'ToolsCallStdio::err() answers STDERR; that ruling says what the line carries'],
        'Console/Commands/Bridge/ToolsCallCommand.php::emit#1' => ['fwrite(undecidable: $io->out())', self::UNDECIDABLE_RULED_BY_READING,
            'ToolsCallStdio::out() answers STDOUT; that ruling says what the envelope carries'],
        'Console/Commands/Bridge/ToolsCallCommand.php::handle#1' => ["ini_set('display_errors')", self::TERMINAL_DELIBERATE,
            "moves PHP's own notices OFF the envelope channel onto fd 2; their text is PHP's, and fd 2 of the ssh forced command is the remote caller's channel"],
    ];

    public function test_every_console_bypass_in_app_is_ruled(): void
    {
        $found = SourceScan::sitesInApp(self::siteAt(...));

        $ruled = array_map(static fn (array $r): string => $r[0], self::RULINGS);
        ksort($found);
        ksort($ruled);

        $this->assertSame(
            $ruled,
            $found,
            'a write that bypasses the output choke appeared, or a ruled one moved or went — rule it in RULINGS with the reading that decided it',
        );
    }

    public function test_an_undecidable_site_is_ruled_as_undecidable_and_every_ruling_carries_a_reason(): void
    {
        foreach (self::RULINGS as $site => [$descriptor, $ruling, $reason]) {
            $this->assertContains($ruling, [self::TERMINAL_DELIBERATE, self::NOT_A_TERMINAL_WRITE, self::UNDECIDABLE_RULED_BY_READING], $site);
            $this->assertNotSame('', trim($reason), "{$site} has no reason");
            $this->assertSame(
                str_contains($descriptor, 'undecidable:'),
                $ruling === self::UNDECIDABLE_RULED_BY_READING,
                "{$site}: a site whose target the scan could not decide must be ruled UNDECIDABLE_RULED_BY_READING, and only such a site",
            );
        }
    }

    /**
     * ⭐ THE CONTROL: every vocabulary member is found in a planted source, and every near miss
     * — a method or declaration of the same name, a comment, a return-mode call, an integer
     * exit, a plain file path, an unrelated ini key, lower-case prose — is not.
     */
    public function test_the_scan_finds_each_planted_bypass_and_skips_each_near_miss(): void
    {
        $esc = "\e";
        $plant = <<<PHP
<?php
namespace Plant;
function planted(\$h, \$x, \$cmd, \$p, \$o) {
    fwrite(STDOUT, "{$esc}[2K");
    \\fputs(\\STDERR, 'x');
    fprintf(\$h, 'x');
    vfprintf(STDERR, '%s', [\$x]);
    fpassthru(\$h);
    file_put_contents('php://stdout', 'x');
    file_put_contents('/tmp/a-file', 'x');
    stream_copy_to_stream(\$h, STDOUT);
    printf('x');
    vprintf('%s', [\$x]);
    print 'x';
    echo 'x';
    readfile('/etc/motd');
    error_log('x');
    passthru(\$cmd);
    system(\$cmd);
    exec(\$cmd);
    shell_exec(\$cmd);
    popen(\$cmd, 'r');
    proc_open(\$cmd, [], \$pipes);
    pcntl_exec(\$cmd);
    var_dump(\$x);
    var_export(\$x);
    print_r(\$x);
    dump(\$x);
    dd(\$x);
    var_export(\$x, true);
    print_r(\$x, true);
    \$p->tty();
    \$p->setTty(true);
    \$fp = fopen('php://stderr', 'w');
    \$script = 'fwrite(STDERR, "boom");';
    \$tty = '/dev/tty';
    \$prose = 'the child stdout and stderr pipes';
    \$o->fwrite('x');
    \$o->print('x');
    Foo::system('x');
    exit('bye');
    die(\$x);
    exit(1);
    `ls`;
    \$s = STDOUT;
    ini_set('display_errors', 'stderr');
    ini_set('memory_limit', '1G');
    // fwrite(STDOUT, 'a comment is not a site');
}
class K
{
    public function system(): void {}
    public function echo(): void {}
    public function print(): void {}
}
?>
INLINE <?= 'x' ?>

PHP;

        $this->assertSame([
            'Plant.php::planted#1' => 'fwrite(STDOUT)',
            'Plant.php::planted#2' => 'fputs(STDERR)',
            'Plant.php::planted#3' => 'fprintf(undecidable: $h)',
            'Plant.php::planted#4' => 'vfprintf(STDERR)',
            'Plant.php::planted#5' => 'fpassthru(undecidable: $h)',
            'Plant.php::planted#6' => "file_put_contents('php://stdout')",
            'Plant.php::planted#7' => 'stream_copy_to_stream(STDOUT)',
            'Plant.php::planted#8' => 'printf',
            'Plant.php::planted#9' => 'vprintf',
            'Plant.php::planted#10' => 'print',
            'Plant.php::planted#11' => 'echo',
            'Plant.php::planted#12' => 'readfile',
            'Plant.php::planted#13' => 'error_log',
            'Plant.php::planted#14' => 'passthru',
            'Plant.php::planted#15' => 'system',
            'Plant.php::planted#16' => 'exec',
            'Plant.php::planted#17' => 'shell_exec',
            'Plant.php::planted#18' => 'popen',
            'Plant.php::planted#19' => 'proc_open',
            'Plant.php::planted#20' => 'pcntl_exec',
            'Plant.php::planted#21' => 'var_dump',
            'Plant.php::planted#22' => 'var_export',
            'Plant.php::planted#23' => 'print_r',
            'Plant.php::planted#24' => 'dump',
            'Plant.php::planted#25' => 'dd',
            'Plant.php::planted#26' => '->tty()',
            'Plant.php::planted#27' => '->setTty()',
            'Plant.php::planted#28' => 'literal: php://stderr',
            'Plant.php::planted#29' => 'literal: STDERR',
            'Plant.php::planted#30' => 'literal: /dev/tty',
            'Plant.php::planted#31' => 'exit(literal)',
            'Plant.php::planted#32' => 'die(undecidable: $x)',
            'Plant.php::planted#33' => 'backtick',
            'Plant.php::planted#34' => 'const STDOUT',
            'Plant.php::planted#35' => "ini_set('display_errors')",
            'Plant.php::(file scope)#1' => 'inline html',
            'Plant.php::(file scope)#2' => '<?=',
        ], SourceScan::sites($plant, 'Plant.php', self::siteAt(...)));
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?string
    {
        [$type, $text] = $tokens[$i];
        $previous = $tokens[$i - 1][0] ?? null;
        $isMember = in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if ($type === T_ECHO || $type === T_PRINT) {
            // `function echo()` lexes as T_ECHO, not T_STRING.
            return $isMember || $previous === T_FUNCTION ? null : strtolower($text);
        }
        if ($type === T_OPEN_TAG_WITH_ECHO) {
            return '<?=';
        }
        if ($type === T_INLINE_HTML) {
            return trim($text) === '' ? null : 'inline html';
        }
        if ($type === '`') {
            return self::opensBacktick($tokens, $i) ? 'backtick' : null;
        }
        if ($type === T_EXIT) {
            return self::exitSite($tokens, $i);
        }
        if ($type === T_CONSTANT_ENCAPSED_STRING || $type === T_ENCAPSED_AND_WHITESPACE) {
            if (preg_match(self::HANDLE_LITERAL, $text, $m) !== 1 || self::isHandleArgument($tokens, $i)) {
                return null;
            }

            return 'literal: '.$m[0];
        }
        if ($type !== T_STRING && $type !== T_NAME_FULLY_QUALIFIED) {
            return null;
        }

        $name = ltrim($text, '\\');
        $isCall = ($tokens[$i + 1][1] ?? null) === '(';

        if (in_array($name, ['STDOUT', 'STDERR'], true) && ! $isCall && ! $isMember) {
            return self::isHandleArgument($tokens, $i) ? null : 'const '.$name;
        }
        if (! $isCall || in_array($previous, [T_FUNCTION, T_NEW, T_CONST], true)) {
            return null;
        }
        $lower = strtolower($name);
        if ($isMember) {
            return $previous === T_OBJECT_OPERATOR && in_array($lower, self::METHODS, true) ? '->'.$name.'()' : null;
        }
        if (array_key_exists($lower, self::HANDLE_ARGUMENT)) {
            $argument = self::arguments($tokens, $i + 1)[self::HANDLE_ARGUMENT[$lower]] ?? [];
            $target = self::handleTarget($argument);

            return $target === null ? null : $lower.'('.$target.')';
        }
        if (in_array($lower, self::RETURN_MODE, true)) {
            $second = self::arguments($tokens, $i + 1)[1] ?? [];

            return count($second) === 1 && strtolower($second[0][1]) === 'true' ? null : $lower;
        }
        if ($lower === 'ini_set') {
            $key = self::arguments($tokens, $i + 1)[0] ?? [];

            return count($key) === 1 && $key[0][0] === T_CONSTANT_ENCAPSED_STRING && strtolower(trim($key[0][1], '\'"')) === 'display_errors'
                ? "ini_set('display_errors')"
                : null;
        }

        return in_array($lower, self::WRITERS, true) ? $lower : null;
    }

    /**
     * What a stream/path argument names: a standard stream, a literal stream path, nothing
     * terminal (a plain literal file path — null), or undecidable.
     *
     * @param  list<array{0: int|string, 1: string}>  $argument
     */
    private static function handleTarget(array $argument): ?string
    {
        if (count($argument) === 1) {
            [$type, $text] = $argument[0];
            if (($type === T_STRING || $type === T_NAME_FULLY_QUALIFIED) && in_array(ltrim($text, '\\'), ['STDOUT', 'STDERR'], true)) {
                return ltrim($text, '\\');
            }
            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                return preg_match(self::HANDLE_LITERAL, $text) === 1 ? $text : null;
            }
        }

        return 'undecidable: '.mb_strimwidth(implode('', array_column($argument, 1)), 0, 60, '…');
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function exitSite(array $tokens, int $i): ?string
    {
        $word = strtolower($tokens[$i][1]);
        if (($tokens[$i + 1][1] ?? null) !== '(') {
            return null;
        }
        $argument = self::arguments($tokens, $i + 1)[0] ?? [];
        if ($argument === [] || (count($argument) === 1 && $argument[0][0] === T_LNUMBER)) {
            return null;
        }
        if (count($argument) === 1 && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return $word.'(literal)';
        }

        return $word.'(undecidable: '.mb_strimwidth(implode('', array_column($argument, 1)), 0, 60, '…').')';
    }

    /**
     * The top-level arguments of the call whose `(` is at $open, each as its token list.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return list<list<array{0: int|string, 1: string}>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $arguments = [[]];
        $depth = 0;
        for ($j = $open + 1, $n = count($tokens); $j < $n; $j++) {
            $text = $tokens[$j][1];
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$j][0] === T_CURLY_OPEN || $tokens[$j][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $arguments[] = [];

                continue;
            }
            $arguments[count($arguments) - 1][] = $tokens[$j];
        }

        return $arguments === [[]] ? [] : $arguments;
    }

    /**
     * Whether the token at $i is, by itself, the stream/path argument of a HANDLE_ARGUMENT call
     * — in which case that call is the site, and the constant or literal is not a second one.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function isHandleArgument(array $tokens, int $i): bool
    {
        if (! in_array($tokens[$i - 1][1] ?? null, [',', '('], true) || ! in_array($tokens[$i + 1][1] ?? null, [',', ')'], true)) {
            return false;
        }

        $index = 0;
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $text = $tokens[$j][1];
            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (in_array($text, ['(', '[', '{'], true)) {
                if ($depth === 0) {
                    $name = strtolower(ltrim($tokens[$j - 1][1] ?? '', '\\'));

                    return $text === '(' && (self::HANDLE_ARGUMENT[$name] ?? null) === $index;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $index++;
            } elseif ($text === ';' && $depth === 0) {
                return false;
            }
        }

        return false;
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function opensBacktick(array $tokens, int $i): bool
    {
        $before = 0;
        for ($j = 0; $j < $i; $j++) {
            if ($tokens[$j][0] === '`') {
                $before++;
            }
        }

        return $before % 2 === 0;
    }
}
