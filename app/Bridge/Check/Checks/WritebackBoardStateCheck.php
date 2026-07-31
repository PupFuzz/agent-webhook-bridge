<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\CoordConfigTerminals;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackMapping;
use Throwable;

/**
 * Every per-mapping writeback assertion that needs a BOARD READ, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3b).
 *
 * WHAT THESE CATCH THAT AN HTTP ERROR NEVER WILL. A token whose user lost board
 * membership — or a drifted `board_id` — gets a 200 with 0 cards, not an error; a typo'd
 * swimlane / stage id / missing custom field makes the write 422 as a PERMANENT no-op
 * (DL-020). Every one of those is a live writeback that silently moves nothing. NOTHING
 * HERE FAILS except the #4553 fail-closed leg below: a temporarily-unreachable kanban or a
 * genuinely-empty new board must not FAIL the install check (DL-026). The non-fatal legs
 * split across two severities since DL-251 — `warn` where the leg ANSWERED and the answer
 * is bad, `unvalidated` where it could not answer at all (the board read threw, or a
 * comparand did not resolve) — so "all warn-level", which this said until then, is no
 * longer a description of this check's output.
 *
 * ONE CHECK, NOT ONE PER LEG, FOR THE SAME REASON {@see WritebackMappingConfigCheck} IS:
 * the inline code iterates mappings on the OUTSIDE and legs on the inside, so a per-leg
 * decomposition would emit every repo's visibility line before any repo's swimlane line.
 * On a single-mapping install the two orders coincide — on a multi-mapping one, the install
 * these checks exist for, they do not. Stage 8's inventory keys on the check id, so the
 * grouping costs granularity there; accepted deliberately over reordering operator-visible
 * output.
 *
 * EVERY THROWING CALL SITS INSIDE A PER-MAPPING `catch`, AND THAT IS THE STAGE-3b
 * CONSTRAINT, NOT A STYLE CHOICE. {@see CheckRunner} materializes a
 * check's findings before the caller renders any of them, whereas the inline code had
 * already PRINTED the earlier mappings' lines — so a throw escaping this generator would
 * discard findings it had already yielded. Stage 3a's legs were all total, which made the
 * difference unreachable there; every leg here reaches the network. The per-mapping catch
 * (which the inline code already carried) is what keeps a mapping-N failure from erasing
 * mappings 1..N-1, and it is why this check may yield incrementally at all.
 */
