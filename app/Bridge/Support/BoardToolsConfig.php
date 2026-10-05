<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ServedTools;

/**
 * The resolved `board_tools` section of a per-agent config (DL-217) — the
 * channel-identity-scoped board window a seat's agent gets over the two-way
 * agent channel. WHICH tools that window contains is
 * {@see BoardToolsRegistry}'s to say and is deliberately not
 * enumerated here: this docblock named two, a third arrived with DL-326, and a list
 * kept in the consumer of a registry is a list that goes quietly stale.
 * Agent-keyed authz belongs on the agent's OWN config, not the
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
 *   3a. RETIRED (`retired: "<date> — <reason>"`, card#8973 / DL-360) ⇒ a well-formed
 *      no-op that also carries the operator's DECISION, which is the only thing that
 *      silences the lost-block leg. Read only where `enabled` is ABSENT or explicitly
 *      `false`: beside `enabled: true` it is a contradiction and THROWS like every other
 *      explicit-assertion malformation, and beside a NON-BOOL `enabled` it is ignored so
 *      that the typo below keeps suppressing rather than minting a durable tombstone.
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
 *  - retiredReason   non-null ONLY on the retired path (always null when enabled,
 *                    plainly disabled, or suppressed) — the operator's own sentence
 *                    VERBATIM, recorded as a durable tombstone and printed back to
 *                    them by bridge:check's lost-block leg.
 *  - sshAccount      optional OS account name the SSH forced command runs as. Only
 *                    meaningful for transport 'ssh' — it tells the bridge:check probe
 *                    which account's sshd posture / authorized_keys to certify
 *                    (default: the invoking run-user). Parse-and-store; the probe
 *                    decides how to use it. Null ⇒ the invoking account (byte-identical
 *                    to pre-4977).
 *  - fleetView       `fleet_view: true` — this agent may read the whole fleet through the
 *                    client-update door's `client_fleet` op (card#10567 / DL-432).
 *  - clientUpdateApprovalRequired  `client_update.approval_required: true` — the door
 *                    offers this agent a published client pack only once its content is
 *                    approved (DL-433).
 *  - descriptionMaxBytes  the PER-CARD byte cap board_my_cards cuts a description to
 *                    when a caller passes `include_description: true` (DL-245). Never
 *                    consulted on the default path — an absent argument omits the field
 *                    entirely. The right value is a property of the install's cards
 *                    (a scope statement runs to several KB), which is why it is
 *                    operator-settable rather than a constant.
 */
