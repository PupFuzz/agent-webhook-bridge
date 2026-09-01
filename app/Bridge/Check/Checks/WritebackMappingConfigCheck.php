<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\CoordConfigTerminals;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackMapping;

/**
 * Every per-mapping writeback assertion that needs NO board read, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * All the WARNING legs fire on a HALF-CONFIGURED INSTALL — the state where the writeback
 * client cannot be constructed at all — which is both why they are separated from the
 * probe plane and why they matter most: every condition there makes some writeback leg
 * silently INERT, and an inert leg produces no error, no retry, and no card movement.
 *
 * TWO LEGS ARE NOT OF THAT FAMILY and are here deliberately — the mention-vs-closure line
 * (card#7348 / DL-305) and the lane-model-without-the-relane-family line (card#8290). Both
 * speak about a mapping that is entirely CORRECT, both are `ok` rather than `warn` for that
 * reason, and both sit with the others because this is the check that walks the mappings at
 * setup time and because what they report — that a merge move now needs an explicit closing
 * form; that a second, opt-in family exists which closes a race the lane model leaves open —
 * is a property of the CODE the operator is running, which their `writeback.json` cannot
 * tell them however carefully they read it. Their own comments at the sites carry the rest.
 * ⛔ A THIRD SUCH LEG IS NOT AUTOMATICALLY WELCOME: what earns the severity is that the fact
 * is unobtainable from the operator's own config, not that it is interesting.
 *
 * ONE CHECK, NOT ONE PER LEG, BECAUSE OUTPUT ORDER IS THE CONTRACT. The inline code
 * iterates mappings on the OUTSIDE and legs on the inside, so a per-leg decomposition
 * would have to iterate mappings once per leg and would emit all repos' orphan warnings
 * before any repo's DL-160 warning. On a single-mapping install the two orders coincide;
 * on a multi-mapping one they differ, which is exactly the install this check is for.
 * Stage 8's inventory keys on the check id, so the grouping costs granularity there —
 * accepted deliberately over reordering operator-visible output.
 *
 * NO LEG HERE CAN THROW: `stageFor()` is a pure array read,
 * {@see GitHubTokenResolver::resolveFor} is documented total ("bridge:check depends on it
 * never throwing"), and {@see CoordConfigTerminals::load} returns null for every
 * absent/unreadable/malformed input rather than raising. That is what lets this check
 * yield incrementally across many mappings without the caller's fail-soft envelope being
 * able to swallow findings it had already produced.
 */
