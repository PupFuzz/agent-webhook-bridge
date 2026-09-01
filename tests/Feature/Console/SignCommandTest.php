<?php

namespace Tests\Feature\Console;

use App\Bridge\Adapters\AbstractWebhookAdapter;
use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `bridge:sign` exists so the deployment runbook can smoke-test the live receiver
 * without resolving the HMAC secret into a command line, where any local user reads it
 * from `/proc/<pid>/cmdline` (card#8336 / DL-322).
 *
 * ⛔ THE LOAD-BEARING TEST IS THE ROUND TRIP: a signature this command produces is
 * ACCEPTED by the real receiver, over a secret file with a trailing newline. Asserting
 * the command's output against a hand-written `hash_hmac(...)` in the test would assert
 * the test's own copy of the rule, which is the drift the command exists to remove.
 */
class SignCommandTest extends TestCase
{
    // The github ping path stores nothing, but the receiver stack it runs through can
    // write, and a missing isolation trait is invisible on SQLite (see CLAUDE_TESTING.md).
    use RefreshDatabase;

    private const SECRET = 'smoke-test-fixture-secret';   // gitleaks:allow — fixture, never a live key

    private const SCOPE = 'acme-corp/widget';

    private string $secretDir;

    private string $bodyFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretDir = sys_get_temp_dir().'/bridge-sign-'.uniqid();
        File::ensureDirectoryExists($this->secretDir.'/github');
        $this->bodyFile = $this->secretDir.'/body.json';

        config(['bridge.secret_dir' => $this->secretDir, 'bridge.config_dir' => $this->secretDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->secretDir);
        parent::tearDown();
    }

    private function secretPath(): string
    {
        return $this->secretDir.'/github/webhook-secret-scope-acme-corp%2Fwidget';
    }

    /**
     * Written the way an operator's editor or a `printf '%s\n'` leaves it: WITH the
     * trailing newline that is the whole newline-handling question.
     */
    private function writeSecret(string $contents = self::SECRET."\n"): void
    {
        File::put($this->secretPath(), $contents);
        chmod($this->secretPath(), 0o600);
    }

    private function body(): string
    {
        return (string) json_encode(['zen' => 'Design for failure.']);
    }

    /**
     * Run the command and return [exit code, everything it printed].
     */
    private function sign(array $options = []): array
    {
        File::put($this->bodyFile, $this->body());

        $code = Artisan::call('bridge:sign', array_merge([
            '--provider' => 'github',
            '--scope' => self::SCOPE,
            '--body-file' => $this->bodyFile,
        ], $options));

        return [$code, Artisan::output()];
    }

    private function postPing(string $signatureHeader)
    {
        $body = $this->body();

        return $this->call('POST', '/webhooks/github?b='.self::SCOPE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signatureHeader,
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_GITHUB_DELIVERY' => 'sign-command-'.uniqid(),
        ], $body);
    }

    public function test_the_signature_it_prints_is_accepted_by_the_real_receiver(): void
    {
        $this->writeSecret();

        [$code, $output] = $this->sign();
        $this->assertSame(0, $code, $output);

        $this->postPing(trim($output))->assertStatus(200)->assertSee('pong');
    }

    public function test_the_trailing_newline_is_the_difference_between_accepted_and_401(): void
    {
        // ⛔ THE CONTROL for the test above: without it, a receiver that accepted
        // everything would score as agreement. Signing the file's bytes RAW — the one
        // plausible way a hand-rolled signer gets it wrong — is refused, so the passing
        // case above is evidence that both ends normalize the secret the same way.
        $this->writeSecret();

        $rawFileSignature = HmacSignature::headerValue($this->body(), self::SECRET."\n");

        $this->postPing($rawFileSignature)->assertStatus(401)->assertSee('sig_mismatch');
    }

    public function test_it_reads_the_body_from_stdin_and_puts_the_signature_alone_on_stdout(): void
    {
        // The runbook pipes the body in, and captures stdout with `$(…)`. Both halves of
        // that contract — the pipe, and stdout carrying NOTHING but the signature — only
        // exist in a real process; in-process the command shares one buffered stream.
        $this->writeSecret();

        [$code, $stdout, $stderr] = $this->runInSubprocess(
            ['--provider=github', '--scope='.self::SCOPE],
            $this->body()
        );

        $this->assertSame(0, $code, $stderr);
        $this->assertSame('', $stderr);
        $this->assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}\n$/', $stdout);
        $this->postPing(trim($stdout))->assertStatus(200)->assertSee('pong');
    }

    public function test_a_diagnostic_goes_to_stderr_and_never_to_the_captured_stdout(): void
    {
        // No secret file: the operator must SEE why, and `$(…)` must capture nothing that
        // could be substituted into a signature header.
        [$code, $stdout, $stderr] = $this->runInSubprocess(
            ['--provider=github', '--scope='.self::SCOPE],
            $this->body()
        );

        $this->assertSame(1, $code);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('401 unknown_scope', $stderr);
        $this->assertStringContainsString($this->secretPath(), $stderr);
    }

    public function test_every_registered_provider_uses_the_convention_this_command_produces(): void
    {
        // ⛔ THE COMMAND'S PRECONDITION, asserted over the POPULATION it is about rather
        // than as a runtime branch that could not fire. `bridge:sign` prints a
        // `sha256=<hex>` value for whatever `--provider` names; an adapter that verified
        // some other scheme would make it print a confidently wrong signature, and the
        // 401 would be debugged at the receiver. If you are reading this because you
        // added an adapter: give it its own signing path before adding it here.
        // A foreach over an empty list asserts nothing at all.
        $this->assertNotEmpty(WebhookAdapterFactory::SUPPORTED);

        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            $this->assertInstanceOf(
                AbstractWebhookAdapter::class,
                WebhookAdapterFactory::for($provider),
                "provider '{$provider}' does not inherit the sha256=<hex> verification bridge:sign produces"
            );
        }
    }

    public function test_quiet_does_not_suppress_the_one_thing_the_command_exists_to_print(): void
    {
        // `-q` on a command whose ENTIRE output is its product would leave $SIG empty and
        // the failure to be discovered at the receiver as a 401.
        $this->writeSecret();

        [$code, $stdout, $stderr] = $this->runInSubprocess(
            ['--provider=github', '--scope='.self::SCOPE, '-q'],
            $this->body()
        );

        $this->assertSame(0, $code, $stderr);
        $this->assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}\n$/', $stdout);

        // …and the same for the diagnostic, which is the only thing a failing run has.
        File::delete($this->secretPath());
        [$failCode, $failStdout, $failStderr] = $this->runInSubprocess(
            ['--provider=github', '--scope='.self::SCOPE, '-q'],
            $this->body()
        );

        $this->assertSame(1, $failCode);
        $this->assertSame('', $failStdout);
        $this->assertStringContainsString('401 unknown_scope', $failStderr);
    }

    public function test_it_never_prints_the_secret_it_read(): void
    {
        $this->writeSecret();

        [$code, $output] = $this->sign();

        // POSITIVE CONTROL: without it, an absence over an empty buffer would certify a
        // command that printed nothing at all as discreet.
        $this->assertSame(0, $code);
        $this->assertStringContainsString('sha256=', $output);
        $this->assertStringNotContainsString(self::SECRET, $output);
    }

    public function test_an_unknown_scope_names_the_path_and_the_status_the_receiver_would_give(): void
    {
        [$code, $output] = $this->sign();

        $this->assertSame(1, $code);
        $this->assertStringContainsString($this->secretPath(), $output);
        $this->assertStringContainsString('401 unknown_scope', $output);
    }

    public function test_a_group_readable_secret_refuses_instead_of_signing_with_it(): void
    {
        $this->writeSecret();
        chmod($this->secretPath(), 0o644);

        [$code, $output] = $this->sign();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('chmod 600', $output);
        $this->assertStringNotContainsString('sha256=', $output);
    }

    public function test_an_unsupported_provider_is_refused(): void
    {
        [$code, $output] = $this->sign(['--provider' => 'bitbucket']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--provider must be one of', $output);
    }

    public function test_a_scope_that_could_never_reach_the_receiver_is_refused(): void
    {
        // The same validator the receiver applies to `?b=` — and the path-traversal
        // boundary, since the scope becomes part of the secret's filename.
        [$code, $output] = $this->sign(['--scope' => '../etc']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--scope is required', $output);
    }

    public function test_an_empty_body_is_refused_rather_than_signed(): void
    {
        $this->writeSecret();
        File::put($this->bodyFile, '');

        $code = Artisan::call('bridge:sign', [
            '--provider' => 'github',
            '--scope' => self::SCOPE,
            '--body-file' => $this->bodyFile,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is empty', Artisan::output());
    }

    /**
     * @param  list<string>  $args
     * @return array{int, string, string} [exit code, stdout, stderr]
     */
    private function runInSubprocess(array $args, string $stdin): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, base_path('artisan'), 'bridge:sign'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            ['PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), 'BRIDGE_SECRET_DIR' => $this->secretDir]
        );
        $this->assertIsResource($process, 'could not start the artisan subprocess');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
