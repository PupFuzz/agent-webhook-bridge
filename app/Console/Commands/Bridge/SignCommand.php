<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\HmacSignature;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\WebhookSecretFailure;
use App\Bridge\Support\WebhookSecretResolver;
use App\Bridge\Validation\ProviderName;
use App\Bridge\Validation\ScopeId;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sign a raw webhook body with the per-(provider, scope) HMAC secret and print the
 * signature header value — WITHOUT the secret ever becoming a command-line argument.
 *
 * ⛔ THE ARGV RULE IS THE POINT OF THIS COMMAND (card#8336 / DL-322). The deployment
 * runbook's signed-delivery smoke test used to say
 * `openssl dgst -sha256 -hmac "$SECRET"`, which puts the resolved secret in openssl's
 * argv, where — on a host without `hidepid`, measured — ANY local user reads it out of
 * `/proc/<pid>/cmdline` for the lifetime of the process. The 0600 on the secret file
 * exists to prevent exactly that, so the runbook was routing around its own protection,
 * and a correct refusal to run it left post-update deploys with no real-surface receiver
 * check at all. This command takes the (provider, scope) KEY as arguments and resolves
 * the secret itself; the value reaches nothing but this process's memory.
 *
 * ⛔ IT ALSO OWNS THE HANDLING RULES THAT USED TO LIVE IN PROSE, and each one is a way
 * the smoke test silently produced a 401 that read as a receiver bug:
 *   - the secret's bytes are normalized by {@see WebhookSecretResolver} — the same code
 *     the receiver verifies with, so a trailing newline cannot make them disagree;
 *   - the `sha256=` prefix and the digest come from {@see HmacSignature}, shared with
 *     the verifier;
 *   - the on-disk filename is `SecretPath`'s, so a scope containing `/` (GitHub's
 *     `org/repo`) is `%2F`-encoded rather than read as a directory separator — the
 *     hand-written `<secret_dir>/github/webhook-secret-scope-${SCOPE}` in the old
 *     runbook could not resolve at all for the provider it was written for.
 *
 * STDOUT CARRIES THE SIGNATURE AND NOTHING ELSE (the caller captures it in `$(…)`);
 * every diagnostic goes to stderr, so a failure is visible to the operator instead of
 * being substituted into a header. Both are written at QUIET verbosity, so `-q` cannot
 * turn this into a command that succeeds and prints nothing — an empty `$SIG` reaches
 * the receiver as a 401 and gets debugged there.
 *
 * ⛔ THE INVARIANT THIS COMMAND RESTS ON: every provider in `WebhookAdapterFactory::SUPPORTED`
 * verifies the `sha256=<hex>` convention. That is a property of a POPULATION, so it is
 * asserted over that population in `SignCommandTest` rather than re-checked per run here —
 * a runtime branch for it could not fire today and so could never be seen to fail. An
 * adapter with another scheme reds that test, which is where the author will meet it.
 */
class SignCommand extends BridgeCommand
{
    protected $signature = 'bridge:sign
        {--provider=github : the webhook provider whose secret signs this body}
        {--scope= : the (provider, scope) key — the SAME value the receiver reads from ?b=}
        {--body-file= : read the raw body from this file instead of stdin}';

    protected $description = 'Print the HMAC signature header value for a raw webhook body, reading the secret from its file (never from argv)';

    public function handle(): int
    {
        $provider = $this->strOption('provider') ?? '';
        if (! ProviderName::matches($provider) || ! WebhookAdapterFactory::supports($provider)) {
            return $this->refuse('--provider must be one of: '.implode(', ', WebhookAdapterFactory::SUPPORTED));
        }

        $scope = $this->strOption('scope');
        if ($scope === null || ! ScopeId::matches($scope)) {
            return $this->refuse('--scope is required, and must be the same scope id the receiver reads from ?b= (for GitHub, repository.full_name)');
        }

        $body = $this->body();
        if ($body === null) {
            return self::FAILURE;
        }

        $secret = WebhookSecretResolver::resolve($provider, $scope);
        if ($secret instanceof WebhookSecretFailure) {
            return $this->refuse($this->explain($secret, $provider, $scope));
        }

        $this->line(HmacSignature::headerValue($body, $secret), null, OutputInterface::VERBOSITY_QUIET);

        return self::SUCCESS;
    }

