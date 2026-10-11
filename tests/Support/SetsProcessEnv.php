<?php

namespace Tests\Support;

/**
 * Set or clear an environment variable for a test that evaluates a config file afresh, and put
 * every touched variable back afterwards. A value is written to `$_SERVER`, `$_ENV` and the
 * process environment, because Laravel's env repository reads `$_SERVER` first (phpunit.xml's
 * header records the measurement) and a leftover in any of the three would leak into a later test.
 *
 * The using class calls {@see restoreProcessEnv()} from its own `tearDown()`, before
 * `parent::tearDown()`.
 */
trait SetsProcessEnv
{
    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $savedProcessEnv = [];

    /**
     * Set $key to $value, or clear it when $value is null. The first touch of a key records what
     * it held, which is what {@see restoreProcessEnv()} puts back.
     */
    protected function setProcessEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->savedProcessEnv)) {
            $this->savedProcessEnv[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'putenv' => getenv($key),
            ];
        }

        $this->clearProcessEnv($key);
        if ($value !== null) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function hasTouchedProcessEnv(string $key): bool
    {
        return array_key_exists($key, $this->savedProcessEnv);
    }

    protected function restoreProcessEnv(): void
    {
        foreach ($this->savedProcessEnv as $key => $was) {
            $this->clearProcessEnv($key);
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
        $this->savedProcessEnv = [];
    }

    private function clearProcessEnv(string $key): void
    {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
}
