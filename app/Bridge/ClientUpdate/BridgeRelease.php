<?php

namespace App\Bridge\ClientUpdate;

/**
 * The bridge release a checkout IS: its root `VERSION` file, read as bare `X.Y.Z`
 * ({@see ClientPackManifest::STRICT_VERSION}) — the one release a client pack is published for
 * (DL-430 Decision 1) and the one `bridge:check` compares the published pack against.
 */
final class BridgeRelease
{
    /**
     * @return ?string the release, or null when the file is missing, unreadable or not bare X.Y.Z
     */
    public static function of(string $versionFile): ?string
    {
        $raw = @file_get_contents($versionFile);
        if (! is_string($raw)) {
            return null;
        }
        $version = trim($raw);

        return preg_match(ClientPackManifest::STRICT_VERSION, $version) === 1 ? $version : null;
    }
}
