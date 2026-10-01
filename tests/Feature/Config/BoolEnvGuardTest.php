<?php

namespace Tests\Feature\Config;

use App\Bridge\Support\BoolEnv;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Every on/off `.env` read in config/ goes through {@see BoolEnv} (card#11029).
 *
 * THE POPULATION IS config/*.php, AND THAT IS MINUTED NARROWER THAN app/ ON PURPOSE: the subject
 * is how a config file turns an env value into a bool, and `env()` is read only there. The files
 * are walked through {@see SourceScan::sites()}, so a cast spelled across lines or with odd
 * spacing (`( boolean ) env (`) is one token sequence, and a mention in a comment is not a site.
 *
 * Three legs: no raw bool cast of `env()`; every key `BoolEnv::get()` is called with is in
 * `BoolEnv::KEYS` and every listed key is read somewhere (a listed key nothing reads would be
 * reported by `bridge:check` while changing nothing); and nothing under app/ makes any static
 * call on `BoolEnv` — `get()` and `unreadable()` both read the env repository, which is right only
 * while config/ is being evaluated.
 */
class BoolEnvGuardTest extends TestCase
{
    public function test_no_config_file_casts_env_to_bool(): void
    {
        $this->assertSame([], $this->rawBoolCasts($this->configSources()), 'cast with BoolEnv::get() instead — `(bool) env()` turns `no` and `off` into true (card#11029)');
    }

    /**
     * THE CONTROL: the predicate finds each spelling it exists to find, and passes the parser's
     * own call and a comment mentioning the cast.
     */
    public function test_the_cast_predicate_discriminates(): void
    {
        $planted = <<<'PHP'
            <?php
            // 'a' => (bool) env('COMMENTED', false),
            return [
                'a' => (bool) env('A', false),
                'b' => ( boolean ) env (
                    'B', true),
                'c' => (bool) \env('C'),
                'd' => boolval(env('D')),
                'e' => BoolEnv::get('E', false),
                'f' => (int) env('F', 1),
            ];
            PHP;

        $this->assertSame(['A', 'B', 'C', 'D'], array_values($this->rawBoolCasts(['planted.php' => $planted])));
    }

    public function test_every_parsed_key_is_registered_and_every_registered_key_is_parsed(): void
    {
        $read = array_values(array_unique(array_values($this->boolEnvGets($this->configSources()))));
        sort($read);
        $registered = BoolEnv::KEYS;
        sort($registered);

        $this->assertNotSame([], $read, 'no BoolEnv::get() call was found in config/ — the derivation, not the code, is what changed');
        $this->assertSame($registered, $read);
    }

    public function test_nothing_under_app_makes_a_static_call_on_bool_env(): void
    {
        $calls = SourceScan::sitesInApp(static fn (array $tokens, int $i): ?string => self::boolEnvStaticCallAt($tokens, $i));

        $this->assertSame([], $calls, 'every BoolEnv static method (get(), unreadable()) reads the env repository, which holds the .env only while config/ is evaluated — read the config value (bridge.* / bridge.unreadable_flags) instead');
    }

    /**
     * THE CONTROL for the leg above: the predicate hits an IMPORTED `unreadable()` call and an
     * FQCN `get()` call, and does not hit `BoolEnv::KEYS` (a property, not a call — no `(`
     * follows) or a `{@see BoolEnv}` docblock mention (comments are dropped by
     * {@see SourceScan::significantTokens()} before the walk ever sees them).
     */
    public function test_the_static_call_predicate_discriminates(): void
    {
        $planted = <<<'PHP'
            <?php

            namespace App\Bridge\Tmp;

            /**
             * {@see BoolEnv} describes the config-time reader.
             */
            use App\Bridge\Support\BoolEnv;

            final class Planted
            {
                public function a(): array
                {
                    return BoolEnv::unreadable();
                }

                public function b(): bool
                {
                    return \App\Bridge\Support\BoolEnv::get('APP_DEBUG', false);
                }

                public function c(): array
                {
                    return BoolEnv::KEYS;
                }
            }
            PHP;

        $hits = SourceScan::sites($planted, 'planted.php', static fn (array $tokens, int $i): ?string => self::boolEnvStaticCallAt($tokens, $i));

        $this->assertSame(['unreadable', 'get'], array_values($hits));
    }

    /**
     * @return array<string, string> file => source
     */
    private function configSources(): array
    {
        $sources = [];
        foreach (glob(base_path('config/*.php')) ?: [] as $path) {
            $sources[basename($path)] = (string) file_get_contents($path);
        }
        $this->assertArrayHasKey('bridge.php', $sources, 'config/ came back without bridge.php — the derivation is what changed');

        return $sources;
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, string> site => env key
     */
    private function rawBoolCasts(array $sources): array
    {
        $sites = [];
        foreach ($sources as $file => $source) {
            $sites += SourceScan::sites($source, $file, static function (array $tokens, int $i): ?string {
                $token = $tokens[$i];
                $envAt = match (true) {
                    $token[0] === T_BOOL_CAST => $i + 1,
                    $token[0] === T_STRING && strtolower($token[1]) === 'boolval' && ($tokens[$i + 1][1] ?? null) === '(' => $i + 2,
                    default => null,
                };
                if ($envAt === null || ! in_array(strtolower(ltrim($tokens[$envAt][1] ?? '', '\\')), ['env', 'getenv'], true)) {
                    return null;
                }
                if (($tokens[$envAt + 1][1] ?? null) !== '(') {
                    return null;
                }

                return trim($tokens[$envAt + 2][1] ?? '', '\'"');
            });
        }

        return $sites;
    }

    /**
     * @param  array<string, string>  $sources
     * @return array<string, string> site => env key
     */
    private function boolEnvGets(array $sources): array
    {
        $sites = [];
        foreach ($sources as $file => $source) {
            $sites += SourceScan::sites($source, $file, static fn (array $tokens, int $i): ?string => self::boolEnvGetAt($tokens, $i));
        }

        return $sites;
    }

    /**
     * The method name when the token at $i opens any `BoolEnv::<method>(` call, else null.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function boolEnvStaticCallAt(array $tokens, int $i): ?string
    {
        if (! self::isBoolEnvName($tokens[$i])) {
            return null;
        }
        if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON || ($tokens[$i + 2][0] ?? null) !== T_STRING || ($tokens[$i + 3][1] ?? null) !== '(') {
            return null;
        }

        return $tokens[$i + 2][1];
    }

    /**
     * @param  array{0: int|string, 1: string}  $token
     */
    private static function isBoolEnvName(array $token): bool
    {
        if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return $token[1] === 'BoolEnv' || str_ends_with($token[1], '\\BoolEnv');
    }

    /**
     * The key literal when the token at $i opens `BoolEnv::get(`, else null.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function boolEnvGetAt(array $tokens, int $i): ?string
    {
        if (! self::isBoolEnvName($tokens[$i])) {
            return null;
        }
        if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON || ($tokens[$i + 2][1] ?? null) !== 'get' || ($tokens[$i + 3][1] ?? null) !== '(') {
            return null;
        }

        return trim($tokens[$i + 4][1] ?? '', '\'"');
    }
}
