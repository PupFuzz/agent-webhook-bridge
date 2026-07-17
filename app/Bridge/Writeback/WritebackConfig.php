<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\PathHelper;

/**
 * The bridge's writeback policy (DL-009/019), loaded from
 * `<config_dir>/writeback.json` — per-install policy, so it lives in the config
 * dir alongside `shared-identities.json`, NOT the repo-tracked `config/bridge.php`
 * (DL-007). Absent file ⇒ writeback is disabled (`load()` returns null). A
 * present-but-malformed file is FAIL-CLOSED (throws) — a half-configured
 * writeback must not silently move cards to the wrong place.
 *
 * Shape:
 *   {
 *     "identity_id": 4242,                       // the kanban user_id the writeback acts as
 *     "alert_channel": {"socket": "/abs/path"},  // optional (FR-4) — loud per-event signal on a permanent move-failure
 *     "mappings": {
 *       "owner/repo": {
 *         "board_id": 8,
 *         "stages": { "started": 49, "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 49 },
 *         "started_from_stages": [46, 47],          // optional (DL-160) — promote-from guard for `started`
 *         "unpark_from_stages": [51],               // optional (DL-194) — auto-unpark a pinned card from these stages on `started`
 *         "hold_marker_tags": ["gate"],             // optional (DL-194) — widen the auto-unpark override alert
 *         "draft_block_reason": "PR is in draft",   // optional (DL-194) — benign draft sentinel (no unpark alert)
 *         "revive_on_reopen": false,               // optional (DL-195) — a reopened abandoned PR revives its parked card (closed_unmerged → opened)
 *         "create_dependabot_cards": false,        // optional (DL-024)
 *         "card_id_tag_template": "id:DEV-pr-{n}",  // optional (#75) — id: tag stamped on created dependabot cards; {n}/{pr_number}, {repo}
 *         "create_coord_cards": false,             // optional (DL-198) — real-time coord-issue → card create
 *         "coord_card_stage_id": 21,               // required-when-create_coord_cards/move_coord_cards — stage a new coord card lands in, and the revive target
 *         "move_coord_cards": false,               // DL-200; guarded fleet default (DL-204): absent ⇒ on where coord_card_terminal_stage_id present, inert where absent
 *         "coord_card_terminal_stage_id": 99,      // required-when-move_coord_cards — terminal a closed coord card moves to (MUST differ from coord_card_stage_id)
 *         "swimlane_id": 31,                        // optional — lane for CREATED cards (DL-027)
 *         "draft_overlay": false,                   // optional (DL-193) — mirror PR draft state to block_reason
 *         "promote_on_release": false               // optional (DL-207) — on a release merge to main, promote Shipped cards now on main to Released (needs stages.merged + stages.merged_to_main)
 *       }
 *     }
 *   }
 *
 * identity_id is unioned into the global echo set so the resulting card_updated
 * webhook doesn't loop back (DL-018).
 */
final class WritebackConfig
{
    public const OUTCOMES = ['started', 'opened', 'merged', 'merged_to_main', 'closed_unmerged'];

    /**
     * @param  array<string, WritebackMapping>  $mappings  keyed by "owner/repo"
     */
    public function __construct(
        public readonly ?int $identityId,
        public readonly array $mappings,
        public readonly ?AlertChannel $alertChannel = null,
    ) {}

    /** Load the policy, or null when `writeback.json` is absent (writeback off). */
    public static function load(string $configDir): ?self
    {
        $path = rtrim($configDir, '/').'/writeback.json';
        if (! is_file($path)) {
            return null;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw) || array_is_list($raw)) {
            throw new ConfigException("writeback.json at {$path} is not a valid JSON object");
        }

        $identityId = isset($raw['identity_id']) && is_numeric($raw['identity_id']) ? (int) $raw['identity_id'] : null;

