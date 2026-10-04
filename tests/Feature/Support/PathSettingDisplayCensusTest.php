<?php

namespace Tests\Feature\Support;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Does any message in `app/` print the RAW value of a path-valued setting? Every one must print it
 * through `PastedSecretShape::displayPathSetting()`, so a token an operator pasted where a path
 * belongs is printed as a `sha256:` fingerprint and never as itself (card#11261, after card#11208 /
 * PR #856 applied the rule to two settings).
 *
 * ⚠ THIS IS A TRIPWIRE FOR THE SHAPES BELOW, NOT A COMPLETENESS GUARANTEE. A token scan over PHP
 * cannot follow every way a value moves, and each time the scan was widened a new way turned up.
 * A green here means "none of the shapes below prints a path setting raw", not "no message does".
 *
 * THE SHAPES IT RECOGNISES — exactly {@see self::siteAt()}'s predicate, over every `*.php` under `app/`:
 *  - a CARRIER: a variable, optionally followed by `->property` steps, that holds a setting's value
 *    as read:
 *      - a PROPERTY named `path`, or the camelCase of a `…path` / `socket` key the readers
 *        ({@see self::KEY_READERS}) read as `['key']` or `array_key_exists('key', …)`
 *        ({@see self::globalProperties()}), or a `->tokenPath(…)`
 *        call ({@see self::GLOBAL_METHODS}), in every file;
 *      - `$x['key']` for such a key, in every file;
 *      - inside the readers, a LOCAL assigned (`$a = …`) from `$x['key']` or from an expression
 *        naming one of those locals, in the function it is in ({@see self::localCarriers()}),
 *        unless the WHOLE right-hand side is one `displayPathSetting(…)` call;
 *      - the per-file locals and parameters in {@see self::CARRIERS}, and the `.env` settings in
 *        {@see self::SETTINGS}, named by hand;
 *  - in MESSAGE POSITION: inside an interpolated string or heredoc, an operand of `.` or `.=`, the
 *    value after `=>` (a log context — and, since the token is the same, a `match` arm or an arrow
 *    function returning a carrier, which a ruling cannot exempt: rename the variable), or an argument of `sprintf` / `implode` / `join` /
 *    `UnreadableFileException::permissionsFault` (which takes the path already displayed — it sits
 *    below `Support` and cannot apply the rule itself).
 * A carrier passed to `displayPathSetting()` is in none of those positions, so it is not a site.
 *
 * {@see self::SETTINGS} is itself derived and checked: every `env('…_PATH')` / `env('…_DIR')` in
 * `config/bridge.php` must be declared there, so a NEW path setting reds here until someone says
 * where its value is printed.
 *
 * ⚠ WHAT THIS DOES NOT REACH, stated so a green is not read as more:
 *  - a key read through a helper that takes the key as an argument (`optionalString($block, 'x_path')`,
 *    `idleNudgeString`), and a helper's RETURN VALUE;
 *  - a copy by `??=`, by a chained assignment (`$a = $b = …`), by `foreach`, `list()` or a by-reference
 *    argument, or a carrier passed on as a whole argument to another function;
 *  - any copy outside the three readers that {@see self::CARRIERS} does not name, and anything printed
 *    from a variable or property this class neither derives nor declares;
 *  - a path DERIVED from a directory setting (`BRIDGE_DIR`, `BRIDGE_CONFIG_DIR`,
 *    `BRIDGE_SECRET_DIR`, `BRIDGE_STATE_DIR`): see {@see self::SETTINGS} — that class is NOT closed
 *    by a display rule at all, and `docs/config-schema.md` § *A token pasted where a path belongs* says why;
 *  - text the bridge relays but did not compose: a PHP warning (`error_get_last()`), Laravel's
 *    `ErrorException`, a subprocess's stderr (`ssh -i $BRIDGE_TOOLS_SSH_KEY`);
 *  - anything outside `app/`.
 */
class PathSettingDisplayCensusTest extends TestCase
{
    /**
     * The readers of the YAML / `writeback.json` settings, relative to `app/`. Every key they read
     * whose name ends in `path` or is `socket`, read as `['key']` or `array_key_exists('key', …)`, is
     * treated as a path-valued setting ({@see self::derivedKeys()}). A key read any other way is not
     * found (see the class docblock).
     */
    private const KEY_READERS = ['Bridge/Support/AgentConfig.php', 'Bridge/Support/BoardToolsConfig.php', 'Bridge/Writeback/WritebackConfig.php'];

    /** @var list<string>|null */
    private static ?array $globalProperties = null;

    /** @var list<string>|null */
    private static ?array $globalKeys = null;

    /** A method of any receiver that returns a configured token path. */
    private const GLOBAL_METHODS = ['tokenPath'];

    /**
     * Per file (relative to `app/`), or per `<file>::<function>` where the same name holds some
     * other path elsewhere in the file: the carriers that hold a path setting's value there.
     *
     * @var array<string, list<string>>
     */
    private const CARRIERS = [
        'Bridge/Check/Checks/AgentApiTokenCheck.php' => ['$tokenPath'],
        'Bridge/Check/Checks/WritebackAlertChannelCheck.php' => ['$socket'],
        'Bridge/Check/Checks/ChannelTokenPathCheck.php' => ['$tokenPath'],
        'Bridge/Check/Checks/GitHubTokenFileCheck.php' => ['$path'],
        'Bridge/Handlers/ChannelPushHandler.php' => ['$allowed'],
        'Bridge/Handlers/SpawnDetachedHandler.php' => ['$configured', '$candidates'],
        'Bridge/IdleNudge/FleetSnapshotReader.php' => ['$path'],
        'Bridge/Support/ChannelToken.php' => ['$path'],
        'Bridge/Support/FileContents.php' => ['$path'],
        'Bridge/Support/CoordConfigFile.php' => ['$path'],
        'Bridge/Support/CoordConfigPath.php' => ['$path'],
        'Bridge/Support/SecretFile.php' => ['$path'],
        'Bridge/Support/UntrustedPathContents.php' => ['$path'],
        'Bridge/Tools/BoardToolAgentResolver.php' => ['$path'],
        'Bridge/Writeback/GitHubTokenResolver.php' => ['$path', '$override'],
        'Bridge/Writeback/WritebackAlertNotifier.php::validateSocketPath' => ['$path'],
        'Console/Commands/Bridge/ProvisionToolsCommand.php::handle' => ['$path'],
    ];

    /**
     * Every path-valued `.env` key `config/bridge.php` reads → where its value is printed, or why
     * this census does not cover it. Ambient variables read by `getenv()` (`$COORD_CONFIG`, read by
     * `CoordConfigPath`; `BRIDGE_TOOLS_SSH_KEY`) are not in `config/bridge.php`: the first is a
     * {@see self::CARRIERS} entry, the second is never interpolated by the bridge — `ssh` prints it
     * in its own stderr, which is relayed text (see the class docblock).
     *
     * @var array<string, string>
     */
    private const SETTINGS = [
        'BRIDGE_COORD_CONFIG_PATH' => 'CoordConfigFile, CoordConfigPath, SeatKanbanUser, AgentKanbanUserRosterCheck',
        'BRIDGE_COORD_CREDENTIALS_PATH' => 'CoordCredentialStore, GitHubTokenResolver',
        'BRIDGE_GITHUB_TOKEN_PATH' => 'GitHubTokenResolver, GitHubTokenFileCheck, and the file-read primitives',
        'BRIDGE_IDLE_NUDGE_TOKEN_PATH' => 'IdleNudgeConfig->tokenPath (global), FleetSnapshotReader',
        'BRIDGE_SPAWN_SETSID_PATH' => 'SpawnDetachedHandler',
        'BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR' => 'ChannelPushHandler — printed as itself, never as the root of a derived path',
        'BRIDGE_DIR' => self::DERIVED,
        'BRIDGE_CONFIG_DIR' => self::DERIVED,
        'BRIDGE_SECRET_DIR' => self::DERIVED,
        'BRIDGE_STATE_DIR' => self::DERIVED,
    ];

    private const DERIVED = 'NOT COVERED: a directory setting is the root of every state, config and secret file path the bridge composes, and those reach messages through many variables and through PHP\'s own warning text — docs/config-schema.md § A token pasted where a path belongs';

    /** The calls whose arguments are message text. */
    private const FORMATTERS = ['sprintf', 'implode', 'join', 'permissionsfault'];

    public function test_no_message_in_app_prints_a_path_setting_raw(): void
    {
        $sites = [];
        foreach (SourceScan::appFiles() as $path) {
            $file = SourceScan::relativeToApp($path);
            $sites += self::sitesIn((string) file_get_contents($path), $file, self::derivedKeys(self::readerSources()));
        }

        $this->assertSame([], $sites, "these messages print a path setting's raw value; pass it through PastedSecretShape::displayPathSetting() first:\n".print_r($sites, true));
    }

    public function test_every_declared_carrier_still_occurs_in_its_file(): void
    {
        foreach (self::CARRIERS as $key => $carriers) {
            $file = explode('::', $key)[0];
            $path = base_path('app/'.$file);
            $this->assertFileExists($path, "CARRIERS names {$file}, which no longer exists — re-derive where the setting it carried is printed");
            $chains = self::chainsIn((string) file_get_contents($path));
            foreach ($carriers as $carrier) {
                $this->assertContains($carrier, $chains, "CARRIERS declares {$carrier} in {$file}, and the file no longer has it — a renamed carrier would leave this census guarding nothing there");
            }
        }
    }

    public function test_every_path_setting_in_config_is_declared(): void
    {
        preg_match_all("/env\\('([A-Z0-9_]+_(?:PATH|DIR))'/", (string) file_get_contents(base_path('config/bridge.php')), $m);
        $read = array_values(array_unique($m[1]));
        sort($read);
        $declared = array_keys(self::SETTINGS);
        sort($declared);

        $this->assertNotSame([], $read, 'the config/bridge.php derivation found no path settings — the derivation broke, not the config');
        $this->assertSame($declared, $read, 'config/bridge.php reads a path setting SETTINGS does not declare (or SETTINGS names one it no longer reads): say where its value is printed');
    }

    /** The control: the predicate finds each message shape, and passes the display-wrapped form. */
    public function test_the_predicate_discriminates(): void
    {
        $raw = <<<'PHP'
            <?php
            function a($cfg, $path) {
                throw new X("token at {$cfg->tokenPath} is missing");
                Log::warning('x', ['path' => $cfg->writeTokenPath]);
                $m = 'at '.$path;
                $n = sprintf('at %s', $path);
                $o = "at {$agent->tokenPath($dir, 'p')}";
                $p = "at $path now";
                $q = "at {$cfg['token_path']}";
                $r = sprintf('%s', $x['socket']);
                $u .= $path;
                $v = $path.' is bad';
                $w = <<<EOT
                    at $path
                    EOT;
                $x1 = implode(',', $path);
                $x2 = join(',', $path);
                throw UnreadableFileException::permissionsFault($path, 'r');
                $y = "at {$file->path}";
            }
            PHP;
        $wrapped = <<<'PHP'
            <?php
            function a($cfg, $path) {
                $shown = PastedSecretShape::displayPathSetting($cfg->tokenPath);
                throw new X("token at {$shown} is missing");
                $m = 'at '.PastedSecretShape::displayPathSetting($path);
                if (is_file($path)) { return $cfg->tokenPath; }
                $o = PastedSecretShape::displayPathSetting($agent->tokenPath($dir, 'p'));
                $q = 'at '.PastedSecretShape::displayPathSetting($cfg['token_path']);
                $r = sprintf('%s', PastedSecretShape::displayPathSetting($x['socket']));
                $t = $cfg['token_path'] ?? null;
                $u .= PastedSecretShape::displayPathSetting($path);
                $v = PastedSecretShape::displayPathSetting($path).' is bad';
                $w = <<<EOT
                    at {$shown}
                    EOT;
                $z = $path;
                $x1 = implode(',', PastedSecretShape::displayPathSetting($path));
                $x2 = join(',', PastedSecretShape::displayPathSetting($path));
                throw UnreadableFileException::permissionsFault(PastedSecretShape::displayPathSetting($path), 'r');
                $y = 'at '.PastedSecretShape::displayPathSetting($file->path);
            }
            PHP;

        $at = fn (array $tokens, int $i, int $scopeStart): ?string => self::siteAt($tokens, $i, $scopeStart, ['$path']);

        $this->assertCount(15, SourceScan::sites($raw, 'fixture.php', $at));
        $this->assertSame([], SourceScan::sites($wrapped, 'fixture.php', $at));
    }

    /**
     * The key derivation finds the settings the readers read today (a positive control — an empty
     * result would make every property check below vacuous) and a key added to a reader, which is
     * then a carrier: printed raw it is a site, with nobody having declared it.
     */
    public function test_a_new_reader_key_becomes_a_carrier_without_being_declared(): void
    {
        $keys = self::derivedKeys(self::readerSources());
        foreach (['token_path', 'write_token_path', 'socket', 'server_path'] as $known) {
            $this->assertContains($known, $keys, "the derivation no longer finds {$known} in the config readers");
        }

        $this->assertSame(['scratch_ake_path'], self::derivedKeys(['f' => "<?php if (array_key_exists('scratch_ake_path', \$m)) {}"]), 'the array_key_exists alternative of the key derivation');

        $scratch = self::readerSources();
        $scratch['Bridge/Support/AgentConfig.php'] .= "\n\$x = \$channel['scratch_new_path'] ?? null;\n";
        $derived = self::derivedKeys($scratch);
        $this->assertSame(['scratch_new_path'], array_values(array_diff($derived, $keys)));

        $at = fn (array $tokens, int $i, int $scopeStart): ?string => self::siteAt($tokens, $i, $scopeStart, [], self::propertiesFor($derived));
        $fixture = '<?php function a($cfg) { throw new X("bad {$cfg->scratchNewPath}"); }';
        $this->assertCount(1, SourceScan::sites($fixture, 'fixture.php', $at));
        $this->assertSame([], SourceScan::sites($fixture, 'fixture.php', fn (array $tokens, int $i, int $scopeStart): ?string => self::siteAt($tokens, $i, $scopeStart, [], self::propertiesFor($keys))), 'control: without the new key the same line is not a site');
    }

    /**
     * The sites in $source, read as app/$file, with $keys the derived path keys.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function sitesIn(string $source, string $file, array $keys): array
    {
        $properties = self::propertiesFor($keys);

        return SourceScan::sites($source, $file, fn (array $tokens, int $i, int $scopeStart): ?string => self::siteAt(
            $tokens,
            $i,
            $scopeStart,
            array_merge(self::carriersAt($file, $tokens, $scopeStart), in_array($file, self::KEY_READERS, true) ? self::localCarriers($tokens, $scopeStart, $i, $keys) : []),
            $properties,
            $keys,
        ));
    }

    /**
     * The control for the local-variable derivation: a key read into a local of a reader, a copy
     * of that local, and a raw message naming either, added to the real `AgentConfig::resolveChannel`
     * source, are sites with nothing declared; the same source without them is clean.
     */
    public function test_a_local_a_reader_copies_a_new_key_into_is_a_carrier_without_being_declared(): void
    {
        $file = 'Bridge/Support/AgentConfig.php';
        $sources = self::readerSources();
        $anchor = "        \$socket = \$channel['socket'] ?? null;\n";
        $this->assertSame(1, substr_count($sources[$file], $anchor), 'the mutation anchor moved in AgentConfig::resolveChannel — re-derive the control, do not delete it');

        $keys = self::derivedKeys($sources);
        $this->assertSame([], self::sitesIn($sources[$file], $file, $keys), 'control: the unmutated reader has no site');

        $cases = [
            'read' => "        \$logPath = \$channel['log_path'] ?? null;\n        throw new ConfigException(\"channel.log_path '{\$logPath}' is bad\");\n",
            'copy' => "        \$logPath = \$channel['log_path'] ?? null;\n        \$shown = (string) \$logPath;\n        throw new ConfigException('channel.log_path '.\$shown.' is bad');\n",
        ];
        foreach ($cases as $name => $added) {
            $mutated = $sources;
            $mutated[$file] = str_replace($anchor, $anchor.$added, $sources[$file]);
            $scratchKeys = self::derivedKeys($mutated);
            $this->assertContains('log_path', $scratchKeys);
            $sites = self::sitesIn($mutated[$file], $file, $scratchKeys);
            $this->assertNotSame([], $sites, "the {$name} shape must red: a new key read into a local and printed raw");
            $this->assertStringContainsString('resolveChannel', (string) array_key_first($sites));
        }

        // The display form of a key's value is not a carrier: printing it is the rule, not a leak.
        $shown = str_replace($anchor, $anchor."        \$shown = PastedSecretShape::displayPathSetting(\$channel['log_path'] ?? '');\n        throw new ConfigException(\"channel.log_path {\$shown} is bad\");\n", $sources[$file]);
        $this->assertSame([], self::sitesIn($shown, $file, self::derivedKeys(array_merge($sources, [$file => $shown]))), 'a local holding the displayPathSetting() form is not a carrier');

        // Only the WHOLE right-hand side being the display call exempts: `display(\$x) ?: \$x` is raw.
        $elvis = str_replace($anchor, $anchor."        \$m = PastedSecretShape::displayPathSetting(\$channel['log_path'] ?? '') ?: \$channel['log_path'];\n        throw new ConfigException(\"channel.log_path {\$m} is bad\");\n", $sources[$file]);
        $this->assertNotSame([], self::sitesIn($elvis, $file, self::derivedKeys(array_merge($sources, [$file => $elvis]))), 'a display call that is only part of the right-hand side must not exempt the local');

        // A copy that is only compared, never printed, is not a site.
        $quiet = str_replace($anchor, $anchor."        \$logPath = \$channel['log_path'] ?? null;\n        \$present = \$logPath !== null;\n", $sources[$file]);
        $this->assertSame([], self::sitesIn($quiet, $file, self::derivedKeys(array_merge($sources, [$file => $quiet]))));
    }

    /** @return array<string, string> */
    private static function readerSources(): array
    {
        $sources = [];
        foreach (self::KEY_READERS as $file) {
            $sources[$file] = (string) file_get_contents(base_path('app/'.$file));
        }

        return $sources;
    }

    /**
     * Every key the readers read that is path-valued: `…path` or `socket`, as `['key']` or
     * `array_key_exists('key', …)`.
     *
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private static function derivedKeys(array $sources): array
    {
        $keys = [];
        foreach ($sources as $source) {
            preg_match_all("/(?:\\[|array_key_exists\\()\\s*'([a-z0-9_]*(?:path|socket))'/", $source, $m);
            $keys = array_merge($keys, $m[1]);
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * The properties of any receiver that hold a path setting's value: `path` — `CoordConfigFile` /
     * `CoordCredentialStore` / `TokenResolution` hold the value as read there (the first two print
     * it through their `shownPath()`) — and the camelCase of every derived key (`token_path` →
     * `tokenPath`, `socket` → `socket`). The camelCase is the convention the config value objects
     * follow; a key a value object stores under another name is outside this derivation.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function propertiesFor(array $keys): array
    {
        return array_values(array_unique(array_merge(['path'], array_map(fn (string $k): string => lcfirst(str_replace('_', '', ucwords($k, '_'))), $keys))));
    }

    /** @return list<string> */
    private static function globalKeys(): array
    {
        return self::$globalKeys ??= self::derivedKeys(self::readerSources());
    }

    /**
     * In a reader, the locals that hold a derived key's value at $upTo, within the function that
     * began at $scopeStart: a local assigned from a read of a derived key (`$v = $x['key']`), and a local assigned from an expression naming one already
     * found (`$s = (string) $v`). Assignments only, in source order; a local that is merely
     * compared or passed is not a site until it stands in message position.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function localCarriers(array $tokens, int $scopeStart, int $upTo, array $keys): array
    {
        $locals = [];
        $count = count($tokens);
        for ($k = $scopeStart; $k < $upTo; $k++) {
            if ($tokens[$k][0] !== T_VARIABLE || ($tokens[$k + 1][1] ?? null) !== '=' || ! in_array($tokens[$k - 1][1] ?? ';', [';', '{', '}'], true)) {
                continue;
            }
            $first = $k + 2;
            while (in_array($tokens[$first][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_DOUBLE_COLON], true)) {
                $first++;
            }
            if (($tokens[$first][1] ?? null) === '(' && ($tokens[$first - 1][1] ?? null) === 'displayPathSetting' && ($tokens[self::matching($tokens, $first) + 1][1] ?? null) === ';') {
                continue;
            }
            $depth = 0;
            for ($j = $k + 2; $j < $count; $j++) {
                $t = $tokens[$j];
                if (in_array($t[1], ['(', '['], true)) {
                    $depth++;
                } elseif (in_array($t[1], [')', ']'], true)) {
                    $depth--;
                } elseif ($t[1] === ';' && $depth <= 0) {
                    break;
                }
                $keyRead = in_array(trim($t[1], '\'"'), $keys, true) && $t[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$j - 1][1] ?? null) === '[';
                if ($keyRead || ($t[0] === T_VARIABLE && in_array($t[1], $locals, true))) {
                    $locals[] = $tokens[$k][1];
                    break;
                }
            }
        }

        return array_values(array_unique($locals));
    }

    /** @return list<string> */
    private static function globalProperties(): array
    {
        return self::$globalProperties ??= self::propertiesFor(self::derivedKeys(self::readerSources()));
    }

    /**
     * The carriers declared for $file, plus those declared for the function the walk is in.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return list<string>
     */
    private static function carriersAt(string $file, array $tokens, int $scopeStart): array
    {
        $function = ($tokens[$scopeStart][0] ?? null) === T_FUNCTION ? $tokens[$scopeStart + 1][1] : SourceScan::FILE_SCOPE;

        return array_merge(self::CARRIERS[$file] ?? [], self::CARRIERS[$file.'::'.$function] ?? []);
    }

    /**
     * The carrier at $i when it stands in message position, as `<chain> (<line text>)`; else null.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  list<string>  $carriers  chains (`$path`, `$this->path`) or bare property names
     * @param  list<string>|null  $properties  the path-holding properties; default {@see self::globalProperties()}
     * @param  list<string>|null  $keys  the derived path keys; `$var['key']` is a carrier; default {@see self::derivedKeys()}
     */
    private static function siteAt(array $tokens, int $i, int $scopeStart, array $carriers, ?array $properties = null, ?array $keys = null): ?string
    {
        if ($tokens[$i][0] !== T_VARIABLE) {
            return null;
        }
        [$chain, $end, $isCarrier] = self::chainAt($tokens, $i, $carriers, $properties ?? self::globalProperties(), $keys ?? self::globalKeys());
        if (! $isCarrier) {
            return null;
        }

        $prev = $tokens[$i - 1] ?? [null, ''];
        $next = $tokens[$end + 1] ?? [null, ''];
        $inMessage = self::insideString($tokens, $scopeStart, $i)
            || $prev[1] === '.' || $prev[0] === T_CONCAT_EQUAL || $next[1] === '.'
            || $prev[0] === T_DOUBLE_ARROW
            || in_array(self::enclosingCall($tokens, $i), self::FORMATTERS, true);

        return $inMessage ? $chain : null;
    }

    /**
     * The variable chain starting at $i, the index of its last token, and whether it is a carrier:
     * one of $carriers, a chain ending in a {@see self::GLOBAL_PROPERTIES} property, or a call of a
     * {@see self::GLOBAL_METHODS} method. A chain that is called, indexed or dereferenced further is
     * not one — its value is something else.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  list<string>  $carriers
     * @param  list<string>  $properties
     * @param  list<string>  $keys
     * @return array{0: string, 1: int, 2: bool}
     */
    private static function chainAt(array $tokens, int $i, array $carriers, array $properties, array $keys = []): array
    {
        $chain = $tokens[$i][1];
        $last = null;
        $j = $i;
        while (in_array($tokens[$j + 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && ($tokens[$j + 2][0] ?? null) === T_STRING) {
            $name = $tokens[$j + 2][1];
            if (($tokens[$j + 3][1] ?? null) === '(') {
                return in_array($name, self::GLOBAL_METHODS, true)
                    ? [$chain.'->'.$name.'()', self::matching($tokens, $j + 3), true]
                    : [$chain, $j, false];
            }
            $chain .= '->'.$name;
            $last = $name;
            $j += 2;
        }
        if (($tokens[$j + 1][1] ?? null) === '[' && ($tokens[$j + 3][1] ?? null) === ']' && in_array(trim($tokens[$j + 2][1] ?? '', '\'"'), $keys, true)) {
            return [$chain."['".trim($tokens[$j + 2][1], '\'"')."']", $j + 3, true];
        }
        if (in_array($tokens[$j + 1][1] ?? null, ['[', '('], true) || ($tokens[$j + 1][0] ?? null) === T_DOUBLE_COLON) {
            return [$chain, $j, false];
        }

        return [$chain, $j, in_array($chain, $carriers, true) || in_array($last, $properties, true)];
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open; $k < count($tokens); $k++) {
            $depth += match ($tokens[$k][1]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $k;
            }
        }

        return count($tokens) - 1;
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function insideString(array $tokens, int $from, int $to): bool
    {
        $open = false;
        for ($k = $from; $k < $to; $k++) {
            if ($tokens[$k][0] === '"' || $tokens[$k][0] === T_START_HEREDOC || $tokens[$k][0] === T_END_HEREDOC) {
                $open = ! $open;
            }
        }

        return $open;
    }

    /**
     * The name of the call whose argument list $i sits in directly, or null.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function enclosingCall(array $tokens, int $i): ?string
    {
        $depth = 0;
        for ($k = $i - 1; $k >= 0; $k--) {
            $t = $tokens[$k][1];
            if ($t === ')' || $t === ']') {
                $depth++;
            } elseif ($t === '(' || $t === '[') {
                if ($depth === 0) {
                    return $t === '(' && ($tokens[$k - 1][0] ?? null) === T_STRING ? strtolower($tokens[$k - 1][1]) : null;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($t, [';', '{', '}'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Every variable chain in $source, as {@see chainAt()} spells it.
     *
     * @return list<string>
     */
    private static function chainsIn(string $source): array
    {
        $tokens = SourceScan::significantTokens($source);
        $chains = [];
        foreach ($tokens as $i => $token) {
            if ($token[0] === T_VARIABLE) {
                $chain = $token[1];
                $j = $i;
                while (($tokens[$j + 1][0] ?? null) === T_OBJECT_OPERATOR && ($tokens[$j + 2][0] ?? null) === T_STRING) {
                    $chain .= '->'.$tokens[$j + 2][1];
                    $j += 2;
                    $chains[] = $chain;
                }
                $chains[] = $token[1];
            }
        }

        return array_values(array_unique($chains));
    }
}
