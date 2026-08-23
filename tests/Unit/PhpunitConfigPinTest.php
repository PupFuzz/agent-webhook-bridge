<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The suite's isolation-critical pins are written TWICE in `phpunit.xml` — once
 * as `<env … force="true"/>` and once as `<server/>` — and this guards the pair
 * (card#7474).
 *
 * Why two copies exist at all: `force="true"` alone does not make a pin hold.
 * PHPUnit's `PhpHandler::handleEnvVariables()` honors it by writing `putenv()`
 * and `$_ENV`, but never `$_SERVER`, and an exported shell variable lands in
 * `$_SERVER` under this build's `variables_order=GPCS`. Laravel resolves `env()`
 * through Dotenv's repository, whose `DEFAULT_ADAPTERS` are `ServerConstAdapter`
 * then `EnvConstAdapter`, so `$_SERVER` is read first and the forced pin loses.
 * `handleServerVariables()` is the only layer that closes it.
 *
 * Two copies of one value is a defect unless something reds when they diverge —
 * and this pair is worse than the usual case, because `$_SERVER` is read FIRST:
 * a maintainer editing the `<env>` line alone would produce a file that reads
 * correctly and behaves as though the edit never happened. Hence a guard rather
 * than a comment.
 *
 * The Redis values are additionally pinned by name: they are indices claimed for
 * this suite on a host whose Redis is shared with other tenants, published so
 * those tenants could avoid them. Changing one silently is a cross-tenant
 * collision, not a local preference. Nothing selects Redis in this suite today
 * (`CACHE_STORE=array`, `QUEUE_CONNECTION=sync`), so the pins are latent — which
 * is exactly when a guard is cheap and an unnoticed regression is free.
 */
class PhpunitConfigPinTest extends TestCase
{
    /**
     * The pins whose ambient override would reach ANOTHER TENANT's data or red
     * every DB-touching test (G-013), and which therefore must carry BOTH entries. Deliberately not the whole `<php>` block:
     * the rest is `force="true"`-only, which buys the child-process layer and
     * nothing in-process, and the DB_* group is unpinned against the environment on
     * purpose — the `phpunit-mariadb` CI matrix overrides it by exporting.
     */
    private const PAIRED_PINS = [
        'REDIS_DB' => '13',
        'REDIS_CACHE_DB' => '12',
        'BRIDGE_INSTALL_SUFFIX' => '',
    ];

    public function test_every_isolation_critical_pin_is_present_as_both_env_and_server(): void
    {
        [$env, $server] = $this->pins();

        foreach (self::PAIRED_PINS as $name => $value) {
            $this->assertArrayHasKey($name, $env, "phpunit.xml lost the <env> pin for {$name}");
            $this->assertArrayHasKey(
                $name,
                $server,
                "phpunit.xml has no <server> pin for {$name} — without it an exported ".
                "{$name} wins, because Laravel reads \$_SERVER before \$_ENV",
            );
            $this->assertSame($value, $env[$name]['value'], "the <env> pin for {$name} changed value");
            $this->assertSame($value, $server[$name], "the <server> pin for {$name} changed value");
            $this->assertTrue(
                $env[$name]['force'],
                "the <env> pin for {$name} lost force=\"true\" — child processes would inherit an ambient value",
            );
        }
    }

    public function test_no_server_pin_disagrees_with_its_env_pin(): void
    {
        [$env, $server] = $this->pins();

        foreach ($server as $name => $value) {
            $this->assertArrayHasKey(
                $name,
                $env,
                "phpunit.xml pins <server> {$name} with no matching <env> entry, so a child process ".
                'would not inherit it',
            );
            $this->assertSame(
                $env[$name]['value'],
                $value,
                "phpunit.xml's <env> and <server> pins for {$name} disagree. \$_SERVER is read FIRST, ".
                'so the <server> value is what the suite actually uses and the <env> value is dead text',
            );
        }
    }

    /**
     * @return array{0: array<string, array{value: string, force: bool}>, 1: array<string, string>}
     */
    private function pins(): array
    {
        $path = dirname(__DIR__, 2).'/phpunit.xml';
        $this->assertFileExists($path);

        $xml = new SimpleXMLElement((string) file_get_contents($path));

        $env = [];
        foreach ($xml->php->env as $node) {
            $env[(string) $node['name']] = [
                'value' => (string) $node['value'],
                'force' => filter_var((string) $node['force'], FILTER_VALIDATE_BOOL),
            ];
        }

        $server = [];
        foreach ($xml->php->server as $node) {
            $server[(string) $node['name']] = (string) $node['value'];
        }

        $this->assertNotEmpty($env, 'parsed no <env> pins at all — the reader, not the file, is broken');
        $this->assertNotEmpty($server, 'parsed no <server> pins at all — the reader, not the file, is broken');

        return [$env, $server];
    }
}
