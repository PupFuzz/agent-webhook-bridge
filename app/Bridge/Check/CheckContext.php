<?php

namespace App\Bridge\Check;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackConfig;

/**
 * The derived model of the install every {@see Check} asserts against (DL-242).
 *
 * `CheckCommand::handle()` conflates two jobs: DERIVING this model and ASSERTING
 * on it. The derivation is interleaved with the assertions — `$configs` accumulates
 * inside the per-agent config loop, `$githubScopeConsumers` inside that same loop's
 * subscription walk, `$writeback` and `$client` later still — and the last consumer
 * reads them near the end of the method. That interleaving is why the method resists
 * ordinary extract-method refactoring, and it is why this type exists.
 *
 * The derivation sites are NAMED, never cited by `handle()` line number — the migration
 * this type exists to serve invalidates offsets every stage. See
 * `docs/CHECK-REGISTRY-PLAN.md` § Stage 2 result.
 *
 * PROPERTIES ARE PUBLIC AND MUTABLE ON PURPOSE, for the duration of the migration
 * only. Plan constraint (c): during stages 1-7 the surviving inline derivation code
 * in `handle()` populates this object, migrated checks read it, and unmigrated code
 * keeps its local variables. Producer and consumer of `$githubScopeConsumers` are
 * necessarily migrated in different stages, so they must communicate through here in
 * the interim. The final stage makes this a builder's product, at which point these
 * become readonly and set once — the target design's "built ONCE, before any check
 * runs."
 *
 * FIELDS ARE ADDED PER STAGE, as the checks that read them migrate. Each one is a
 * derivation some migrating check was MEASURED to need, never a guess at what checks
 * will want — which is why this paragraph states the rule and carries no count of the
 * fields below: a count here is a second copy of the field list, and it drifts on the
 * first stage that adds one.
 */
final class CheckContext
{
    /**
     * Every agent YAML that parsed. A config that threw on load is reported and
     * skipped, so absence here is not silence.
     *
     * POPULATED AFTER THE PER-AGENT LOOP FINISHES, because that loop is what
     * accumulates it. A check running INSIDE that loop (slot
     * {@see CheckSlot::AgentConfig}) therefore sees this EMPTY, not partial — read it
     * only from a slot that runs later. This is constraint (c)'s interleaving in its
     * sharpest form: the trap is not a missing value, it is a plausible wrong one.
     *
     * @var list<AgentConfig>
     */
    public array $configs = [];

    /**
     * github scope (repo full_name) => the agents subscribed to it and the top-level
     * event types each classifier CONSUMES. Multiple agents can subscribe one scope
     * (the bridge dispatches to all of them), so this is a list per scope and the
     * consumed set is their union (card#4183 / DL-196).
     *
     * @var array<string, list<array{agent: string, class: string, consumed: list<string>, declared: bool}>>
     */
    public array $githubScopeConsumers = [];

    /**
     * github scopes (repo full_names) covered by SOME agent running a
     * writeback-emitting classifier (#2162). A writeback.json mapping for a scope
     * absent here is inert — no classifier drives it.
     *
     * POPULATED AFTER THE PER-AGENT LOOP FINISHES, exactly as {@see self::$configs} is,
     * and with the same trap: a check reading it from {@see CheckSlot::AgentConfig}
     * sees it EMPTY and would report every mapping orphaned.
     *
     * @var array<string, true>
     */
    public array $writebackEmittingScopes = [];

    /**
     * github scopes where an agent enables the coord-card-move family — gate 1 of the
     * DL-204 move leg (gate 2 is the mapping's `move_coord_cards`). Scopes the
     * fleet-default nudges to where the leg can actually fire.
     *
     * POPULATED AFTER THE PER-AGENT LOOP FINISHES (see above).
     *
     * @var array<string, true>
     */
    public array $coordCardMoveScopes = [];

    /**
     * Every `<name>.yml` the config-dir scan SAW, whether or not it parsed.
     *
     * DELIBERATELY NOT THE NAMES OF {@see self::$configs}: a malformed YAML is here and
     * absent there, because the scan records the name before the load is attempted. The
     * difference is the whole point — a leg asking "does this name have a config file"
     * must say yes for an agent whose file exists and is broken, since a different leg
     * already reported the parse failure and the fix is not "create the file".
     *
     * POPULATED AFTER THE PER-AGENT LOOP FINISHES, with the same trap as
     * {@see self::$configs}: a check reading it from inside that loop sees it EMPTY.
     *
     * @var list<string>
     */
    public array $agentNames = [];

    /**
     * The configured config dir, or null when it is unset / not a string — the state in
     * which no path under it can be formed at all.
     *
     * NULL IS NOT "the dir is missing or unusable": a non-existent or insecure directory
     * is reported by its own leg and still arrives here as a string. This field answers
     * only whether a path can be BUILT, which is the question the legs reading it ask.
     */
    public ?string $configDir = null;

    /**
     * The agent roster built from every config that parsed, or null when there was
     * nothing to build one from.
     *
     * BUILT ONCE, IN `CheckCommand`, AND SHARED. {@see AgentRegistry} finds and
     * LOGS identity collisions at CONSTRUCTION — its `collisions()` accessor
     * only returns what the build already accumulated — so every check that needs a
     * roster must read this one instance. Two checks each constructing their own would
     * re-log every collision on a colliding install: a behavior change invisible to the
     * command's output, and therefore invisible to the golden contract that guards this
     * migration.
     */
    public ?AgentRegistry $registry = null;

    /**
     * The validated secret dir, or null when it is unset / not absolute — the state in
     * which the token-path legs cannot form a path to check at all.
     *
     * ONE NULLABLE FIELD, NOT A BOOL + STRING PAIR: `handle()` carries both a
     * `$secretDir` and a `$hasSecretDir` derived from it, and two fields that can
     * disagree is a state no check should have to reason about.
     */
    public ?string $secretDir = null;

    /** Parsed writeback.json, or null when the file is absent (⇒ writeback off). */
    public ?WritebackConfig $writeback = null;

    /**
     * The writeback kanban client, or null when it could not be constructed (no
     * token / no base URL). Null is the half-configured install, which is exactly
     * where the config-only checks matter most — they must not be gated on it.
     */
    public ?KanbanClient $client = null;
}
