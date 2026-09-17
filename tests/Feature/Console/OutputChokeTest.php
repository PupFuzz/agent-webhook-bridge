<?php

namespace Tests\Feature\Console;

use App\Bridge\Console\StrippingConsoleKernel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Console\ChokeFixtureCommand;
use Tests\Support\SplitConsoleOutput;
use Tests\TestCase;

/**
 * ⛔ LEG E OF card#9251 (DL-393): no byte of the stripped class reaches fd 1 or fd 2 of a real
 * `php artisan` process, whatever API wrote it.
 *
 * ⭐ WHY A SUBPROCESS. `Artisan::call()` and `$this->artisan()` enter the console kernel through
 * `Kernel::call()`, never `Kernel::handle()`, and the uncaught-exception render and the real
 * `ConsoleOutput` exist only on the `handle()` path. An in-process test of the choke therefore
 * measures a different entry from the one an operator's terminal is on. Every stream assertion
 * here is on the RAW bytes a child `artisan` wrote to its own stdout and stderr pipes. The
 * in-process arms at the bottom cover the `call()` entry separately, because it is a second
 * entry with its own wrap.
 *
 * ⚠ A PIPE IS NOT A TERMINAL: `StreamOutput` auto-detects no colour on a pipe, so a
 * subprocess run is undecorated with or without the choke. The decoration pin is therefore
 * measured by the two arms that force colour ON (`--ansi`, and a command calling
 * `setDecorated(true)` itself), which are decorated on a pipe too.
 *
 * Every assertion is paired with a PRESENCE witness (`evil`, `hidden`), because an output
 * that dropped the payload entirely would pass the absence half.
 */
class OutputChokeTest extends TestCase
{
    /**
     * One member of every stripped class, plus formatter tags a foreign string could inject
     * (conceal, black-on-black, a hyperlink whose text is not its target). NUL is here because
     * a file carries it; argv cannot, so the argv arms below leave it out.
     */
    private const PAYLOAD = "X\x00\e[2K\r evil \u{009B}31m c1 \u{202E}bidi \u{200B}zw\x07\x7F tab\tend \xC2 "
        .'<options=conceal>hidden</> <fg=black;bg=black>dark</> <href=https://evil.example/x>https://good.example/</>';

    private const ARGV_PAYLOAD = "X\e[2K\revil\u{009B}31m\u{202E}bidi\u{200B}\x07";

