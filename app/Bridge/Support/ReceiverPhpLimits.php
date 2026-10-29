<?php

namespace App\Bridge\Support;

/**
 * The PHP limits of the WEB process that serves the receiver, as that process itself reported them
 * on a webhook request (card#12576) — `bridge:check` runs under the CLI, whose ini is a different
 * file, so the CLI's own `ini_get()` would answer for the wrong process.
 *
 * ⭐ WHY THE RECEIVER REPORTS IT, rather than `bridge:check` reading an ini file or running
 * `php-fpm -i`: the value that applies to a delivery is the one in effect inside the request, after
 * every layer that can set it — the SAPI's php.ini, a pool's `php_admin_value` / `php_value`, a
 * vhost's `php_value`, a `.user.ini`. `ini_get()` inside the request is the only read that sees
 * the result of all of them; `php-fpm -i` reads the php.ini alone and misses a pool override.
 *
 * Written from a terminable middleware AFTER the response, and only when a value changed, so the
 * request path pays one small file read. A CLI process never writes it (a test, `bridge:replay`).
 * Bridge vocabulary only: the SAPI name, the PHP version, the loaded ini path and the raw ini
 * values — nothing from the request.
 */
final class ReceiverPhpLimits
{
    public const FILE = 'receiver-php-limits.json';

    /** The ini settings recorded. `upload_max_filesize` is absent on purpose: it bounds multipart FILE uploads, and a webhook is a raw JSON body (measured, card#12576). */
    public const SETTINGS = ['post_max_size'];

    public static function path(): string
    {
        return BridgePaths::stateDir().'/'.self::FILE;
    }

    /**
     * Record `$sapi`'s limits when they differ from the record.
     *
     * @param  array<string, string|false>  $ini  setting => `ini_get()` value
     */
    public static function observe(string $sapi, string $phpVersion, ?string $iniFile, array $ini): void
    {
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return;
        }
        $record = [
            'sapi' => $sapi,
            'php_version' => $phpVersion,
            'ini_file' => $iniFile,
            'ini' => array_map(static fn (string|false $v): ?string => $v === false ? null : $v, $ini),
        ];
        $current = self::read();
        if ($current !== null && array_diff_key($current, ['recorded_at' => true]) === $record) {
            return;
        }
        BridgePaths::ensureDir(BridgePaths::stateDir());
        BridgePaths::writeFileAtomic(self::path(), (string) json_encode($record + ['recorded_at' => gmdate('Y-m-d\TH:i:s\Z')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** Record this process's own limits. */
    public static function observeThisProcess(): void
    {
        $ini = [];
        foreach (self::SETTINGS as $setting) {
            $ini[$setting] = ini_get($setting);
        }
        $loaded = php_ini_loaded_file();
        self::observe(PHP_SAPI, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $loaded === false ? null : $loaded, $ini);
    }

    /**
     * The record, or null when there is none or it is not the shape this class writes.
     *
     * @return array{sapi: string, php_version: string, ini_file: ?string, ini: array<string, ?string>, recorded_at: string}|null
     */
    public static function read(): ?array
    {
        $raw = FileContents::read(self::path(), 'receiver PHP limits record');
        $data = $raw === null ? null : json_decode($raw, true);
        if (! is_array($data) || ! is_string($data['sapi'] ?? null) || ! is_string($data['php_version'] ?? null)
            || ! array_key_exists('ini_file', $data) || ! (is_string($data['ini_file']) || $data['ini_file'] === null)
            || ! is_array($data['ini'] ?? null) || ! is_string($data['recorded_at'] ?? null)) {
            return null;
        }
        $ini = [];
        foreach ($data['ini'] as $k => $v) {
            if (! is_string($k) || ! (is_string($v) || $v === null)) {
                return null;
            }
            $ini[$k] = $v;
        }

        return ['sapi' => $data['sapi'], 'php_version' => $data['php_version'], 'ini_file' => $data['ini_file'], 'ini' => $ini, 'recorded_at' => $data['recorded_at']];
    }
}