        $mappings = [];
        $rawMappings = $raw['mappings'] ?? [];
        if (! is_array($rawMappings)) {
            throw new ConfigException('writeback.json: mappings must be an object keyed by "owner/repo"');
        }
        foreach ($rawMappings as $repo => $m) {
            if (! is_string($repo) || ! is_array($m)) {
                throw new ConfigException('writeback.json: each mapping key must be a repo string and its value an object');
            }
            if (! isset($m['board_id']) || ! is_numeric($m['board_id'])) {
                throw new ConfigException("writeback.json: mapping for {$repo} needs a numeric board_id");
            }
            $rawStages = $m['stages'] ?? [];
            if (! is_array($rawStages)) {
                throw new ConfigException("writeback.json: mapping for {$repo} stages must be an object keyed by outcome");
            }
            $stages = [];
            foreach ($rawStages as $outcome => $stageId) {
                if (! in_array($outcome, self::OUTCOMES, true)) {
                    throw new ConfigException("writeback.json: mapping for {$repo} has an unknown stage outcome '{$outcome}' (allowed: ".implode(', ', self::OUTCOMES).')');
                }
                if (! is_numeric($stageId)) {
                    throw new ConfigException("writeback.json: mapping for {$repo} stage '{$outcome}' must be a numeric workflow_stage_id");
                }
                $stages[$outcome] = (int) $stageId;
            }
            // Optional promote-from guard for the `started` outcome (DL-160): the
            // list of workflow_stage_ids the branch-create `started` move is
            // allowed to promote a card FROM (the board's Backlog/Prioritized
            // stages). The handler refuses to advance a card whose current stage
            // isn't in this list, so re-creating/force-pushing an old branch can't
            // drag an already-In-Review/Shipped/Released card backward. Strict like
            // board_id/stages — a present non-list, or a non-numeric element,
            // THROWS (fail-closed) rather than silently disabling the guard.
            // Absent ⇒ null ⇒ a `started` move is refused (the guard can't know
            // what's safe to promote from), logged by the handler.
            $startedFromStages = null;
            if (array_key_exists('started_from_stages', $m) && $m['started_from_stages'] !== null) {
                if (! is_array($m['started_from_stages']) || ! array_is_list($m['started_from_stages'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} started_from_stages must be a list of workflow_stage_ids");
                }
                if ($m['started_from_stages'] === []) {
                    throw new ConfigException("writeback.json: mapping for {$repo} started_from_stages must be non-empty (an empty list silently disables the `started` move; omit the key to disable instead)");
                }
                $startedFromStages = [];
                foreach ($m['started_from_stages'] as $sid) {
                    if (! is_numeric($sid)) {
                        throw new ConfigException("writeback.json: mapping for {$repo} started_from_stages must contain only numeric workflow_stage_ids");
                    }
                    $startedFromStages[] = (int) $sid;
                }
            }
            // Optional auto-unpark set for the `started` outcome (DL-194): the stage
            // ids a branch-create `started` move promotes a card FROM even when the
            // card is PINNED (the DL-178 reversal, scoped to these stages). Parsed
            // strictly like started_from_stages — a present non-list, an empty list,
            // or a non-numeric element THROWS (fail-closed), never silently disables.
            $unparkFromStages = null;
            if (array_key_exists('unpark_from_stages', $m) && $m['unpark_from_stages'] !== null) {
                if (! is_array($m['unpark_from_stages']) || ! array_is_list($m['unpark_from_stages'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} unpark_from_stages must be a list of workflow_stage_ids");
                }
                if ($m['unpark_from_stages'] === []) {
                    throw new ConfigException("writeback.json: mapping for {$repo} unpark_from_stages must be non-empty (an empty list silently disables auto-unpark; omit the key to disable instead)");
                }
                $unparkFromStages = [];
                foreach ($m['unpark_from_stages'] as $sid) {
                    if (! is_numeric($sid)) {
                        throw new ConfigException("writeback.json: mapping for {$repo} unpark_from_stages must contain only numeric workflow_stage_ids");
                    }
                    $unparkFromStages[] = (int) $sid;
                }
            }
            // A stage cannot be both refuse-if-pinned (started_from_stages) and
            // move-if-pinned (unpark_from_stages) — fail-closed on any overlap (DL-194).
            if ($startedFromStages !== null && $unparkFromStages !== null) {
                $overlap = array_values(array_intersect($startedFromStages, $unparkFromStages));
                if ($overlap !== []) {
                    throw new ConfigException("writeback.json: mapping for {$repo} stage id(s) ".implode(', ', $overlap).' appear in BOTH started_from_stages and unpark_from_stages — a stage cannot be both refuse-if-pinned (started_from_stages) and move-if-pinned (unpark_from_stages)');
                }
            }
            // Optional install-specific hold-marker tags (DL-194) that WIDEN the unpark
            // alert set (catch a hold convention PinGuard doesn't recognize). Absent ⇒
            // [] (the fail-safe alerts on every non-benign unpark). An empty list is a
            // VALID declared state (unlike unpark_from_stages), so it is not rejected.
            $holdMarkerTags = [];
            if (array_key_exists('hold_marker_tags', $m) && $m['hold_marker_tags'] !== null) {
                if (! is_array($m['hold_marker_tags']) || ! array_is_list($m['hold_marker_tags'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} hold_marker_tags must be a list of tag strings");
                }
                foreach ($m['hold_marker_tags'] as $tag) {
                    if (! is_string($tag) || $tag === '') {
                        throw new ConfigException("writeback.json: mapping for {$repo} hold_marker_tags must contain only non-empty tag strings");
                    }
                    $holdMarkerTags[] = $tag;
                }
            }
            // Optional benign automated-draft `block_reason` sentinel (DL-194). Parsed
            // strictly: a PRESENT value must be a non-empty string — an empty string
            // would collapse the benign-draft/human-hold distinction and silently
            // disable draft-park suppression (a noise regression). Absent ⇒ null ⇒ the
            // handler resolves the KanbanBlockReasonHandler::MARKER default.
            $draftBlockReason = null;
            if (array_key_exists('draft_block_reason', $m) && $m['draft_block_reason'] !== null) {
                if (! is_string($m['draft_block_reason']) || $m['draft_block_reason'] === '') {
                    throw new ConfigException("writeback.json: mapping for {$repo} draft_block_reason must be a non-empty string");
                }
                $draftBlockReason = $m['draft_block_reason'];
            }
            // Opt-in id-tag template (#75 / card-4485): when set, KanbanDependabotCardHandler
            // stamps a rendered `id:` provenance tag on each dependabot card it creates, so a
            // tag-keyed Shipped→Released promoter can find them (the bridge otherwise mints
            // dependabot cards without the id: tag its impl-created siblings carry). Free-form
            // per-tenant grammar (sola: `id:DEV-pr-{n}`; AIMLA: `id:dep:{repo}#{n}`); placeholders
            // {n}=pr_number, {repo}=repo NAME. Absent/null ⇒ no tag (inert, back-compat).
            $cardIdTagTemplate = null;
            if (array_key_exists('card_id_tag_template', $m) && $m['card_id_tag_template'] !== null) {
                if (! is_string($m['card_id_tag_template']) || $m['card_id_tag_template'] === '') {
                    throw new ConfigException("writeback.json: mapping for {$repo} card_id_tag_template must be a non-empty string");
                }
                $cardIdTagTemplate = $m['card_id_tag_template'];
            }
            // Opt-in promote-on-release (DL-207). Plain bool, default false — parsed like
            // draft_overlay/revive_on_reopen, so a promote_on_release-absent config is
            // byte-identical. The leg REUSES stages.merged (Shipped source) + stages.merged_to_main
            // (Released target), so BOTH are required when it is on: without them the scan has no
            // source or target stage and would silently never move a card. Fail LOUD at load (the
            // DL-160/198 fail-closed precedent) rather than no-op quietly at dispatch.
            $promoteOnRelease = ($m['promote_on_release'] ?? false) === true;
            if ($promoteOnRelease && (! isset($stages['merged']) || ! isset($stages['merged_to_main']))) {
                throw new ConfigException("writeback.json: mapping for {$repo} sets promote_on_release but is missing stages.merged and/or stages.merged_to_main — the promote leg reads cards at the Shipped stage (stages.merged) and moves them to the Released stage (stages.merged_to_main); set both (or remove promote_on_release)");
            }
            $createDependabotCards = ($m['create_dependabot_cards'] ?? false) === true;
            // Opt-in draft → block_reason overlay (DL-193). Plain bool, default false —
            // parsed exactly like create_dependabot_cards (a non-`true` value, absent or
            // otherwise, disables it), so a draft_overlay-absent config is byte-identical
            // to today. NOT a stage-mapped outcome, so WritebackConfig::OUTCOMES is unchanged.
            $draftOverlay = ($m['draft_overlay'] ?? false) === true;
            // Opt-in Won't-Do-revival (DL-195). Plain bool, default false — parsed exactly
            // like draft_overlay/create_dependabot_cards, so a revive_on_reopen-absent config
            // is byte-identical to today. NOT a stage-mapped outcome (the `reopened` move
            // outcome reuses `stages.opened`), so WritebackConfig::OUTCOMES is unchanged.
            $reviveOnReopen = ($m['revive_on_reopen'] ?? false) === true;
            // Optional lane for CREATED cards (DL-027). Strict like board_id/stages —
            // a non-numeric swimlane_id THROWS rather than silently dropping to null
            // (which would land cards in the default lane with no error, the fail-quiet
            // trap DL-026 fought). Absent ⇒ null ⇒ POST omits swimlane_id (today's behavior).
            $swimlaneId = null;
            if (array_key_exists('swimlane_id', $m) && $m['swimlane_id'] !== null) {
                if (! is_numeric($m['swimlane_id'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} swimlane_id must be a numeric swimlane id");
                }
                $swimlaneId = (int) $m['swimlane_id'];
            }
            // Opt-in coordination-issue → card create (DL-198). Plain bool, default
            // false — parsed exactly like create_dependabot_cards, so a
            // create_coord_cards-absent config is byte-identical to today.
            $createCoordCards = ($m['create_coord_cards'] ?? false) === true;
            // The stage a created coord card lands in (DL-198). Strict-numeric like
            // swimlane_id, and REQUIRED-when-create_coord_cards: a create with no
            // stage can't POST, so an absent stage while create_coord_cards is on
            // must fail LOUD at load (fail-closed), not silently no-op at dispatch.
            $coordCardStageId = null;
            if (array_key_exists('coord_card_stage_id', $m) && $m['coord_card_stage_id'] !== null) {
                if (! is_numeric($m['coord_card_stage_id'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} coord_card_stage_id must be a numeric workflow_stage_id");
                }
                $coordCardStageId = (int) $m['coord_card_stage_id'];
            }
            if ($createCoordCards && $coordCardStageId === null) {
                throw new ConfigException("writeback.json: mapping for {$repo} sets create_coord_cards but no coord_card_stage_id — a coord-card create has no stage to POST to; set coord_card_stage_id (or remove create_coord_cards)");
            }
            // The terminal a coord card moves to when its issue closes (DL-200). Strict-numeric.
            // Its PRESENCE is also the "operator configured the move leg" signal for the fleet
            // default below — the key has no other consumer. Under an EXPLICIT move_coord_cards:true
            // an absent terminal is fail-closed at load (the guard after the resolution): a move
            // with no terminal has nowhere to PATCH to.
            $coordCardTerminalStageId = null;
            if (array_key_exists('coord_card_terminal_stage_id', $m) && $m['coord_card_terminal_stage_id'] !== null) {
                if (! is_numeric($m['coord_card_terminal_stage_id'])) {
                    throw new ConfigException("writeback.json: mapping for {$repo} coord_card_terminal_stage_id must be a numeric workflow_stage_id");
                }
                $coordCardTerminalStageId = (int) $m['coord_card_terminal_stage_id'];
            }
            // Coordination-issue → card MOVE/revive (DL-200), a guarded FLEET DEFAULT (DL-204, #4357).
            // Resolved separately from create_coord_cards (roundtable #18 "opt-in first") — a
            // move-on/create-off mapping is coherent, so this must NOT ride createCoordCards.
            // EXPLICIT move_coord_cards is honored exactly (opt-in still fail-closed on an incomplete
            // config below; opt-out still silent-off). When the key is ABSENT the leg defaults ON
            // where the move config is complete (terminal present) and INERT where it is not — so an
            // install that never configured a terminal upgrades byte-identically, while one whose
            // per-board stage ids are already present activates without also setting the flag. This
            // governs only the handler-side gate; the classifier's coord-card-move family is a
            // separate opt-in, so the leg fires only where BOTH are on — bridge:check nudges an
            // install that enabled the family but left this leg inert (the terminal absent). A
            // PARTIAL default-on config (terminal present, revive stage missing/equal) is made LOUD
            // by the guards below, never a silent no-op.
            if (array_key_exists('move_coord_cards', $m) && $m['move_coord_cards'] !== null) {
                $moveCoordCards = $m['move_coord_cards'] === true;
            } else {
                $moveCoordCards = $coordCardTerminalStageId !== null;
            }
            if ($moveCoordCards && $coordCardTerminalStageId === null) {
                throw new ConfigException("writeback.json: mapping for {$repo} sets move_coord_cards but no coord_card_terminal_stage_id — a coord-card close has no terminal stage to move to; set coord_card_terminal_stage_id (or remove move_coord_cards)");
            }
            // coord_card_stage_id is the REVIVE target under move_coord_cards (the stage a
            // reopened card returns to — the same stage a fresh card lands in, mirroring
            // DL-195's "revive reuses stages.opened"). Absent ⇒ the leg half-works: closes
            // land, reopens silently no-op. Fail closed.
            if ($moveCoordCards && $coordCardStageId === null) {
                throw new ConfigException("writeback.json: mapping for {$repo} has coord_card_terminal_stage_id set (so the move leg is on — explicitly via move_coord_cards or by the DL-204 default) but no coord_card_stage_id — a reopened coord card has no stage to revive to; set coord_card_stage_id, or remove coord_card_terminal_stage_id to disable the move leg");
            }
            // Disjointness, fail-closed (the DL-194 unpark_from_stages precedent): an equal
            // terminal and create/revive stage collapses both transitions onto one stage —
            // close→terminal and reopen→revive become indistinguishable no-ops.
            if ($coordCardTerminalStageId !== null && $coordCardTerminalStageId === $coordCardStageId) {
                throw new ConfigException("writeback.json: mapping for {$repo} coord_card_terminal_stage_id must differ from coord_card_stage_id — a coord card cannot conclude into the same stage it is created/revived in");
            }
            $mappings[$repo] = new WritebackMapping((int) $m['board_id'], $stages, $createDependabotCards, $swimlaneId, $startedFromStages, $draftOverlay, $unparkFromStages, $holdMarkerTags, $draftBlockReason, $reviveOnReopen, $createCoordCards, $coordCardStageId, $moveCoordCards, $coordCardTerminalStageId, $cardIdTagTemplate, $promoteOnRelease);
        }

        return new self($identityId, $mappings, self::parseAlertChannel($raw));
    }

    /**
     * Parse the optional top-level `alert_channel` (FR-4). Deliberately NOT
     * fail-closed like the mappings above: a malformed alert_channel must not
     * disable the whole writeback (every card move would go dark for an opt-in
     * diagnostic). A non-object / absent value ⇒ null ⇒ log-only. A mutually-
     * exclusive socket/url violation (both or neither) is carried through as-is
     * and surfaced by `bridge:check` (warn) / a caught runtime push failure —
     * the notifier rejects an invalid channel before sending.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function parseAlertChannel(array $raw): ?AlertChannel
    {
        $ac = $raw['alert_channel'] ?? null;
        if (! is_array($ac)) {
            return null;
        }
        $socket = is_string($ac['socket'] ?? null) && $ac['socket'] !== '' ? $ac['socket'] : null;
        if ($socket !== null) {
            try {
                // Mirror DL-039 channel.socket expansion (AgentConfig), applied at LOAD so the
                // resolved path flows to BOTH the runtime push and bridge:check's parent-dir probe.
                $socket = PathHelper::expandRuntimeTokens($socket);
            } catch (ConfigException) {
                // DL-171 fail-OPEN invariant: a malformed alert_channel must only WARN, never fail
                // the whole writeback closed (every card move would go dark over an opt-in
                // diagnostic). channel.socket lets this throw (fail-closed); alert_channel must not.
                // Keep the unexpanded value → SocketPath::isValid rejects it → bridge:check warns +
                // the runtime push is caught (log-only), exactly like any other invalid alert_channel.
            }
        }
        $url = is_string($ac['url'] ?? null) && $ac['url'] !== '' ? $ac['url'] : null;
        $auth = $ac['auth'] ?? null;
        $tokenPath = is_array($auth) && is_string($auth['token_path'] ?? null) && $auth['token_path'] !== ''
            ? $auth['token_path']
            : null;

        return new AlertChannel($socket, $url, $tokenPath);
    }

    public function mappingFor(string $repo): ?WritebackMapping
    {
        return $this->mappings[$repo] ?? null;
    }

    /**
     * Whether more than one repo mapping targets the given board. Repo-qualified
     * correlation (DL-167) exists to disambiguate DL/PR-number collisions across
     * repos SHARING a board; on a 1:1 board the qualifier protects nothing and a
     * strict kanban `source` filter would exclude cards whose derived refs carry
     * no source (every operator-stamped `dl_number`/`pr_number` card — DL-174).
     */
    public function boardIsShared(int $boardId): bool
    {
        $n = 0;
        foreach ($this->mappings as $mapping) {
            if ($mapping->boardId === $boardId && ++$n > 1) {
                return true;
            }
        }

        return false;
    }
}