    private string $payloadFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payloadFile = tempnam(sys_get_temp_dir(), 'choke-payload-');
        file_put_contents($this->payloadFile, self::PAYLOAD);
    }

    protected function tearDown(): void
    {
        @unlink($this->payloadFile);

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function writeApis(): array
    {
        return [
            'line / info / error / warn / OUTPUT_RAW / OUTPUT_PLAIN' => ['line', 'stdout'],
            'table()' => ['table', 'stdout'],
            'a components-> block' => ['components', 'stdout'],
            'section() — writes to the raw stream underneath' => ['section', 'stdout'],
            'getErrorOutput()' => ['stderr', 'stderr'],
            'setErrorOutput() with an unwrapped stream, then getErrorOutput()' => ['set-error-output', 'stderr'],
        ];
    }

    #[DataProvider('writeApis')]
    public function test_every_console_write_api_reaches_the_real_streams_stripped(string $arm, string $witness): void
    {
        [$exit, $stdout, $stderr] = $this->runArtisan(['bridge:choke-fixture', $arm, '--payload-file='.$this->payloadFile], fixture: true);

        $this->assertSame(0, $exit, "[{$arm}] stderr: ".bin2hex(substr($stderr, 0, 300)));
        $this->assertNoStrippedByte($stdout, "{$arm} stdout");
        $this->assertNoStrippedByte($stderr, "{$arm} stderr");
        $this->assertStringContainsString('evil', $witness === 'stdout' ? $stdout : $stderr, "[{$arm}] the payload did not reach {$witness} at all");
    }

    /**
     * Laravel's Artisan runs with `setCatchExceptions(false)`, so an exception escaping
     * `handle()` is rendered by `Kernel::handle()`'s own catch through
     * `Illuminate\Foundation\Exceptions\Handler::renderForConsole()` — onto STDOUT, not
     * stderr. Asserting stderr here would pass whether or not the render was stripped.
     */
    public function test_an_uncaught_exception_is_rendered_to_stdout_stripped(): void
    {
        [$exit, $stdout, $stderr] = $this->runArtisan(['bridge:choke-fixture', 'exception', '--payload-file='.$this->payloadFile], fixture: true);

        $this->assertSame(1, $exit);
        $this->assertNoStrippedByte($stdout, 'exception stdout');
        $this->assertNoStrippedByte($stderr, 'exception stderr');
        $this->assertStringContainsString('evil', $stdout);
        $this->assertStringContainsString('RuntimeException', $stdout);
    }

    /** A production command's own diagnostic, written to its error stream. */
    public function test_a_real_bridge_command_diagnostic_on_stderr_is_stripped(): void
    {
        [$exit, $stdout, $stderr] = $this->runArtisan([
            'bridge:sign', '--provider=github', '--scope=acme-corp/widget', '--body-file=/nonexistent/'.self::ARGV_PAYLOAD,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertNoStrippedByte($stderr, 'bridge:sign stderr');
        $this->assertStringContainsString('no file at /nonexistent/X', $stderr);
        $this->assertStringContainsString('evil', $stderr);
    }

    /** A framework exception thrown while binding a production command's input. */
    public function test_a_real_bridge_command_input_error_is_rendered_stripped(): void
    {
        [$exit, $stdout, $stderr] = $this->runArtisan(['bridge:sign', '-n', '--nope'.self::ARGV_PAYLOAD]);

        $this->assertSame(1, $exit);
        $this->assertNoStrippedByte($stdout, 'bad option stdout');
        $this->assertNoStrippedByte($stderr, 'bad option stderr');
        $this->assertStringContainsString('evil', $stdout);
    }

    /** The kernel's command-not-found render, which goes through a Termwind components block. */
    public function test_an_unknown_command_name_is_rendered_stripped(): void
    {
        [$exit, $stdout, $stderr] = $this->runArtisan(['-n', 'bridge:'.self::ARGV_PAYLOAD]);

        $this->assertSame(1, $exit);
        $this->assertNoStrippedByte($stdout, 'unknown command stdout');
        $this->assertNoStrippedByte($stderr, 'unknown command stderr');
        $this->assertStringContainsString('evil', $stdout);
    }

    /** ⛔ `--ansi` no longer colours `bridge:*` output. Byte-exact: no SGR and no `[32m` litter. */
    public function test_the_ansi_flag_does_not_turn_colour_back_on(): void
    {
        [$exit, $stdout] = $this->runArtisan(['bridge:choke-fixture', 'own-style', '--ansi', '--payload-file='.$this->payloadFile], fixture: true);

        $this->assertSame(0, $exit);
        $this->assertSame(bin2hex("OK\nBAD\n"), bin2hex($stdout));
    }

    /** A command that asks its own output for colour — `setDecorated(true)`, or a decorated formatter — gets none. */
    public function test_a_command_forcing_decoration_on_gets_none(): void
    {
        [$exit, $stdout] = $this->runArtisan(['bridge:choke-fixture', 'force-decoration', '--payload-file='.$this->payloadFile], fixture: true);

        $this->assertSame(0, $exit);
        $this->assertSame(bin2hex("OK\nBAD\n"), bin2hex($stdout));
    }

    /**
     * The WIRING, asserted in the container `bootstrap/app.php` builds — the reason the choke
     * is registered there and not in a service provider is that a provider re-bind lands after
     * `handleCommand()` has already resolved the kernel, and is inert.
     */
    public function test_the_console_kernel_the_application_resolves_is_the_choke(): void
    {
        $this->assertInstanceOf(StrippingConsoleKernel::class, $this->app->make(Kernel::class));
    }

    /** The `Kernel::call()` entry: a buffer the kernel builds, still readable through `Artisan::output()`. */
    public function test_artisan_call_output_is_stripped_and_still_readable(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new ChokeFixtureCommand);

        $exit = Artisan::call('bridge:choke-fixture', ['arm' => 'line', '--payload-file' => $this->payloadFile]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertNoStrippedByte($output, 'Artisan::output()');
        $this->assertStringContainsString('evil', $output);
        $this->assertStringContainsString('hidden', $output);
    }

    /** The `Kernel::call()` entry with a caller-built console buffer: both of its streams are wrapped. */
    public function test_a_caller_built_console_buffer_is_wrapped_on_both_streams(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new ChokeFixtureCommand);
        $buffer = new SplitConsoleOutput;

        $exit = Artisan::call('bridge:choke-fixture', ['arm' => 'stderr', '--payload-file' => $this->payloadFile], $buffer);
        $errors = $buffer->errors->fetch();

        $this->assertSame(0, $exit);
        $this->assertNoStrippedByte($errors, 'caller buffer error stream');
        $this->assertStringContainsString('evil', $errors);
    }

    /**
     * ⚠ IT NAMES THE OFFENDING CODEPOINT, never prints it: a failure message carrying the
     * erase-line would mangle the terminal reading the failure.
     */
    private function assertNoStrippedByte(string $bytes, string $where): void
    {
        $this->assertTrue(
            mb_check_encoding($bytes, 'UTF-8'),
            "[{$where}] invalid UTF-8 reached the stream (a lone byte, e.g. a split C1): ".bin2hex(substr($bytes, 0, 400)),
        );

        $hits = [];
        if (preg_match_all('/[\x00-\x08\x0B-\x1F\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u', $bytes, $m) > 0) {
            foreach ($m[0] as $char) {
                $hits[] = sprintf('U+%04X', (int) mb_ord($char, 'UTF-8'));
            }
        }

        $this->assertSame([], $hits, "[{$where}] stripped-class codepoints reached the stream: ".implode(' ', $hits));
    }

    /**
     * @param  list<string>  $args
     * @return array{int, string, string} [exit code, stdout, stderr]
     */
    private function runArtisan(array $args, bool $fixture = false): array
    {
        $command = [PHP_BINARY];
        if ($fixture) {
            array_push($command, '-d', 'auto_prepend_file='.base_path('tests/Fixtures/Console/register-choke-fixture.php'));
        }

        // LOG_CHANNEL is pinned because a checkout's `.env` may select the `stderr` channel,
        // which writes a reported exception to fd 2 around the choke — a named bound of
        // DL-393, not something this test measures.
        $process = proc_open(
            array_merge($command, [base_path('artisan')], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'LOG_CHANNEL' => 'null'],
        );
        $this->assertIsResource($process, 'could not start the artisan subprocess');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
