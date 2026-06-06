<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ConfigException;

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
 *     "mappings": {
 *       "owner/repo": {
 *         "board_id": 8,
 *         "stages": { "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 49 },
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
    public const OUTCOMES = ['opened', 'merged', 'merged_to_main', 'closed_unmerged'];

    /**
     * @param  array<string, WritebackMapping>  $mappings  keyed by "owner/repo"
     */
    public function __construct(
        public readonly ?int $identityId,
        public readonly array $mappings,
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
            $mappings[$repo] = new WritebackMapping((int) $m['board_id'], $stages, $createDependabotCards, $swimlaneId);
        }

        return new self($identityId, $mappings);
    }

    public function mappingFor(string $repo): ?WritebackMapping
    {
        return $this->mappings[$repo] ?? null;
    }
}
