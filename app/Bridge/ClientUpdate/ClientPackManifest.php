<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\RedactedErrorText;
use JsonException;

/**
 * The manifest `bin/build-client-pack.py` writes beside a pack (DL-428), read strictly. The
 * builder's docstring owns the format; this class refuses anything that is not exactly that
 * format, because every field here decides what a seat installs.
 *
 * Every version is bare `X.Y.Z` (design review r3-M5): the shared comparator reads `v1.0.0` as
 * 0.0.0, so a prefixed or pre-release value never reaches it.
 */
final class ClientPackManifest
{
    public const SCHEMA = 1;

    public const KIND = 'agent-webhook-bridge-client-pack';

    public const STRICT_VERSION = '/\A[0-9]+\.[0-9]+\.[0-9]+\z/';

    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    private const COMMIT = '/\A[0-9a-f]{40}\z/';

    private function __construct(
        public readonly string $bridgeRelease,
        public readonly string $mintedFromCommit,
        public readonly string $clientVersion,
        public readonly string $nodeEngines,
        public readonly string $packFile,
        public readonly string $packSha256,
        public readonly int $packSize,
        public readonly string $filesJsonSha256,
    ) {}

    public static function packFileName(string $bridgeRelease): string
    {
        return "client-pack-v{$bridgeRelease}.tar.gz";
    }

    public static function manifestFileName(string $bridgeRelease): string
    {
        return "client-pack-v{$bridgeRelease}.manifest.json";
    }

    /**
     * @throws ClientPackRefused
     */
    public static function parse(string $bytes): self
    {
        try {
            $m = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ClientPackRefused('the client pack manifest is not valid JSON ('.RedactedErrorText::of($e).')');
        }
        if (! is_array($m) || array_is_list($m)) {
            throw new ClientPackRefused('the client pack manifest is not a JSON object');
        }
        if (($m['schema'] ?? null) !== self::SCHEMA || ($m['kind'] ?? null) !== self::KIND) {
            throw new ClientPackRefused('the client pack manifest is not schema '.self::SCHEMA.' `'.self::KIND.'`');
        }

        $release = self::matching($m, 'bridge_release', self::STRICT_VERSION, 'bare X.Y.Z');
        $pack = $m['pack'] ?? null;
        if (! is_array($pack) || array_is_list($pack)) {
            throw new ClientPackRefused('the client pack manifest has no `pack` object');
        }
        $file = $pack['file'] ?? null;
        if ($file !== self::packFileName($release)) {
            throw new ClientPackRefused('the client pack manifest names pack file '.json_encode($file).', not `'.self::packFileName($release).'` for release '.$release);
        }
        $size = $pack['size'] ?? null;
        if (! is_int($size) || $size < 1) {
            throw new ClientPackRefused('the client pack manifest\'s `pack.size` is not a positive integer');
        }
        $engines = $m['node_engines'] ?? null;
        if (! is_string($engines) || trim($engines) === '') {
            throw new ClientPackRefused('the client pack manifest\'s `node_engines` is not a non-empty string');
        }

        return new self(
            $release,
            self::matching($m, 'minted_from_commit', self::COMMIT, 'a 40-hex commit id'),
            self::matching($m, 'client_version', self::STRICT_VERSION, 'bare X.Y.Z'),
            $engines,
            $file,
            self::matching($pack, 'sha256', self::SHA256, '64 lower-case hex', 'pack.sha256'),
            $size,
            self::matching($m, 'files_json_sha256', self::SHA256, '64 lower-case hex'),
        );
    }

    /**
     * @param  array<mixed>  $from
     */
    private static function matching(array $from, string $key, string $pattern, string $expected, ?string $label = null): string
    {
        $value = $from[$key] ?? null;
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new ClientPackRefused('the client pack manifest\'s `'.($label ?? $key).'` is '.json_encode($value).', not '.$expected);
        }

        return $value;
    }
}
