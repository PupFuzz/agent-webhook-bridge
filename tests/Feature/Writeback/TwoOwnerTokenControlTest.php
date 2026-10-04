<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubWriteAttempt;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\PrOutcome;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\CoordCredentialStoreFixture;
use Tests\TestCase;

/**
 * The rt#593 acceptance control (card#11208 step 7, DL-456): an install mapping repos under TWO
 * resource owners, where a fine-grained PAT covers one owner and GitHub answers `404` for any other.
 *
 *  - With the coord credential store mapping each owner to its own key (a token file per key), the
 *    correlation comment posts on BOTH repos, each with its own owner's token.
 *  - With only the single token file (one owner's PAT), it posts on that owner's repo and warns,
 *    naming the repo, for the other — the state the sola install was in.
 *
 * GitHub is stubbed to answer as a per-owner PAT does: the request's bearer must belong to the
 * repo's owner, else `404`. That stub is what makes the first case able to fail.
 */
class TwoOwnerTokenControlTest extends TestCase
{
    private const ALPHA = 'alpha-org/one';

    private const BETA = 'beta-org/two';

    /** token => the one owner it covers */
    private const OWNER_OF = ['ghp_alpha_owner' => 'alpha-org', 'ghp_beta_owner' => 'beta-org'];

    private string $dir;

    /** @var array<string, list<string>> repo => the bearer of each comment that landed */
    private array $landed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/two-owner-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/writeback.json', (string) json_encode(['mappings' => [
            self::ALPHA => ['board_id' => 8, 'stages' => ['merged' => 52]],
            self::BETA => ['board_id' => 9, 'stages' => ['merged' => 62]],
        ]]));
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.token_path' => null,
        ]);
        Http::fake(fn (Request $request) => $this->github($request));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_with_a_token_file_per_owner_the_comment_posts_on_both_repos(): void
    {
        $store = (new CoordCredentialStoreFixture($this->dir.'/coord'))->use();
        $store->write(
            ['github.com/alpha-org' => 'alpha', 'github.com/beta-org' => 'beta'],
            ['alpha_file' => $store->tokenFile('alpha-token', 'ghp_alpha_owner'), 'beta_file' => $store->tokenFile('beta-token', 'ghp_beta_owner')],
        );

        $alpha = $this->comment(self::ALPHA, 11);
        $beta = $this->comment(self::BETA, 22);

        $this->assertTrue($alpha->landed, $alpha->arm);
        $this->assertTrue($beta->landed, $beta->arm);
        $this->assertSame(['ghp_alpha_owner'], $this->landed[self::ALPHA]);
        $this->assertSame(['ghp_beta_owner'], $this->landed[self::BETA]);
    }

    public function test_with_only_the_single_file_it_posts_on_one_repo_and_warns_by_name_for_the_other(): void
    {
        File::put($this->dir.'/github/token', 'ghp_alpha_owner');
        chmod($this->dir.'/github/token', 0o600);
        Log::spy();

        $alpha = $this->comment(self::ALPHA, 11);
        $beta = $this->comment(self::BETA, 22);

        $this->assertTrue($alpha->landed, $alpha->arm);
        $this->assertFalse($beta->landed);
        $this->assertSame(404, $beta->status);
        $this->assertArrayNotHasKey(self::BETA, $this->landed);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'pr_correlation_comment: NOT posted')
            && ($context['repo'] ?? null) === self::BETA
            && ($context['status'] ?? null) === 404);
    }

    public function test_a_token_pasted_as_the_map_value_is_in_no_log_line_the_dropped_comment_writes(): void
    {
        $pasted = 'ghp_SyntheticPastedToken0123456789abcdef';
        $store = (new CoordCredentialStoreFixture($this->dir.'/coord'))->use();
        $store->write(['github.com/alpha-org' => $pasted], []);
        $logged = [];
        Log::listen(function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $alpha = $this->comment(self::ALPHA, 11);

        $this->assertFalse($alpha->landed);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($pasted, $line);
        }
        $this->assertNotEmpty(array_filter($logged, fn (string $line) => str_contains($line, 'token_unresolved') && str_contains($line, 'credential-shaped')), 'the witness: the drop is logged, with the elided name');
    }

    private function comment(string $repo, int $number): GitHubWriteAttempt
    {
        $outcome = PrOutcome::INTEGRATION_MERGE;

        return (new PrCorrelationCommenter)->repost($repo, $number, $outcome, PrCorrelationComment::markerFor($outcome)."\nthe card did not move");
    }

    private function github(Request $request): PromiseInterface
    {
        if (preg_match('#^https://api\.github\.com/repos/([^/]+)/([^/]+)/issues/\d+/comments(\?.*)?$#', $request->url(), $m) !== 1) {
            return Http::response(['message' => 'not stubbed: '.$request->url()], 599);
        }
        $repo = "{$m[1]}/{$m[2]}";
        $bearer = substr($request->header('Authorization')[0] ?? '', strlen('Bearer '));
        if ((self::OWNER_OF[$bearer] ?? null) !== $m[1]) {
            return Http::response(['message' => 'Not Found'], 404);
        }
        if ($request->method() === 'GET') {
            return Http::response([]);
        }
        $this->landed[$repo][] = $bearer;

        return Http::response(['id' => 1, 'body' => $request->data()['body'] ?? ''], 201);
    }
}
