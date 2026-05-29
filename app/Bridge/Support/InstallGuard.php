<?php

namespace App\Bridge\Support;

/**
 * Dev/prod crosstalk guard (DL-001). When an install opts into the suffix
 * convention (BRIDGE_INSTALL_SUFFIX = -prod or -dev), the configured database
 * name MUST contain the matching _prod / _dev marker — otherwise a -dev install
 * has been pointed at the prod DB (or vice versa) and would cross streams.
 *
 * Returns the error message on a mismatch, or null when OK / not opted in.
 * Installs at the default (no suffix) skip the check entirely (back-compat).
 */
final class InstallGuard
{
    public static function dsnCrosstalk(): ?string
    {
        $suffix = (string) config('bridge.install_suffix');
        if ($suffix !== '-prod' && $suffix !== '-dev') {
            return null;   // not opted in
        }

        $expected = str_replace('-', '_', $suffix);   // _prod / _dev
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        if (! str_contains($database, $expected)) {
            return sprintf(
                "DSN safety check failed: BRIDGE_INSTALL_SUFFIX is '%s' but the database name '%s' "
                ."does not contain '%s'. Refusing to prevent dev/prod crosstalk. "
                .'Fix DB_DATABASE in .env, or unset BRIDGE_INSTALL_SUFFIX to skip this check.',
                $suffix,
                $database,
                $expected,
            );
        }

        return null;
    }
}
