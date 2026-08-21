<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\CoordConfigTerminals;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\WritebackMapping;

/**
 * Every per-mapping writeback assertion that needs NO board read, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * All of these fire on a HALF-CONFIGURED INSTALL — the state where the writeback client
 * cannot be constructed at all — which is both why they are separated from the probe
 * plane and why they matter most: every condition here makes some writeback leg silently
 * INERT, and an inert leg produces no error, no retry, and no card movement.
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
            // gate both carry one). It is the only one of the four map-fed legs that
            // asserts NOTHING when the scope is absent: it is scoped to family-enabled
            // scopes precisely so a pure PR-writeback mapping stays quiet, so an unread
            // agent costs it no false claim — only the same silence it already keeps for
            // every install that does not use coord cards. A disclosure here would print on
            // EVERY mapping of every run with an unreadable agent config, including the
            // majority that carry no coord-card config at all. That is the silent-LEG
            // class (DL-251 stage 10), whose members answered nothing while their siblings
            // answered; this arm has no answering sibling.
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
            // NO card#5698 DISCLOSURE, and NO MIRROR ARM, for the leg above — both omissions
            // are rulings. The disclosure: it is family-scoped exactly like the DL-204 arm it
            // mirrors, so an absent scope asserts nothing and an unread agent costs it no
            // false claim — the DL-204 arm's own no-disclosure ruling above owns that
            // reasoning and this arm inherits it unchanged. The mirror:
            // the DL-204 pair has a second direction because `coord_card_terminal_stage_id`
            // means the move leg and nothing else, so setting it declares an intent the
            // missing family contradicts. Nothing here carries that meaning —
            // `coord_card_lane_stage_ids` is the CREATE leg's key (required with
            // `create_coord_cards`, and read by the revive leg since card#6393), so a lane
            // model without the relane family is the normal, intended shape of every
            // lane-model install. A "lane ids set but no relane family" warn would fire on
            // all of them, including the reference install, for a family that is opt-in by
            // design.
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
        }

        yield Silence::because('every mapping has a subscribed writeback-emitting classifier spelled as the mapping spells it, and no half-configured optional leg — each of the legs above speaks only when a key is set without the key it needs, or when two files spell one repo two ways');
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
