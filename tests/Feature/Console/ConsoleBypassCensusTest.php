<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentFactory;
use ReflectionClass;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * ⛔ LEG D OF card#9251 (DL-393): every place in `app/` that can put bytes on the operator's
 * TERMINAL without going through the console output the choke wraps, each one ruled. Most of
 * those are this process writing fd 1 or fd 2 around the choke, or a child inheriting them —
 * but HIDDEN INPUT is not, and the class is stated as the terminal rather than as the two
 * descriptors because of it: there the TTY echoes bytes this process never writes at all.
 *
 * ⭐ THE PREDICATE IS OVER WRITER CATEGORIES, NOT WRITER NAMES. A list of names let a freshly
 * built output object, a member-form dumper, Termwind and Laravel Prompts through unseen. A
 * token is a site when it is:
 *
 * - OUTPUT CONSTRUCTION: `new X`, `new static|self|parent`, `new class extends X`, `X::class`
 *   (a container resolve), or a string literal naming X, where X is ANY class or interface
 *   that is-a Symfony `OutputInterface`. That is decided by autoloading X, not by its name.
 * - RAW STREAM: the `STDOUT`/`STDERR` constants, a stream path literal (also inside a string),
 *   the stream-or-path argument of an `fwrite`-family call, `ini_set('display_errors')`.
 * - LANGUAGE OUTPUT: `echo`, `print`, `<?=`, inline HTML, `exit`/`die` with a non-integer, and
 *   PHP's own printing functions, a set the language closes. The return-mode ones count
 *   only when not returning.
 * - DUMPER: `dump()`/`dd()`, any `->dump(`/`::dump(`/`->dd(` member call, and any name in
 *   `Symfony\Component\VarDumper\` or Laravel's `CliDumper`.
 * - TERMWIND: any name in `Termwind\`.
 * - PROMPTS: any name in `Laravel\Prompts\`, including its `use` imports and a `Prompt`
 *   subclass's `extends`.
 * - PROCESS PASSTHROUGH: `passthru`, `system` and PHP's other process-spawning functions,
 *   backticks, `->tty()`/`->setTty()`.
 * - HIDDEN INPUT: a masked console read. Under DL-393 Decision 8's
 *   `QuestionHelper::disableStty()` the masked read raises and `doAsk()` SWALLOWS that unless
 *   the question is explicitly non-fallback, so the answer is read back VISIBLY and the
 *   terminal ECHOES what is typed — a secret onto the screen and into the scrollback, on bytes
 *   the choke never sees, because the tty wrote them and not this process (bound (12)). ⭐ Its
 *   members are METHOD NAMES ({@see self::HIDDEN_INPUT_METHODS}), and that list is DERIVED
 *   rather than recalled: {@see self::hiddenReadEntryPoints()} re-computes from VENDOR, on
 *   every run, every method of the classes a command reaches a hidden read through, and
 *   {@see self::HIDDEN_READ_RULINGS} rules each one in or out with the reading that decided it.
 *   A recalled list had already missed one (R3 MF1, `askHidden()`).
 *
 * Names resolve through the file's `namespace` and `use` imports, so an alias hides nothing.
 * The population is derived by {@see SourceScan::sitesInApp()} on every run and compared both
 * ways against {@see self::RULINGS}: a new site reds, and so does a ruling whose site has gone.
 *
 * ⚠ WHAT IT CANNOT SEE (DL-393 bound (1)): a writer reached through a VARIABLE (`new $class`,
 * `$class::make()`, `app($abstract)`, `$fn()`, `$object->$method()`, `call_user_func('fwrite', …)`),
 * and a call into vendor code outside the named namespaces that opens an output of its own.
 * The token stream carries the call, not what the callee does. For the NAME-matched categories
 * (hidden input, process passthrough, dumper methods) it also cannot see an entry point whose
 * own NAME is outside the category — the hidden-input names are derived from vendor and ruled
 * below, so what stays blind there is a wrapper OUTSIDE those root classes, and a spelling that
 * is no member call at all (Symfony's `#[Ask(hidden: true)]` attribute is the shape; it is
 * reached only through `InvokableCommand`, which Laravel never builds). A member call it DOES
 * see through either operator: `->` and `?->` are both members for every category here.
 *
 * A handle held in a variable (`fwrite($h, …)`) is a site the scan sees but cannot decide: its
 * descriptor reads `undecidable: <the argument>`, and its ruling must be
 * `UNDECIDABLE_RULED_BY_READING`, with the reading that decided it.
 */
