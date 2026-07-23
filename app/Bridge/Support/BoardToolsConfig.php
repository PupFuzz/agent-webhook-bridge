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
 * Classification (DL-217 → default-ON, structural per v7). The block is CLASSIFIED
 * before it is parsed, and that class decides whether a malformation THROWS or
 * SUPPRESSES:
 *   1. `board_tools` ABSENT ⇒ null config (byte-identical no-op).
 *   2. EXPLICIT (`enabled: true`, strict bool) ⇒ the DL-217 fail-loud posture:
 *      requireBearer / requireInt / optionalInt / parseAddressTags all THROW on
 *      malformation. An operator-written assertion that cannot be satisfied is
 *      malformed config; loud-at-load stands.
 *   3. DISABLED (`enabled: false`, strict bool) ⇒ well-formed no-op (staging /
 *      opt-out); the rest of the block is not parsed.
 *   4. EVERYTHING ELSE PRESENT ⇒ DEFAULT-CLASS, and it NEVER throws: a non-array
 *      block, a non-bool `enabled` (incl. bare `enabled:` → null — array_key_exists
 *      discriminates absent from null), or `enabled` absent with an unsatisfiable
 *      requirement all SUPPRESS (enabled=false + a suppressedReason). A default that
 *      cannot be satisfied disables itself LOUDLY-at-check (bridge:check FAILs on any
 *      suppressedReason), never fatally-at-load — so one under-configured agent can
 *      never 5xx the whole fleet via SubscriptionRegistry.
 *
 * Transport (card 4952): `transport: http|ssh` (default `ssh` since v0.68.0 /
 * DL-225 — an unset key now reads as `ssh`, NOT the pre-0.68.0 `http`) selects
 * which FRONT DOOR authenticates the call. `http` ⇒ the loopback POST resolves the
 * agent by bearer (the channel token post-DL-222). `ssh` ⇒ the SSH-forced-command
 * `bridge:tools-call` resolves the agent by the pinned `--agent` name and carries
 * NO bearer — so an ssh block that also writes `board_tools.auth` is contradictory
 * and fails (explicit ⇒ throw, default ⇒ suppress).
 *
 * Bearer resolution (single site, HTTP transport only): `tokenPath :=
 * board_tools.auth.token_path (the deprecation ALIAS, honored first) ?? the
 * agent's channel token (channel.auth.token_path)`. INVARIANT (transport-scoped):
 * for `transport: 'http'`, `enabled === true ⟹ tokenPath !== null`. For
 * `transport: 'ssh'`, an enabled config legitimately has `tokenPath === null` —
 * identity is the forced-command `--agent`, not a bearer, so consumers that key
 * off the HTTP index (BoardToolAgentResolver, the --probe-tools loop) must
 * exclude ssh agents explicitly.
 *
 *  - tokenPath       absolute path to the Bearer token file the Node channel
 *                    server presents; read fail-closed at request time by
 *                    SecretFile (0600 perms enforced). Same secret-file CLASS as
 *                    every other token. Null only when disabled/suppressed.
 *  - bearerFromChannel  true when tokenPath defaulted to the channel token (no
 *                    explicit auth.token_path alias). bridge:provision-tools skips
 *                    such agents (nothing to mint — the channel token is
 *                    provisioned elsewhere), and a collision message on them names
 *                    the channel token as the fix site.
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
 *  - suppressedReason  non-null ONLY on the default-suppressed path (always null
 *                    when enabled or explicitly disabled); the message bridge:check
 *                    renders as a FAIL.
 *  - sshAccount      optional OS account name the SSH forced command runs as. Only
 *                    meaningful for transport 'ssh' — it tells the bridge:check probe
 *                    which account's sshd posture / authorized_keys to certify
 *                    (default: the invoking run-user). Parse-and-store; the probe
 *                    decides how to use it. Null ⇒ the invoking account (byte-identical
 *                    to pre-4977).
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
        public readonly bool $bearerFromChannel = false,
        public readonly ?string $suppressedReason = null,
        public readonly string $transport = 'ssh',
        public readonly ?string $sshAccount = null,
        // true iff a `transport` key was PRESENT in the parsed block; false when it
        // fell through to the default. bridge:check's v0.68.0 pre-upgrade advisory
        // (DL-225) keys on this to flag agents that landed on ssh by the flipped
        // default rather than by an explicit operator choice.
        public readonly bool $transportExplicit = false,
    ) {}

    /**
     * Parse the top-level `board_tools` block from a per-agent config. Absent ⇒
     * null (the byte-identical no-op). See the class docblock for the four
     * classification branches. `$channel` is the agent's already-resolved channel
     * config (AgentConfig resolves it BEFORE board_tools); its token is the default
     * bearer when the block carries no `auth.token_path` alias.
     *
     * @param  array<mixed>  $raw  the whole per-agent config array
     */
    public static function fromArray(array $raw, ?ChannelConfig $channel = null): ?self
    {
        if (! array_key_exists('board_tools', $raw)) {
            return null;   // (1) absent
        }
        $block = $raw['board_tools'];
        $isArray = is_array($block);
        $enabledKeyPresent = $isArray && array_key_exists('enabled', $block);

        // (2) EXPLICIT: is_array AND enabled === true (strict) — fail-loud on any
        // malformation (require*/parse* throw; an unsatisfiable explicit assertion
        // is malformed config).
        if ($enabledKeyPresent && $block['enabled'] === true) {
            return self::build($block, $channel);
        }

        // (3) DISABLED: is_array AND enabled === false (strict) — well-formed no-op.
        if ($enabledKeyPresent && $block['enabled'] === false) {
            return self::disabled();
        }

        // (4) DEFAULT-CLASS: everything else present — NEVER throws.
        if (! $isArray) {
            return self::suppressed('board_tools must be a mapping — default-on suppressed');
        }
        if ($enabledKeyPresent) {
            // enabled is present but not a strict bool (a string, an int, or bare
            // `enabled:` → null). A "false"-string classifying as default-then-on
            // would fail OPEN on a typo, so suppress rather than attempt enablement.
            return self::suppressed('board_tools.enabled must be a boolean (only true/false — symfony/yaml does not booleanize yes/no/on) — default-on suppressed');
        }

        // enabled absent → attempt satisfaction with the SAME require*/optional*/
        // parse* calls as the explicit path; any ConfigException suppresses.
        try {
            return self::build($block, $channel);
        } catch (ConfigException $e) {
            return self::suppressed($e->getMessage());
        }
    }

    private static function disabled(): self
    {
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

    private static function suppressed(string $reason): self
    {
        return new self(
            enabled: false,
            tokenPath: null,
            boardId: null,
            swimlaneId: null,
            createStageId: null,
            sharedSwimlaneId: null,
            coordBoardId: null,
            addressTags: [],
            suppressedReason: $reason,
        );
    }

    /**
     * Resolve the full scope into an enabled config. Shared by the explicit path
     * (throws propagate) and the default path (the caller catches ConfigException
     * and suppresses). Every helper here throws ONLY a board_tools-scoped
     * ConfigException — expandUser cannot throw (unlike expandRuntimeTokens, which
     * stays out of this call graph); a future field parsed here must preserve that.
     *
     * @param  array<mixed>  $block
     */
    private static function build(array $block, ?ChannelConfig $channel): self
    {
        // Transport is parsed FIRST — it gates whether a bearer is required at all.
        $transportExplicit = array_key_exists('transport', $block);
        $transport = self::parseTransport($block);
        [$tokenPath, $bearerFromChannel] = self::requireBearer($block, $channel, $transport);
        $boardId = self::requireInt($block, 'board_id');
        $swimlaneId = self::requireInt($block, 'swimlane_id');
        $createStageId = self::requireInt($block, 'create_stage_id');
        $sharedSwimlaneId = self::optionalInt($block, 'shared_swimlane_id');
        $coordBoardId = self::optionalInt($block, 'coord_board_id');
        $addressTags = self::parseAddressTags($block, $coordBoardId);
        $sshAccount = self::optionalString($block, 'ssh_account');

        return new self(
            enabled: true,
            tokenPath: $tokenPath,
            boardId: $boardId,
            swimlaneId: $swimlaneId,
            createStageId: $createStageId,
            sharedSwimlaneId: $sharedSwimlaneId,
            coordBoardId: $coordBoardId,
            addressTags: $addressTags,
            bearerFromChannel: $bearerFromChannel,
            transport: $transport,
            sshAccount: $sshAccount,
            transportExplicit: $transportExplicit,
        );
    }

    /**
     * The board-tools transport: `http` or `ssh` (default `ssh` since v0.68.0 /
     * DL-225). An absent key reads as `ssh` — the pre-0.68.0 default was `http`, so
     * a config relying on the implicit default must now pin `transport: http`
     * explicitly to keep the loopback path (see the UPGRADING note). A bad value
     * THROWS — like every other malformation, it fails loud on the explicit path and
     * suppresses on the default path (the caller catches ConfigException); never
     * fail-open.
     *
     * @param  array<mixed>  $block
     */
    private static function parseTransport(array $block): string
    {
        if (! array_key_exists('transport', $block)) {
            return 'ssh';
        }
        $transport = $block['transport'];
        if ($transport !== 'http' && $transport !== 'ssh') {
            throw new ConfigException("board_tools.transport must be 'http' or 'ssh' (default ssh)");
        }

        return $transport;
    }

    /**
     * The tools bearer PATH and whether it defaulted to the channel token.
     *
     * For `transport: 'ssh'` there is NO bearer — identity is the pinned
     * forced-command `--agent`, resolved by name — so this returns `[null, false]`.
     * BUT any `board_tools.auth` key the operator wrote is a bearer intent ssh
     * cannot honor: a contradictory block that must FAIL, not be silently swallowed
     * (DR2-1, maximally fail-closed). `array_key_exists('auth', $block)` is pinned
     * over `$block['auth'] ?? null` so it also catches bare `auth:` (→ null) and
     * `auth: {}`. The throw is a board_tools-scoped ConfigException, so it inherits
     * the 4-branch throw/suppress classification (explicit ⇒ fatal at load, default
     * ⇒ suppressed by the caller) — no new fork.
     *
     * For `transport: 'http'`, the `board_tools.auth.token_path` alias is honored
     * FIRST (deprecation path, bridge:check warns); absent, the bearer reuses the
     * agent's channel token; throws only when NEITHER exists (unsatisfiable).
     *
     * @param  array<mixed>  $block
     * @return array{0: ?string, 1: bool} [tokenPath, bearerFromChannel]
     */
    private static function requireBearer(array $block, ?ChannelConfig $channel, string $transport): array
    {
        if ($transport === 'ssh') {
            if (array_key_exists('auth', $block)) {
                throw new ConfigException('board_tools.transport: ssh authenticates by the forced-command --agent identity and carries NO bearer — remove board_tools.auth');
            }

            return [null, false];
        }

        $auth = $block['auth'] ?? null;
        if ($auth !== null) {
            if (! is_array($auth)) {
                throw new ConfigException('board_tools.auth must be a mapping with a token_path');
            }
            $raw = $auth['token_path'] ?? null;
            if ($raw !== null) {
                if (! is_string($raw) || $raw === '') {
                    throw new ConfigException('board_tools.auth.token_path must be a non-empty path');
                }

                return [PathHelper::expandUser($raw), false];
            }
        }

        // No alias → reuse the agent's channel token (the default bearer).
        if ($channel !== null && $channel->tokenPath !== null) {
            return [$channel->tokenPath, true];
        }

        // Unsatisfiable — name the cure by transport.
        if ($channel !== null && $channel->url !== null) {
            throw new ConfigException('board_tools enabled but no bearer: set channel.auth.token_path (reused automatically) or board_tools.auth.token_path');
        }

        throw new ConfigException('board_tools enabled but no channel token exists to reuse (no HTTP channel) — set board_tools.auth.token_path, or use the HTTP transport (channel.url) with a channel.auth.token_path');
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
     */
    private static function optionalString(array $block, string $key): ?string
    {
        if (! array_key_exists($key, $block) || $block[$key] === null) {
            return null;
        }
        $value = $block[$key];
        if (! is_string($value) || $value === '') {
            throw new ConfigException("board_tools.{$key} must be a non-empty string when set");
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
