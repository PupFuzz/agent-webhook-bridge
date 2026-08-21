<?php

namespace App\Bridge\Check;

use App\Bridge\Check\EventConsumers\EventConsumerReconciliation;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Support\SharedIdentitiesFile;
use App\Bridge\Tools\BoardToolAgentResolver;
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
     * `$agentScopeCoverage` — which agents never reached the three scope maps below, and
     * therefore whether an absence in any of them is evidence (card#5698). READ IT BEFORE
     * CONCLUDING ANYTHING FROM A SCOPE BEING ABSENT: the maps are accumulated only by agents
     * that got past both of `CheckCommand`'s per-agent aborts, so on a run where one did
     * not, "no agent enables this" and "the agent that does was never read" are the same
     * value.
     *
     * THE ONE CONSTRUCTOR-PROMOTED FIELD, and only because PHP cannot default a plain
     * property to an object. Non-nullable is the point: an empty ledger MEANS complete, so a
     * hand-built context is complete by default and no consumer has to decide what a null
     * would have meant — the ambiguity this whole type exists to remove.
     */
    public function __construct(
        public AgentScopeCoverage $agentScopeCoverage = new AgentScopeCoverage,
    ) {}

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
     * ACCUMULATED *DURING* THE PER-AGENT LOOP, NOT AFTER IT — one of the FOUR fields
     * here whose trap is PARTIAL rather than EMPTY ({@see self::$writebackEmittingScopes},
     * {@see self::$coordCardMoveScopes} and {@see self::$coordCardRelaneScopes} are the
     * others; the two groups are separated
     * below). {@see self::$configs} is assigned once the loop has finished, so a check
     * reading it too early sees nothing at all; this one grows an entry per agent, so a
     * check reading it from a slot INSIDE the loop would see the agents processed so far
     * and report confidently on a roster that is still being read. It is safe only from a
     * slot that runs after the loop — {@see CheckSlot::EventConsumer} does.
     *
     * AND EVEN THEN IT MAY BE SHORT: an aborted agent contributes nothing here, so consult
     * {@see self::$agentScopeCoverage} before reading an absence as an answer.
     *
     * `declared` IS TRI-STATE: true = declares, false = does not implement the interface,
     * NULL = implements it but the declaration could not be read (card#5698). `null` is
     * never a synonym for `false` here — see `CheckCommand`'s producing comment.
     *
     * @var array<string, list<array{agent: string, class: string, consumed: list<string>, declared: ?bool}>>
     */
    public array $githubScopeConsumers = [];

    /**
     * The observed-vs-consumed reconciliation of {@see self::$githubScopeConsumers},
     * derived ONCE per run and read by two renderers (DL-249 stage 9).
     *
     * DERIVATION, NOT ASSERTION, and here for the same reason {@see self::$registry} is:
     * `EventFollowsConsumerCheck` renders it as prose and the `--format=json` document
     * emits it as data, so a check deriving its own would be the second derivation
     * card#5229 rules out — and would re-run the per-scope query behind the other
     * renderer's back.
     *
     * NULL MEANS THE DERIVATION NEVER RAN, which `CheckCommand::handle()` cannot produce
     * — it publishes this unconditionally, before the {@see CheckSlot::EventConsumer}
     * slot. It is reachable only from a hand-built context, and the check treats it as
     * nothing to report rather than as an empty (clean) reconciliation: the two are not
     * the same claim, which is the distinction
     * {@see EventConsumerReconciliation::$error} draws one level down.
     */
    public ?EventConsumerReconciliation $eventConsumers = null;

    /**
     * github scopes (repo full_names) covered by SOME agent running a
     * writeback-emitting classifier (#2162). A writeback.json mapping for a scope
     * absent here is inert — no classifier drives it.
     *
     * ACCUMULATED *DURING* THE PER-AGENT LOOP, like {@see self::$githubScopeConsumers} —
     * a check reading it from a slot inside the loop sees a PARTIAL map, not an empty
     * one, and would report a mapping orphaned on the strength of a roster still being
     * read. (This paragraph said "populated after the loop finishes" until DL-242 stage
     * 7a: false since the field was added, and invisible because no check has ever read
     * it from inside the loop.)
     *
     * AND EVEN AFTER THE LOOP IT MAY BE SHORT: an aborted agent contributes nothing here,
     * so consult {@see self::$agentScopeCoverage} before reading an absence as an answer.
     *
     * @var array<string, true>
     */
    public array $writebackEmittingScopes = [];

    /**
     * github scopes where an agent enables the coord-card-move family — gate 1 of the
     * DL-204 move leg (gate 2 is the mapping's `move_coord_cards`). Scopes the
     * fleet-default nudges to where the leg can actually fire.
     *
     * ACCUMULATED *DURING* THE PER-AGENT LOOP (see above) — same partial-not-empty trap,
     * and the same correction. It carries the abort trap too: consult
     * {@see self::$agentScopeCoverage} before reading an absence as an answer.
     *
     * IT IS ALSO THE MAP AN ABORT COSTS MOST NEEDLESSLY, which is a finding and not a
     * design note: the derivation behind it is a RAW-CONFIG family membership test that
     * needs no classifier, yet it sits below the classifier-resolution abort, so a parsed
     * config whose classifier is missing withholds an answer this command already holds.
     * Hoisting it above that abort is tracked on card#5698 and deliberately not done here —
     * it changes what the map MEANS for an agent that cannot classify at all.
     *
     * @var array<string, true>
     */
    public array $coordCardMoveScopes = [];

    /**
     * github scopes where an agent enables the coord-card-relane family (card#6393) —
     * gate 1 of the label-driven lane move, exactly as {@see self::$coordCardMoveScopes}
     * is for the DL-204 close/reopen move. Gate 2 is the mapping's `move_coord_cards`
     * PLUS a configured `coord_card_lane_stage_ids`; with either missing the classifier
     * emits nothing at all, which is a silence no other leg reports.
     *
     * SEPARATE FROM the move map rather than folded into it, because the two families are
     * independently enabled: an install can run either without the other, and one map
     * would make each family's advisory speak for the other's absence.
     *
     * Same accumulation timing and the same two traps as the sibling above (PARTIAL, not
     * empty, inside the per-agent loop; short by any aborted agent — consult
     * {@see self::$agentScopeCoverage}).
     *
     * @var array<string, true>
     */
    public array $coordCardRelaneScopes = [];

    /**
     * The key form of the three github scope maps above, and the form to look one up
     * with (DL-293).
     *
     * BOTH SIDES OF THAT COMPARE ARE OPERATOR-WRITTEN AND NAME THE SAME THING: the key is
     * an agent YAML's `subscriptions[].scope_id`, and every consumer asks with a
     * `writeback.json` mapping key. GitHub `owner/repo` is case-insensitive, so two
     * spellings of one repo are one repo — and a raw compare here does not report a
     * mismatch, it reports the mapping as ORPHANED (inert), which is a config accusation
     * against an install that is working.
     *
     * The canonicalization is {@see WritebackConfig::mappingFor}'s, so a scope map and the
     * writeback config cannot disagree about which repo a string names. A value that
     * canonicalizes to nothing keeps its raw form — it is no scope's id, so it matches
     * nothing either way, and the map key stays a string.
     */
    public static function canonicalScope(string $scopeId): string
    {
        return (new ExternalReferenceNormalizer)->canonicalizeSource($scopeId) ?? $scopeId;
    }

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
     * The one read of `shared-identities.json` this run performed — its STATE, and the
     * identities when it parsed. Null means the derivation never ran: a hand-built
     * context, or an install with no config dir to form the path under.
     *
     * POPULATED AFTER THE PER-AGENT LOOP, with the same trap as {@see self::$configs} —
     * a check reading it from a slot inside that loop sees null and would report the
     * file absent on an install that has one.
     *
     * READ ONCE AND SHARED, for the same reason {@see self::$registry} is BUILT once:
     * `AgentRegistry::readSharedIdentities()` LOGS — a permissions fault, a non-object,
     * and one line per wrongly-shaped entry — so a second reader duplicates every one of
     * those warnings, and stdout is identical either way, which is exactly the kind of
     * behavior change this migration's output contract cannot see (card#5546).
     *
     * IT CARRIES THE STATE AND NOT A BARE LIST because the loader is fail-soft: absent,
     * unreadable and malformed all answer the empty list, and DL-259 requires the
     * preflight to pronounce four different verdicts over them.
     */
    public ?SharedIdentitiesFile $sharedIdentities = null;

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

    /**
     * The agents whose `board_tools` block is ENABLED — the subset the whole board-tools
     * plane below the suppression scan is bounded by.
     *
     * NOT THE SUBSET THE SUPPRESSION SCAN READS: a block that could not satisfy itself is
     * `enabled === false`, so it is absent here and its own check reads
     * {@see self::$configs} instead. Empty is a meaningful value, not a missing one —
     * `--probe-tools` reports "nothing to probe" from it.
     *
     * @var list<AgentConfig>
     */
    public array $boardToolsEnabled = [];

    /**
     * The board-tools bearer index, or null when no agent has the block enabled (the
     * state in which `CheckCommand` does not build one).
     *
     * BUILT ONCE, IN `CheckCommand`, AND SHARED, for the same reason
     * {@see self::$registry} is: {@see BoardToolAgentResolver} READS every enabled HTTP
     * agent's token file and LOGS each collision at CONSTRUCTION, and its `problems()`
     * accessor only returns what the build accumulated. A check constructing its own
     * would re-read those secrets and re-log every collision — invisible to this
     * migration's output contract, which is exactly why it is recorded here.
     */
    public ?BoardToolAgentResolver $boardToolsResolver = null;

    /**
     * The kanban client the board-STATE legs read boards with, or null when it could not
     * be constructed.
     *
     * A SECOND CONSTRUCTION, DELIBERATELY NOT {@see self::$client}. The two are built from
     * the same factory but under DIFFERENT guards — that one inside the `writeback.json`
     * envelope (mappings present), this one inside the board-tools envelope (some agent
     * enabled) — and each guard's failure prints its own diagnosis and skips its own legs.
     * Collapsing them onto one field would make an install with writeback off and board
     * tools on (or the reverse) construct a client where it constructs none today, and
     * would make one failure message stand for two causes.
     */
    public ?KanbanClient $boardToolsClient = null;

    /**
     * Agent name => its ssh setup is INCOMPLETE or could not be verified, read back off
     * the {@see CheckSlot::BoardToolsSsh} findings' severities. Absent ⇒ verified, or
     * that slot never ran for the agent.
     *
     * THE FIRST FIELD HERE THAT IS NOT A FACT ABOUT THE INSTALL — it is a fact about what
     * another check REPORTED, so it exists only after that slot has run and it changes
     * meaning if that slot's severities are re-assigned — which DL-251 did, moving both of
     * the pinned-line leg's `warn`s to `unvalidated`; the predicate that reads this widened
     * in the same commit. Every other field is derived from config, the filesystem or a board
     * read, and is therefore true independently of the registry. The cost is a real
     * coupling: the DL-225 advisory that reads this is downstream of another check's
     * SEVERITY vocabulary, not of the install, and `CheckCommand` narrows that coupling
     * to one check id rather than the whole slot (see its readback).
     *
     * @var array<string, true>
     */
    public array $sshSetupIncomplete = [];
}