class ConsoleBypassCensusTest extends TestCase
{
    private const TERMINAL_DELIBERATE = 'TERMINAL_DELIBERATE';

    private const NOT_A_TERMINAL_WRITE = 'NOT_A_TERMINAL_WRITE';

    private const UNDECIDABLE_RULED_BY_READING = 'UNDECIDABLE_RULED_BY_READING';

    /** An output object that IS the choke, or that the choke wraps before anything writes to it. */
    private const INSIDE_THE_CHOKE = 'INSIDE_THE_CHOKE';

    /** RAW STREAM: functions whose Nth argument (0-based) is the stream or path written to. */
    private const HANDLE_ARGUMENT = [
        'fwrite' => 0, 'fputs' => 0, 'fprintf' => 0, 'vfprintf' => 0, 'fpassthru' => 0,
        'file_put_contents' => 0, 'stream_copy_to_stream' => 1,
    ];

    /** LANGUAGE OUTPUT: PHP's functions that print to the process's own output. */
    private const LANGUAGE_OUTPUT = [
        'printf', 'vprintf', 'var_dump', 'debug_zval_dump', 'debug_print_backtrace', 'readfile', 'error_log', 'phpinfo', 'phpcredits',
    ];

    /** LANGUAGE OUTPUT that RETURNS instead of printing when its second argument is `true`. */
    private const RETURN_MODE = ['var_export', 'print_r', 'highlight_string', 'highlight_file', 'show_source'];

