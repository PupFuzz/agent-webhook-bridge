<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Writeback\WritebackClientFactory;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The factory's THROW CONTRACT (card#5778). Its callers — the correlation classifier
 * (treatment A) and the durable move handler (treatment B) — are written against
 * `ConfigException`, and `bridge:check` wraps the construction in a fail-soft envelope
 * that renders the message to the operator. A raw PHP stream warning escaping as an
 * `ErrorException` satisfied neither: it carried no diagnosis and matched no catch.
 */
class WritebackClientFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wbf-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');

        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_readable_token_builds_a_client(): void
    {
        $this->writeToken('wb-token', 0o600);   // gitleaks:allow — test fixture

        // The positive control: without it, a factory that threw unconditionally would
        // pass every assertion below.
        WritebackClientFactory::make();
        $this->addToAssertionCount(1);
    }

    public function test_a_present_but_unreadable_token_throws_config_exception(): void
    {
        $path = $this->writeToken('wb-token', 0o000);   // gitleaks:allow — test fixture
        clearstatcache(true, $path);
        if (is_readable($path)) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }

        try {
            WritebackClientFactory::make();
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
            $this->assertStringContainsString('could not be read by this process', $e->getMessage());
            $this->assertStringNotContainsString('wb-token', $e->getMessage());
        }
    }

    /**
     * The discriminating control: an ABSENT token keeps its own distinct diagnosis, so the
     * arm above cannot be satisfied by collapsing both states onto one message.
     */
    public function test_an_absent_token_throws_config_exception_naming_absence(): void
    {
        try {
            WritebackClientFactory::make();
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('no token at', $e->getMessage());
            $this->assertStringNotContainsString('could not be read by this process', $e->getMessage());
        }
    }

    private function writeToken(string $value, int $mode): string
    {
        $path = $this->dir.'/kanban/writeback-token';
        File::put($path, $value);
        chmod($path, $mode);

        return $path;
    }
}
