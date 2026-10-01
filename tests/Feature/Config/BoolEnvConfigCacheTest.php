<?php

namespace Tests\Feature\Config;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * An unreadable on/off value is reported by `bridge:check` on a `config:cache`d install
 * (card#11029), measured on two real `php artisan` subprocesses: `config:cache` writes a cache
 * file, then `bridge:check` boots from it.
 *
 * ⭐ THE LIVE ENVIRONMENT AT CHECK TIME DISAGREES WITH THE CACHE ON PURPOSE, both ways. A cached
 * install never loads its `.env`, so the value it runs with is the one frozen into the cache, and
 * that is the value the leg must report: cache `nope` and check under `on` → reported; cache `on`
 * and check under `nope` → not reported. A leg that re-read the live environment would pass
 * neither.
 *
 * The cache goes to a temp file through `APP_CONFIG_CACHE`, never `bootstrap/cache/`, so a run
 * cannot leave a cached config behind in the checkout. Each child gets an explicit environment,
 * and `bridge:check` on that bare install fails for other reasons too; only the flag line and its
 * absence are asserted.
 */
class BoolEnvConfigCacheTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/bool-env-config-cache-'.getmypid().'.php';
        File::delete($this->cachePath);
    }

    protected function tearDown(): void
    {
        File::delete($this->cachePath);
        parent::tearDown();
    }

    public function test_the_cached_unreadable_value_is_reported_whatever_the_live_env_says(): void
    {
        $this->cacheConfigWith('nope');

        [$exit, $out] = $this->runArtisan(['bridge:check'], 'on');

        $this->assertStringContainsString("FAIL: BRIDGE_SPAWN_ENABLED='nope' is not an on/off value", $out);
        $this->assertNotSame(0, $exit);
    }

    public function test_a_cached_readable_value_is_not_reported_whatever_the_live_env_says(): void
    {
        $this->cacheConfigWith('on');

        [, $out] = $this->runArtisan(['bridge:check'], 'nope');

        // The witness that the check RAN and rendered: the install-suffix leg always prints.
        $this->assertStringContainsString('install-suffix DSN check', $out);
        $this->assertStringNotContainsString('BRIDGE_SPAWN_ENABLED', $out);
    }

    private function cacheConfigWith(string $value): void
    {
        [$exit, $out] = $this->runArtisan(['config:cache'], $value);

        $this->assertSame(0, $exit, "config:cache failed:\n{$out}");
        $this->assertFileExists($this->cachePath);
    }

    /**
     * @param  list<string>  $args
     * @return array{int, string} [exit code, stdout + stderr]
     */
    private function runArtisan(array $args, string $spawnEnabled): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, base_path('artisan')], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            [
                'PATH' => (string) getenv('PATH'),
                'HOME' => (string) getenv('HOME'),
                'LOG_CHANNEL' => 'null',
                'APP_CONFIG_CACHE' => $this->cachePath,
                'BRIDGE_SPAWN_ENABLED' => $spawnEnabled,
            ],
        );
        $this->assertIsResource($process, 'could not start the artisan subprocess');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout.$stderr];
    }
}
