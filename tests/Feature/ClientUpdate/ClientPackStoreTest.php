<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackRefused;
use App\Bridge\ClientUpdate\ClientPackStore;
use Illuminate\Support\Facades\File;
use Tests\Support\ClientPackFixture;
use Tests\TestCase;

/**
 * The published-pack store (DL-430): publication never moves down, one release never carries two
 * packs, and a stored file that no longer matches its publication record is never served.
 */
class ClientPackStoreTest extends TestCase
{
    private string $dir;

    private ClientPackStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/client-pack-store-'.uniqid();
        config(['bridge.state_dir' => $this->dir]);
        $this->store = new ClientPackStore;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function publish(ClientPackFixture $f): bool
    {
        return $this->store->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-27T00:00:00Z');
    }

    public function test_nothing_is_published_on_a_fresh_install(): void
    {
        $this->assertNull($this->store->published());
    }

    public function test_a_published_pack_is_recorded_and_served_as_published(): void
    {
        $f = new ClientPackFixture;

        $this->assertTrue($this->publish($f));

        $published = $this->store->published();
        $this->assertNotNull($published);
        $this->assertSame('0.91.0', $published->bridgeRelease);
        $this->assertSame('0.9.28', $published->clientVersion);
        $this->assertSame(hash('sha256', $f->manifest), $published->manifestSha256);
        $this->assertSame($f->pack, $this->store->packBytes($published));
        $this->assertSame($f->manifest, $this->store->manifestBytes($published));
        $this->assertFileExists($this->dir.'/client-packs/0.91.0/'.$f->packName());
    }

    public function test_the_same_pack_again_changes_nothing(): void
    {
        $f = new ClientPackFixture;
        $this->publish($f);
        $before = (string) file_get_contents($this->store->publishedPath());

        $this->assertFalse($this->store->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-28T00:00:00Z'));
        $this->assertSame($before, file_get_contents($this->store->publishedPath()));
    }

    public function test_a_newer_release_replaces_the_published_one(): void
    {
        $this->publish(new ClientPackFixture('0.91.0'));
        $this->publish(new ClientPackFixture('0.92.0', packBytes: 'newer'));

        $this->assertSame('0.92.0', $this->store->published()?->bridgeRelease);
    }

    /**
     * The comparison is numeric per component, so 0.100.0 is above 0.99.0 and 1.0.0 above 0.91.0
     * (design review r3-M5: a string compare or a v-prefixed value would order these wrongly).
     */
    public function test_ordering_is_numeric_across_a_digit_count_and_a_major(): void
    {
        $this->publish(new ClientPackFixture('0.99.0'));
        $this->assertTrue($this->publish(new ClientPackFixture('0.100.0', packBytes: 'b')));
        $this->assertTrue($this->publish(new ClientPackFixture('1.0.0', packBytes: 'c')));
        $this->assertSame('1.0.0', $this->store->published()?->bridgeRelease);
    }

    public function test_a_lower_release_is_refused_and_the_published_one_stays(): void
    {
        $this->publish(new ClientPackFixture('0.92.0'));

        try {
            $this->publish(new ClientPackFixture('0.91.0', packBytes: 'older'));
            $this->fail('a lower release was published');
        } catch (ClientPackRefused $e) {
            $this->assertStringContainsString('below the published', $e->getMessage());
        }
        $this->assertSame('0.92.0', $this->store->published()?->bridgeRelease);
        $this->assertDirectoryDoesNotExist($this->dir.'/client-packs/0.91.0');
    }

    public function test_one_release_with_different_pack_bytes_is_refused_and_nothing_moves(): void
    {
        $first = new ClientPackFixture('0.91.0', packBytes: 'first');
        $this->publish($first);

        try {
            $this->publish(new ClientPackFixture('0.91.0', packBytes: 'second'));
            $this->fail('a second pack for one release was published');
        } catch (ClientPackRefused $e) {
            $this->assertStringContainsString('never carries two packs', $e->getMessage());
        }
        $published = $this->store->published();
        $this->assertNotNull($published);
        $this->assertSame($first->pack, $this->store->packBytes($published));
    }

    public function test_pack_bytes_that_do_not_match_the_manifest_are_refused(): void
    {
        $f = new ClientPackFixture;

        $this->expectException(ClientPackRefused::class);
        $this->store->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack.'x', '2026-09-27T00:00:00Z');
    }

    public function test_a_stored_pack_altered_after_publication_is_not_served(): void
    {
        $f = new ClientPackFixture;
        $this->publish($f);
        file_put_contents($this->dir.'/client-packs/0.91.0/'.$f->packName(), 'tampered bytes!!!!!!!!!!!!!');

        $published = $this->store->published();
        $this->assertNotNull($published);
        $this->expectException(ClientPackRefused::class);
        $this->store->packBytes($published);
    }

    public function test_a_malformed_publication_record_is_refused_not_read_as_none(): void
    {
        File::ensureDirectoryExists($this->dir.'/client-packs');
        file_put_contents($this->dir.'/client-packs/published.json', '{"bridge_release":"0.91.0"}');

        $this->expectException(ClientPackRefused::class);
        $this->store->published();
    }
}