final class WritebackMappingConfigCheck implements Check
{
    public function id(): string
    {
        return 'writeback.mapping_config';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $writeback = $ctx->writeback;
        if ($writeback === null || $writeback->mappings === []) {
            yield Silence::because('there is no writeback config or it maps no repo, so there is no mapping whose keys could disagree with each other');

            return;
        }

        foreach ($writeback->mappings as $repo => $mapping) {
            // The scope maps are keyed by repo IDENTITY, not by spelling (DL-293) — an
            // agent YAML and writeback.json can name one repo two ways, and a raw compare
            // would call the mapping ORPHANED on an install whose writeback works.
            // `$repo` itself keeps its configured spelling in every message: it is what
            // the operator can find in their own file.
            $scope = CheckContext::canonicalScope((string) $repo);
            // #2162: a writeback.json mapping is INERT unless some agent runs a
            // writeback-emitting classifier subscribed to its github scope. INDEPENDENT
            // of the board probe in the sibling slot — it must fire even when the
            // writeback client can't be constructed (no token / base URL), which is
            // exactly the half-configured install where an orphan is most likely.
            if (! isset($ctx->writebackEmittingScopes[$scope])) {
                // card#5698: the map is accumulated only by agents that got past both
                // per-agent aborts, so on a run where one did not, ORPHANED and "the agent
                // that drives it was never read" are the same value. Naming the orphan
                // there sends the operator to add an agent for a fault a line above already
                // attributed to the agent they have.
                if ($ctx->agentScopeCoverage->mayCover($scope)) {
                    yield Finding::unvalidated("writeback: could NOT determine whether the mapping for {$repo} is orphaned — ".$ctx->agentScopeCoverage->gapClause($scope).", so an agent this run never finished reading could be the one running a writeback-emitting classifier (App\\Bridge\\Contracts\\EmitsWritebackReactions) on github:{$repo}. Fix the error(s) above and re-run; until then this says nothing about whether the mapping is inert.");
                } else {
                    yield Finding::warn("writeback: mapping for {$repo} is ORPHANED — no agent runs a writeback-emitting classifier (App\\Bridge\\Contracts\\EmitsWritebackReactions) subscribed to github:{$repo}; the mapping is inert (no card will ever move) until an agent subscribes to it with that classifier");
                }
            }
            // ⛔ card#7124 review — THE SPELLING SPLIT, and the reason canonicalizing the
            // compare above is not the whole answer. The writeback matches a repo by
            // IDENTITY since DL-293; the DISPATCHER does not. `SubscriptionRegistry::
            // subscribedTo` compares `$sub->scopeId === $scopeId` raw against the
            // delivery's scope, so an agent subscribed as `pupfuzz/x` receives NOTHING for
            // a delivery spelled `PupFuzz/x` — no dispatch, no log, no alert. Left
            // unreported, the canonicalization above would have turned that install's
            // ORPHANED warn into SILENCE: every delivery reaching no agent at all, and
            // `bridge:check` exiting 0 (the DL-265 shape — a leg that examined nothing
            // stops reporting `ok`, re-minted by the fix meant to close a silent-failure
            // class).
            //
            // Strictly MORE informative than the ORPHANED warn it replaces here: that one
            // said "add an agent" for an install that HAS one. This names both spellings
            // and the layer that still splits them. It is scoped to a genuine divergence —
            // same repo, different spelling — so an install whose two files agree stays
            // quiet, which is every install that has not hit this.
            $divergent = array_values(array_unique(array_filter(
                $ctx->githubScopeSpellings[$scope] ?? [],
                fn (string $spelling): bool => $spelling !== (string) $repo,
            )));
            if ($divergent !== []) {
                yield Finding::warn(
                    "writeback: github scope SPELLING SPLIT for {$repo} — writeback.json keys it \"{$repo}\" while an agent subscribes to \""
                    .implode('", "', $divergent).'" (same repo; GitHub owner/repo is case-insensitive). The WRITEBACK matches these as one repo '
                    .'(DL-293), but the DISPATCHER does not: it matches a subscription by EXACT spelling, so a delivery is dispatched only when '
                    .'its `repository.full_name` matches the SUBSCRIPTION byte-for-byte — and every other spelling reaches NO agent at all, with '
                    .'no log, no finding and no alert. Spell the agent YAML `scopes:` entry and the writeback.json mapping key the same way GitHub '
                    .'sends them, or this install is one webhook away from silently dispatching nothing.'
                );
            }
            // #2652: the DL-160 branch-create `started` trigger is fail-closed — it needs
            // BOTH `stages.started` AND `started_from_stages`. With exactly one set the
            // move is silently INERT (the `stages.started`-only half is refused for lack
            // of a promote-from set; the `started_from_stages`-only half has no `started`
            // outcome to fire).
            $hasStartedStage = $mapping->stageFor('started') !== null;
            $hasStartedFrom = $mapping->startedFromStages !== null && $mapping->startedFromStages !== [];
            if ($hasStartedStage !== $hasStartedFrom) {
                $present = $hasStartedStage ? 'stages.started' : 'started_from_stages';
                $missing = $hasStartedStage ? 'started_from_stages' : 'stages.started';
                yield Finding::warn("writeback: mapping for {$repo} sets {$present} but not {$missing} — the branch-create `started` trigger (DL-160) needs BOTH and is silently INERT (never fires) until {$missing} is set");
            }
            // DL-195: Won't-Do-revival needs BOTH stages.opened (the revive-to target)
            // AND stages.closed_unmerged (the abandon stage the revival is scoped from).
            // With revive_on_reopen on but either missing, a reopened PR's revival is
            // silently INERT.
            if ($mapping->reviveOnReopen) {
                $missingRevive = [];
                if ($mapping->stageFor('opened') === null) {
                    $missingRevive[] = 'stages.opened';
                }
                if ($mapping->stageFor('closed_unmerged') === null) {
                    $missingRevive[] = 'stages.closed_unmerged';
                }
                if ($missingRevive !== []) {
                    yield Finding::warn("writeback: mapping for {$repo} sets revive_on_reopen but not ".implode(' / ', $missingRevive).' — Won\'t-Do-revival (DL-195) needs BOTH stages.opened (revive-to) and stages.closed_unmerged (abandon stage) and is silently INERT until set');
                }
            }
            // DL-198: a created coord card's task.created webhook would echo back to the
            // bridge; only the global-echo gate (identity_id) stops it from self-waking a
            // kanban-triage session. With no identity_id that guard is absent — surface
            // the concrete hazard, which the generic no-identity_id warn does not name.
            if ($mapping->createCoordCards && $writeback->identityId === null) {
                yield Finding::warn("writeback: mapping for {$repo} sets create_coord_cards but writeback.json has no identity_id — a created coord card's task.created webhook echoes back and could self-wake a kanban-triage session; set identity_id (the global-echo gate is the sole guard).");
            }
            // #4553: under population=all the bridge is the SOLE real-time mover for the
            // NON-PREFIXED coord-issue set — the prefix/tag-keyed reconcile ignores those
            // issues, so unless the consumer extends its reconcile to correlate them by
            // github_issue by-ref, a bridge-missed non-prefixed event self-heals NOWHERE.
            // Gated on create OR move — the move leg (create off) also correlates
            // non-prefixed cards by-ref, so it carries the same backstop stake.
            if (($mapping->createCoordCards || $mapping->moveCoordCards) && $mapping->issuePopulation === WritebackMapping::POPULATION_ALL) {
                yield Finding::warn("writeback: issue_population=all for {$repo} — the bridge is the SOLE real-time mover for NON-PREFIXED coord issues (the prefix/tag-keyed reconcile does not card them). Ensure the consumer's reconcile is extended to correlate non-prefixed issues by github_issue by-ref, else a bridge-missed non-prefixed event has NO backstop. Prefixed issues remain backstopped via the shared id: tag.");
                // by-ref correlation is only correct in `ref` mode — scan mode does a bare
                // issue-number match with NO repo/source disambiguation, so on a multi-repo
                // board it correlates the wrong repo's issue #N.
                if (config('bridge.writeback.correlation', 'ref') !== 'ref') {
                    yield Finding::warn("writeback: issue_population=all for {$repo} but BRIDGE_WRITEBACK_CORRELATION is not `ref` — the github_issue by-ref correlation degrades to a bare issue-number scan with NO repo disambiguation, so on a multi-repo board it can correlate the wrong repo's issue #N. Set correlation=ref (the default) for the `all` population.");
                }
                yield from $this->issuePopulationAgreement((string) $repo, $mapping);
            }
            // DL-204 (#4357): the move leg fires only where BOTH gates are on — the
            // coord-card-move family (gate 1) AND the mapping's move_coord_cards (gate 2,
            // a guarded fleet default: on where coord_card_terminal_stage_id is present).
            // An install that enabled the family but never set the terminal gets
            // issues.closed/reopened classified with NO card move — silent-inert. Scoped
            // to family-enabled scopes so a pure PR-writeback mapping stays quiet.
            if (isset($ctx->coordCardMoveScopes[$scope]) && $mapping->coordCardTerminalStageId === null) {
                yield Finding::warn("writeback: github:{$repo} enables the coord-card-move family but its writeback mapping has no coord_card_terminal_stage_id — the real-time coord-issue close/reopen → card move (DL-200) is INERT (issues.closed/reopened are classified but no card moves). Set coord_card_terminal_stage_id (the fleet default activates the leg where it is present), or remove coord-card-move from classifier.config.families if the move leg is not wanted.");
            }
            // NO card#5698 DISCLOSURE ON THE ARM ABOVE, and the omission is a ruling rather
            // than an oversight (the sibling arm below and `WritebackBoardStateCheck`'s
            // gate both carry one). It is a map-fed leg that asserts NOTHING when the scope
            // is absent — EVERY arm below whose map term is a POSITIVE is of that shape and
            // inherits this ruling. NO COUNT AND NO LIST IS STATED for that set: the criterion
            // above re-derives it, which a naming does not. The map-fed legs here
            // have grown twice since this paragraph was written (card#6393, card#8292), and
            // a number in a comment is a second copy of a list nothing re-derives. The arm
            // is scoped to family-enabled scopes precisely so a pure PR-writeback mapping
            // stays quiet, so an unread agent costs it no false claim — only the same
            // silence it already keeps for every install that does not use coord cards. A
            // disclosure here would print on EVERY mapping of every run with an unreadable
            // agent config, including the majority that carry no coord-card config at all.
            // That is the silent-LEG class (DL-251 stage 10), whose members answered nothing
            // while their siblings answered; this arm has no answering sibling.
            // DL-204 MIRROR: the other silent-inert direction. Gate 2 on but gate 1 off —
            // the handler-side gate is on, but the classifier never emits a move to hand
            // it. This is the DL-204 adoption path ("set the terminal, no flag needed")
            // dying when the operator sets the terminal but never enables the family.
            if ($mapping->moveCoordCards && ! isset($ctx->coordCardMoveScopes[$scope]) && $ctx->agentScopeCoverage->mayCover($scope)) {
                // card#5698: same map, opposite direction. "No agent enables the family" is
                // the accusation, and an unread agent is indistinguishable from an absent
                // one — so the operator would be sent to edit a families list that may
                // already be correct in the config that failed to load.
                yield Finding::unvalidated("writeback: could NOT determine whether any agent enables the coord-card-move family on github:{$repo} — ".$ctx->agentScopeCoverage->gapClause($scope).'. This mapping has coord_card_terminal_stage_id set (the move leg is on), so if none does, the leg cannot fire. Fix the error(s) above and re-run.');
            } elseif ($mapping->moveCoordCards && ! isset($ctx->coordCardMoveScopes[$scope])) {
                yield Finding::warn("writeback: github:{$repo} has coord_card_terminal_stage_id set (the move leg is on — explicitly or by the DL-204 default) but no agent enables the coord-card-move family on that scope — the leg cannot fire (nothing classifies issues.closed/reopened into a move). Add coord-card-move to the serving agent's classifier.config.families, or remove coord_card_terminal_stage_id to disable the move leg.");
            }
            // card#8292: the DL-204 mirror one family EARLIER — `create_coord_cards` set
            // (gate 2) while no agent enables coord-card-create (gate 1). The classifier
            // dispatches on the family before it ever reads the mapping, so this install
            // classifies nothing: issues.opened/reopened are delivered, the create family
            // never runs, and no coordination issue on the repo is carded in real time.
            //
            // ⚠ A `warn`, WHERE ITS TWO NEIGHBOURS ARE `ok`, AND THE DIFFERENCE IS THE WHOLE
            // POINT — read this before copying either shape. card#8290's lane-model line
            // below and the card#7348 / DL-305 line at the end of the loop are `ok` because
            // NOTHING THE OPERATOR CONFIGURED IS DEAD there: a lane model with no relane
            // family is a valid, fully-firing install that merely cannot learn from its own
            // `writeback.json` that a second opt-in family exists. Here a leg the operator
            // explicitly turned on is INERT, which is exactly the condition this check's
            // WARNING family is defined by (see the class docblock), so a warn accuses
            // nothing correct. The severity follows the deadness of the leg, never the
            // interestingness of the fact.
            //
            // NOT gated on `issue_population`: both populations are carded by this family
            // and by nothing else in the bridge, so the leg is equally dead under either.
            if ($mapping->createCoordCards && ! isset($ctx->coordCardCreateScopes[$scope])) {
                // card#5698, and the same limb as the DL-204 mirror above: "no agent enables
                // the family" IS the accusation, so an agent this run never finished reading
                // is indistinguishable from an absent one and the operator would be sent to
                // edit a families list that may already be correct in the config that failed
                // to load. A disclosure, therefore, and deliberately carrying no remediation.
                if ($ctx->agentScopeCoverage->mayCover($scope)) {
                    yield Finding::unvalidated("writeback: could NOT determine whether any agent enables the coord-card-create family on github:{$repo} — ".$ctx->agentScopeCoverage->gapClause($scope).'. This mapping sets create_coord_cards, so if none does, no coordination issue on this repo is ever carded in real time. Fix the error(s) above and re-run.');
                } else {
                    yield Finding::warn("writeback: github:{$repo} sets create_coord_cards but no agent enables the coord-card-create family on that scope — the real-time coordination issue → card create (DL-198) is INERT: issues.opened/reopened arrive, nothing is classified and no card is ever created. Where a periodic reconcile runs it still backstops the PREFIXED set (not in real time), and under issue_population=all the non-prefixed set is carded by nothing at all. Add coord-card-create to the serving agent's classifier.config.families, or remove create_coord_cards from the mapping if the create leg is not wanted. See docs/writeback.md § Optional: real-time coordination issue → card (DL-198).");
                }
            }
            // card#8305: the create family's OTHER silent-inert direction, and the cell the
            // gate1/gate2 matrix was missing — gate 1 ON (an agent enables coord-card-create
            // on this scope) with gate 2 OFF (the mapping does not set create_coord_cards).
            // `CoordinationClassifier::coordCardCreateFamily()` dispatches on the family and
            // then returns null at its own mapping gate, so issues.opened/reopened are
            // delivered, the family classifies nothing, and no coordination issue on this
            // repo is carded in real time. Its two neighbours already report the same
            // deadness from the opposite side (the mirror above; the DL-204 pair for the
            // move family), which is why the asymmetry was an omission and not a ruling.
            //
            // ⚠ IT CAN ACCUSE A MULTI-SCOPE AGENT'S OTHER REPOS, and that exposure is not
            // new here: `classifier.config.families` is per AGENT, so an agent serving two
            // repos enables the family on BOTH, and a mapping that deliberately cards no
            // coord issues draws this line. The DL-204 arm above carries exactly that and
            // its ruling settled it — the remediation names the scope, so an operator who
            // wants the leg off on one repo is told which config says so.
            //
            // NO card#5698 DISCLOSURE, INHERITED BY NAME from the DL-204 arm's ruling above
            // rather than re-argued: this is a map-fed leg whose map term is a POSITIVE, so
            // an unread agent cannot make it speak — it can only leave it SILENT, which is
            // the same silence it keeps for every install that does not enable the family.
            // Both terms of a line it does print are established facts: the family is in a
            // config this run read to completion, and `create_coord_cards` is absent from a
            // `writeback.json` that parsed. A disclosure here would need a NEGATIVE map read
            // it does not perform, and would print on every mapping that does not card coord
            // issues — the majority — on any run with one unreadable agent config.
            if (isset($ctx->coordCardCreateScopes[$scope]) && ! $mapping->createCoordCards) {
                yield Finding::warn("writeback: github:{$repo} enables the coord-card-create family but its writeback mapping does not set create_coord_cards — the real-time coordination issue → card create (DL-198) is INERT: issues.opened/reopened are delivered, the create family returns at its own mapping gate, and no coordination issue on this repo is ever carded in real time. Set create_coord_cards (with coord_card_stage_id, which the config refuses to load without it), or remove coord-card-create from the serving agent's classifier.config.families if the create leg is not wanted on this scope. See docs/writeback.md § Optional: real-time coordination issue → card (DL-198).");
            }
            // card#6393: the coord-card-relane family's silent-inert shape, the DL-204 pair
            // above one family over. The relane leg needs gate 1 (the family) AND BOTH keys
            // of gate 2 — `move_coord_cards` (a relane IS the bridge moving a coord card)
            // and a `coord_card_lane_stage_ids` lane model to move it INTO. With either
            // missing the classifier emits nothing at all, so this install is silent in a
            // way none of the legs above reports: no config error (a lane-less mapping is
            // valid for every other leg), no board write, and nothing even classified.
            // Missing keys are collected rather than reported one per run (the DL-195 shape)
            // — an operator who set neither should be told so once.
            if (isset($ctx->coordCardRelaneScopes[$scope])) {
                $missingRelane = [];
                if (! $mapping->moveCoordCards) {
                    $missingRelane[] = 'move_coord_cards';
                }
                if ($mapping->coordCardLaneStageIds === null) {
                    $missingRelane[] = 'coord_card_lane_stage_ids';
                }
                if ($missingRelane !== []) {
                    yield Finding::warn("writeback: github:{$repo} enables the coord-card-relane family but its writeback mapping has no ".implode(' / ', $missingRelane).' — the label-driven coord-card re-lane (card#6393) is INERT: issues.labeled arrives, nothing is classified and no card moves. Set '.implode(' and ', $missingRelane).' (a relane needs both the permission to move a coord card and a lane model to move it into), or remove coord-card-relane from classifier.config.families if the relane leg is not wanted.');
                }
            }
            // NO card#5698 DISCLOSURE for the leg above, and that omission is a ruling: it is
            // family-scoped exactly like the DL-204 arm it mirrors, so an absent scope asserts
            // nothing and an unread agent costs it no false claim — the DL-204 arm's own
            // no-disclosure ruling above owns that reasoning and this arm inherits it unchanged.
            //
            // card#8290: THE OTHER DIRECTION — a lane model, and no family to close the race it
            // leaves open. It was declined on card#6393 as a WARN, and THAT HALF OF THE DECLINE
            // STANDS: `coord_card_lane_stage_ids` is read by every coord-card write (create
            // since card#6371, revive and relane since card#6393) and, since DL-294, is accepted
            // with EITHER family, so it declares no family in particular. Nothing here is inert —
            // every leg this operator configured fires — and every warning in this check means
            // "something you configured cannot fire", so a warn would accuse a correct config
            // and would do it on every lane-model install, the reference one included.
            //
            // WHAT THE DECLINE WEIGHED WAS THE COST OF THE NOISE; WHAT IT DID NOT WEIGH IS THE
            // COST OF THE SILENCE, and that one has since been measured on a peer install: an
            // operator ran the birth half for weeks, watched coord cards keep landing in the
            // wrong lane, diagnosed it as a missing mechanism and wrote a fix proposal — for a
            // family this bridge had already shipped and released. Nothing in their own config
            // could tell them it existed. That is the card#7348 / DL-305 shape exactly (a
            // property of the CODE they are running which their `writeback.json` cannot state),
            // so it gets that shape's severity rather than its own: a green setup-time line that
            // names the race, what closes it, and what closing it costs — never a verdict on a
            // config that is allowed to stay exactly as it is.
            if ($mapping->coordCardLaneStageIds !== null && ! isset($ctx->coordCardRelaneScopes[$scope])) {
                // The absence of the family is the whole predicate, so an agent this run never
                // read makes the claim unavailable rather than merely doubtful — corollary (A):
                // an `ok` may disclose what its measurement IMPLIES, never that the measurement
                // may not have happened.
                if ($ctx->agentScopeCoverage->mayCover($scope)) {
                    yield Finding::unvalidated("writeback: could NOT determine whether any agent enables the coord-card-relane family on github:{$repo} — ".$ctx->agentScopeCoverage->gapClause($scope).'. This mapping sets coord_card_lane_stage_ids, so if none does, a `stage:*` label added after a coord card exists moves nothing. Fix the error(s) above and re-run.');
                } else {
                    // `move_coord_cards` is named ONLY when it is actually missing: the relane
                    // family additionally requires it, so on a create-only lane model (valid
                    // since DL-294) the family alone would still classify nothing, and a line
                    // that asked only for the family would send the operator to a config that
                    // stays silent.
                    $alsoNeeded = $mapping->moveCoordCards ? '' : ' and set move_coord_cards (a relane IS the bridge moving a coord card, and the family classifies nothing without it)';
                    yield Finding::ok("writeback: github:{$repo} has a lane model (coord_card_lane_stage_ids) but no agent enables the coord-card-relane family on that scope — the lane a coord card sits in is written when the card is CREATED and on a revive, never when a `stage:*` label arrives afterwards. A `[TASK]` labelled after its card exists therefore keeps the lane it was created in (`later` for an issue opened with no lane label), and on an install whose consumer writes a card's lane back onto the issue as a `stage:*` label, the label the operator just set is converged BACK to that lane — the sequencing ruling silently overwritten. Closing that race is the opt-in coord-card-relane family: add it to the serving agent's classifier.config.families".$alsoNeeded.'. Leaving it off stays a valid choice — it is the only family that reacts to a label edit, and issues.labeled is a high-volume action — but it is a choice this mapping cannot show you, which is why this line exists. See docs/writeback.md § Following a label added after the card exists.');
                }
            }
            // DL-207: promote-on-release health. WritebackConfig::load already fails
            // closed on a missing shipped/released stage, so this catches the two
            // silent-inert shapes load cannot: both stages mapped to ONE column (the
            // promote is a no-op), and no FPM-viable GitHub token. The promote leg runs in
            // the webhook RUNTIME — unlike bridge:reconcile (CLI), under FPM GH_TOKEN is
            // absent and the git-credential-coord store helper is CLI-only (DL-184), so
            // ONLY a placed token FILE resolves there. There is no reconcile backstop for
            // Shipped→Released, so an inert leg strands cards.
            if ($mapping->promoteOnRelease) {
                if ($mapping->stageFor('merged') !== null && $mapping->stageFor('merged') === $mapping->stageFor('merged_to_main')) {
                    yield Finding::warn("writeback: mapping for {$repo} sets promote_on_release but stages.merged and stages.merged_to_main are the same stage — the Shipped→Released promote is a no-op (nothing to move); map them to distinct columns or remove promote_on_release.");
                }
                // Reuse the authoritative resolver; a file leg's `source` starts with
                // "token file" / "token_path override" (mirrors GitHubTokenResolver).
                $promoteToken = (new GitHubTokenResolver)->resolveFor((string) $repo);
                $fromFile = $promoteToken->ok() && $promoteToken->source !== null
                    && (str_starts_with($promoteToken->source, 'token file') || str_starts_with($promoteToken->source, 'token_path override'));
                if (! $fromFile) {
                    yield Finding::warn("writeback: mapping for {$repo} sets promote_on_release but no GitHub read token resolves from a FILE (<secret_dir>/github/token, or providers.github.token_path) — the promote leg runs in the FPM webhook runtime where GH_TOKEN is absent and the credential-store helper is CLI-only, so a store/GH_TOKEN-only token (usable by bridge:reconcile) leaves the promote leg INERT at runtime with no reconcile backstop. Place a read-only token file (chmod 600).");
                }
            }
            // card#7348 / DL-305: the MENTION-vs-CLOSURE semantics, said at setup time.
            // The only leg here that speaks about a mapping which is entirely CORRECT —
            // and it earns the line because what it reports is not in the operator's file.
            // `writeback.json` says which stage a merge lands on; it cannot say that since
            // DL-305 a merge lands there only when something CLAIMS the card is done. A
            // peer wired a brand-new board into this classifier hours before the defect
            // surfaced and nothing in the setup path told them what they were inheriting;
            // this is that sentence, on the surface that actually runs.
            //
            // ⚠ DL-308 GAVE THE CLAIM A SECOND ROUTE, and this line is the reason the
            // sentence is composed at `PrOutcome::describeClosure()` rather than here: the
            // text that stood here said the TITLE was the only thing that could move a
            // card, which an operator would read as a rule the structural route violates.
            // A restated accept-set on a surface whose job is telling operators what they
            // inherited is the DL-239 defect on the worst possible surface.
            //
            // SCOPED TO MAPPINGS THAT HAVE A MERGE LEG AT ALL, so a started/opened-only
            // mapping stays silent — the emptiness there is the operator's own config
            // (Severity corollary (B)) and the gate has nothing to describe. The mapped
            // outcomes are NAMED rather than counted, so an install that maps only one of
            // the two is told which one.
            // The gated set is READ from the authority, never re-listed: `PrOutcome` decides
            // which outcomes require a closing form, and a literal pair here would be a
            // second copy free to disagree with the runtime the day the set moves — which is
            // precisely the failure mode this check exists to report on other people's
            // config.
            $gated = [];
            foreach (PrOutcome::MERGE_OUTCOMES as $mergeOutcome) {
                if ($mapping->stageFor($mergeOutcome) !== null) {
                    $gated[] = "stages.{$mergeOutcome} (".$mapping->stageFor($mergeOutcome).')';
                }
            }
            if ($gated !== []) {
                yield Finding::ok("writeback: mapping for {$repo} moves a card on MERGE only when the merge CLAIMS that card is done — "
                    .implode(' and ', $gated).' '.(count($gated) === 1 ? 'is' : 'are')
                    .' gated this way (card#7348 / DL-305, widened DL-308). A PR that merely MENTIONS a card#/DL token is a NO-OP for the stage: the card is left exactly where it is, never moved back — so nothing needs backfilling, and a missing claim costs an UNDER-promoted card you can move by hand. '
                    .'Accepted: '.PrOutcome::describeClosureAccepted()
                    .'. The token still selects WHICH card; the claim is what says the merge finishes it. (The REJECTED side of BOTH sets — the branch shapes that name no card, and the title shapes that name a card without claiming it done — is rendered by the runtime warning at the moment one is seen, where it is diagnostic rather than noise.)');
            }
        }

        yield Silence::because('no mapping maps a merge outcome (so the mention-vs-closure line has nothing to describe), every mapping has a subscribed writeback-emitting classifier spelled as the mapping spells it, and no half-configured optional leg — each of the WARNING legs above speaks only when a key is set without the key it needs, when a coord-card family is enabled without the mapping keys that family reads, or when two files spell one repo two ways');
    }

