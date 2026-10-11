<?php

namespace Tests\Feature\Config;

use Illuminate\Support\Facades\Blade;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * A config default is written once, in config/*.php — never again as a call-site fallback
 * (card#11714).
 *
 * `config('bridge.max_body_bytes', 256 * 1024)` restates a default the config file already
 * defines. The fallback is dead at runtime, because the config file always defines the key, so
 * nothing reds when the two copies drift — and they do drift (kanban's rate-limit default lived
 * in several places that way).
 *
 * THE SITE is a call to the global `config()` helper with a second argument that is not `null`,
 * `[]` or `array()`, whose key resolves to a non-null value in the booted test app. A key whose
 * value is null keeps its fallback: there the call site supplies a value the config file
 * deliberately leaves out, which is null-coalescing, not a copy. A key that is not a single
 * string literal cannot be classified at all and is a site too, so a computed key cannot walk
 * past the guard.
 *
 * THE POPULATION is every `*.php` under the trees SCANNED_DIRS names (minus the generated
 * bootstrap/cache/), walked through {@see SourceScan::sites()},
 * so comments, docblocks and strings are excluded by construction and a call spelled across
 * lines is one token sequence. A Blade template is compiled first, because `{{ config(…) }}` is
 * inline HTML to the PHP tokenizer until it is.
 *
 * BOUNDS, stated: "non-null" is read from the test app's config, so it is the config file's
 * value under phpunit.xml's env, not under an operator's `.env`. Other spellings of the same
 * read (`Config::get('k', d)`, `config()->get('k', d)`, `config()->integer('k', d)`) are outside
 * the predicate.
 */
class ConfigDefaultsDefinedOnceTest extends TestCase
{
    /**
     * Sites that keep a fallback on purpose, keyed as {@see SourceScan::sites()} keys them, with
     * the reason. Both directions are asserted: a new site reds, and so does an entry whose site
     * has gone.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [];

    private const SCANNED_DIRS = ['app', 'routes', 'bootstrap', 'database', 'resources', 'tests'];

    public function test_no_call_site_restates_a_config_default(): void
    {
        $sites = $this->fallbackSites($this->sources());
        $unexpected = array_diff_key($sites, self::ALLOWED);
        $stale = array_diff_key(self::ALLOWED, $sites);

        $this->assertSame([], $unexpected, 'drop the second argument: config/*.php already defines this key, so the fallback is a dead second copy of its default (card#11714). If the key can genuinely be absent here, add the site to ALLOWED with the reason.');
        $this->assertSame([], $stale, 'ALLOWED names a site that no longer has a fallback — remove the entry');
    }

    /**
     * THE CONTROL: the predicate finds each spelling it exists to find — a one-line call, a call
     * spelled across lines, a fully-qualified call, a computed key, a Blade echo — and passes a
     * call with no default, a `null` / `[]` default, a key whose config value is null, a method
     * named `config`, a comment and a string.
     */
    public function test_the_predicate_discriminates(): void
    {
        $planted = <<<'PHP'
            <?php
            // config('bridge.max_body_bytes', 1);
            $a = config('bridge.max_body_bytes', 1);
            $b = config(
                'bridge.inbox_layout',
                'shared',
            );
            $c = \config('bridge.jobs.enabled', true);
            $d = config('bridge.'.$suffix, 'x');
            $e = config('bridge.max_body_bytes');
            $f = config('bridge.max_body_bytes', null);
            $g = config('bridge.global_echo_ids', []);
            $h = config('bridge.card11714_no_such_key', 'x');
            $i = $this->config('bridge.max_body_bytes', 5);
            $j = Thing::config('bridge.max_body_bytes', 5);
            $k = 'config("bridge.max_body_bytes", 9)';
            PHP;
        $blade = "<title>{{ config('app.name', 'Laravel') }}</title>\n<p>{{ config('app.name') }}</p>\n";

        $hits = $this->fallbackSites(['planted.php' => $planted, 'planted.blade.php' => $blade]);

        $this->assertSame([
            'bridge.max_body_bytes => 1',
            "bridge.inbox_layout => 'shared'",
            'bridge.jobs.enabled => true',
            "(computed key) 'bridge.' . \$suffix => 'x'",
            "app.name => 'Laravel'",
        ], array_values($hits));
    }

    /**
     * @return array<string, string> path under the repo => source
     */
    private function sources(): array
    {
        $sources = [];
        foreach (self::SCANNED_DIRS as $dir) {
            foreach (SourceScan::phpFilesUnder($dir) as $path) {
                $relative = ltrim(str_replace(base_path(), '', $path), '/');
                if (str_starts_with($relative, 'bootstrap/cache/')) {
                    continue;
                }
                $sources[$relative] = (string) file_get_contents($path);
            }
        }
        $this->assertArrayHasKey('app/Http/Middleware/EnvelopeSizeLimit.php', $sources, 'the scanned population came back without a known app/ file — the derivation, not the code, is what changed');
        $this->assertArrayHasKey('resources/views/welcome.blade.php', $sources, 'the scanned population came back without the Blade view — the derivation, not the code, is what changed');

        return $sources;
    }

    /**
     * @param  array<string, string>  $sources  path => source
     * @return array<string, string> site => "<key> => <default>"
     */
    private function fallbackSites(array $sources): array
    {
        $sites = [];
        foreach ($sources as $file => $source) {
            if (str_ends_with($file, '.blade.php')) {
                $source = Blade::compileString($source);
            }
            $sites += SourceScan::sites($source, $file, fn (array $tokens, int $i): ?string => $this->fallbackAt($tokens, $i));
        }

        return $sites;
    }

    /**
     * "<key> => <default>" when the token at $i opens a `config(<key>, <default>)` helper call
     * that restates a non-null config value, else null.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private function fallbackAt(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        $isHelper = ($token[0] === T_STRING && strtolower($token[1]) === 'config')
            || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\config');
        if (! $isHelper || ($tokens[$i + 1][1] ?? null) !== '(') {
            return null;
        }
        if (in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            return null;
        }

        $args = $this->arguments($tokens, $i + 1);
        if (count($args) < 2) {
            return null;
        }
        $default = $this->spell($args[1]);
        if (in_array(strtolower(str_replace(' ', '', $default)), ['null', '[]', 'array()'], true)) {
            return null;
        }

        $key = $args[0];
        if (count($key) !== 1 || $key[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return '(computed key) '.$this->spell($key).' => '.$default;
        }
        $name = substr($key[0][1], 1, -1);
        if (config($name) === null) {
            return null;
        }

        return $name.' => '.$default;
    }

    /**
     * The argument token lists of the call whose `(` is at $open, split at its own depth.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return list<list<array{0: int|string, 1: string}>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $args = [[]];
        $depth = 0;
        for ($j = $open, $n = count($tokens); $j < $n; $j++) {
            $text = $tokens[$j][1];
            if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                if (++$depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = [];

                continue;
            }
            $args[count($args) - 1][] = $tokens[$j];
        }
        if ($args[count($args) - 1] === []) {
            array_pop($args);
        }

        return $args;
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private function spell(array $tokens): string
    {
        return implode(' ', array_column($tokens, 1));
    }
}
