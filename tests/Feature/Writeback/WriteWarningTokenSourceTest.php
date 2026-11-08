<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordCredentialStoreFixture;
use Tests\TestCase;

/**
 * card#12968: every warning a GitHub write leaves when it does not land names the token SOURCE the
 * resolver chose (`token_source`), so an operator fixes the token that was used — and names a store
 * key by its display name, never the file it points at or the token.
 *
 * One arm per log site of the two writers. Each repo here is mapped in a synthetic store to
 * {@see self::KEY}, so the asserted label is the store's — the incident shape, a repo mapped to a
 * read-only credential-store key.
 */
class WriteWarningTokenSourceTest extends TestCase
{
    private const REPO = 'acme/widget';

    private const KEY = 'readonly_key';

    private const TOKEN = 'ghp_synthetic_store_token_value';

    private string $dir;

    private CoordCredentialStoreFixture $store;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/write-warning-source-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.token_path' => null,
            'bridge.protocol_invalid_label.repos' => [self::REPO],
        ]);
        $this->store = (new CoordCredentialStoreFixture($this->dir.'/coord'))->use();
        $this->tokenPath = $this->store->tokenFile('readonly-token', self::TOKEN);
        $this->store->write(['github.com/acme' => self::KEY], [self::KEY.'_file' => $this->tokenPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: callable(): mixed}>
     */
    public static function commentArms(): array
    {
        $marker = PrCorrelationComment::markerFor('merged');

        return [
            'comments read refused' => ['pr_correlation_comment.comments_read_http_error', fn () => Http::response(['message' => 'Resource not accessible by personal access token'], 403)],
            'comments read failed' => ['pr_correlation_comment.comments_read_failed', fn () => Http::failedConnection()],
            'comments read incomplete' => ['pr_correlation_comment.comments_read_incomplete', fn () => Http::response(['not' => 'a list'])],
            'post refused' => ['pr_correlation_comment.post_http_error', fn () => [Http::response([]), Http::response(['message' => 'Resource not accessible by personal access token'], 403)]],
            'post failed' => ['pr_correlation_comment.post_failed', fn () => [Http::response([]), Http::failedConnection()]],
            'post unconfirmed' => ['pr_correlation_comment.post_unconfirmed', fn () => [Http::response([]), Http::response(['body' => 'not '.$marker], 201)]],
        ];
    }

    /** @param  callable(): mixed  $answers  the GET answer, or [GET answer, POST answer] */
    #[DataProvider('commentArms')]
    public function test_every_comment_warning_names_the_store_key_that_answered(string $catalogId, callable $answers): void
    {
        $this->fakeGitHub($answers());
        Log::spy();

        (new PrCorrelationCommenter(GitHubTokenResolver::forWriteback(null)))->repost(self::REPO, 7, 'merged', PrCorrelationComment::markerFor('merged')."\nbody");

        $this->assertWarnedWithSource($catalogId);
    }

    /**
     * @return array<string, array{0: string, 1: callable(): mixed}>
     */
    public static function labelArms(): array
    {
        return [
            'add refused' => ['protocol_invalid_label.add_http_error', fn () => Http::response(['message' => 'Resource not accessible by personal access token'], 403)],
            'add failed' => ['protocol_invalid_label.add_failed', fn () => Http::failedConnection()],
            'add unconfirmed' => ['protocol_invalid_label.add_unconfirmed', fn () => Http::response([['name' => 'something-else']])],
        ];
    }

    /** @param  callable(): mixed  $answer */
    #[DataProvider('labelArms')]
    public function test_every_label_warning_names_the_store_key_that_answered(string $catalogId, callable $answer): void
    {
        Http::fake(['https://api.github.com/*' => $answer()]);
        Log::spy();

        (new ProtocolInvalidLabeler(GitHubTokenResolver::forWriteback(null)))->apply(['repo' => self::REPO, 'number' => 7, 'comment_id' => 1]);

        $this->assertWarnedWithSource($catalogId);
    }

    public function test_a_store_key_with_no_usable_file_names_the_key_on_both_no_token_warnings(): void
    {
        $this->store->write(['github.com/acme' => self::KEY], []);
        Http::fake();
        Log::spy();

        (new PrCorrelationCommenter(GitHubTokenResolver::forWriteback(null)))->repost(self::REPO, 7, 'merged', 'body');
        (new ProtocolInvalidLabeler(GitHubTokenResolver::forWriteback(null)))->apply(['repo' => self::REPO, 'number' => 7, 'comment_id' => 1]);

        $this->assertWarnedWithSource('pr_correlation_comment.no_token');
        $this->assertWarnedWithSource('protocol_invalid_label.no_token');
        Http::assertNothingSent();
    }

    /** @param  mixed  $answers  one answer for every request, or [GET answer, POST answer] */
    private function fakeGitHub(mixed $answers): void
    {
        [$get, $post] = is_array($answers) ? $answers : [$answers, null];
        Http::fake(function (Request $request) use ($get, $post): mixed {
            $answer = $request->method() === 'GET' ? $get : $post;

            // Http::failedConnection() is a stub CALLBACK, which the array form invokes and this does not.
            return $answer instanceof Closure ? $answer($request) : $answer;
        });
    }

    private function assertWarnedWithSource(string $catalogId): void
    {
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use ($catalogId): bool {
            if (($context['catalog_id'] ?? null) !== $catalogId) {
                return false;
            }
            $this->assertSame('store key '.self::KEY, $context['token_source'] ?? null, "{$catalogId} must name the store key that answered");
            $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($context));
            $this->assertStringNotContainsString($this->tokenPath, (string) $context['token_source']);

            return true;
        })->once();
    }
}