    /** PROCESS PASSTHROUGH: a child that inherits this process's descriptors. */
    private const PROCESS_PASSTHROUGH = ['passthru', 'system', 'exec', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec'];

    private const PROCESS_PASSTHROUGH_METHODS = ['tty', 'settty'];

    /**
     * HIDDEN INPUT: a masked console read whose fallback under `disableStty()` is a VISIBLE one.
     *
     * ⛔ NOT A RECALLED LIST. Every name here is the `app/`-visible spelling of an entry point
     * {@see self::hiddenReadEntryPoints()} derives from VENDOR, and
     * {@see test_every_vendor_hidden_read_entry_point_is_ruled} reds if vendor offers one this
     * list does not carry. `askhidden` is the name a recalled list missed (R3 MF1): Laravel's
     * `OutputStyle` IS a `SymfonyStyle`, so `$this->output->askHidden(…)` is a masked read on
     * every command that spells no `secret` or `hidden` marker anywhere in `app/`.
     */
    private const HIDDEN_INPUT_METHODS = ['askhidden', 'secret', 'sethidden', 'sethiddenfallback'];

    /**
     * A method REACHES A HIDDEN READ when its own source — SIGNATURE INCLUDED, so that declaring
     * one of these counts as well as calling one — marks a question hidden or performs the
     * stty-masked read.
     */
    private const HIDDEN_READ = '/setHidden\s*\(|setHiddenFallback\s*\(|getHiddenResponse\s*\(/';

    /**
     * ⭐ THE HIDDEN-INPUT POPULATION, DERIVED FROM VENDOR AND RULED ONE BY ONE — the artifact
     * that makes "a future masked read in `app/` arrives as a red test" a CHECKED claim.
     *
     * The ROOTS are what a command reaches a hidden read through with NO variable indirection:
     * `$this` (`Illuminate\Console\Command`), `$this->output` (`Illuminate\Console\OutputStyle`,
     * which `InteractsWithIO::setOutput(OutputStyle $output)` pins the property to), a `Question`
     * built by hand, `QuestionHelper` (the mechanism itself), and every
     * `Illuminate\Console\View\Components\*` class, which `$this->components-><name>()` dispatches
     * to by class short name. {@see self::hiddenReadRoots()} re-derives them; the components are
     * globbed off `Factory`'s own directory rather than listed.
     *
     * A ruling with a SPELLING is reachable from `app/` and its name must be in
     * {@see self::HIDDEN_INPUT_METHODS}; a ruling with `null` is ruled OUT, and the reading says
     * why it is not a masked console read a command can spell.
     *
     * @var array<string, array{?string, string}>
     */
    private const HIDDEN_READ_RULINGS = [
        'Illuminate\Console\Command::__construct' => [null,
            'matches only on `$this->setHidden($this->isHidden())` — Symfony COMMAND VISIBILITY in `artisan list`. No question is built and nothing is read'],
        'Illuminate\Console\Command::secret' => ['secret',
            "`\$this->secret('…')`: builds a Question, `setHidden(true)->setHiddenFallback(true)`, and hands it to `\$this->output->askQuestion()`. Bound (12)'s hazard, spelled the way Laravel documents it"],
        'Illuminate\Console\Command::setHidden' => [null,
            'command visibility again (`parent::setHidden($this->hidden = $hidden)`), sharing its NAME with the Question marker. ⚠ The token predicate matches on the name, so a `$command->setHidden(true)` in `app/` would red this census as a hidden input it is not — a FALSE POSITIVE that arrives loud, with a ruling to write, which is the direction a census must fail in'],
        'Illuminate\Console\View\Components\Secret::render' => ['secret',
            "`\$this->components->secret('…')`: `Factory::__call` dispatches the component's short name to `render()`, which builds the same hidden fallback-true question. Its `app/` spelling is that short name, already covered"],
        'Symfony\Component\Console\Helper\QuestionHelper::doAsk' => [null,
            'private: the branch that performs the masked read and SWALLOWS its failure when the question is hidden-fallback. What the entry points reach, never a spelling `app/` can write'],
        'Symfony\Component\Console\Helper\QuestionHelper::getHiddenResponse' => [null,
            'private: the stty-masked read itself, reached only through `doAsk()`'],
        'Symfony\Component\Console\Question\Question::setHidden' => ['sethidden',
            '`$question->setHidden(true)` on a hand-built question — the marker both `askHidden()` and `secret()` call inside vendor'],
        'Symfony\Component\Console\Question\Question::setHiddenFallback' => ['sethiddenfallback',
            'a site whichever way it is called: `false` makes a masked read THROW under `disableStty()`, and `true` (or its absence, the default) is bound (12)'."'".'s visible echo'],
        'Symfony\Component\Console\Style\SymfonyStyle::askHidden' => ['askhidden',
            "`\$this->output->askHidden('…')`: `OutputStyle` extends `SymfonyStyle`, so this sits on `\$this->output` of EVERY command. It calls `setHidden(true)` INSIDE vendor, where a token scan over `app/` never looks, and passes no fallback, so Symfony's default TRUE applies and the answer is echoed. The spelling a recalled name list missed (R3 MF1)"],
    ];

    private const DUMPER_FUNCTIONS = ['dump', 'dd'];

    private const DUMPER_METHODS = ['dump', 'dd', 'dumprawsql', 'ddrawsql'];

    /** Every name under one of these prefixes belongs to a writer that opens its own output. */
    private const WRITER_NAMESPACES = [
        'symfony\\component\\vardumper\\' => 'dumper',
        'illuminate\\foundation\\console\\clidumper' => 'dumper',
        'termwind\\' => 'termwind',
        'laravel\\prompts\\' => 'prompts',
    ];

    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** The constant names are case-sensitive (prose says "stdout" constantly); the paths are not. */
    private const HANDLE_LITERAL = '#\bSTD(?:OUT|ERR)\b|(?i:php://(?:stdout|stderr|output|fd/)|/dev/(?:tty|stdout|stderr|fd/))#';

    /**
     * site => [descriptor, ruling, the reading that decided it]
     *
     * @var array<string, array{string, string, string}>
     */
    private const RULINGS = [
        'Bridge/Console/StrippingConsoleKernel.php::(file scope)#1' => ['prompts: use Laravel\Prompts\Prompt', self::INSIDE_THE_CHOKE,
            "the choke's own import, for disableInteractiveEscapes()'s Prompt::fallbackWhen(true) call — it does not construct a Prompt or write through one"],
        'Bridge/Console/StrippingConsoleKernel.php::call#1' => ['output: new Symfony\Component\Console\Output\BufferedOutput', self::INSIDE_THE_CHOKE,
            'the default call() buffer: Artisan receives it only through StrippingOutput::wrap() on the next line, and output() only reads it back'],
        'Bridge/Console/StrippingConsoleKernel.php::disableInteractiveEscapes#1' => ['prompts: Laravel\Prompts\Prompt::', self::INSIDE_THE_CHOKE,
            'operator decision 2026-09-15 (Option 1, the plain-text console): Prompt::fallbackWhen(true) diverts every Laravel Prompts call with a registered fallback to its line-based form, process-wide — it is the mechanism, not a bypass of it'],
        'Bridge/Console/StrippingConsoleKernel.php::handle#1' => ['output: new Symfony\Component\Console\Output\ConsoleOutput', self::INSIDE_THE_CHOKE,
            "php artisan's real stdout/stderr, wrapped by StrippingOutput::wrap() in the same expression, before Artisan or Kernel::handle()'s exception render holds it"],
        'Bridge/Console/StrippingConsoleOutput.php::section#1' => ['output: new App\Bridge\Console\StrippingSectionOutput', self::INSIDE_THE_CHOKE,
            'the stripping section: permanently undecorated, and its doWrite() and addContent() strip'],
        'Bridge/Console/StrippingOutput.php::wrap#1' => ['output: new App\Bridge\Console\StrippingConsoleOutput', self::INSIDE_THE_CHOKE,
            'the choke decorator itself, over an output with an error stream'],
        'Bridge/Console/StrippingOutput.php::wrap#2' => ['output: new App\Bridge\Console\StrippingOutput', self::INSIDE_THE_CHOKE,
            'the choke decorator itself (`new self`)'],
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

    /** @var list<array{0: int|string, 1: string}>|null */
    private static ?array $contextTokens = null;

    /** @var array{namespace: string, classes: array<string, string>, functions: array<string, string>, imports: array<int, string>, prefixes: array<int, true>, declarations: list<array{int, string, ?string}>} */
    private static array $context;

    /** @var array<string, bool> */
    private static array $isOutput = [];

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
            $this->assertContains($ruling, [self::TERMINAL_DELIBERATE, self::NOT_A_TERMINAL_WRITE, self::UNDECIDABLE_RULED_BY_READING, self::INSIDE_THE_CHOKE], $site);
            $this->assertNotSame('', trim($reason), "{$site} has no reason");
            $this->assertSame(
                str_contains($descriptor, 'undecidable:'),
                $ruling === self::UNDECIDABLE_RULED_BY_READING,
                "{$site}: a site whose target the scan could not decide must be ruled UNDECIDABLE_RULED_BY_READING, and only such a site",
            );
        }
    }

