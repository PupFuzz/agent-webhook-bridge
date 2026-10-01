<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every on/off `.env` setting the config files read, resolved THROUGH THE CONFIG FILE that reads
 * it (card#11029): a `BRIDGE_SPAWN_ENABLED=no` used to turn spawn ON, because `(bool) env(...)`
 * casts every non-empty string Laravel does not convert, other than `"0"` (`no`, `off`, `nope`), to `true`.
 *
 * The subject is the RESOLVED CONFIG VALUE, not the parser, so this class reds against the old
 * cast and against any site that stops going through the parser. Each case sets the variable in
 * `$_SERVER` — the adapter Laravel's env repository reads FIRST (phpunit.xml's header records the
 * measurement) — and evaluates the config file afresh.
 */
class BoolEnvFlagsTest extends TestCase
{
    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $was) {
            $this->restore($key, $was);
        }
        $this->saved = [];
        parent::tearDown();
    }

    /**
     * Each on/off site: the config file, the dotted path inside it, the env key, and the shipped
     * default. Adding a site to config/ without adding it here is caught by
     * {@see BoolEnvGuardTest}, which derives the set of parsed keys from the config source.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function sites(): array
    {
        return [
            'APP_DEBUG' => ['app', 'debug', 'APP_DEBUG', false],
            'BRIDGE_RETENTION_ENABLED' => ['bridge', 'retention.enabled', 'BRIDGE_RETENTION_ENABLED', true],
            'BRIDGE_STANDUP_ENABLED' => ['bridge', 'standup.enabled', 'BRIDGE_STANDUP_ENABLED', true],
            'BRIDGE_IDLE_NUDGE_ENABLED' => ['bridge', 'idle_nudge.enabled', 'BRIDGE_IDLE_NUDGE_ENABLED', true],
            'BRIDGE_JOBS_ENABLED' => ['bridge', 'jobs.enabled', 'BRIDGE_JOBS_ENABLED', true],
            'BRIDGE_OWED_WRITE_RETRY_DISABLED' => ['bridge', 'jobs.owed_write_retry_disabled', 'BRIDGE_OWED_WRITE_RETRY_DISABLED', false],
            'BRIDGE_SPAWN_ENABLED' => ['bridge', 'spawn.enabled', 'BRIDGE_SPAWN_ENABLED', false],
        ];
    }

    /**
     * The readable spellings and what each means. Laravel itself turns `true`/`false`/`(true)`/
     * `(false)` into bools and `empty`/`(empty)` into `''` before the parser sees them; every
     * other spelling reaches it as the raw string.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function readableValues(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            '(true)' => ['(true)', true],
            '1' => ['1', true],
            'yes' => ['yes', true],
            'Yes' => ['Yes', true],
            'on' => ['on', true],
            'ON' => ['ON', true],
            'padded on' => ['  on ', true],
            'false' => ['false', false],
            'FALSE' => ['FALSE', false],
            '(false)' => ['(false)', false],
            '0' => ['0', false],
            'no' => ['no', false],
            'No' => ['No', false],
            'off' => ['off', false],
            'OFF' => ['OFF', false],
            'padded false' => [' false ', false],
            'padded no' => [' no', false],
            'empty string' => ['', false],
            'whitespace only' => ['   ', false],
            'laravel (empty)' => ['(empty)', false],
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool, 4: string, 5: bool}>
     */
    public static function siteByReadableValue(): iterable
    {
        foreach (self::sites() as $site => $s) {
            foreach (self::readableValues() as $label => [$value, $expected]) {
                yield "{$site} = {$label}" => [...$s, $value, $expected];
            }
        }
    }

    #[DataProvider('siteByReadableValue')]
    public function test_a_readable_value_is_read_as_meant(string $file, string $path, string $key, bool $default, string $value, bool $expected): void
    {
        $this->setEnv($key, $value);

        $this->assertSame($expected, data_get($this->loadConfig($file), $path), "{$key}=".var_export($value, true));
    }

    /**
     * An unreadable value is the setting's DEFAULT (operator decision on card#11029, option A) —
     * the bridge keeps serving, and `bridge:check` is where it is reported. `2` and `enabled` are
     * the plausible typos; `nope` is the card's own example.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool, 4: string}>
     */
    public static function siteByUnreadableValue(): iterable
    {
        foreach (self::sites() as $site => $s) {
            foreach (['nope', '2', 'enabled', 'disable', 'y'] as $value) {
                yield "{$site} = {$value}" => [...$s, $value];
            }
        }
    }

    #[DataProvider('siteByUnreadableValue')]
    public function test_an_unreadable_value_is_the_default_and_is_recorded(string $file, string $path, string $key, bool $default, string $value): void
    {
        $this->setEnv($key, $value);

        $this->assertSame($default, data_get($this->loadConfig($file), $path), "{$key}=".var_export($value, true).' must read as its default');
        $this->assertSame([$key => $value], $this->loadConfig('bridge')['unreadable_flags']);
    }

    #[DataProvider('sites')]
    public function test_an_unset_value_is_the_default(string $file, string $path, string $key, bool $default): void
    {
        $this->setEnv($key, null);

        $this->assertSame($default, data_get($this->loadConfig($file), $path));
        $this->assertSame([], $this->loadConfig('bridge')['unreadable_flags']);
    }

    /**
     * The WITNESS for the default case above: an unset key and an explicit opposite of the
     * default must resolve differently, or the default assertion could be satisfied by a site that
     * ignores the environment altogether.
     */
    #[DataProvider('sites')]
    public function test_the_opposite_of_the_default_is_honoured(string $file, string $path, string $key, bool $default): void
    {
        $this->setEnv($key, $default ? 'off' : 'on');

        $this->assertSame(! $default, data_get($this->loadConfig($file), $path));
    }

    public function test_only_the_unreadable_keys_are_recorded_with_their_values(): void
    {
        $this->setEnv('BRIDGE_SPAWN_ENABLED', 'nope');
        $this->setEnv('APP_DEBUG', 'maybe');
        $this->setEnv('BRIDGE_STANDUP_ENABLED', 'no');
        $this->setEnv('BRIDGE_JOBS_ENABLED', null);

        $recorded = $this->loadConfig('bridge')['unreadable_flags'];
        ksort($recorded);

        $this->assertSame(['APP_DEBUG' => 'maybe', 'BRIDGE_SPAWN_ENABLED' => 'nope'], $recorded);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(string $file): array
    {
        $config = require base_path("config/{$file}.php");
        $this->assertIsArray($config);

        return $config;
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->saved)) {
            $this->saved[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'putenv' => getenv($key),
            ];
        }

        foreach (array_keys(self::sites()) as $other) {
            if (! array_key_exists($other, $this->saved)) {
                $this->saved[$other] = [
                    'server' => $_SERVER[$other] ?? null,
                    'env' => $_ENV[$other] ?? null,
                    'putenv' => getenv($other),
                ];
                $this->clear($other);
            }
        }

        $this->clear($key);
        if ($value !== null) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    private function clear(string $key): void
    {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    /**
     * @param  array{server: mixed, env: mixed, putenv: string|false}  $was
     */
    private function restore(string $key, array $was): void
    {
        $this->clear($key);
        if ($was['server'] !== null) {
            $_SERVER[$key] = $was['server'];
        }
        if ($was['env'] !== null) {
            $_ENV[$key] = $was['env'];
        }
        if ($was['putenv'] !== false) {
            putenv("{$key}={$was['putenv']}");
        }
    }
}
