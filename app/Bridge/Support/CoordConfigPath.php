<?php

namespace App\Bridge\Support;

/**
 * WHERE `bridge:check` finds the coordination project's `coordination.config.json` — the one
 * resolution every CLI reader of that file shares (card#10869 hoisted it out of the two checks
 * that each spelled it inline).
 *
 * The per-install override `bridge.writeback.coord_config_path` (`BRIDGE_COORD_CONFIG_PATH` in
 * the install's `.env`) first, then the ambient `$COORD_CONFIG` read LIVE through `getenv()`.
 * `getenv()` rather than `env()` is load-bearing: `php artisan optimize` caches `config/` and
 * freezes every `env()` at deploy time, and the frozen value wins over the live one, so an
 * ambient path resolved in `config/bridge.php` would be whatever the DEPLOYING shell had —
 * usually nothing — forever. `getenv()` is cache-immune.
 *
 * ⛔ CLI-ONLY. The receiver's PHP-FPM environment has no `$COORD_CONFIG` and does not run as the
 * operator (`config/bridge.php` § writeback `coord_config_path`), which is why every reader of
 * this file is a `bridge:check` leg and nothing on the request path calls this.
 *
 * No `~/.config/coord/...` default, deliberately unlike the toolkit's `kb_coord_config_path`:
 * `bridge:check` often runs as a different OS user from the seat, and a home-relative default
 * would answer about that user's file, not the seat's.
 */
final class CoordConfigPath
{
    public static function resolve(): ?string
    {
        $path = config('bridge.writeback.coord_config_path');
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
            : "the coordination config at {$path} is absent, unreadable, or malformed";
    }
}