    /**
     * ⭐ THE CONTROL for the raw-stream, language-output, process-passthrough and hidden-input
     * categories: every member is found in a planted source, and every near miss is not. The
     * near misses are a method or declaration of the same name, a comment, a return-mode call,
     * an integer exit, a plain file path, an unrelated ini key, lower-case prose, and — for
     * HIDDEN INPUT — a STATIC call and a PROPERTY of the same name, neither of which reads the
     * terminal. ⛔ The NULLSAFE spelling of each member-call category is planted as a POSITIVE
     * (R3 MF2): `?->` was silently not a site, in the two categories that exist to red.
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
    \$o->secret('Paste the token');
    \$o->setHidden(true);
    \$o->setHiddenFallback(false);
    \$o->askHidden('Paste the token');
    \$o?->secret('Paste the token');
    \$o?->setHidden(true);
    \$o?->setHiddenFallback(false);
    \$o?->askHidden('Paste the token');
    \$p?->tty();
    \$p?->setTty(true);
    Foo::secret('x');
    Foo::askHidden('x');
    \$o->secretPath;
    \$o?->secretPath;
    // fwrite(STDOUT, 'a comment is not a site');
}
class K
{
    public function system(): void {}
    public function echo(): void {}
    public function secret(): void {}
    public function askHidden(): void {}
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
            'Plant.php::planted#36' => '->secret()',
            'Plant.php::planted#37' => '->setHidden()',
            'Plant.php::planted#38' => '->setHiddenFallback()',
            'Plant.php::planted#39' => '->askHidden()',
            'Plant.php::planted#40' => '?->secret()',
            'Plant.php::planted#41' => '?->setHidden()',
            'Plant.php::planted#42' => '?->setHiddenFallback()',
            'Plant.php::planted#43' => '?->askHidden()',
            'Plant.php::planted#44' => '?->tty()',
            'Plant.php::planted#45' => '?->setTty()',
            'Plant.php::(file scope)#1' => 'inline html',
            'Plant.php::(file scope)#2' => '<?=',
        ], SourceScan::sites($plant, 'Plant.php', self::siteAt(...)));
    }

    /**
     * ⭐ THE CONTROL for the categories a name list could not express: output construction,
     * dumpers, Termwind and Prompts, each reached through a fully-qualified name, an import, an
     * alias, a group import and a container resolve. The near misses are the same classes used
     * WITHOUT constructing or calling a writer: an import of an output class, an `instanceof`, a
     * type hint, a class constant, a same-named method on another object, an unimported function
     * of the same short name, a declared `dump()` method, and a namespace string that names no class.
     */
    public function test_the_scan_finds_each_planted_category_writer_and_skips_each_near_miss(): void
    {
        $plant = <<<'PHP'
<?php
namespace Plant;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\confirm;
use Laravel\Prompts\{Prompt, TextPrompt};
use function Termwind\renderUsing as termwindUsing;
function categories(OutputInterface $o, $class) {
    new ConsoleOutput();
    (new \Symfony\Component\Console\Output\StreamOutput($o))->write('x');
    app(ConsoleOutput::class);
    resolve('Symfony\Component\Console\Output\BufferedOutput');
    new class extends \Symfony\Component\Console\Output\NullOutput {};
    dump($o);
    \dd($o);
    $o->dump();
    $o?->dd();
    Foo::dump($o);
    \Symfony\Component\VarDumper\VarDumper::dump($o);
    \Termwind\render('<p>x</p>');
    termwindUsing($o);
    confirm('ok?');
    new TextPrompt('x');
    Prompt::theme();
    $o instanceof ConsoleOutput;
    $v = OutputInterface::VERBOSITY_QUIET;
    $o->render();
    render('x');
    new \ArrayObject();
    $s = 'Symfony\Component\Console\Output';
}
class Choke extends \Symfony\Component\Console\Output\Output
{
    public function dump(): void {}
    public static function make(): static { return new static(); }
    protected function doWrite(string $message, bool $newline): void {}
}
class Asks extends Prompt {}
PHP;

        $this->assertSame([
            'Plant.php::(file scope)#1' => 'prompts: use Laravel\Prompts\confirm',
            'Plant.php::(file scope)#2' => 'prompts: use Laravel\Prompts\Prompt',
            'Plant.php::(file scope)#3' => 'prompts: use Laravel\Prompts\TextPrompt',
            'Plant.php::(file scope)#4' => 'termwind: use Termwind\renderUsing',
            'Plant.php::categories#1' => 'output: new Symfony\Component\Console\Output\ConsoleOutput',
            'Plant.php::categories#2' => 'output: new Symfony\Component\Console\Output\StreamOutput',
            'Plant.php::categories#3' => 'output: Symfony\Component\Console\Output\ConsoleOutput::class',
            'Plant.php::categories#4' => 'output: literal Symfony\Component\Console\Output\BufferedOutput',
            'Plant.php::categories#5' => 'output: new class extends Symfony\Component\Console\Output\NullOutput',
            'Plant.php::categories#6' => 'dump',
            'Plant.php::categories#7' => 'dd',
            'Plant.php::categories#8' => '->dump()',
            'Plant.php::categories#9' => '->dd()',
            'Plant.php::categories#10' => '::dump()',
            'Plant.php::categories#11' => 'dumper: Symfony\Component\VarDumper\VarDumper::',
            'Plant.php::categories#12' => 'termwind: Termwind\render()',
            'Plant.php::categories#13' => 'termwind: Termwind\renderUsing()',
            'Plant.php::categories#14' => 'prompts: Laravel\Prompts\confirm()',
            'Plant.php::categories#15' => 'prompts: new Laravel\Prompts\TextPrompt',
            'Plant.php::categories#16' => 'prompts: Laravel\Prompts\Prompt::',
            'Plant.php::make#1' => 'output: new Plant\Choke',
            'Plant.php::(file scope)#5' => 'prompts: Laravel\Prompts\Prompt',
        ], SourceScan::sites($plant, 'Plant.php', self::siteAt(...)));
    }

