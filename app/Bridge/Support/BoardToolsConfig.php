<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * The resolved `board_tools` section of a per-agent config (DL-217) — the
 * channel-identity-scoped board window an impl agent gets over the two-way
 * agent channel (board_my_cards read-proxy + board_create_card own-swimlane
 * write). Agent-keyed authz belongs on the agent's OWN config, not the
 * repo-keyed writeback.json: `swimlane_id` here IS the write scope, forced from
 * config so a caller can never name another lane.
 *
 * Parse posture MIRRORS create_coord_cards (see WritebackMapping / DL-198):
 * absent ⇒ byte-identical no-op (fromArray returns null); present-but-malformed
 * ⇒ throws at load (fail-loud — a provisioning bug must be loud, never a silent
 * fail-open). A present block MUST carry `enabled`; when enabled, the write
 * scope (board_id / swimlane_id / create_stage_id) and `auth.token_path` are
 * required (a half-configured tool cannot POST → fail closed at load, not
 * silently at dispatch).
 *
 *  - tokenPath       absolute path to the Bearer token file the Node channel
 *                    server presents; read fail-closed at request time by
 *                    SecretFile (0600 perms enforced). Same secret-file CLASS as
 *                    every other token, provision-minted (Q4). Null only when
 *                    disabled.
 *  - boardId         the product board the tools read/write.
 *  - swimlaneId      THE agent's own swimlane — the write scope, not caller-
 *                    choosable, and the read-isolation boundary (kanban scopes
 *                    reads by the token USER's board membership, never by
 *                    swimlane, so per-agent read isolation is 100% bridge-enforced
 *                    by swimlaneId + the fail-closed row filter).
 *  - createStageId   the column tool-created cards land in (typically backlog).
 *  - sharedSwimlaneId optional cross-system swimlane also included in reads.
 *  - coordBoardId    optional: enables the coordination read leg (Q1). Absent ⇒
 *                    product-only, no coord leg.
 *  - addressTags     optional: the `repo:<self>` (etc.) tags a coord card must
 *                    carry to be "addressed to me"; only consulted when
 *                    coordBoardId is set.
 */
final class BoardToolsConfig
{
    /**
     * @param  list<string>  $addressTags
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $tokenPath,
        public readonly ?int $boardId,
        public readonly ?int $swimlaneId,
        public readonly ?int $createStageId,
        public readonly ?int $sharedSwimlaneId,
        public readonly ?int $coordBoardId,
        public readonly array $addressTags,
    ) {}

    /**
     * Parse the top-level `board_tools` block from a per-agent config. Absent ⇒
     * null (the byte-identical no-op). Present ⇒ shape-validated fail-closed.
     *
     * @param  array<mixed>  $raw  the whole per-agent config array
     */
    public static function fromArray(array $raw): ?self
    {
        if (! array_key_exists('board_tools', $raw)) {
            return null;
        }
        $block = $raw['board_tools'];
        if (! is_array($block)) {
            throw new ConfigException('board_tools must be a mapping');
        }

        $enabled = $block['enabled'] ?? false;
        if (! is_bool($enabled)) {
            throw new ConfigException('board_tools.enabled must be a boolean');
        }

        // A disabled block is a well-formed no-op: it may omit the scope fields
        // entirely (an operator staging the block before minting the token).
        if (! $enabled) {
            return new self(
                enabled: false,
                tokenPath: null,
                boardId: null,
                swimlaneId: null,
                createStageId: null,
                sharedSwimlaneId: null,
                coordBoardId: null,
                addressTags: [],
            );
        }

        $tokenPath = self::requireTokenPath($block);
        $boardId = self::requireInt($block, 'board_id');
        $swimlaneId = self::requireInt($block, 'swimlane_id');
        $createStageId = self::requireInt($block, 'create_stage_id');
        $sharedSwimlaneId = self::optionalInt($block, 'shared_swimlane_id');
        $coordBoardId = self::optionalInt($block, 'coord_board_id');
        $addressTags = self::parseAddressTags($block, $coordBoardId);

        return new self(
            enabled: true,
            tokenPath: $tokenPath,
            boardId: $boardId,
            swimlaneId: $swimlaneId,
            createStageId: $createStageId,
            sharedSwimlaneId: $sharedSwimlaneId,
            coordBoardId: $coordBoardId,
            addressTags: $addressTags,
        );
    }

    /**
     * @param  array<mixed>  $block
     */
    private static function requireTokenPath(array $block): string
    {
        $auth = $block['auth'] ?? null;
        if (! is_array($auth)) {
            throw new ConfigException('board_tools.auth must be a mapping with a token_path (board_tools is enabled)');
        }
        $raw = $auth['token_path'] ?? null;
        if (! is_string($raw) || $raw === '') {
            throw new ConfigException('board_tools.auth.token_path must be a non-empty path (board_tools is enabled)');
        }

        return PathHelper::expandUser($raw);
    }

    /**
     * @param  array<mixed>  $block
     */
    private static function requireInt(array $block, string $key): int
    {
        $value = $block[$key] ?? null;
        if (! is_int($value)) {
            throw new ConfigException("board_tools.{$key} must be an integer (board_tools is enabled)");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $block
     */
    private static function optionalInt(array $block, string $key): ?int
    {
        if (! array_key_exists($key, $block) || $block[$key] === null) {
            return null;
        }
        $value = $block[$key];
        if (! is_int($value)) {
            throw new ConfigException("board_tools.{$key} must be an integer when set");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $block
     * @return list<string>
     */
    private static function parseAddressTags(array $block, ?int $coordBoardId): array
    {
        if (! array_key_exists('address_tags', $block) || $block['address_tags'] === null) {
            return [];
        }
        $raw = $block['address_tags'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ConfigException('board_tools.address_tags must be a list of strings');
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (! is_string($tag) || $tag === '') {
                throw new ConfigException('board_tools.address_tags entries must be non-empty strings');
            }
            $tags[] = $tag;
        }
        // address_tags without a coord board has nothing to filter — a config
        // that sets one but not the other is a mistake, not a silent no-op.
        if ($tags !== [] && $coordBoardId === null) {
            throw new ConfigException('board_tools.address_tags requires board_tools.coord_board_id (the coordination read leg it filters)');
        }

        return $tags;
    }
}
