<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\GitHubRequestLog;
use App\Bridge\Writeback\GitHubApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * The witness for `Tests\TestCase::pointGitHubRequestLogAwayFromTheRealInstall()`: a test that
 * fakes a GitHub call must not write into the checkout's `storage/logs`.
 */
class GitHubRequestLogIsolationTest extends TestCase
{
    public function test_the_channel_does_not_write_under_storage_path_during_tests(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([], 200)]);

        GitHubApi::request('ghp_x', 5)->get(GitHubApi::BASE.'/repos/o/r');

        $storage = rtrim(storage_path(), '/').'/';
        $this->assertStringStartsNotWith($storage, (string) config('logging.channels.'.GitHubRequestLog::CHANNEL.'.path'));

        $logger = Log::channel(GitHubRequestLog::CHANNEL)->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $this->assertNotEmpty($handlers);
        foreach ($handlers as $handler) {
            $this->assertInstanceOf(StreamHandler::class, $handler);
            $this->assertStringStartsNotWith($storage, (string) $handler->getUrl());
            $this->assertFileExists((string) $handler->getUrl(), 'the faked request was logged, so the check above looked at a file that was written');
        }
    }
}
