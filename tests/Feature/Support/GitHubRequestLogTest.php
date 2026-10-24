<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\GitHubRequestLog;
use App\Bridge\Writeback\GitHubApi;
use App\Bridge\Writeback\GitHubReadClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The GitHub request log (card#12286): one tab-separated line per GitHub REST request —
 * UTC time, method, path, status — read back from the file the real channel writes, so the
 * formatter is under test too.
 */
class GitHubRequestLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/github-request-log-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        config(['logging.channels.'.GitHubRequestLog::CHANNEL.'.path' => $this->dir.'/github-requests.log']);
        Carbon::setTestNow('2026-10-23T14:05:09Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_response_writes_one_line_of_time_method_path_status(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['state' => 'open'], 404)]);

        GitHubApi::request('ghp_x', 5)->get(GitHubApi::BASE.'/repos/o/r/pulls/7');

        $this->assertSame(["2026-10-23T14:05:09Z\tGET\t/repos/o/r/pulls/7\t404"], $this->lines());
    }

    public function test_a_request_through_the_read_client_is_logged(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['resources' => []], 200)]);

        (new GitHubReadClient('ghp_x'))->probeRepo('o/r');

        $this->assertSame(["2026-10-23T14:05:09Z\tGET\t/repos/o/r\t200"], $this->lines());
    }

    public function test_a_connection_failure_writes_err_and_still_throws(): void
    {
        Http::fake(['https://api.github.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        try {
            GitHubApi::request('ghp_x', 5)->post(GitHubApi::BASE.'/repos/o/r/issues/3/labels', ['labels' => ['x']]);
            $this->fail('the connection failure must still reach the caller');
        } catch (ConnectionException) {
        }

        $this->assertSame(["2026-10-23T14:05:09Z\tPOST\t/repos/o/r/issues/3/labels\tERR"], $this->lines());
    }

    /** The production transport's shape: curl answers a failed transfer with a rejected promise. */
    public function test_a_rejected_transfer_writes_err_and_still_throws(): void
    {
        $request = GitHubApi::request('ghp_x', 5)
            ->allowStrayRequests([GitHubApi::BASE.'/*'])
            ->setHandler(fn (RequestInterface $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $r)));

        try {
            $request->get(GitHubApi::BASE.'/repos/o/r/actions/runs');
            $this->fail('the connection failure must still reach the caller');
        } catch (ConnectionException) {
        }

        $this->assertSame(["2026-10-23T14:05:09Z\tGET\t/repos/o/r/actions/runs\tERR"], $this->lines());
    }

    public function test_no_token_query_string_header_or_body_reaches_the_line(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response('RESPONSE-BODY-SECRET', 201, ['X-Leak' => 'RESPONSE-HEADER-SECRET'])]);

        GitHubApi::request('ghp_TOKENSECRET0123456789', 5)
            ->withHeaders(['X-Extra' => 'REQUEST-HEADER-SECRET'])
            ->post(GitHubApi::BASE.'/repos/o/r/issues/9/comments?access_token=QUERYSECRET#FRAGMENTSECRET', ['body' => 'REQUEST-BODY-SECRET']);

        $raw = $this->raw();
        foreach (['TOKENSECRET', 'QUERYSECRET', 'access_token', 'FRAGMENTSECRET', 'REQUEST-HEADER-SECRET', 'REQUEST-BODY-SECRET', 'RESPONSE-BODY-SECRET', 'RESPONSE-HEADER-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertSame("2026-10-23T14:05:09Z\tPOST\t/repos/o/r/issues/9/comments\t201\n", $raw);
    }

    public function test_a_redirect_to_another_host_logs_only_the_api_request(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response('', 302, ['Location' => 'https://objects.githubusercontent.com/asset/1?sig=abc']),
            'https://objects.githubusercontent.com/*' => Http::response('BYTES', 200),
        ]);

        $bytes = (new GitHubReadClient('ghp_x'))->releaseAssetBytes('o/r', 42);

        $this->assertSame('BYTES', $bytes);
        $this->assertSame(["2026-10-23T14:05:09Z\tGET\t/repos/o/r/releases/assets/42\t302"], $this->lines());
    }

    public function test_an_unwritable_log_leaves_the_response_unchanged(): void
    {
        touch($this->dir.'/a-file');
        config(['logging.channels.'.GitHubRequestLog::CHANNEL.'.path' => $this->dir.'/a-file/github-requests.log']);
        Http::fake(['https://api.github.com/*' => Http::response(['ok' => true], 200)]);

        $response = GitHubApi::request('ghp_x', 5)->get(GitHubApi::BASE.'/repos/o/r');

        $this->assertSame(200, $response->status());
        $this->assertSame(['ok' => true], $response->json());
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_values(array_filter(explode("\n", $this->raw()), fn (string $l) => $l !== ''));
    }

    private function raw(): string
    {
        $files = glob($this->dir.'/github-requests*.log') ?: [];
        $this->assertCount(1, $files, 'the channel writes one daily file');

        return (string) file_get_contents($files[0]);
    }
}
