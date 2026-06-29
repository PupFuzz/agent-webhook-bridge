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
 *         "create_dependabot_cards": false,        // optional (DL-024)
 *         "swimlane_id": 31                         // optional — lane for CREATED cards (DL-027)
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
            $createDependabotCards = ($m['create_dependabot_cards'] ?? false) === true;
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
            $mappings[$repo] = new WritebackMapping((int) $m['board_id'], $stages, $createDependabotCards, $swimlaneId, $startedFromStages);
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
}
