<?php

namespace Tests\Unit\ClientUpdate;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ClientPackFixture;

/**
 * The bridge reads the DL-428 manifest strictly (DL-430): every field decides what a seat
 * installs, so anything but exactly that format is refused, naming the field.
 */
class ClientPackManifestTest extends TestCase
{
    public function test_the_builder_shape_parses(): void
    {
        $fixture = new ClientPackFixture;
        $m = ClientPackManifest::parse($fixture->manifest);

        $this->assertSame('0.91.0', $m->bridgeRelease);
        $this->assertSame('0.9.28', $m->clientVersion);
        $this->assertSame($fixture->packName(), $m->packFile);
        $this->assertSame(hash('sha256', $fixture->pack), $m->packSha256);
        $this->assertSame(strlen($fixture->pack), $m->packSize);
        $this->assertSame('>=20', $m->nodeEngines);
    }

    /**
     * The file names are the builder's, so the manifest and the release assets agree on them.
     */
    public function test_file_names_are_the_builders(): void
    {
        $this->assertSame('client-pack-v1.2.3.tar.gz', ClientPackManifest::packFileName('1.2.3'));
        $this->assertSame('client-pack-v1.2.3.manifest.json', ClientPackManifest::manifestFileName('1.2.3'));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('refusedManifests')]
    public function test_a_manifest_off_the_format_is_refused_naming_the_field(array $override, string $names): void
    {
        $this->expectException(ClientPackRefused::class);
        $this->expectExceptionMessage($names);

        ClientPackManifest::parse((new ClientPackFixture(override: $override))->manifest);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedManifests(): array
    {
        $pack = ['file' => 'client-pack-v0.91.0.tar.gz', 'sha256' => str_repeat('c', 64), 'size' => 5];

        return [
            'wrong schema' => [['schema' => 2], 'schema 1'],
            'wrong kind' => [['kind' => 'something-else'], 'schema 1'],
            'v-prefixed release' => [['bridge_release' => 'v0.91.0'], '`bridge_release`'],
            'pre-release' => [['bridge_release' => '0.91.0-rc1'], '`bridge_release`'],
            'release with a trailing newline' => [['bridge_release' => "0.91.0\n"], '`bridge_release`'],
            'v-prefixed client version' => [['client_version' => 'v0.9.28'], '`client_version`'],
            'short commit' => [['minted_from_commit' => 'abc123'], '`minted_from_commit`'],
            'upper-case digest' => [['files_json_sha256' => str_repeat('B', 64)], '`files_json_sha256`'],
            'no pack object' => [['pack' => 'client-pack-v0.91.0.tar.gz'], '`pack` object'],
            'pack file names another release' => [['pack' => ['file' => 'client-pack-v0.90.0.tar.gz'] + $pack], 'names pack file'],
            'zero size' => [['pack' => ['size' => 0] + $pack], '`pack.size`'],
            'string size' => [['pack' => ['size' => '5'] + $pack], '`pack.size`'],
            'short pack sha256' => [['pack' => ['sha256' => 'abc'] + $pack], '`pack.sha256`'],
            'empty engines' => [['node_engines' => ' '], '`node_engines`'],
        ];
    }

    public function test_bytes_that_are_not_a_json_object_are_refused(): void
    {
        foreach (['', 'not json', '[1,2]', '"x"'] as $bytes) {
            try {
                ClientPackManifest::parse($bytes);
                $this->fail('parsed: '.$bytes);
            } catch (ClientPackRefused $e) {
                $this->assertStringContainsString('manifest', $e->getMessage());
            }
        }
    }
}
