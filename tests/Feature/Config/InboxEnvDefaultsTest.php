<?php

namespace Tests\Feature\Config;

use App\Bridge\Support\BridgePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SetsProcessEnv;
use Tests\TestCase;

/**
 * The inbox layout and file mode under the three states of their `.env` variable — unset, set
 * but empty, set — resolved THROUGH config/bridge.php and then read by {@see BridgePaths}
 * (card#11714). Each case sets the variable in `$_SERVER`, `$_ENV` and the process environment,
 * evaluates the config file afresh, and installs the result as the running config.
 *
 * `bridge.inbox_layout`'s default lives only in the config file, so its empty case is asserted on
 * the CONFIG VALUE as well as on what the reader returns.
 */
class InboxEnvDefaultsTest extends TestCase
{
    use SetsProcessEnv;

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function layouts(): array
    {
        return [
            'unset' => [null, 'shared'],
            'empty' => ['', 'shared'],
            'set' => ['per-agent', 'per-agent'],
        ];
    }

    #[DataProvider('layouts')]
    public function test_the_inbox_layout_config_value_is_already_the_default(?string $env, string $expected): void
    {
        $this->setProcessEnv('BRIDGE_INBOX_LAYOUT', $env);

        $this->assertSame($expected, $this->loadBridgeConfig()['inbox_layout']);
    }

    #[DataProvider('layouts')]
    public function test_the_inbox_layout_reader_returns_the_value_or_the_default(?string $env, string $expected): void
    {
        $this->setProcessEnv('BRIDGE_INBOX_LAYOUT', $env);
        config(['bridge.inbox_layout' => $this->loadBridgeConfig()['inbox_layout']]);

        $this->assertSame($expected, BridgePaths::inboxLayout());
    }

    /**
     * @return array<string, array{0: ?string, 1: int}>
     */
    public static function fileModes(): array
    {
        return [
            'unset' => [null, 0640],
            'empty' => ['', 0640],
            'set' => ['0600', 0600],
        ];
    }

    #[DataProvider('fileModes')]
    public function test_the_inbox_file_mode_reader_returns_the_value_or_the_default(?string $env, int $expected): void
    {
        $this->setProcessEnv('BRIDGE_INBOX_FILE_MODE', $env);
        config(['bridge.inbox_file_mode' => $this->loadBridgeConfig()['inbox_file_mode']]);

        $this->assertSame($expected, BridgePaths::inboxFileMode());
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBridgeConfig(): array
    {
        $config = require base_path('config/bridge.php');
        $this->assertIsArray($config);

        return $config;
    }
}
