<?php

namespace Tests\Unit\ClientUpdate;

use App\Bridge\ClientUpdate\InstallLogEntry;
use App\Bridge\ClientUpdate\InstallLogRefused;
use App\Bridge\ClientUpdate\SeatClientLedger;
use App\Models\SeatClientEvent;
use PHPUnit\Framework\TestCase;

/**
 * The seat updater (`examples/channel-servers/client-update.mjs`, card#10568) sizes its
 * `client_report` batches and its install-log lines by the limits THIS bridge enforces. They are
 * pinned there as literals — a Node file cannot read a PHP constant — so this holds each pinned
 * value to the bridge's own: a report the seat thinks fits, and the bridge refuses whole, would
 * leave the seat's log unreported launch after launch with nothing but a 422 to show for it.
 *
 * The widths `InstallLogEntry` spells as literals (`source`) are held by BEHAVIOUR: a value at
 * the pinned width is accepted and one byte over is refused.
 */
class ClientReportLimitsLockstepTest extends TestCase
{
    private const CLIENT = __DIR__.'/../../../examples/channel-servers/client-update.mjs';

    private static function pinned(string $name, ?string $source = null): int
    {
        $source ??= (string) file_get_contents(self::CLIENT);
        if (preg_match('/^export const '.$name.' = (\d+);$/m', $source, $m) !== 1) {
            self::fail("client-update.mjs no longer pins {$name} as `export const {$name} = <integer>;`");
        }

        return (int) $m[1];
    }

    public function test_the_report_bounds_are_the_bridges(): void
    {
        $this->assertSame(SeatClientLedger::MAX_REPORT_ENTRIES, self::pinned('MAX_REPORT_ENTRIES'));
        $this->assertSame(SeatClientLedger::MAX_REPORT_BYTES, self::pinned('MAX_REPORT_BYTES'));
    }

    public function test_the_line_and_field_widths_are_the_bridges(): void
    {
        $this->assertSame(InstallLogEntry::MAX_LINE_BYTES, self::pinned('MAX_LINE_BYTES'));
        $this->assertSame(SeatClientEvent::REASON_MAX_CHARS, self::pinned('REASON_MAX_BYTES'));

        $source = self::pinned('SOURCE_MAX_BYTES');
        $line = static fn (int $width): string => json_encode(['install_id' => 'i', 'seq' => 1, 'action' => 'install', 'result' => 'ok', 'actor' => 'provision', 'source' => str_repeat('s', $width)], JSON_THROW_ON_ERROR);
        $this->assertSame(str_repeat('s', $source), InstallLogEntry::parse($line($source), 'i')->source);
        $this->expectException(InstallLogRefused::class);
        InstallLogEntry::parse($line($source + 1), 'i');
    }

    public function test_the_reader_sees_a_drifted_pin(): void
    {
        // The control: the same reader over a client whose pin moved must disagree with the bridge.
        $drifted = str_replace(
            'export const MAX_REPORT_BYTES = '.SeatClientLedger::MAX_REPORT_BYTES.';',
            'export const MAX_REPORT_BYTES = '.(SeatClientLedger::MAX_REPORT_BYTES + 1).';',
            (string) file_get_contents(self::CLIENT),
        );
        $this->assertNotSame(SeatClientLedger::MAX_REPORT_BYTES, self::pinned('MAX_REPORT_BYTES', $drifted));
    }
}
