<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ClientPackSourceCheck;
use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClientPackFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * `board_tools.client_pack_source` (card#10567 B2, design review r3-M7): does this bridge publish
 * the client pack of the release its checkout IS? Keyed on `bridge_release` against `VERSION`,
 * never on the client version, so a release whose pack was never published warns even when its
 * client did not change.
 */
class ClientPackSourceCheckTest extends TestCase
{
    use MaterializesChecks;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/client-pack-source-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.state_dir' => $this->dir.'/state']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function publish(string $release): void
    {
        $f = new ClientPackFixture($release, packBytes: "pack of {$release}");
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-29T00:00:00Z');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function check(?string $version): array
    {
        $file = $this->dir.'/VERSION';
        if ($version !== null) {
            File::put($file, $version);
        }

        return array_map(
            static fn ($f): array => [$f->severity->value, $f->message],
            $this->findingsOf(new ClientPackSourceCheck($file), new CheckContext),
        );
    }

    public function test_it_is_ok_when_this_release_s_pack_is_published(): void
    {
        $this->publish('0.93.0');

        $this->assertSame(
            [[Severity::Ok->value, "client_pack_source: this bridge publishes release 0.93.0's client pack (client 0.9.28), the release this checkout is."]],
            $this->check("0.93.0\n"),
        );
    }

    public function test_it_warns_when_nothing_is_published_and_names_both_remedies(): void
    {
        $findings = $this->check('0.93.0');

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Warn->value, $severity);
        $this->assertStringContainsString('publishes no client pack, so no seat can install or update its channel server from it', $message);
        $this->assertStringContainsString('`php artisan bridge:client-pack:install`', $message);
        $this->assertStringContainsString('the release carries no client pack', $message);
        $this->assertStringNotContainsString('0.93.0', $message, 'the golden fixtures would move on every VERSION bump');
        $this->assertStringContainsString('re-run', $message);
        $this->assertStringContainsString('Auto-tag + GitHub Release on merge to main', $message);
    }

    /**
     * A release ahead of the published one is the case a client_version key could not see: most
     * releases do not change the client, so the versions agree while this release's pack is unpublished.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function olderPublications(): array
    {
        return [
            'the next minor' => ['0.92.0', '0.93.0'],
            'across a major bump' => ['0.99.0', '1.0.0'],
            'a minor past 9, which a string compare would order the other way' => ['0.9.0', '0.10.0'],
        ];
    }

    #[DataProvider('olderPublications')]
    public function test_it_warns_when_an_older_release_s_pack_is_published(string $published, string $version): void
    {
        $this->publish($published);

        $findings = $this->check($version);

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Warn->value, $severity);
        $this->assertStringContainsString("publishes release {$published}'s client pack, but this checkout is release {$version}, so seats stay on release {$published}'s client", $message);
        $this->assertStringContainsString('`php artisan bridge:client-pack:install`', $message);
        $this->assertStringContainsString('the release carries no client pack', $message);
    }

    public function test_it_warns_that_a_rolled_back_bridge_did_not_roll_its_seats_back(): void
    {
        $this->publish('0.94.0');

        $findings = $this->check('0.93.0');

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Warn->value, $severity);
        $this->assertStringContainsString('publishes release 0.94.0\'s client pack, newer than release 0.93.0', $message);
        $this->assertStringContainsString('did not roll its seats\' client back', $message);
        $this->assertStringNotContainsString('bridge:client-pack:install', $message);
    }

    public function test_it_warns_that_seats_get_503_when_the_publication_record_cannot_be_read(): void
    {
        $this->publish('0.93.0');
        File::put((new ClientPackStore)->publishedPath(), '{not json');

        $findings = $this->check('0.93.0');

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Warn->value, $severity);
        $this->assertStringContainsString('the published client pack record cannot be read', $message);
        $this->assertStringContainsString('answers every seat 503', $message);
        $this->assertStringContainsString((new ClientPackStore)->recordRecovery(), $message);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unreadableVersions(): array
    {
        return [
            'no VERSION file' => [null],
            'a tag name, not bare X.Y.Z' => ['v0.93.0'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unreadableVersions')]
    public function test_it_is_unvalidated_when_this_checkout_s_release_is_unknown(?string $version): void
    {
        $this->publish('0.93.0');

        $findings = $this->check($version);

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Unvalidated->value, $severity);
        $this->assertStringContainsString('VERSION', $message);
        $this->assertStringContainsString('not bare X.Y.Z', $message);
    }
}
