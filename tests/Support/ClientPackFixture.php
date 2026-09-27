<?php

namespace Tests\Support;

/**
 * A client pack and its manifest as `bin/build-client-pack.py` shapes them (DL-428), for the
 * bridge-side tests of DL-430. The pack bytes are opaque here — nothing bridge-side opens the
 * archive — so any bytes with a matching size and sha256 stand in for one.
 */
final class ClientPackFixture
{
    public readonly string $pack;

    public readonly string $manifest;

    /**
     * @param  array<string, mixed>  $override  top-level manifest keys to replace, for refusal cases
     */
    public function __construct(
        public readonly string $release = '0.91.0',
        public readonly string $clientVersion = '0.9.28',
        string $packBytes = "pack bytes\x00\x01 for a test",
        array $override = [],
    ) {
        $this->pack = $packBytes;
        $this->manifest = (string) json_encode(array_merge([
            'schema' => 1,
            'kind' => 'agent-webhook-bridge-client-pack',
            'bridge_release' => $release,
            'minted_from_commit' => str_repeat('a', 40),
            'client_version' => $clientVersion,
            'node_engines' => '>=20',
            'pack' => ['file' => "client-pack-v{$release}.tar.gz", 'sha256' => hash('sha256', $packBytes), 'size' => strlen($packBytes)],
            'files_json_sha256' => str_repeat('b', 64),
        ], $override), JSON_PRETTY_PRINT)."\n";
    }

    public function packName(): string
    {
        return "client-pack-v{$this->release}.tar.gz";
    }

    public function manifestName(): string
    {
        return "client-pack-v{$this->release}.manifest.json";
    }

    public function sums(): string
    {
        return hash('sha256', $this->pack).'  '.$this->packName()."\n"
            .hash('sha256', $this->manifest).'  '.$this->manifestName()."\n";
    }
}