final class WritebackBoardStateCheck implements Check
{
    public function id(): string
    {
        return 'writeback.board_state';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $writeback = $ctx->writeback;
        $client = $ctx->client;
        if ($writeback === null || $client === null || $writeback->mappings === []) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        foreach ($writeback->mappings as $repo => $mapping) {
            try {
                // Cheap visibility probe (DL-029): one limit=1 read,
                // preferring meta.total — independent of correlation mode.
                $vis = $client->visibility($mapping->boardId);
                if ($vis['total'] === 0) {
                    // 0 cards is AMBIGUOUS on a 200 read: an empty board (no
                    // cards created yet — fine) vs a non-member token (every
                    // move silently no-ops). Don't assert membership on this
                    // evidence alone — true inaccessibility surfaces separately
                    // (the by-ref reachability probe in the sibling check 404s for
                    // a non-member board in `ref` mode). So present both.
                    yield Finding::warn("writeback: token sees 0 cards on board {$mapping->boardId} ({$repo}) — EITHER the board is empty (no cards yet → fine, the writeback works once cards exist) OR the token's user isn't a member / `board_id` is wrong (then every move silently no-ops). If you expect cards on that board, verify membership + `board_id`; a genuinely-empty board is not a problem.");
                } elseif (! $vis['exact']) {
                    // Pre-DL-146 kanban: confirmed non-blind, exact size unknown.
                    yield Finding::ok("writeback: token can see board {$mapping->boardId} ({$repo}) (exact card count unavailable — kanban predates pagination meta)");
                } else {
                    yield Finding::ok("writeback: token sees {$vis['total']} card(s) on board {$mapping->boardId} ({$repo})");
                    if (config('bridge.writeback.correlation', 'ref') !== 'ref' && $vis['total'] > KanbanClient::SEARCH_LIMIT * KanbanClient::MAX_PAGES) {
                        yield Finding::warn("writeback: board {$mapping->boardId} ({$repo}) has {$vis['total']} cards, beyond the scan ceiling — correlations beyond it will be missed; switch BRIDGE_WRITEBACK_CORRELATION=ref");
                    }
                }
                // DL-027: a mapping's swimlane_id (created-card lane) must exist on
                // its board, else card creation 422s and the handler SILENTLY no-ops
                // (permanent-4xx). A static typo never self-resolves, so name it here.
                if ($mapping->swimlaneId !== null) {
                    if (! in_array($mapping->swimlaneId, $client->boardSwimlaneIds($mapping->boardId), true)) {
                        yield Finding::warn("writeback: swimlane_id {$mapping->swimlaneId} not found on board {$mapping->boardId} ({$repo}) — created cards will 422 and SILENTLY no-op until fixed (a deleted lane, or a lane on a different board)");
                    } else {
                        yield Finding::ok("writeback: swimlane_id {$mapping->swimlaneId} ok on board {$mapping->boardId} ({$repo})");
                    }
                }
                // #2949: a create_dependabot_cards mapping's board MUST define every
                // custom field the create payload sets (pr_number, pr_url, origin),
                // else POST /tasks.json 422s on the unregistered key and the handler
                // SILENTLY no-ops (permanent-4xx, DL-020) — the create path's twin of
                // the DL-027 swimlane gap above. A static config/board mismatch never
                // self-resolves, so surface it here (DL-026 "degraded must be loud").
                if ($mapping->createDependabotCards) {
                    $required = KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS;
                    $present = $client->boardCustomFieldKeys($mapping->boardId);
                    $missing = array_values(array_diff($required, $present));
                    if ($missing !== []) {
                        yield Finding::warn("writeback: create_dependabot_cards is on for {$repo} but board {$mapping->boardId} is MISSING the custom field(s) ".implode(', ', $missing).' the create payload sets ('.implode(', ', $required).') — every dependabot-card create will 422 and SILENTLY no-op until they are registered (add them on the board, or set create_dependabot_cards=false)');
                    } else {
                        yield Finding::ok("writeback: create_dependabot_cards custom fields ok on board {$mapping->boardId} ({$repo})");
                    }
                }
                // #4553: population=all correlates + creates by github_issue by-ref, which
                // derives from the `issue_number` payload custom field. If the board does
                // NOT register issue_number, kanban 422s every non-prefixed create as a
                // PERMANENT no-op (silent), AND an empty by-ref pre-check is indistinguishable
                // from a real no-match — so the bridge (the sole real-time mover for this
                // population) would silently DOUBLE-CARD. FAIL-CLOSED (exit non-zero), not a
                // warn: refuse to certify an install that would silently lose/duplicate cards.
                // Gated on create OR move: the move leg (create off) also correlates
                // non-prefixed cards by-ref, so it too 422s / silently no-ops without
                // issue_number registered.
                if (($mapping->createCoordCards || $mapping->moveCoordCards) && $mapping->issuePopulation === WritebackMapping::POPULATION_ALL) {
                    // Read in its OWN try so a read failure fails CLOSED. This is the one
                    // fail-closed leg in this check (no sibling here fails), so it must NOT
                    // be swallowed by the per-mapping catch below: a fail-closed invariant
                    // we could not verify is a FAILURE, not a warn (DL-026 / canon #9 — an
                    // unrun measurement is not a pass). A blind token / wrong board / transient
                    // 5xx here therefore exits non-zero rather than certifying blind.
                    try {
                        $present = $client->boardCustomFieldKeys($mapping->boardId);
                        if (! in_array('issue_number', $present, true)) {
                            yield Finding::fail("writeback: issue_population=all for {$repo} but board {$mapping->boardId} does not register the 'issue_number' custom field — every non-prefixed coord-card create 422s as a permanent no-op AND by-ref correlation cannot tell 'not indexed' from 'no match', so the bridge would silently double-card. Register issue_number (+ issue_url for source) on the board, or set issue_population=prefixed.");
                        } else {
                            yield Finding::ok("writeback: issue_number custom field registered on board {$mapping->boardId} ({$repo}) — github_issue by-ref ready (issue_population=all)");
                        }
                    } catch (Throwable $e) {
                        yield Finding::fail("writeback: issue_population=all for {$repo} but could NOT read board {$mapping->boardId}'s custom fields to verify issue_number registration — ".$e->getMessage().'. This fail-closed check must not be skipped (an unverifiable board could silently double-card); fix board access / board_id and re-run.');
                    }
                }
                // #2652: every workflow stage id the mapping targets — each
                // `stages.*` value plus the `started_from_stages` ids — must be a
                // real stage on the board. A typo'd id makes the move 422 (the
                // forward outcomes) or the `started`/no-regression guard silently
                // never match. Same silent-misconfig class as the swimlane (DL-027)
                // and dependabot-CF (DL-162) checks; cheap via boardStageOrder (DL-163).
                $targets = array_values($mapping->stages);
                foreach ($mapping->startedFromStages ?? [] as $fromId) {
                    $targets[] = $fromId;
                }
                // DL-194: the unpark_from_stages ids are read on the
                // `started` path too — a typo'd id makes the auto-unpark
                // guard silently never match (same class as above).
                foreach ($mapping->unparkFromStages ?? [] as $fromId) {
                    $targets[] = $fromId;
                }
                // DL-198: the coord-card create stage — a typo'd id makes
                // every coord-card create 422 and silently no-op (same class).
                if ($mapping->coordCardStageId !== null) {
                    $targets[] = $mapping->coordCardStageId;
                }
                // DL-200: the coord-card terminal — same class again (a typo'd
                // id 422s every close→terminal move and silently no-ops).
                if ($mapping->coordCardTerminalStageId !== null) {
                    $targets[] = $mapping->coordCardTerminalStageId;
                }
                // The read stays UNCONDITIONAL even when there is nothing to compare:
                // moving it inside the guard below would change this check's HTTP
                // behaviour, which is a different change from the message correction.
                $boardStageIds = array_keys($client->boardStageOrder($mapping->boardId));
                if ($targets === []) {
                    // NOTHING IS MAPPED, so there is no question to answer and no finding
                    // to make. `stages` is optional (`WritebackConfig`: `$m['stages'] ?? []`)
                    // and so is every other target source, so a mapping can legitimately
                    // target no stage at all — a by-ref-correlation-only mapping does. Both
                    // of the arms below would then say something false about it: the
                    // `unvalidated` claims the ids "could NOT be checked" when there were
                    // none, and the `ok` claims "all mapped stage ids exist" on an empty
                    // set. Silence is the honest answer for a vacuous question; it is limb 1
                    // of the rule, not limb 2.
                } elseif ($boardStageIds === []) {
                    // DL-251 §2b: this leg used to fall silent here. `boardStageOrder()`
                    // documents the empty read as EXPECTED (the caller treats can't-order
                    // as fail-open), and not-false-warning was right — but silence made
                    // "every mapped stage id exists" and "the comparand never resolved"
                    // the same output, which is green-because-never-looked at the one leg
                    // whose whole job is to catch a silent 422. The comparison could not be
                    // made, so it is UNVALIDATED, not a warn about the config: nothing here
                    // is evidence the ids are wrong.
                    yield Finding::unvalidated("writeback: could NOT check the mapped stage ids for {$repo} — board {$mapping->boardId} returned no workflow stages, so there was nothing to compare them against; a typo'd id would look exactly like this. Verify board_id + the token's membership and re-run.");
                } else {
                    $unknownStages = array_values(array_unique(array_diff($targets, $boardStageIds)));
                    if ($unknownStages !== []) {
                        yield Finding::warn("writeback: mapping for {$repo} references workflow stage id(s) ".implode(', ', $unknownStages)." not on board {$mapping->boardId} — those moves will 422 (or the started/no-regression guard will silently never match) until fixed");
                    } else {
                        yield Finding::ok("writeback: all mapped stage ids exist on board {$mapping->boardId} ({$repo})");
                    }
                }
                // DL-200: the cross-config compare — the MANDATORY preflight that
                // makes the move leg's bridge-owned terminal config legitimate. Gated
                // on the coord-card-move family (gate 1): after the DL-204 default flip,
                // move_coord_cards can resolve true from terminal-presence alone, so
                // without this gate the compare would verify a terminal for a leg that
                // cannot fire (family off) and read as though the leg were live.
                if (isset($ctx->coordCardMoveScopes[$repo])) {
                    yield from $this->coordTerminalAgreement((string) $repo, $mapping, $client);
                }
            } catch (Throwable $e) {
                yield Finding::unvalidated("writeback: could not read board {$mapping->boardId} ({$repo}) with the writeback token — ".$e->getMessage());
            }
        }
    }

