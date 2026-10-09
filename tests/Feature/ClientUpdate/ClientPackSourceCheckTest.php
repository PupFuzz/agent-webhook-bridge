<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ClientPackSourceCheck;
use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\ClientCapabilities;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClientPackFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * `board_tools.client_pack_source` (card#10567 B2, design review r3-M7): does this bridge publish
 * the client pack of the release its checkout IS? Keyed on `bridge_release` against `VERSION`,
 * never on the client version alone, so a release whose pack was never published warns even when
 * its client did not change — and fails when its client did (card#11579).
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

    private function publish(string $release, string $clientVersion = '0.9.28'): void
    {
        $f = new ClientPackFixture($release, $clientVersion, packBytes: "pack of {$release}");
        (new ClientPackStore)->publish(ClientPackManifest::parse($f->manifest), $f->manifest, $f->pack, '2026-09-29T00:00:00Z');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function check(?string $version, ?ClientCapabilities $capabilities = null): array
    {
        $file = $this->dir.'/VERSION';
        if ($version !== null) {
            File::put($file, $version);
        }

        return array_map(
            static fn ($f): array => [$f->severity->value, $f->message],
            // ⚑ THE CHECKOUT'S OWN CLIENT DEFAULTS TO THE PUBLISHED ONE (0.9.28) so the release-lag
            // cases below measure the release axis alone; the client-lag cases pass their own.
            $this->findingsOf(new ClientPackSourceCheck($file, $capabilities ?? self::ownClient('0.9.28')), new CheckContext),
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

    /** A capability table whose newest client is `$version` — this checkout's own client, for the check. */
    private static function ownClient(string $version): ClientCapabilities
    {
        return new ClientCapabilities($version, [], []);
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
     * card#11579 ask 1: an older release's pack whose CLIENT is also older than this checkout's is a
     * `fail` — every seat installing or updating from this bridge gets a client missing what this
     * checkout's client declares (on the measured install: no `ci_await`), so `bridge:check` exits
     * non-zero. Measured against the checkout's REAL capability table, so the named gap is the one
     * a seat would actually have.
     */
    public function test_it_fails_when_the_published_client_lags_this_checkout_s_client_and_names_the_gap(): void
    {
        $own = ClientCapabilities::bundled();
        $this->publish('0.95.0', '0.9.39');

        $findings = $this->check('0.98.1', $own);

        $this->assertCount(1, $findings);
        [$severity, $message] = $findings[0];
        $this->assertSame(Severity::Fail->value, $severity);
        $this->assertStringContainsString("publishes release 0.95.0's client pack (client 0.9.39), but this checkout is release 0.98.1 with client {$own->currentClientVersion}", $message);
        $this->assertStringContainsString('ci_await', $message, 'the 0.9.39 client lacks ci_await, which this checkout\'s client declares');
        $this->assertStringContainsString('`php artisan bridge:client-pack:install`', $message);
    }

    /** Control for the fail above: the same release lag with an UNCHANGED client stays a warn. */
    public function test_a_release_lag_with_the_same_client_stays_a_warn(): void
    {
        $this->publish('0.97.0', '0.9.48');

        $findings = $this->check('0.98.1', self::ownClient('0.9.48'));

        $this->assertSame(Severity::Warn->value, $findings[0][0]);
    }

    /**
     * A dev checkout AHEAD of its last release whose client moved has no pack to publish yet: the
     * published pack IS this release's, so this is the `ok` line and never the `fail` — the remedy
     * the fail names could not clear it.
     */
    public function test_a_client_ahead_of_this_release_s_own_pack_is_not_a_fail(): void
    {
        $this->publish('0.98.1', '0.9.48');

        $findings = $this->check('0.98.1', self::ownClient('0.9.49'));

        $this->assertSame(Severity::Ok->value, $findings[0][0]);
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
