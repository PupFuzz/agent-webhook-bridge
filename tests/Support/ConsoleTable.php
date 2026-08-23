<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Assert what a Symfony console table ACTUALLY PRINTS IN A CELL, for the commands whose
 * whole output is a metric/count table (`bridge:stats`).
 *
 * Extracted at the second real caller (canon #5): `BridgeCommandsTest` and
 * `WritebackBoardDivergenceLedgerTest` both needed it. An unanchored substring match on a
 * table is a presence claim wearing a count's clothes — it reads the CAPTION and never the
 * number beside it — which is the defect this helper exists to stop being re-minted
 * (card#7471). The two anchorings that make it a cell rather than a substring:
 *  - `\s*\|` right after the label, so the label is a WHOLE cell. Measured against a real
 *    `bridge:stats --agent=pm` render: `agent_dispatches` does NOT match the
 *    `| agent_dispatches [pm] | 1 |` row, in either value.
 *  - `^` and `$` under `/m`, so a match cannot straddle two rows or start mid-row.
 *
 * ⚠ The COUNT is what these callers assert, so the value is compared as the string the
 * table rendered — never coerced. A cell that reads `0` and a cell that reads `NOT
 * MEASURED` are different measurements, and a numeric compare would erase the difference.
 */
final class ConsoleTable
{
    /**
     * @param  string  $output  the whole captured command output (Artisan::output())
     * @param  string  $metric  the left cell's label. Surrounding whitespace is not
     *                          significant (the table pads, and the sub-rows are indented),
     *                          but the cell BOUNDARIES are: a label is a whole cell, never
     *                          a prefix of one
     * @param  string  $cell  the right cell, exactly as rendered
     */
    public static function assertRow(string $output, string $metric, string $cell, string $message = ''): void
    {
        Assert::assertMatchesRegularExpression(
            '/^\|\s*'.preg_quote($metric, '/').'\s*\|\s*'.preg_quote($cell, '/').'\s*\|$/m',
            $output,
            $message !== '' ? $message : "no table row reads `{$metric}` = `{$cell}`",
        );
    }
}