    /**
     * The raw body to sign, or null once the reason it could not be read has been
     * reported. The body is NOT a secret — it is read from a file or a pipe only because
     * a webhook body is JSON with quotes and newlines in it, and because the caller
     * already has it in a shell variable.
     */
    private function body(): ?string
    {
        $path = $this->strOption('body-file');
        if ($path !== null) {
            try {
                $raw = FileContents::read($path, 'body file');
            } catch (UnreadableFileException $e) {
                $this->refuse($e->getMessage());

                return null;
            }
            if ($raw === null) {
                $this->refuse("--body-file: no file at {$path}");

                return null;
            }

            return $this->nonEmpty($raw, "the body file at {$path} is empty");
        }

        // The raw constants, not the `ToolsCallStdio` seam: that exists so a test can
        // capture the ssh front door's fd 1 WITHOUT the real one, and here the real
        // process's stream split is precisely what is under test (SignCommandTest drives
        // an actual subprocess for it), so a seam would test the seam.
        //
        // A terminal on stdin means nothing was piped: read it and the command hangs with
        // no output, which is indistinguishable from a slow one.
        if (stream_isatty(STDIN)) {
            $this->refuse("no body to sign — pipe the raw body on stdin (printf '%s' \"\$BODY\" | php artisan bridge:sign …) or pass --body-file");

            return null;
        }

        $raw = stream_get_contents(STDIN);
        if ($raw === false) {
            $this->refuse('stdin could not be read');

            return null;
        }

        return $this->nonEmpty($raw, 'nothing arrived on stdin — an empty body signs to a value the receiver will reject as sig_mismatch');
    }

    private function nonEmpty(string $raw, string $message): ?string
    {
        if ($raw === '') {
            $this->refuse($message);

            return null;
        }

        return $raw;
    }

    /**
     * Name the failure AND the receiver status it would produce, so the operator does not
     * take a resolution fault here for a receiver fault there.
     */
    private function explain(WebhookSecretFailure $failure, string $provider, string $scope): string
    {
        // Non-null in every arm that reads it: pathFor() answers null only for the two
        // config cases, which name no path.
        $path = (string) WebhookSecretResolver::pathFor($provider, $scope);

        return match ($failure) {
            WebhookSecretFailure::ConfigSecretDirMissing => 'bridge.secret_dir is not set (BRIDGE_SECRET_DIR, or BRIDGE_DIR) — the receiver answers 500 config_secret_dir_missing in this state',
            WebhookSecretFailure::ConfigSecretDirNotAbsolute => 'bridge.secret_dir is not an absolute path — the receiver answers 500 config_secret_dir_not_absolute in this state',
            WebhookSecretFailure::SecretPermsInsecure => SecretFile::permsMessage($path).'. The receiver answers 500 secret_perms_insecure until that is fixed',
            WebhookSecretFailure::UnknownScope => "no secret for {$provider}:{$scope} at {$path} — the receiver answers 401 unknown_scope for this scope (a scope containing / is %2F-encoded in the filename; `bridge:provision` writes the file for API-provisioned providers)",
            WebhookSecretFailure::SecretUnreadable => "the secret for {$provider}:{$scope} at {$path} exists but THIS process cannot read it — ownership and mode are relative to the asking user, so run this as the install's OS user (the receiver, reading it as its own user, may be fine)",
            WebhookSecretFailure::EmptySecretFile => "the secret file at {$path} is blank — the receiver answers 500 empty_secret_file",
        };
    }

    private function refuse(string $message): int
    {
        $this->errorStream()->writeln("bridge:sign: {$message}", OutputInterface::VERBOSITY_QUIET);

        return self::FAILURE;
    }

    /**
     * ⛔ NOT `$this->error()`: that writes to STDOUT, which here is the machine-readable
     * channel a caller captures with `$(…)`. A diagnostic printed there is substituted
     * into the signature header instead of being seen. Under a buffered/test output there
     * is no separate error stream, so it falls back to the one output there is.
     */
    private function errorStream(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $this->output;
    }
}