    /**
     * ⭐ THE HIDDEN INPUT NAMES ARE RE-DERIVED FROM VENDOR ON EVERY RUN, which is what makes the
     * claim "a future masked read in `app/` arrives as a red test" CHECKED rather than recalled.
     * It fails BOTH ways: a vendor upgrade that adds an entry point reds with the new method
     * named, and a ruling whose method vendor has removed reds too.
     *
     * ⚠ WHAT IT DOES NOT ESTABLISH: that no OTHER vendor class can mask an input. The roots are
     * the ones a command reaches with no variable indirection, so a wrapper outside them that
     * marks a question hidden internally is bound (1), stated on the class docblock, not covered
     * here. The derivation closes the gap a NAME LIST has against its OWN roots; it does not
     * close the gap a name list has against all of vendor.
     */
    public function test_every_vendor_hidden_read_entry_point_is_ruled(): void
    {
        $found = self::hiddenReadEntryPoints();

        $this->assertSame(
            array_keys(self::HIDDEN_READ_RULINGS),
            array_keys($found),
            'vendor offers a method that reaches a hidden console read which this census has not ruled, or a ruled one has gone — rule it in HIDDEN_READ_RULINGS with the reading that decided it',
        );

        // ⛔ THE CONTROL, both directions, against the very class the miss was on: the entry
        // point a recalled list missed IS derived, and its non-hidden sibling on that same
        // class is NOT — so a predicate that matched everything, or nothing, reds here.
        $this->assertArrayHasKey(SymfonyStyle::class.'::askHidden', $found);
        $this->assertArrayNotHasKey(SymfonyStyle::class.'::ask', $found);

        foreach (self::HIDDEN_READ_RULINGS as $method => [$spelling, $reading]) {
            $this->assertNotSame('', trim($reading), "{$method} has no reading");
            if ($spelling !== null) {
                $this->assertContains(
                    $spelling,
                    self::HIDDEN_INPUT_METHODS,
                    "{$method} is reachable from app/ as ->{$spelling}(), so HIDDEN_INPUT_METHODS must carry that name or the census cannot see it",
                );
            }
        }
    }

