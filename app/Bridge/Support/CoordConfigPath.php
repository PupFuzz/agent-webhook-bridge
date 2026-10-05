<?php

namespace App\Bridge\Support;

/**
 * WHERE `bridge:check`'s WRITEBACK cross-config compares find the coordination project's
 * `coordination.config.json` — the one resolution those CLI readers share (card#10869 hoisted it
 * out of the checks that each spelled it inline).
 *
 * The per-install setting `bridge.coord_config_path` (`BRIDGE_COORD_CONFIG_PATH` in the install's
 * `.env`) first, then the ambient `$COORD_CONFIG` read LIVE through `getenv()`. `getenv()` rather
 * than `env()` is load-bearing: `php artisan optimize` caches `config/` and freezes every `env()`
 * at deploy time, and the frozen value wins over the live one, so an ambient path resolved in
 * `config/bridge.php` would be whatever the DEPLOYING shell had — usually nothing — forever.
 * `getenv()` is cache-immune.
 *
 * ⛔ THE AMBIENT FALLBACK IS CLI-ONLY, AND THE ROSTER DOES NOT USE IT (card#11172 / DL-450). The
 * receiver's PHP-FPM environment has no `$COORD_CONFIG`, so the runtime roster read
 * ({@see CoordConfigFile::configured}) takes the SETTING alone, and so does `bridge:check`'s roster
 * leg — a leg that measured a file the runtime never opens would be a false pass. Only the
 * writeback compares, which have no runtime half, resolve through here.
 *
 * No `~/.config/coord/...` default, deliberately unlike the toolkit's `kb_coord_config_path`:
 * `bridge:check` often runs as a different OS user from the seat, and a home-relative default
 * would answer about that user's file, not the seat's.
 */
final class CoordConfigPath
{
    public static function resolve(): ?string
    {
        $path = config('bridge.coord_config_path');
        if (is_string($path) && $path !== '') {
            return $path;
        }
        $ambient = getenv('COORD_CONFIG');

        return is_string($ambient) && $ambient !== '' ? $ambient : null;
    }

    /** Why a load of $path answered nothing — the clause every CANNOT-VERIFY finding carries. */
    public static function unreadableClause(?string $path): string
    {
        return $path === null
            ? '$COORD_CONFIG is not set'
            : 'the coordination config at '.PastedSecretShape::displayPathSetting($path).' is absent, unreadable, or malformed';
    }
}