    /**
     * DL-200 — the MANDATORY cross-config preflight for the coord-card move leg
     * (roundtable #18, ruled 3-way): compare THIS bridge's `coord_card_terminal_stage_id`
     * against what the coordination config considers terminal for the same board.
     *
     * WHY IT IS MANDATORY, not a nicety. Q1's real failure is NOT "a stage id that isn't
     * on the board" — the stage-existence leg above already catches that. It is the two
     * movers DISAGREEING about which column concludes a card: the bridge moves a closed
     * card to stage X while the reconcile treats stage Y as terminal, so they fight every
     * cycle, forever, with each side individually "working". Only comparing the two
     * CONFIGS can see that. This read is what makes it legitimate for the bridge to own a
     * terminal stage id in its own config at all.
     *
     * TWO BINDING CONDITIONS (non-negotiable, both peer-affirmed):
     *  (a) FAIL SOFT, and report CANNOT-VERIFY **distinctly from agreement**. An absent /
     *      unreadable / malformed / silent-on-this-board coord config means the comparison
     *      COULD NOT RUN. Never print agreement on a read failure — a missing input is not
     *      evidence of agreement, it is evidence we could not ask.
     *  (b) NEVER FAIL THE BRIDGE. Diagnostics only, never-fail (the DL-196 posture) —
     *      `bridge:check` must not go non-zero because a coord file moved. Every
     *      CANNOT-VERIFY arm below reports `unvalidated` rather than `warn` (DL-251):
     *      the comparison could not be MADE, which is not a finding about the config.
     *
     * @return iterable<Finding>
     */
    private function coordTerminalAgreement(string $repo, WritebackMapping $mapping, KanbanClient $client): iterable
    {
        // Leg off ⇒ nothing to verify (and no CANNOT-VERIFY noise on installs that never
        // enable it). Only the first clause is a reachable decision: `WritebackConfig` throws
        // when move_coord_cards is on with no terminal, so the null arm cannot fire on its
        // own for any loadable config — it is inherited from the migrated inline leg, and no
        // test can kill it without constructing a config-impossible mapping.
        if (! $mapping->moveCoordCards || $mapping->coordCardTerminalStageId === null) {
            return;
        }
        $mine = $mapping->coordCardTerminalStageId;
        $prefix = "writeback: move_coord_cards ({$repo}, board {$mapping->boardId})";
        $tail = 'Until this is verified the two movers may disagree about which column is terminal and fight every cycle.';

        // The per-install override (BRIDGE_COORD_CONFIG_PATH via .env) first, then the
        // ambient $COORD_CONFIG read LIVE through getenv(). getenv() rather than env()
        // is load-bearing, not a style choice: `php artisan optimize` caches config/ and
        // freezes every env() at deploy time (and the frozen value wins over the live
        // one), so an ambient path resolved in config/bridge.php would be whatever the
        // DEPLOYING shell had — usually nothing — forever. That would make this
        // "mandatory" compare permanently report CANNOT-VERIFY: present, running, and
        // never once doing its job. getenv() is cache-immune, and reading it here is
        // legitimate ONLY because this check runs from a CLI-only command (the receiver's
        // FPM env has no $COORD_CONFIG — which is the whole reason the compare lives in
        // `bridge:check`).
        $path = config('bridge.writeback.coord_config_path');
        if (! is_string($path) || $path === '') {
            $ambient = getenv('COORD_CONFIG');
            $path = is_string($ambient) && $ambient !== '' ? $ambient : null;
        }
        $config = CoordConfigTerminals::load($path);
        if ($config === null) {
            $where = $path === null ? '$COORD_CONFIG is not set' : "the coordination config at {$path} is absent, unreadable, or malformed";

            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY the terminal against the coordination config — {$where}. {$tail} Point bridge.writeback.coord_config_path (or \$COORD_CONFIG) at coordination.config.json.");

            return;
        }

        // Resolved through the framework's OWN rule (explicit terminal_columns, else the
        // user_lanes → "Done" lane-model fallback), joined by board_id and unioned across
        // every entry sharing it — see CoordConfigTerminals. A bare terminal_columns read
        // would resolve NOTHING on the canonical lane-model `issues` board.
        $names = CoordConfigTerminals::terminalNamesForBoardId($config, $mapping->boardId);
        if ($names === []) {
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY the terminal against the coordination config — it declares no terminal for board {$mapping->boardId} (no kanban.boards[] entry carries that board_id, or the entry has neither terminal_columns nor user_lanes). {$tail}");

            return;
        }
        if (count($names) > 1) {
            // >1 is legal framework-wide (e.g. ["Released to main", "Won't Do"]), but the
            // bridge concludes into exactly ONE stage, so which of them it ought to match
            // is genuinely unknowable. Ambiguous ⇒ cannot verify; never pick one and call
            // that agreement.
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY the terminal against the coordination config — it resolves ".count($names)." terminals for board {$mapping->boardId} (".implode(', ', $names).'), but the bridge concludes cards into exactly one stage, so which it should match is ambiguous. '.$tail);

            return;
        }
        $name = $names[0];

        try {
            $byName = $client->boardStageIdsByName($mapping->boardId);
        } catch (Throwable $e) {
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY the terminal against the coordination config — could not read board {$mapping->boardId} to resolve its terminal column \"{$name}\" to a stage id: ".$e->getMessage().' '.$tail);

            return;
        }
        if (! array_key_exists($name, $byName)) {
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY the terminal against the coordination config — its terminal column \"{$name}\" for board {$mapping->boardId} is not a stage on that board, so it cannot be compared against stage {$mine}. {$tail}");

            return;
        }

        $theirs = $byName[$name];
        if ($theirs === $mine) {
            yield Finding::ok("{$prefix}: coord config agrees — its terminal \"{$name}\" is stage {$theirs}, matching coord_card_terminal_stage_id");

            return;
        }

        yield Finding::warn("{$prefix}: the two movers DISAGREE on the terminal — this bridge concludes coord cards into stage {$mine}, but the coordination config's terminal for board {$mapping->boardId} is \"{$name}\" (stage {$theirs}). They will fight every cycle: the bridge moves a closed card to {$mine} and the reconcile drags it back to {$theirs}. Set coord_card_terminal_stage_id={$theirs}, or change that board's terminal_columns.");
    }
}