    /**
     * Every method of {@see self::hiddenReadRoots()} whose own source matches
     * {@see self::HIDDEN_READ}, keyed `<declaring class>::<method>`.
     *
     * @return array<string, true>
     */
    private static function hiddenReadEntryPoints(): array
    {
        $found = [];
        foreach (self::hiddenReadRoots() as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $file = $method->getFileName();
                if ($file === false) {
                    continue;
                }
                $source = implode('', array_slice(
                    (array) file($file),
                    $method->getStartLine() - 1,
                    $method->getEndLine() - $method->getStartLine() + 1,
                ));
                if (preg_match(self::HIDDEN_READ, $source) === 1) {
                    $found[$method->getDeclaringClass()->getName().'::'.$method->getName()] = true;
                }
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The classes a command reaches a hidden read through with no variable indirection. The view
     * components are globbed off `Factory`'s OWN directory rather than listed, so a framework
     * upgrade that adds one is scanned without an edit here.
     *
     * @return list<class-string>
     */
    private static function hiddenReadRoots(): array
    {
        $roots = [Command::class, OutputStyle::class, Question::class, QuestionHelper::class];

        $directory = dirname((string) (new ReflectionClass(ComponentFactory::class))->getFileName());
        foreach ((array) glob($directory.'/*.php') as $file) {
            /** @var class-string $component */
            $component = 'Illuminate\Console\View\Components\\'.basename((string) $file, '.php');
            $roots[] = $component;
        }

        return $roots;
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
            if (preg_match(self::HANDLE_LITERAL, $text, $m) === 1) {
                return self::isHandleArgument($tokens, $i) ? null : 'literal: '.$m[0];
            }

            return $type === T_CONSTANT_ENCAPSED_STRING ? self::classLiteralSite($text, $tokens) : null;
        }
        if ($type === T_CLASS && $previous === T_NEW) {
            return self::anonymousClassSite($tokens, $i);
        }
        if ($type === T_STATIC && $previous === T_NEW) {
            $class = self::resolveClass($tokens, $i);

            return $class === null ? null : self::constructionSite('new '.$class, $class, $tokens);
        }
        if (! in_array($type, self::NAME_TOKENS, true)) {
            return null;
        }

        $context = self::context($tokens);
        if (isset($context['prefixes'][$i])) {
            return null;
        }
        if (isset($context['imports'][$i])) {
            $category = self::writerCategory($context['imports'][$i]);

            return $category === null ? null : $category.': use '.$context['imports'][$i];
        }

        $isCall = ($tokens[$i + 1][1] ?? null) === '(';
        if ($isMember) {
            return $isCall ? self::memberSite($tokens, $i) : null;
        }
        if (in_array($previous, [T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO, T_CASE], true)) {
            return null;
        }

        $name = ltrim($text, '\\');
        if (in_array($name, ['STDOUT', 'STDERR'], true) && ! $isCall) {
            return self::isHandleArgument($tokens, $i) ? null : 'const '.$name;
        }
        if ($isCall && $previous !== T_NEW) {
            return self::functionSite($tokens, $i);
        }

        $class = (string) self::resolveClass($tokens, $i);
        if ($previous === T_NEW) {
            return self::constructionSite('new '.$class, $class, $tokens);
        }
        $isStatic = ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON;
        if ($isStatic && ($tokens[$i + 2][0] ?? null) === T_CLASS) {
            return self::constructionSite($class.'::class', $class, $tokens);
        }
        $category = self::writerCategory($class);

        return $category === null ? null : $category.': '.$class.($isStatic ? '::' : '');
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function memberSite(array $tokens, int $i): ?string
    {
        [$previous, $operator] = $tokens[$i - 1];
        $lower = strtolower($tokens[$i][1]);

        if (in_array($lower, self::DUMPER_METHODS, true)) {
            // `VarDumper::dump(` is already a site as a DUMPER class reference.
            $owner = $tokens[$i - 2] ?? null;
            if ($previous === T_DOUBLE_COLON && $owner !== null && in_array($owner[0], self::NAME_TOKENS, true)
                && self::writerCategory((string) self::resolveClass($tokens, $i - 2)) !== null) {
                return null;
            }

            return ($previous === T_DOUBLE_COLON ? '::' : '->').$tokens[$i][1].'()';
        }

        // ⛔ BOTH MEMBER OPERATORS. `?->` is `app/`'s own idiom, and gating on T_OBJECT_OPERATOR
        // alone made every nullsafe hidden-input and process-passthrough call invisible while the
        // dumper arm above already normalised either one (R3 MF2). A STATIC call of the same name
        // stays a near miss: a class-level helper called `secret()` or `tty()` reads no terminal.
        if (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && in_array($lower, [...self::PROCESS_PASSTHROUGH_METHODS, ...self::HIDDEN_INPUT_METHODS], true)) {
            return $operator.$tokens[$i][1].'()';
        }

        return null;
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function functionSite(array $tokens, int $i): ?string
    {
        [$type, $text] = $tokens[$i];
        $context = self::context($tokens);
        $name = ltrim($text, '\\');
        $imported = $type === T_STRING ? ($context['functions'][strtolower($name)] ?? null) : null;

        $candidates = match (true) {
            $imported !== null => [$imported],
            $type === T_NAME_FULLY_QUALIFIED => [$name],
            $type === T_STRING => [ltrim($context['namespace'].'\\'.$name, '\\'), $name],
            default => [(string) self::resolveClass($tokens, $i)],
        };
        foreach ($candidates as $function) {
            $category = self::writerCategory($function);
            if ($category !== null) {
                return $category.': '.$function.'()';
            }
        }

        // Only a name PHP can resolve to the GLOBAL function is one of the language's own.
        $global = end($candidates);
        if (str_contains($global, '\\')) {
            return null;
        }
        $lower = strtolower($global);

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

        return in_array($lower, [...self::LANGUAGE_OUTPUT, ...self::PROCESS_PASSTHROUGH, ...self::DUMPER_FUNCTIONS], true) ? $lower : null;
    }

    /**
     * A class declared in the scanned file that cannot be autoloaded (a planted source) is
     * judged by the class it declares it extends.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function constructionSite(string $what, string $class, array $tokens): ?string
    {
        $category = self::writerCategory($class);
        if ($category !== null) {
            return $category.': '.$what;
        }
        if (! class_exists($class)) {
            foreach (self::context($tokens)['declarations'] as [, $declared, $extends]) {
                if ($extends !== null && strcasecmp($declared, $class) === 0) {
                    $class = $extends;
                }
            }
        }

        return self::isOutputClass($class) ? 'output: '.$what : null;
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function classLiteralSite(string $literal, array $tokens): ?string
    {
        $inner = str_replace('\\\\', '\\', substr($literal, 1, -1));
        if (preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+\z/', $inner) !== 1) {
            return null;
        }
        $class = ltrim($inner, '\\');

        return self::constructionSite('literal '.$class, $class, $tokens);
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function anonymousClassSite(array $tokens, int $i): ?string
    {
        $depth = 0;
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $text = $tokens[$j][1];
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            } elseif ($text === '{' && $depth === 0) {
                return null;
            } elseif ($depth === 0 && in_array($tokens[$j][0], self::NAME_TOKENS, true)
                && in_array($tokens[$j - 1][0], [T_EXTENDS, T_IMPLEMENTS, ','], true)) {
                $class = (string) self::resolveClass($tokens, $j);
                $site = self::constructionSite('new class extends '.$class, $class, $tokens);
                if ($site !== null) {
                    return $site;
                }
            }
        }

        return null;
    }

    private static function writerCategory(string $name): ?string
    {
        $lower = strtolower(ltrim($name, '\\'));
        foreach (self::WRITER_NAMESPACES as $prefix => $category) {
            if (str_starts_with($lower, $prefix)) {
                return $category;
            }
        }

        return null;
    }

    private static function isOutputClass(string $class): bool
    {
        return self::$isOutput[strtolower($class)] ??= (class_exists($class) || interface_exists($class))
            && is_a($class, OutputInterface::class, true);
    }

    /**
     * The fully-qualified class the name token at $i names in its file, `self`/`static`/`parent`
     * included; null for `self`/`static`/`parent` outside any declaration.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function resolveClass(array $tokens, int $i): ?string
    {
        [$type, $text] = $tokens[$i];
        $context = self::context($tokens);
        $lower = strtolower($text);

        if ($type === T_STATIC || in_array($lower, ['self', 'static', 'parent'], true)) {
            $resolved = null;
            foreach ($context['declarations'] as [$at, $class, $extends]) {
                if ($at < $i) {
                    $resolved = $lower === 'parent' ? $extends : $class;
                }
            }

            return $resolved;
        }

        return self::qualify($context, $type, $text);
    }

    /**
     * @param  array{namespace: string, classes: array<string, string>}  $context
     */
    private static function qualify(array $context, int|string $type, string $text): string
    {
        if ($type === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if ($type === T_NAME_RELATIVE) {
            return ltrim($context['namespace'].'\\'.substr($text, strlen('namespace\\')), '\\');
        }
        $segments = explode('\\', $text, 2);
        $imported = $context['classes'][strtolower($segments[0])] ?? null;
        if ($imported !== null) {
            return $imported.(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        return ltrim($context['namespace'].'\\'.$text, '\\');
    }

    /**
     * The file's namespace, its `use` imports (class and function maps, and the token index of
     * every imported name), and its class-like declarations in order. Built once per file: the
     * walk hands the SAME token array to every call, and an identical array compares in O(1).
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return array{namespace: string, classes: array<string, string>, functions: array<string, string>, imports: array<int, string>, prefixes: array<int, true>, declarations: list<array{int, string, ?string}>}
     */
    private static function context(array $tokens): array
    {
        if (self::$contextTokens === $tokens) {
            return self::$context;
        }

        $context = ['namespace' => '', 'classes' => [], 'functions' => [], 'imports' => [], 'prefixes' => [], 'declarations' => []];
        $pendingExtends = [];
        $depth = 0;
        for ($j = 0, $n = count($tokens); $j < $n; $j++) {
            [$type, $text] = $tokens[$j];
            if ($text === '{' || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }
            if ($text === '}') {
                $depth--;

                continue;
            }
            if ($type === T_NAMESPACE && in_array($tokens[$j + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                $context['namespace'] = $tokens[$j + 1][1];

                continue;
            }
            if (in_array($type, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ($tokens[$j + 1][0] ?? null) === T_STRING
                && ! in_array($tokens[$j - 1][0] ?? null, [T_DOUBLE_COLON, T_NEW], true)) {
                $context['declarations'][] = [$j, ltrim($context['namespace'].'\\'.$tokens[$j + 1][1], '\\'), null];
                $pendingExtends[] = ($tokens[$j + 2][0] ?? null) === T_EXTENDS ? $tokens[$j + 3] : null;

                continue;
            }
            if ($type !== T_USE || $depth !== 0 || ($tokens[$j + 1][1] ?? null) === '(') {
                continue;
            }

            $statementKind = ($tokens[$j + 1][0] ?? null) === T_FUNCTION ? 'functions' : (($tokens[$j + 1][0] ?? null) === T_CONST ? 'const' : 'classes');
            $kind = $statementKind;
            $prefix = '';
            for ($k = $j + 1; $k < $n && $tokens[$k][1] !== ';'; $k++) {
                [$itemType, $itemText] = $tokens[$k];
                if ($itemType === T_FUNCTION) {
                    $kind = 'functions';
                } elseif ($itemType === T_CONST) {
                    $kind = 'const';
                } elseif ($itemText === ',' || $itemText === '}') {
                    $kind = $statementKind;
                } elseif (in_array($itemType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$k - 1][0] ?? null) !== T_AS) {
                    if (($tokens[$k + 1][0] ?? null) === T_NS_SEPARATOR) {
                        $prefix = ltrim($itemText, '\\').'\\';
                        $context['prefixes'][$k] = true;

                        continue;
                    }
                    $imported = $prefix.ltrim($itemText, '\\');
                    $alias = ($tokens[$k + 1][0] ?? null) === T_AS ? $tokens[$k + 2][1] : substr((string) strrchr('\\'.$imported, '\\'), 1);
                    $context['imports'][$k] = $imported;
                    if ($kind !== 'const') {
                        $context[$kind][strtolower($alias)] = $imported;
                    }
                }
            }
            $j = $k;
        }

        foreach ($pendingExtends as $d => $extends) {
            if ($extends !== null) {
                $context['declarations'][$d][2] = self::qualify($context, $extends[0], $extends[1]);
            }
        }

        self::$contextTokens = $tokens;

        return self::$context = $context;
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
