<?php

namespace App\Bridge\Check;

use App\Bridge\Support\AgentConfig;
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
 * FIELDS ARE ADDED PER STAGE, as the checks that read them migrate. The four below
 * are the plan's measured derivation set, not a guess at what checks will want.
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
