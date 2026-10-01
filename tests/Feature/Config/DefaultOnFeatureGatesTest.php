<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The SHIPPED defaults of the feature gates card#10918 / DL-441 turned on: new functionality
 * ships enabled and names its missing setup, rather than waiting for an install to opt in.
 *
 * Pinned over the SOURCE TEXT of `config/bridge.php`, not a resolved value, for the reason
 * `NullPayloadDefaultTest` records: requiring the file evaluates `env()`, so the assertion
 * would follow whatever the running environment exports rather than the shipped default.
 */
class DefaultOnFeatureGatesTest extends TestCase
{
    private function configSource(): string
    {
        $source = file_get_contents(base_path('config/bridge.php'));
        $this->assertIsString($source, 'config/bridge.php is unreadable — this pin measures nothing');

        return $source;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function defaultOnGates(): iterable
    {
        yield 'standup digest' => ["/'enabled'\s*=>\s*\(bool\)\s*env\(\s*'BRIDGE_STANDUP_ENABLED'\s*,\s*true\s*\)/"];
        yield 'idle nudge' => ["/'enabled'\s*=>\s*\(bool\)\s*env\(\s*'BRIDGE_IDLE_NUDGE_ENABLED'\s*,\s*true\s*\)/"];
        // Every state-mutating job handler is armed unless named here: an empty default.
        yield 'mutator kill-switch list' => ["/'disarmed_mutators'\s*=>\s*env\(\s*'BRIDGE_JOBS_DISARMED_MUTATORS'\s*,\s*''\s*\)/"];
    }

    #[DataProvider('defaultOnGates')]
    public function test_the_gate_ships_on(string $pin): void
    {
        $this->assertSame(1, preg_match_all($pin, $this->configSource()), "expected exactly one default-on declaration matching {$pin}");
    }

    /**
     * The retired opt-in list must not come back as a gate: no file under `app/` other than the
     * preflight leg that says it no longer does anything may name the retired key or env var, by
     * ANY access form — the dotted `config()` path, a bare array key against `config('bridge.jobs')`,
     * or a direct `env()` read that bypasses `config/bridge.php` altogether. One substring match
     * per form is deliberately broader than the single dotted-path literal it replaces (that literal
     * alone would have missed `config('bridge.jobs')['armed_mutators']` or a raw `env('BRIDGE_JOBS_ARMED_MUTATORS')`).
     */
    public function test_the_retired_armed_list_is_read_only_by_the_preflight(): void
    {
        // Negative lookbehind on 'dis' — `disarmed_mutators` (the live kill-switch key) contains
        // `armed_mutators` as a bare substring and must not trip this on its own name.
        $pattern = '/(?<!dis)armed_mutators|BRIDGE_JOBS_ARMED_MUTATORS/';
        $readers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match($pattern, $source) === 1) {
                $readers[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
        }

        $this->assertSame(['app/Bridge/Check/Checks/JobsPostureCheck.php'], array_values(array_unique($readers)));
    }
}