final class BoardToolsConfig
{
    /**
     * Per-card description byte cap when none is configured (DL-245). Sized to
     * carry a real scope statement WHOLE — the motivating case was a 7,805-byte
     * one — while still bounding a pathological description.
     */
    public const DEFAULT_DESCRIPTION_MAX_BYTES = 16384;

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
        public readonly int $descriptionMaxBytes = self::DEFAULT_DESCRIPTION_MAX_BYTES,
        // The operator's own `retired:` sentence, VERBATIM, or null. Non-null ONLY on the
        // retired path — never beside `enabled: true` (that contradiction throws) and never
        // beside a `suppressedReason`, so the discriminator `NextSteps::stateOf()` draws
        // between "a default-on block that could not satisfy itself" and "a decision" is
        // untouched: a retired block reaches it as enabled=false with no suppressedReason
        // and correctly owes no next step.
        public readonly ?string $retiredReason = null,
        // card#10567 B4: `fleet_view: true` — this agent (the PM's seat) may read the whole fleet
        // through the `client_fleet` op. False for everyone else, and for every non-enabled block.
        public readonly bool $fleetView = false,
        // card#10567 B4: `client_update.approval_required: true` — the client-update door offers this
        // agent a published client pack only once `bridge:client-approve` has approved its content.
        public readonly bool $clientUpdateApprovalRequired = false,
        // card#11283: whether this agent is served the self-scoped CI tools (`ci_await`,
        // `ci_await_cancel`). `board_tools.ci_tools: false` opts out; a value that is not a strict
        // bool opts out too and names itself in `ciToolsProblem`, which bridge:check FAILs on. It
        // never throws: an opt-out that cannot be read must not take the agent's door down with it.
        public readonly bool $ciTools = true,
        public readonly ?string $ciToolsProblem = null,
    ) {}

    /**
     * The keys whose presence makes a block SCOPED (card#11283). An explicit block carrying NONE
     * of them — by `array_key_exists`, so a key written as bare `board_id:` counts as present and
     * takes the scoped path, where it fails as it does today — is a scope-less block: an enabled
     * agent that is served the CI tools and no board tool.
     */
    public const SCOPE_KEYS = ['board_id', 'swimlane_id', 'create_stage_id', 'shared_swimlane_id', 'coord_board_id', 'address_tags'];

    /**
     * Keys that only mean something to a scoped agent, refused on a scope-less block rather than
     * silently ignored: `description_max_bytes` caps a board read the agent is never served, and
     * `fleet_view` opens the whole fleet's client states to the PM's seat, which a CI-only seat is
     * not (card#11283 ruling). `client_update` stays: the update door serves a scope-less agent.
     */
    private const SCOPED_ONLY_KEYS = ['description_max_bytes', 'fleet_view'];

    /**
     * An ENABLED block with no board scope (card#11283): served the CI tools and nothing that
     * reads or writes a board. {@see ServedTools} is what turns this into a
     * served set; nothing else should branch on it to decide what an agent may call.
     */
    public function isScopeless(): bool
    {
        return $this->enabled && $this->boardId === null;
    }

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
        // The `$isArray` conjunct is what makes this safe on a non-mapping block, exactly as
        // it does one line above — `array_key_exists` on a non-array is fatal, and the
        // idiom that already guards `enabled` guards this too, so the classification below
        // needs no reordering and the non-mapping branch stays where it is.
        $retiredKeyPresent = $isArray && array_key_exists('retired', $block);

        // (3a) RETIRED (card#8973 / DL-360): the operator's explicit statement that this
        // seat is decommissioned, which is the ONE thing that silences the lost-block leg.
        // It is CLASS 3a — a well-formed no-op, like (3) — and it is TESTED first because
        // its two guards are about `enabled`: the contradiction has to throw before (2)
        // enables the block, and the honouring arm has to run before (3) disables it and
        // drops the reason on the floor.
        //
        // ⛔ IT IS HONOURED ONLY WHERE `enabled` IS ABSENT OR EXPLICITLY `false`, and the
        // non-bool case is the reason the guard is written this way rather than as a strict
        // `enabled !== true`. `board_tools: {enabled: yes, retired: "…"}` is the exact typo
        // the non-bool arm below exists to catch — `symfony/yaml` does not booleanize `yes`
        // — and letting it reach this arm would turn a malformed block into a DURABLE
        // tombstone silencing that seat forever, where today it suppresses and FAILs. A
        // malformed `enabled` keeps suppressing whatever else the block carries.
        if ($retiredKeyPresent) {
            if ($enabledKeyPresent && $block['enabled'] === true) {
                // Consistent with every other explicit-assertion malformation in this file:
                // an operator asserting both "this seat is live" and "this seat is retired"
                // has written config that cannot be satisfied, and guessing which half they
                // meant is how a decommission silently un-decommissions itself.
                throw new ConfigException('board_tools.retired contradicts enabled: true — remove one');
            }
            if (! $enabledKeyPresent || $block['enabled'] === false) {
                $reason = $block['retired'];
                if (is_string($reason) && trim($reason) !== '') {
                    return self::retired($reason);
                }

                // Fail-closed, and the block is still PRESENT — so the lost-block leg stays
                // silent for this agent and the operator gets ONE failure to fix rather
                // than a suppression FAIL plus a LOST FAIL for one defect.
                return self::suppressed('board_tools.retired must be a non-empty string naming the date and reason, e.g. "2026-09-08 — seat decommissioned" — default-on suppressed');
            }
        }

        // (2) EXPLICIT: is_array AND enabled === true (strict) — fail-loud on any
        // malformation (require*/parse* throw; an unsatisfiable explicit assertion
        // is malformed config).
        if ($enabledKeyPresent && $block['enabled'] === true) {
            // card#11283: only an EXPLICIT block can be scope-less. A default-class block with no
            // scope keeps failing `requireInt` below and suppressing, as it always has, so no
            // YAML that loads today changes meaning — every block that is newly valid here threw.
            return self::carriesScopeKey($block) ? self::build($block, $channel) : self::buildScopeless($block, $channel);
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

    /**
     * An explicitly RETIRED seat: a well-formed no-op that also carries the operator's
     * decision, so the lost-block leg can say WHY it is silent instead of merely being it.
     *
     * `suppressedReason` stays null on purpose — a retirement is a decision, not a
     * default-on block that could not satisfy itself, and collapsing the two would put the
     * seat back in `BoardToolsSuppressedCheck`'s FAIL population.
     */
    private static function retired(string $reason): self
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
            retiredReason: $reason,
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
        $descriptionMaxBytes = self::optionalPositiveInt($block, 'description_max_bytes') ?? self::DEFAULT_DESCRIPTION_MAX_BYTES;
        $fleetView = self::optionalBool($block, 'fleet_view', 'board_tools.fleet_view');
        $approvalRequired = self::parseApprovalRequired($block);
        [$ciTools, $ciToolsProblem] = self::parseCiTools($block);

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
            descriptionMaxBytes: $descriptionMaxBytes,
            fleetView: $fleetView,
            clientUpdateApprovalRequired: $approvalRequired,
            ciTools: $ciTools,
            ciToolsProblem: $ciToolsProblem,
        );
    }

    /**
     * @param  array<mixed>  $block
     */
    private static function carriesScopeKey(array $block): bool
    {
        foreach (self::SCOPE_KEYS as $key) {
            if (array_key_exists($key, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An explicit block with no scope key (card#11283). The door half — transport, bearer,
     * ssh account, client-update approval — parses exactly as {@see build} parses it, so a
     * scope-less agent authenticates through the same doors as any other; the scope stays null,
     * which is what {@see isScopeless} reads.
     *
     * @param  array<mixed>  $block
     */
    private static function buildScopeless(array $block, ?ChannelConfig $channel): self
    {
        foreach (self::SCOPED_ONLY_KEYS as $key) {
            if (array_key_exists($key, $block)) {
                throw new ConfigException("board_tools.{$key} needs a board scope (board_id, swimlane_id, create_stage_id) — a block with none is CI-tools-only; remove {$key}, or add the scope");
            }
        }
        $transportExplicit = array_key_exists('transport', $block);
        $transport = self::parseTransport($block);
        [$tokenPath, $bearerFromChannel] = self::requireBearer($block, $channel, $transport);
        [$ciTools, $ciToolsProblem] = self::parseCiTools($block);

        return new self(
            enabled: true,
            tokenPath: $tokenPath,
            boardId: null,
            swimlaneId: null,
            createStageId: null,
            sharedSwimlaneId: null,
            coordBoardId: null,
            addressTags: [],
            bearerFromChannel: $bearerFromChannel,
            transport: $transport,
            sshAccount: self::optionalString($block, 'ssh_account'),
            transportExplicit: $transportExplicit,
            clientUpdateApprovalRequired: self::parseApprovalRequired($block),
            ciTools: $ciTools,
            ciToolsProblem: $ciToolsProblem,
        );
    }

    /**
     * `board_tools.ci_tools` (card#11283): absent ⇒ on. A strict bool is honoured. ANY other
     * value — bare `ci_tools:` (null) and `no`/`off` strings included, which symfony/yaml does not
     * booleanize — is read as OFF and named, never as on and never as a throw: it is an opt-out,
     * so the safe reading of an unreadable one is "the operator wanted it off".
     *
     * @param  array<mixed>  $block
     * @return array{0: bool, 1: ?string}
     */
    private static function parseCiTools(array $block): array
    {
        if (! array_key_exists('ci_tools', $block)) {
            return [true, null];
        }
        if (is_bool($block['ci_tools'])) {
            return [$block['ci_tools'], null];
        }

        return [false, 'board_tools.ci_tools must be true or false (symfony/yaml does not booleanize yes/no/on/off) — the CI tools are OFF for this agent until it is'];
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
     * An optional int that must be >= 1 when set. A zero/negative cap would
     * silently return every description as an empty string flagged truncated —
     * a configuration that cannot mean what it says, so it fails like any other
     * malformation (explicit ⇒ throws, default ⇒ suppresses) rather than
     * falling back to the default and reading as honored.
     *
     * @param  array<mixed>  $block
     */
    private static function optionalPositiveInt(array $block, string $key): ?int
    {
        $value = self::optionalInt($block, $key);
        if ($value !== null && $value < 1) {
            throw new ConfigException("board_tools.{$key} must be a positive integer when set");
        }

        return $value;
    }

    /**
     * An optional strict boolean, false when absent. `yes`/`on` stay strings under symfony/yaml, so
     * anything but true/false is a malformation like every other here — never read as either value.
     *
     * @param  array<mixed>  $block
     */
    private static function optionalBool(array $block, string $key, string $label): bool
    {
        if (! array_key_exists($key, $block) || $block[$key] === null) {
            return false;
        }
        if (! is_bool($block[$key])) {
            throw new ConfigException("{$label} must be true or false when set");
        }

        return $block[$key];
    }

    /**
     * `client_update.approval_required` (card#10567 B4).
     *
     * @param  array<mixed>  $block
     */
    private static function parseApprovalRequired(array $block): bool
    {
        if (! array_key_exists('client_update', $block) || $block['client_update'] === null) {
            return false;
        }
        if (! is_array($block['client_update'])) {
            throw new ConfigException('board_tools.client_update must be a mapping when set');
        }

        return self::optionalBool($block['client_update'], 'approval_required', 'board_tools.client_update.approval_required');
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
