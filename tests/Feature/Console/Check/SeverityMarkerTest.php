<?php

namespace Tests\Feature\Console\Check;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenCapture;
use Tests\TestCase;

/**
 * The severity marker `CheckCommand::emitFinding()` now prefixes every text-format
 * finding line with (card#9251, operator decision 2026-09-15, Option 1: the plain-text
 * console). DL-393 removed colour from every Artisan command, which left a `fail`, a
 * `warn` and an `ok` line differing only in their wording; `FAIL: `/`WARN: `/
 * `UNVALIDATED: `/`OK: ` restores the signal in text.
 *
 * ⭐ ONE FIXTURE, ALL FOUR SEVERITIES. `config-dir-missing` (also a `CheckGoldenTest`
 * fixture, reproduced here rather than shared so this test does not depend on that
 * class's private `buildFixture()`) prints a `fail`, a `warn`, several `ok` and an
 * `unvalidated` finding in one run, so the marker is pinned against the real renderer
 * instead of a hand-built `Finding`.
 */
class SeverityMarkerTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    private function bootConfigDirMissing(): void
    {
        $this->bootGoldenInstall('config-dir-missing', function ($i) {
            $i->boot();
            config(['bridge.config_dir' => $i->path('does-not-exist')]);
        });
    }

    public function test_every_severity_prints_its_marker_at_the_start_of_the_line(): void
    {
        $this->bootConfigDirMissing();

        $output = GoldenCapture::capture($this->install->path());

        $this->assertMatchesRegularExpression('/^FAIL: config dir is not usable: /m', $output);
        $this->assertMatchesRegularExpression('/^WARN: secret dir <INSTALL> is group\/world-accessible/m', $output);
        $this->assertMatchesRegularExpression('/^OK: database: connected$/m', $output);
        $this->assertMatchesRegularExpression('/^OK: install-suffix DSN check: ok$/m', $output);
        $this->assertMatchesRegularExpression(
            '/^UNVALIDATED: retention: could NOT determine whether the receiver ends the request early/m',
            $output,
        );

        // Not ONE marked arm going unmarked while the others are: every line this fixture's
        // committed golden shows as a bare finding message now starts with a marker, so the
        // un-prefixed form is absent.
        $this->assertStringNotContainsString("---\nconfig dir is not usable:", $output);
        $this->assertStringNotContainsString("\nsecret dir <INSTALL> is group/world-accessible", $output);
        $this->assertStringNotContainsString("\ndatabase: connected", $output);
    }

    public function test_the_json_document_carries_no_marker_and_is_unchanged(): void
    {
        $this->bootConfigDirMissing();

        $json = GoldenCapture::capture($this->install->path(), ['--format' => 'json']);

        $this->assertStringNotContainsString('FAIL: ', $json);
        $this->assertStringNotContainsString('WARN: ', $json);
        $this->assertStringNotContainsString('UNVALIDATED: ', $json);
        $this->assertStringNotContainsString('OK: ', $json);
        $this->assertStringContainsString('"message": "config dir is not usable: ', $json);
        $this->assertStringContainsString('"message": "secret dir <INSTALL> is group/world-accessible', $json);
        $this->assertStringContainsString('"message": "database: connected"', $json);
    }
}
