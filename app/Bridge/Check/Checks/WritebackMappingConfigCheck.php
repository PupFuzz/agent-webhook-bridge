<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
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
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $writeback = $ctx->writeback;
        if ($writeback === null || $writeback->mappings === []) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        foreach ($writeback->mappings as $repo => $mapping) {
            // #2162: a writeback.json mapping is INERT unless some agent runs a
            // writeback-emitting classifier subscribed to its github scope. INDEPENDENT
            // of the board probe in the sibling slot — it must fire even when the
            // writeback client can't be constructed (no token / base URL), which is
            // exactly the half-configured install where an orphan is most likely.
            if (! isset($ctx->writebackEmittingScopes[$repo])) {
                yield Finding::warn("writeback: mapping for {$repo} is ORPHANED — no agent runs a writeback-emitting classifier (App\\Bridge\\Contracts\\EmitsWritebackReactions) subscribed to github:{$repo}; the mapping is inert (no card will ever move) until an agent subscribes to it with that classifier");
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
            if (isset($ctx->coordCardMoveScopes[$repo]) && $mapping->coordCardTerminalStageId === null) {
                yield Finding::warn("writeback: github:{$repo} enables the coord-card-move family but its writeback mapping has no coord_card_terminal_stage_id — the real-time coord-issue close/reopen → card move (DL-200) is INERT (issues.closed/reopened are classified but no card moves). Set coord_card_terminal_stage_id (the fleet default activates the leg where it is present), or remove coord-card-move from classifier.config.families if the move leg is not wanted.");
            }
            // DL-204 MIRROR: the other silent-inert direction. Gate 2 on but gate 1 off —
            // the handler-side gate is on, but the classifier never emits a move to hand
            // it. This is the DL-204 adoption path ("set the terminal, no flag needed")
            // dying when the operator sets the terminal but never enables the family.
            if ($mapping->moveCoordCards && ! isset($ctx->coordCardMoveScopes[$repo])) {
                yield Finding::warn("writeback: github:{$repo} has coord_card_terminal_stage_id set (the move leg is on — explicitly or by the DL-204 default) but no agent enables the coord-card-move family on that scope — the leg cannot fire (nothing classifies issues.closed/reopened into a move). Add coord-card-move to the serving agent's classifier.config.families, or remove coord_card_terminal_stage_id to disable the move leg.");
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
        }
    }

    /**
     * The cross-config three-state compare (converged w/ sola): bind the bridge's runtime
     * `issue_population` (writeback.json) to the reconcile's (`$COORD_CONFIG`, its source
     * of truth) so `bridge-on-all + reconcile-on-prefixed` — the exact non-prefixed
     * no-backstop gap — is a CHECKABLE DISAGREE, not silence. Three-state (agree /
     * DISAGREE / CANNOT-VERIFY), warn-never-fail. CANNOT-VERIFY is kept DISTINCT from
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

            yield Finding::warn("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — {$where}. {$tail} Point bridge.writeback.coord_config_path (or \$COORD_CONFIG) at coordination.config.json.");

            return;
        }
        $theirs = CoordConfigTerminals::issuePopulationsForBoardId($config, $mapping->boardId);
        if ($theirs === []) {
            yield Finding::warn("{$prefix}: CANNOT VERIFY against the reconcile's issue_population — the coordination config has no kanban.boards[] entry for board {$mapping->boardId}. {$tail}");

            return;
        }
        if (count($theirs) > 1) {
            yield Finding::warn("{$prefix}: CANNOT VERIFY — the coordination config resolves multiple issue_population values for board {$mapping->boardId} (".implode(', ', $theirs)."). {$tail}");

            return;
        }
        if ($theirs[0] === $mine) {
            yield Finding::ok("{$prefix}: coord config agrees — reconcile issue_population is '{$mine}', so the non-prefixed set is backstopped by the reconcile's by-ref correlation.");

            return;
        }

        yield Finding::warn("{$prefix}: the two movers DISAGREE on issue_population — this bridge is 'all' (it real-times NON-PREFIXED issues), but the coordination config's issue_population for board {$mapping->boardId} is '{$theirs[0]}'. A bridge-missed non-prefixed event then has NO reconcile backstop. Set kanban.boards[].issue_population=all in \$COORD_CONFIG (and extend the reconcile to correlate by github_issue by-ref), or set the bridge's issue_population=prefixed.");
    }
}