    /**
     * The cross-config three-state compare (converged w/ sola): bind the bridge's runtime
     * `issue_population` (writeback.json) to the reconcile's (`$COORD_CONFIG`, its source
     * of truth) so `bridge-on-all + reconcile-on-prefixed` — the exact non-prefixed
     * no-backstop gap — is a CHECKABLE DISAGREE, not silence. Three-state (agree /
     * DISAGREE / CANNOT-VERIFY), never-fail — and since DL-251 the three states carry
     * three severities: `ok`, `warn`, and `unvalidated` for CANNOT-VERIFY, which had been
     * a second `warn`. CANNOT-VERIFY is kept DISTINCT from
     * agreement: an unset/unreadable `$COORD_CONFIG` is "could not ask," not "they agree."
     * Called only when the bridge side is already `all` (the direction that can strand
     * cards); the mirror (reconcile=all, bridge=prefixed) is a lesser not-real-time gap
     * and is not force-checked.
     *
     * CLI-ONLY BY NATURE: the FPM webhook env has no `$COORD_CONFIG` (the whole reason the
     * compare lives in `bridge:check`), and `getenv()` is read live (cache-immune) so
     * `php artisan optimize` cannot freeze a deploy-time value.
     *
     * @return iterable<Finding>
     */
    private function issuePopulationAgreement(string $repo, WritebackMapping $mapping): iterable
    {
        $mine = $mapping->issuePopulation;   // 'all' (only reached under `all`)
        $prefix = "writeback: issue_population ({$repo}, board {$mapping->boardId})";
        $tail = 'A bridge on `all` with a reconcile on `prefixed` is the no-backstop gap — the non-prefixed set self-heals nowhere.';

        $path = config('bridge.writeback.coord_config_path');
        if (! is_string($path) || $path === '') {
            $ambient = getenv('COORD_CONFIG');
            $path = is_string($ambient) && $ambient !== '' ? $ambient : null;
        }
        $config = CoordConfigTerminals::load($path);
        if ($config === null) {
            $where = $path === null ? '$COORD_CONFIG is not set' : "the coordination config at {$path} is absent, unreadable, or malformed";

            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — {$where}. {$tail} Point bridge.writeback.coord_config_path (or \$COORD_CONFIG) at coordination.config.json.");

            return;
        }
        $theirs = CoordConfigTerminals::issuePopulationsForBoardId($config, $mapping->boardId);
        if ($theirs === []) {
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — the coordination config has no kanban.boards[] entry for board {$mapping->boardId}. {$tail}");

            return;
        }
        if (count($theirs) > 1) {
            yield Finding::unvalidated("{$prefix}: CANNOT VERIFY — the coordination config resolves multiple issue_population values for board {$mapping->boardId} (".implode(', ', $theirs)."). {$tail}");

            return;
        }
        if ($theirs[0] === $mine) {
            yield Finding::ok("{$prefix}: coord config agrees — reconcile issue_population is '{$mine}', so the non-prefixed set is backstopped by the reconcile's by-ref correlation.");

            return;
        }

        yield Finding::warn("{$prefix}: the two movers DISAGREE on issue_population — this bridge is 'all' (it real-times NON-PREFIXED issues), but the coordination config's issue_population for board {$mapping->boardId} is '{$theirs[0]}'. A bridge-missed non-prefixed event then has NO reconcile backstop. Set kanban.boards[].issue_population=all in \$COORD_CONFIG (and extend the reconcile to correlate by github_issue by-ref), or set the bridge's issue_population=prefixed.");
    }
}
