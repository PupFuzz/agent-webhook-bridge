<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\ClientUpdate\ClientPackStoreUnwritable;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\SystemProcessIdentity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\ClientPackFixture;
use Tests\TestCase;

/**
 * `bridge:client-pack:install` (DL-430): it publishes this release's pack only when every check
 * holds, and a run that refuses or cannot read GitHub changes nothing.
 *
 * The release is this checkout's `VERSION`; every fixture here is built for it, so the suite does
 * not move when the repo's version does.
 */
class ClientPackInstallCommandTest extends TestCase
{
    private const REPO = 'owner/bridge';

    private string $dir;

    private string $release;

    private string|false $ambientGhToken;

    protected function setUp(): void
    {
        parent::setUp();
        // Hermetic: an ambient GH_TOKEN is the resolver's last fallback, and a developer shell has one.
        $this->ambientGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
        $this->dir = sys_get_temp_dir().'/client-pack-install-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'gh-read-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/github/token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.token_path' => null,
            'bridge.providers.github.credential_helper' => '',
            'bridge.client_pack.repo' => self::REPO,
        ]);
        $this->release = trim((string) file_get_contents(base_path('VERSION')));
    }

    protected function tearDown(): void
    {
        if (is_string($this->ambientGhToken)) {
            putenv('GH_TOKEN='.$this->ambientGhToken);
        }
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function install(): array
    {
        $exit = Artisan::call('bridge:client-pack:install');

        return [$exit, Artisan::output()];
    }

    /**
     * Fake the GitHub release `v<VERSION>` carrying these assets.
     *
     * @param  array<string, string>  $assets  name => bytes
     * @param  array<string, string>  $digests  name => the `digest` GitHub records, where it records one
     * @param  array<string, int>  $sizes  name => a recorded size that differs from the bytes
     */
    private function release(array $assets, array $digests = [], array $sizes = []): void
    {
        $listed = [];
        $downloads = [];
        $id = 100;
        foreach ($assets as $name => $bytes) {
            $id++;
            $listed[] = ['id' => $id, 'name' => $name, 'size' => $sizes[$name] ?? strlen($bytes)] + (isset($digests[$name]) ? ['digest' => $digests[$name]] : []);
            $downloads['api.github.com/repos/'.self::REPO."/releases/assets/{$id}"] = Http::response($bytes);
        }
        Http::fake($downloads + [
            'api.github.com/repos/'.self::REPO.'/releases/tags/v'.$this->release => Http::response(['tag_name' => 'v'.$this->release, 'assets' => $listed]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function assetsOf(ClientPackFixture $f): array
    {
        return [$f->packName() => $f->pack, $f->manifestName() => $f->manifest, 'SHA256SUMS' => $f->sums(), 'unrelated.zip' => 'other'];
    }

    private function published(): ?string
    {
        return (new ClientPackStore)->published()?->bridgeRelease;
    }

    public function test_a_release_carrying_a_good_pack_is_published(): void
    {
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f), [$f->packName() => 'sha256:'.hash('sha256', $f->pack)]);

        [$exit, $out] = $this->install();

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString("published the client pack for release {$this->release}", $out);
        $this->assertSame($this->release, $this->published());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer gh-read-token')
            && str_ends_with($r->url(), '/releases/tags/v'.$this->release));
        // Exactly one Accept value: a second one (the API's JSON type) makes GitHub answer the
        // asset's JSON metadata instead of its bytes — measured against a live release.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/releases/assets/')
            && $r->header('Accept') === ['application/octet-stream']);
    }

    public function test_running_it_again_is_a_no_op(): void
    {
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f));
        $this->install();

        [$exit, $out] = $this->install();

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('was already published; nothing changed', $out);
    }

    public function test_a_release_with_no_pack_assets_publishes_nothing(): void
    {
        $this->release(['unrelated.zip' => 'other']);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('carries no client pack', $out);
        $this->assertStringContainsString('still publishes no client pack', $out);
        $this->assertNull($this->published());
    }

    public function test_a_release_missing_one_of_the_three_assets_publishes_nothing(): void
    {
        $f = new ClientPackFixture($this->release);
        $assets = $this->assetsOf($f);
        unset($assets['SHA256SUMS']);
        $this->release($assets);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing: SHA256SUMS', $out);
        $this->assertNull($this->published());
    }

    public function test_no_release_for_this_version_publishes_nothing(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('has no published release v'.$this->release, $out);
    }

    public function test_github_unreachable_is_could_not_measure(): void
    {
        Http::fake(['api.github.com/*' => Http::response('upstream down', 502)]);

        [$exit, $out] = $this->install();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('could not read release', $out);
        $this->assertNull($this->published());
    }

    public function test_no_github_token_is_could_not_measure_and_asks_nothing(): void
    {
        File::delete($this->dir.'/github/token');
        Http::fake();

        [$exit, $out] = $this->install();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('no GitHub read token', $out);
        Http::assertNothingSent();
    }

    public function test_a_pack_whose_bytes_differ_from_the_github_digest_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f), [$f->packName() => 'sha256:'.str_repeat('0', 64)]);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not match the digest GitHub records', $out);
        $this->assertNull($this->published());
    }

    public function test_a_download_shorter_than_github_records_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f), sizes: [$f->packName() => strlen($f->pack) + 1]);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('GitHub records', $out);
        $this->assertNull($this->published());
    }

    public function test_a_manifest_that_does_not_match_sha256sums_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $assets = $this->assetsOf($f);
        $assets['SHA256SUMS'] = hash('sha256', $f->pack).'  '.$f->packName()."\n".str_repeat('0', 64).'  '.$f->manifestName()."\n";
        $this->release($assets);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("{$f->manifestName()}'s sha256 does not match SHA256SUMS", $out);
        $this->assertNull($this->published());
    }

    public function test_sha256sums_that_omits_the_pack_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $assets = $this->assetsOf($f);
        $assets['SHA256SUMS'] = hash('sha256', $f->manifest).'  '.$f->manifestName()."\n";
        $this->release($assets);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("SHA256SUMS does not list {$f->packName()}", $out);
    }

    /**
     * Every checksum on the release agrees with the bytes, and the pack still is not the one the
     * manifest describes — the manifest's own sha256 is the check that catches it.
     */
    public function test_a_pack_that_is_not_the_one_its_manifest_names_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $other = 'a different pack';
        $sums = hash('sha256', $other).'  '.$f->packName()."\n".hash('sha256', $f->manifest).'  '.$f->manifestName()."\n";
        $this->release([$f->packName() => $other, $f->manifestName() => $f->manifest, 'SHA256SUMS' => $sums]);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("does not match its manifest's size and sha256", $out);
        $this->assertNull($this->published());
    }

    public function test_a_manifest_naming_another_release_is_refused(): void
    {
        $other = new ClientPackFixture('0.1.0');
        $mine = new ClientPackFixture($this->release);
        $sums = hash('sha256', $other->pack).'  '.$mine->packName()."\n".hash('sha256', $other->manifest).'  '.$mine->manifestName()."\n";
        $this->release([$mine->packName() => $other->pack, $mine->manifestName() => $other->manifest, 'SHA256SUMS' => $sums]);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('names release 0.1.0', $out);
        $this->assertNull($this->published());
    }

    /**
     * A bridge rolled back to an older checkout keeps serving the newer pack: seats are never
     * offered a downgrade by a rollback.
     */
    public function test_a_rolled_back_bridge_keeps_the_newer_published_pack(): void
    {
        $newer = new ClientPackFixture('999.0.0', packBytes: 'newer');
        (new ClientPackStore)->publish(ClientPackManifest::parse($newer->manifest), $newer->manifest, $newer->pack, '2026-09-27T00:00:00Z');
        $this->release($this->assetsOf(new ClientPackFixture($this->release)));

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('below the published', $out);
        $this->assertStringContainsString("release 999.0.0's client pack stays in service", $out);
        $this->assertSame('999.0.0', $this->published());
    }

    /**
     * A 200 that carries no asset list is not "the release carries no pack": the read established
     * nothing about the release, so the run is could-not-measure.
     */
    public function test_a_release_answer_with_no_readable_asset_list_is_could_not_measure(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v'.$this->release])]);

        [$exit, $out] = $this->install();

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('carries no readable asset list', $out);
        $this->assertStringNotContainsString('carries no client pack', $out);
        $this->assertNull($this->published());
    }

    public function test_sha256sums_listing_one_file_twice_with_different_values_is_refused(): void
    {
        $f = new ClientPackFixture($this->release);
        $assets = $this->assetsOf($f);
        $assets['SHA256SUMS'] = $f->sums().str_repeat('0', 64).'  '.$f->packName()."\n";
        $this->release($assets);

        [$exit, $out] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("SHA256SUMS lists {$f->packName()} twice with different sha256", $out);
        $this->assertNull($this->published());
    }

    /**
     * A second publish while one holds the store's lock is refused, never run alongside it.
     */
    public function test_a_publish_while_another_holds_the_store_lock_changes_nothing(): void
    {
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f));
        $lock = (new ClientPackStore)->publishedPath().'.lock';
        File::ensureDirectoryExists(dirname($lock));
        $held = fopen($lock, 'c');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            [$exit, $out] = $this->install();
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('holds '.$lock, $out);
        $this->assertNull($this->published());
    }

    public function test_a_run_as_root_writes_nothing_and_asks_github_nothing(): void
    {
        $this->actAs(0);
        Http::fake();

        [$exit, $out] = $this->install();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('runs as root', $out);
        $this->assertDirectoryDoesNotExist($this->dir.'/state/client-packs');
        Http::assertNothingSent();
    }

    public function test_a_store_owned_by_another_user_is_not_taken_from_it(): void
    {
        $f = new ClientPackFixture('0.1.0');
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-27T00:00:00Z');
        $published = (new ClientPackStore)->publishedPath();
        $me = (int) (new SystemProcessIdentity)->euid();
        $this->actAs($me, [$published => $me + 1], [$me + 1 => 'receiver']);
        Http::fake();

        [$exit, $out] = $this->install();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString("{$published} is owned by receiver", $out);
        $this->assertStringContainsString('run it as receiver', $out);
        Http::assertNothingSent();
    }

    /**
     * The command's up-front check is not the only guard: publish() itself refuses a root run, so
     * no other caller can write the store as root.
     */
    public function test_the_store_itself_refuses_a_root_publish(): void
    {
        $this->actAs(0);
        $f = new ClientPackFixture($this->release);

        $this->expectException(ClientPackStoreUnwritable::class);
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-27T00:00:00Z');
    }

    /**
     * A write that fails after the pack and manifest are in place leaves published.json naming the
     * previous release, whose files were never touched — so seats keep being served it.
     */
    public function test_a_write_failing_part_way_leaves_the_previous_publication_served(): void
    {
        if ((new SystemProcessIdentity)->euid() === 0) {
            $this->markTestSkipped('root ignores the directory mode this test relies on');
        }
        $old = new ClientPackFixture('0.1.0', packBytes: 'the old pack');
        $store = new ClientPackStore;
        $store->publish(ClientPackManifest::parse($old->manifest), $old->manifest, $old->pack, '2026-09-27T00:00:00Z');
        $f = new ClientPackFixture($this->release);
        $this->release($this->assetsOf($f));
        // The release directory is writable and the store directory is not, so the pack and
        // manifest land and published.json (written last, beside them) cannot.
        File::ensureDirectoryExists($store->dir().'/'.$this->release, 0o700);
        chmod($store->dir(), 0o500);

        try {
            [$exit, $out] = $this->install();
        } finally {
            chmod($store->dir(), 0o700);
        }

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('could not write the client pack store', $out);
        $this->assertStringContainsString("release 0.1.0's client pack stays in service", $out);
        $this->assertFileExists($store->dir().'/'.$this->release.'/'.$f->packName());
        $published = $store->published();
        $this->assertNotNull($published);
        $this->assertSame('0.1.0', $published->bridgeRelease);
        $this->assertSame($old->pack, $store->packBytes($published));
    }

    /**
     * @param  array<string, int>  $owners  path => the uid that owns it; any other present path is owned by $euid
     * @param  array<int, string>  $names
     */
    private function actAs(int $euid, array $owners = [], array $names = []): void
    {
        $this->app->instance(ProcessIdentity::class, new class($euid, $owners, $names) implements ProcessIdentity
        {
            /**
             * @param  array<string, int>  $owners
             * @param  array<int, string>  $names
             */
            public function __construct(private int $euid, private array $owners, private array $names) {}

            public function euid(): ?int
            {
                return $this->euid;
            }

            public function ownerOf(string $path): ?int
            {
                return (new SystemProcessIdentity)->ownerOf($path) === null ? null : ($this->owners[$path] ?? $this->euid);
            }

            public function accountName(int $uid): ?string
            {
                return $this->names[$uid] ?? null;
            }
        });
    }
}
