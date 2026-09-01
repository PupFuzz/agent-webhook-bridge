<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\WebhookSecretFailure;
use App\Bridge\Support\WebhookSecretResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The one place the on-disk HMAC secret's LOCATION and its byte NORMALIZATION are
 * decided, now that a producer of signatures (`bridge:sign`) reads it as well as the
 * receiver. The trailing-newline case is the one this class exists for: the receiver has
 * always trimmed, so a producer that does not makes every delivery `401 sig_mismatch` —
 * a failure that reads as a receiver bug (card#8336 / DL-322).
 */
class WebhookSecretResolverTest extends TestCase
{
    private string $secretDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretDir = sys_get_temp_dir().'/bridge-secret-resolver-'.uniqid();
        File::ensureDirectoryExists($this->secretDir.'/github');
        config(['bridge.secret_dir' => $this->secretDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->secretDir);
        parent::tearDown();
    }

    private function writeSecret(string $contents, string $scopeFile = 'webhook-secret-scope-acme%2Fwidget'): string
    {
        $path = $this->secretDir.'/github/'.$scopeFile;
        File::put($path, $contents);
        chmod($path, 0o600);

        return $path;
    }

    public function test_a_trailing_newline_is_trimmed_off_the_secret(): void
    {
        // The exact shape a `printf '%s\n' … > secret` or an editor leaves behind.
        $this->writeSecret("s3cr3t-fixture\n");

        $this->assertSame('s3cr3t-fixture', WebhookSecretResolver::resolve('github', 'acme/widget'));
    }

    public function test_surrounding_whitespace_of_every_kind_php_trims_is_trimmed(): void
    {
        // Pinned as the CONTRACT, not as an accident of using trim(): a producer that
        // strips only "\n" would disagree with the receiver on any of the others.
        $this->writeSecret(" \t\r\ns3cr3t-fixture\r\n \t");

        $this->assertSame('s3cr3t-fixture', WebhookSecretResolver::resolve('github', 'acme/widget'));
    }

    public function test_interior_whitespace_is_kept(): void
    {
        $this->writeSecret("two words\n");

        $this->assertSame('two words', WebhookSecretResolver::resolve('github', 'acme/widget'));
    }

    public function test_a_scope_containing_a_slash_resolves_the_url_encoded_filename(): void
    {
        // The runbook's hand-written `webhook-secret-scope-${SCOPE}` could not resolve at
        // all for GitHub's org/repo — the `/` read as a directory separator.
        $this->writeSecret("s3cr3t-fixture\n");

        $this->assertSame(
            $this->secretDir.'/github/webhook-secret-scope-acme%2Fwidget',
            WebhookSecretResolver::pathFor('github', 'acme/widget')
        );
        $this->assertSame('s3cr3t-fixture', WebhookSecretResolver::resolve('github', 'acme/widget'));
    }

    public function test_an_absent_secret_is_unknown_scope(): void
    {
        $this->assertSame(
            WebhookSecretFailure::UnknownScope,
            WebhookSecretResolver::resolve('github', 'acme/widget')
        );
    }

    public function test_a_group_or_world_readable_secret_is_insecure_not_a_secret(): void
    {
        $path = $this->writeSecret("s3cr3t-fixture\n");
        chmod($path, 0o644);

        $this->assertSame(
            WebhookSecretFailure::SecretPermsInsecure,
            WebhookSecretResolver::resolve('github', 'acme/widget')
        );
    }

    public function test_a_blank_secret_is_empty_not_absent(): void
    {
        // The distinction the shared TokenFile trim cannot express, and the reason this
        // resolver owns its own: blank is a broken install (500), absent is an unknown
        // subscriber (401).
        $this->writeSecret("   \n");

        $this->assertSame(
            WebhookSecretFailure::EmptySecretFile,
            WebhookSecretResolver::resolve('github', 'acme/widget')
        );
    }

    public function test_a_present_but_unreadable_secret_is_not_reported_as_absent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a 0000 file, so the unreadable state cannot be produced here');
        }

        $path = $this->writeSecret("s3cr3t-fixture\n");
        chmod($path, 0o000);

        try {
            $this->assertSame(
                WebhookSecretFailure::SecretUnreadable,
                WebhookSecretResolver::resolve('github', 'acme/widget')
            );
        } finally {
            chmod($path, 0o600);
        }
    }

    public function test_an_unset_secret_dir_is_a_config_failure_with_no_path_to_name(): void
    {
        config(['bridge.secret_dir' => null]);

        $this->assertSame(
            WebhookSecretFailure::ConfigSecretDirMissing,
            WebhookSecretResolver::resolve('github', 'acme/widget')
        );
        $this->assertNull(WebhookSecretResolver::pathFor('github', 'acme/widget'));
    }

    public function test_a_relative_secret_dir_is_a_config_failure_with_no_path_to_name(): void
    {
        config(['bridge.secret_dir' => 'relative/dir']);

        $this->assertSame(
            WebhookSecretFailure::ConfigSecretDirNotAbsolute,
            WebhookSecretResolver::resolve('github', 'acme/widget')
        );
        $this->assertNull(WebhookSecretResolver::pathFor('github', 'acme/widget'));
    }
}
