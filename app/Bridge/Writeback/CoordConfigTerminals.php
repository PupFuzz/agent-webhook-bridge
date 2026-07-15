<?php

namespace App\Bridge\Writeback;

/**
 * Resolve what the OTHER mover considers TERMINAL, from the coordination project's
 * `coordination.config.json` (`$COORD_CONFIG`) — the cross-config read `bridge:check`
 * performs for the DL-200 coord-card move leg (roundtable #18, ruled: Option A + a
 * MANDATORY preflight cross-config compare).
 *
 * WHY THIS EXISTS. The bridge writes `coord_card_terminal_stage_id` (a stage ID it
 * owns, in its own `writeback.json`); the consumer's periodic reconcile derives its
 * terminal from `terminal_columns` (column NAMES, in the coord config). Q1's real
 * failure is NOT a stage id missing from the board — the existing board-stage check
 * catches that. It is the two movers DISAGREEING about which column is terminal:
 * the bridge concludes a card into stage X while the reconcile treats stage Y as
 * terminal, so they fight every cycle. Only comparing the two CONFIGS catches that.
 *
 * WHY ONLY FROM THE CLI. This read lives in `bridge:check` — an operator-invoked CLI
 * with the operator's environment — and NEVER on the webhook request path, which runs
 * under PHP-FPM whose environment is NOT the operator's. Coupling the request path to
 * `$COORD_CONFIG` would bind it to a file that demonstrably is not there at runtime,
 * failing silently in the one process nobody watches. Keep this CLI-only.
 *
 * WHY A SECOND IMPLEMENTATION. The rule's home is Python
 * (`coord.kanban_common.terminals_for_board` + `_terminal_columns_by_board`); the
 * bridge is PHP and cannot import it. This is a deliberate mirror of a rule the bridge
 * does not own. Its conformance is pinned by the framework's OWN vectors, ported verbatim
 * into tests/Feature/Writeback/CoordConfigTerminalsTest.php — re-port them if the
 * framework changes the rule. (Named in prose, not an @see: a production class must not
 * import a test class, which is what an FQCN reference here fixes itself into.)
 *
 * READ-SITE SCOPE. `terminals_for_board` also folds deprecated `done_column=` /
 * `terminal_columns=` adapter kwargs for un-migrated callers. Its docstring is explicit
 * that the READ-site sees none of them ("only terminals present in CONFIG are visible
 * to the read-site"), and this is a read-site, so they are deliberately not mirrored.
 */
final class CoordConfigTerminals
{
    /** The lane-model fallback terminal — `terminals_for_board`'s `default_terminal`. */
    private const DEFAULT_TERMINAL = 'Done';

    /**
     * Read + decode `coordination.config.json`. Absent / unreadable / malformed ⇒ null,
     * NEVER a throw and NEVER an empty array: the caller must be able to tell "could not
     * ask" apart from "asked, and the answer is no terminals". A missing input is not
     * evidence of agreement.
     *
     * @return array<string, mixed>|null
     */
    public static function load(?string $path): ?array
    {
        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The terminal column NAMES a single `kanban.boards[]` entry resolves to — the
     * mirror of `terminals_for_board`:
     *   1. explicit `terminal_columns`;
     *   2. else, if the board declares `user_lanes` (the lane model), the
     *      `default_terminal` fallback ("Done");
     *   3. else no terminals at all — the board never concludes anything.
     *
     * Step 2 is the one the DL-200 spec text originally missed: the canonical `issues`
     * board declares `user_lanes` and NO `terminal_columns`, so a literal
     * `terminal_columns` read resolves NOTHING on the reference shape.
     *
     * @param  array<string, mixed>  $boardCfg
     * @return list<string>
     */
    public static function terminalsForBoard(array $boardCfg): array
    {
        $terminals = [];
        $explicit = $boardCfg['terminal_columns'] ?? null;
        foreach (is_array($explicit) ? $explicit : [] as $name) {
            if (is_string($name) && $name !== '') {
                $terminals[$name] = true;
            }
        }
        if ($terminals === []) {
            $lanes = $boardCfg['user_lanes'] ?? null;
            if (is_array($lanes) && $lanes !== []) {
                $terminals[self::DEFAULT_TERMINAL] = true;
            }
        }

        return array_keys($terminals);
    }

    /**
     * The terminal column NAMES resolved for a BOARD ID — the mirror of
     * `_terminal_columns_by_board`. The join key is the board id, NOT the `key` string
     * (installs name their boards differently, and the framework's own read-site keys by
     * id), and several entries MAY share one board id (e.g. a `prs` + `product-tasks`
     * pair) in which case their terminals UNION.
     *
     * A non-numeric `board_id` is skipped — that is what makes the `"REPLACE_ME"`
     * placeholder inert. Numeric STRINGS are honored: real configs carry `"8"`, while
     * the framework's vectors use `8`.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function terminalNamesForBoardId(array $config, int $boardId): array
    {
        $kanban = $config['kanban'] ?? null;
        $boards = is_array($kanban) ? ($kanban['boards'] ?? null) : null;

        $names = [];
        foreach (is_array($boards) ? $boards : [] as $boardCfg) {
            if (! is_array($boardCfg) || ! is_numeric($boardCfg['board_id'] ?? null)) {
                continue;
            }
            if ((int) $boardCfg['board_id'] !== $boardId) {
                continue;
            }
            foreach (self::terminalsForBoard($boardCfg) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
