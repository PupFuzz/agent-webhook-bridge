<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Support\RedactedErrorText;
use JsonException;

/**
 * The one client pack this bridge currently serves: `published.json` in the pack store
 * ({@see ClientPackStore}). Written only after the pack and manifest it names are in place, so a
 * reader that finds this record finds its files.
 */
final class PublishedClientPack
{
    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    public function __construct(
        public readonly string $bridgeRelease,
        public readonly string $clientVersion,
        public readonly string $filesJsonSha256,
        public readonly string $packSha256,
        public readonly int $packSize,
        public readonly string $manifestSha256,
        public readonly string $publishedAt,
    ) {}

    public static function of(ClientPackManifest $manifest, string $manifestBytes, string $publishedAt): self
    {
        return new self(
            $manifest->bridgeRelease,
            $manifest->clientVersion,
            $manifest->filesJsonSha256,
            $manifest->packSha256,
            $manifest->packSize,
            hash('sha256', $manifestBytes),
            $publishedAt,
        );
    }

    /**
     * @throws ClientPackRefused a record that is not exactly what {@see toJson()} writes
     */
    public static function fromJson(string $bytes, string $path): self
    {
        try {
            $r = json_decode($bytes, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ClientPackRefused("the published client pack record {$path} is not valid JSON (".RedactedErrorText::of($e).')');
        }
        $ok = is_array($r)
            && is_string($r['bridge_release'] ?? null) && preg_match(ClientPackManifest::STRICT_VERSION, $r['bridge_release']) === 1
            && is_string($r['client_version'] ?? null) && preg_match(ClientPackManifest::STRICT_VERSION, $r['client_version']) === 1
            && is_string($r['files_json_sha256'] ?? null) && preg_match(self::SHA256, $r['files_json_sha256']) === 1
            && is_string($r['pack_sha256'] ?? null) && preg_match(self::SHA256, $r['pack_sha256']) === 1
            && is_int($r['pack_size'] ?? null) && $r['pack_size'] > 0
            && is_string($r['manifest_sha256'] ?? null) && preg_match(self::SHA256, $r['manifest_sha256']) === 1
            && is_string($r['published_at'] ?? null);
        if (! $ok) {
            throw new ClientPackRefused("the published client pack record {$path} is malformed");
        }

        return new self($r['bridge_release'], $r['client_version'], $r['files_json_sha256'], $r['pack_sha256'], $r['pack_size'], $r['manifest_sha256'], $r['published_at']);
    }

    public function toJson(): string
    {
        return json_encode([
            'bridge_release' => $this->bridgeRelease,
            'client_version' => $this->clientVersion,
            'files_json_sha256' => $this->filesJsonSha256,
            'pack_sha256' => $this->packSha256,
            'pack_size' => $this->packSize,
            'manifest_sha256' => $this->manifestSha256,
            'published_at' => $this->publishedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
