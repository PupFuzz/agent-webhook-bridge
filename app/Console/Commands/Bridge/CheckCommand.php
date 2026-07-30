<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckInventory;
use App\Bridge\Check\CheckReport;
use App\Bridge\Check\CheckRunner;
use App\Bridge\Check\Checks\AgentApiTokenCheck;
use App\Bridge\Check\Checks\AgentClassifierResolvableCheck;
use App\Bridge\Check\Checks\AgentDefaultAgentCheck;
use App\Bridge\Check\Checks\AgentIdentityCollisionsCheck;
use App\Bridge\Check\Checks\AgentTreatAsSignalCheck;
use App\Bridge\Check\Checks\AgentWebhookSecretCheck;
use App\Bridge\Check\Checks\BoardToolsBearerCheck;
use App\Bridge\Check\Checks\BoardToolsBoardStateCheck;
use App\Bridge\Check\Checks\BoardToolsHttpProbeCheck;
use App\Bridge\Check\Checks\BoardToolsSshDefaultAdvisoryCheck;
use App\Bridge\Check\Checks\BoardToolsSuppressedCheck;
use App\Bridge\Check\Checks\ChannelSnapshotCheck;
use App\Bridge\Check\Checks\ChannelTokenPathCheck;
use App\Bridge\Check\Checks\ChannelTransportCheck;
use App\Bridge\Check\Checks\CiFailureFilterCheck;
use App\Bridge\Check\Checks\DatabaseConnectivityCheck;
use App\Bridge\Check\Checks\EventFollowsConsumerCheck;
use App\Bridge\Check\Checks\InboxSurfacingConfigCheck;
use App\Bridge\Check\Checks\InstallConfigDirCheck;
use App\Bridge\Check\Checks\InstallEndpointUrlsCheck;
use App\Bridge\Check\Checks\InstallProviderAdaptersCheck;
use App\Bridge\Check\Checks\InstallSecretDirCheck;
use App\Bridge\Check\Checks\InstallSuffixDsnCheck;
use App\Bridge\Check\Checks\ReconcileRepoTokensCheck;
use App\Bridge\Check\Checks\RetentionPostureCheck;
use App\Bridge\Check\Checks\SharedIdentitiesCheck;
use App\Bridge\Check\Checks\SshLiveProbeCheck;
use App\Bridge\Check\Checks\SshPinnedLineCheck;
use App\Bridge\Check\Checks\WakeMembershipCheck;
use App\Bridge\Check\Checks\WritebackAlertChannelCheck;
use App\Bridge\Check\Checks\WritebackBoardStateCheck;
use App\Bridge\Check\Checks\WritebackByRefCheck;
use App\Bridge\Check\Checks\WritebackConfigCheck;
use App\Bridge\Check\Checks\WritebackIdentityCheck;
use App\Bridge\Check\Checks\WritebackMappingConfigCheck;
use App\Bridge\Check\Checks\WritebackSourceCoverageCheck;
use App\Bridge\Check\Checks\WritebackTokenCheck;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Contracts\DeclaresConsumedEvents;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\BoardToolAgentResolver;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use Throwable;

/**
 * Validate the install: config/secret dirs, DB connectivity, and that every
 * per-agent YAML parses. Run before going live (and in the cutover runbook).
 */
class CheckCommand extends BridgeCommand
{
    protected $signature = 'bridge:check {--probe-tools= : POST a live board_my_cards to this /agent-tools/call endpoint per enabled agent (opt-in — verifies the same-box loopback recipe end to end; the endpoint is the value the channel server uses, e.g. https://<bridge-hostname>/agent-tools/call)}
                            {--probe-tools-ssh= : round-trip a live board_my_cards over ssh to this <user@host> (opt-in — certifies the SSH-forced-command board-tools transport end to end; card 4952)}';

    protected $description = 'Validate the bridge install config (dirs, DB connectivity, agent YAMLs)';

    /**
     * How many findings this run reported as `unvalidated` — checks that did NOT
     * run, so a zero exit says nothing about them (card 5170). Reset per run in
     * {@see self::handle()}: the container can hand back the same instance to a
     * second `Artisan::call`, and a leaked count would over-report.
     */
    private int $unvalidatedCount = 0;

    public function handle(): int
    {
        $ok = true;
        $this->unvalidatedCount = 0;

        // The registry, and WHAT IS IN IT is {@see self::registry()}'s docblock — one
        // copy, beside the list it describes.
        $sshEnv = $this->laravel->make(SshProbeEnvironment::class);
        $runner = $this->registry($sshEnv, $this->strOption('probe-tools'), $this->strOption('probe-tools-ssh'));
        // Plan constraint (c): the surviving inline derivation in this method populates
        // the context; migrated checks read it, unmigrated code keeps its locals.
        $ctx = new CheckContext;

        // The two install directories — migrated to InstallConfigDirCheck and
        // InstallSecretDirCheck (DL-242 stage 6). Both checks read their config keys
        // RAW, and so does the derivation below: what they report on is the SETTING, and
        // the narrowed value published on the context cannot express an empty string or a
        // value env() coerced away from a string. Reading a config key a second time is
        // free of side effects, which is why this differs from the shared-identities read
        // stage 5c preserved rather than collapsed (card#5546) — that one logs.
        if (! $this->emitReport($runner->run(CheckSlot::Install, $ctx))) {
            $ok = false;
        }

        if (! $this->emitReport($runner->run(CheckSlot::Database, $ctx))) {
            $ok = false;
        }

        // The inbox surfacing layout/mode config — migrated to InboxSurfacingConfigCheck
        // (DL-242 stage 6).
        if (! $this->emitReport($runner->run(CheckSlot::Inbox, $ctx))) {
            $ok = false;
        }

        if (! $this->emitReport($runner->run(CheckSlot::Retention, $ctx))) {
            $ok = false;
        }

        // The per-install endpoint URLs and the provider/adapter coverage leg — migrated
        // to InstallEndpointUrlsCheck and InstallProviderAdaptersCheck (DL-242 stage 6).
        if (! $this->emitReport($runner->run(CheckSlot::Providers, $ctx))) {
            $ok = false;
        }

        $configDir = config('bridge.config_dir');
        $secretDir = config('bridge.secret_dir');
        // These two stay local and are published once the loop has finished; the three
        // SCOPE maps it accumulates are written straight to the context instead, because
        // their consumers migrated in stages 3a and 7a and a local kept alongside would
        // be a second copy to keep in step. See CheckContext for what each means — and
        // for why the two groups have different traps when read too early.
        $agentNames = [];
        $configs = [];
        $ctx->secretDir = is_string($secretDir) && str_starts_with($secretDir, '/') ? $secretDir : null;
        // Only whether a path under it can be FORMED — the legs reading it ask nothing
        // else. A dir that does not exist, or is insecure, is still a string here and is
        // reported by its own leg above.
        $ctx->configDir = is_string($configDir) ? $configDir : null;
        if (is_string($configDir) && is_dir($configDir)) {
            foreach (glob(rtrim($configDir, '/').'/*.yml') ?: [] as $file) {
                $name = basename($file, '.yml');
                $agentNames[] = $name;
                try {
                    $cfg = AgentConfig::load($name, $configDir);
                } catch (Throwable $e) {
                    $this->error("agent config {$name}: ".$e->getMessage());
                    $ok = false;

                    continue;
                }
                $configs[] = $cfg;

                // The classifier-resolution gate — migrated to
                // AgentClassifierResolvableCheck (DL-242 stage 5a), run HERE so its line
                // stays at this agent's position (plan constraint (b)). THE `continue`
                // IS THE MIGRATION'S ONE COUPLING: an unresolvable classifier means every
                // remaining leg for this agent is skipped, exactly as the inline code did,
                // and CheckSlot::AgentClassifier is the abort slot that says so.
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentClassifier, $cfg, $ctx))) {
                    $ok = false;

                    continue;
                }

                // Record which github scopes this agent DRIVES the writeback for:
                // its classifier must emit writeback reactions (#2162). Detected
                // out-of-process (DL-025) — AgentClassifierResolvableCheck's
                // probeLoadable already passed above, so this child loads cleanly. Used
                // after the loop to flag orphaned writeback.json mappings (a mapping no
                // classifier drives).
                if (ClassifierResolver::probeImplements($cfg->classifierClass, EmitsWritebackReactions::class)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $ctx->writebackEmittingScopes[$sub->scopeId] = true;
                        }
                    }
                }

                // DL-204 (#4357): record scopes whose agent enables the coord-card-move family.
                // coord-card-move is never in DEFAULT_FAMILIES, so a raw-config membership test IS
                // the resolved answer — an unset families list defaults to [coord-message] and can
                // never contain it.
                if (in_array('coord-card-move', $cfg->classifierConfig->strings('families'), true)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $ctx->coordCardMoveScopes[$sub->scopeId] = true;
                        }
                    }
                }

                // card#4183 (DL-196): record the top-level github event types this
                // agent's classifier CONSUMES per subscribed github scope, for the
                // event-follows-consumer check after the loop. DL-025-safe, mirroring
                // the orphan check above: probeImplements is OUT OF PROCESS; the
                // consumedEventTypes() call is on the instance for() already resolved
                // in-process (in AgentClassifierResolvableCheck, after its probeLoadable
                // passed — a cache hit here, never a fresh load), wrapped in
                // catch(Throwable) → an undeclared/failing classifier contributes
                // nothing to `consumed` (conservative — at worst a false WARN, never a
                // false clean). A classifier NOT implementing the interface is recorded
                // as `declared:false` so the check can disambiguate a possible false
                // positive (sola's #22 note).
                $declares = ClassifierResolver::probeImplements($cfg->classifierClass, DeclaresConsumedEvents::class);
                $consumed = [];
                if ($declares) {
                    try {
                        $instance = ClassifierResolver::for($cfg);
                        $consumed = $instance instanceof DeclaresConsumedEvents
                            ? $instance->consumedEventTypes($cfg->classifierConfig)
                            : [];
                        $declares = $instance instanceof DeclaresConsumedEvents;
                    } catch (Throwable) {
                        $declares = false;   // treat as undeclared (conservative)
                        $consumed = [];
                    }
                }

                // The lazy-classifier-config advisories (DL-197's CI-failure name filter,
                // DL-213's wake_membership/comment_to population) — migrated to
                // CiFailureFilterCheck + WakeMembershipCheck (DL-242 stage 5a), run HERE
                // so their lines stay at this agent's position (plan constraint (b)) and
                // AFTER the derivation above, which prints nothing.
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentPolicy, $cfg, $ctx))) {
                    $ok = false;
                }

                foreach ($cfg->subscriptions as $sub) {
                    if ($sub->provider === 'github') {
                        $ctx->githubScopeConsumers[$sub->scopeId][] = [
                            'agent' => $name,
                            'class' => $cfg->classifierClass,
                            'consumed' => $consumed,
                            'declared' => $declares,
                        ];
                    }
                }

                // The per-agent SECRET/TOKEN and CHANNEL legs, plus the DEPLOYED
                // channel-server snapshot (DL-229) — migrated to AgentWebhookSecretCheck,
                // AgentApiTokenCheck, ChannelTokenPathCheck, ChannelTransportCheck and
                // ChannelSnapshotCheck (DL-242 stages 1 and 5b), run HERE so their lines
                // stay interleaved at this agent's position (plan constraint (b)).
                // Nothing unmigrated prints inside this slot any more, which is why one
                // call site serves it. The migration's stage-by-stage accounting is the
                // plan doc's, deliberately not restated here.
                if (! $this->emitReport($runner->runForAgent(CheckSlot::AgentConfig, $cfg, $ctx))) {
                    $ok = false;
                }
            }
        }

        // Stage 8: why the three per-agent slots may never have been reached. Recorded
        // UNCONDITIONALLY and after the fact, which is safe by construction: a reason
        // surfaces only for a check the run recorded no disposition for, so noting one
        // that did run is inert. That is also why this cannot go stale against the loop
        // above — it describes causes, not control flow it has to stay in step with.
        $perAgentSkip = match (true) {
            // NOT "unreadable" any more: the arm below owns that case, so naming it here
            // would name a cause this arm no longer covers.
            ! is_string($configDir) || ! is_dir($configDir) => 'the config dir is unset or is not a directory, so no agent config was loaded',
            // A dir that EXISTS but the bridge user cannot read passes the arm above,
            // and its glob() comes back empty — so without this arm the next one would
            // tell the operator the install has no agent config files, which is a
            // confidently false claim about an install that may hold a dozen.
            ! is_readable($configDir) => 'the config dir could not be read, so no agent config was loaded',
            $agentNames === [] => 'this install has no agent config files (no *.yml in the config dir)',
            $configs === [] => 'no agent config parsed (see the errors above)',
            default => 'every parsed agent aborted before this leg (see the errors above)',
        };
        $runner
            ->noteNotRun(CheckSlot::AgentClassifier, $perAgentSkip)
            ->noteNotRun(CheckSlot::AgentPolicy, $perAgentSkip)
            ->noteNotRun(CheckSlot::AgentConfig, $perAgentSkip);

        // Constraint (c): the accumulation above is this method's; publishing it to the
        // context is what lets a check migrated in a LATER stage read it without also
        // migrating its producer.
        $ctx->configs = $configs;
        $ctx->agentNames = $agentNames;

        // DERIVATION, NOT ASSERTION (plan constraint (c)): building the registry is what
        // FINDS the id collisions — AgentRegistry accumulates and logs them at
        // construction, and collisions() only returns what the build already found. So
        // the build cannot move into either check that reads it: two checks each
        // constructing their own would re-log every collision on a colliding install, a
        // behavior change this migration's byte-identical output contract cannot see.
        if ($configs !== [] && $ctx->configDir !== null) {
            $ctx->registry = AgentRegistry::fromAgentConfigs(
                $configs,
                AgentRegistry::loadSharedIdentities($ctx->configDir),
            );
        }

        // The post-loop ROSTER/IDENTITY legs — migrated to AgentIdentityCollisionsCheck,
        // AgentTreatAsSignalCheck, AgentDefaultAgentCheck and SharedIdentitiesCheck
        // (DL-242 stage 5c), run HERE so their lines stay between the per-agent loop and
        // the writeback envelope (plan constraint (b)). They run after the loop rather
        // than inside it because the roster they assert against does not exist until
        // every YAML has been read.
        if (! $this->emitReport($runner->run(CheckSlot::AgentRoster, $ctx))) {
            $ok = false;
        }

        // writeback.json is optional (absent ⇒ writeback off). A malformed file
        // is fail-closed (load throws) — catch it as a preflight failure. The config
        // -plane assertions on a file that DID parse are registered checks
        // (CheckSlot::Writeback); what stays here is the load and its envelope.
        if (is_string($configDir) && is_file(rtrim($configDir, '/').'/writeback.json')) {
            try {
                // DERIVATION, NOT ASSERTION (plan constraint (c)): the load is what
                // populates the context; every assertion on the result is a registered
                // check.
                $ctx->writeback = WritebackConfig::load($configDir);
                if (! $this->emitReport($runner->run(CheckSlot::Writeback, $ctx))) {
                    $ok = false;
                }

                // Probe that the writeback token can actually SEE each mapped
                // board. A token whose user lost board membership (or a drifted
                // board_id) gets a 200 with 0 cards — NOT an HTTP error — so the
                // writeback silently no-ops every move (or duplicates a dependabot
                // card). Catch that degraded-but-not-erroring state HERE, at config
                // time. The assertions are registered checks (CheckSlot::WritebackProbe);
                // what stays here is the client construction — DERIVATION, not assertion
                // (plan constraint (c)) — and its fail-soft envelope.
                if ($ctx->writeback !== null && $ctx->writeback->mappings !== []) {
                    try {
                        $ctx->client = WritebackClientFactory::make();
                        if (! $this->emitReport($runner->run(CheckSlot::WritebackProbe, $ctx))) {
                            $ok = false;
                        }
                    } catch (Throwable $e) {
                        // THE SECOND FAIL-SOFT ENVELOPE, INLINE for the same reason the
                        // outer one is (DL-242 stage 3a): CheckRunner deliberately does not
                        // catch, so wrapping emitReport() keeps "a probe failure degrades to
                        // one warn" a property of this method rather than an assumption
                        // about this slot's checks' callees. Its realistic thrower is
                        // WritebackClientFactory::make() above — derivation, inline anyway.
                        //
                        // THE REASON IS PITCHED AT THE ENVELOPE, NOT AT THAT ONE THROWER.
                        // Naming the client construction would be a confident diagnosis the
                        // arm cannot support: a check throwing after the client built lands
                        // here too, and the warn below would then print a different cause on
                        // the same screen.
                        $runner->noteNotRun(CheckSlot::WritebackProbe, 'the writeback board-visibility probe could not be set up (see the warning above)');
                        $this->warn('writeback: skipped board-visibility probe — '.$e->getMessage());
                    }
                } else {
                    $runner->noteNotRun(CheckSlot::WritebackProbe, 'writeback.json declares no repo mappings, so there is no board to probe');
                }
            } catch (Throwable $e) {
                // THE FAIL-SOFT ENVELOPE STAYS INLINE, WRAPPING the emitReport() calls
                // rather than moving into a check (DL-242 stage 3a). CheckRunner
                // deliberately does not catch, so a check that threw would ABORT
                // bridge:check where today this arm turns the throw into one error line
                // plus a non-zero exit. Keeping it here preserves that by construction.
                // The realistic thrower is WritebackConfig::load above — every leg the
                // registered checks own is total (see ReconcileRepoTokensCheck) or carries
                // its own catch (see WritebackBoardStateCheck) — but the envelope is what
                // makes that a property of this method rather than an assumption about
                // every registered writeback check's callees.
                //
                // WHICH IS EXACTLY WHY THE REASON DOES NOT NAME THE LOAD. The envelope is
                // wider than its likeliest thrower, so a reason saying "writeback.json
                // could not be loaded" would be a confident diagnosis for any check that
                // threw after it parsed — contradicting the error line printed below it.
                $wbAborted = 'the writeback checks could not be completed (see the error above)';
                $runner
                    ->noteNotRun(CheckSlot::Writeback, $wbAborted)
                    ->noteNotRun(CheckSlot::WritebackProbe, $wbAborted);
                $this->error('writeback.json: '.$e->getMessage());
                $ok = false;
            }
        } else {
            $noWriteback = 'this install has no readable writeback.json, so the PR-to-card writeback is off';
            $runner
                ->noteNotRun(CheckSlot::Writeback, $noWriteback)
                ->noteNotRun(CheckSlot::WritebackProbe, $noWriteback);
        }

        // card#4183 (DL-196): event-follows-consumer — WARN (never fail) when a github
        // event type has ARRIVED for a scope but no enabled classifier consumes it.
        // Independent of writeback (a coord agent has no writeback), which is why it is
        // outside the envelope above. Migrated to EventFollowsConsumerCheck (DL-242
        // stage 7a). THE RETURN IS HONOURED EVEN THOUGH THE CHECK CANNOT FAIL TODAY —
        // it yields only ok/warn/unvalidated, so this arm is unreachable now, and it is
        // still not defensive code: stage 10 re-assigns severities across this command
        // (cards#5291/#5292), and a call site that ignored the return because "this one
        // only warns" would swallow the first `fail` a re-assignment gives it, silently.
        if (! $this->emitReport($runner->run(CheckSlot::EventConsumer, $ctx))) {
            $ok = false;
        }

        // DL-217 (default-ON per v7): board-tools health, migrated to the registry in
        // DL-242 stage 7b. A default-on block that could not be satisfied
        // (suppressedReason) and a dead/ambiguous bearer FAIL (a broken enablement, not
        // opt-in); the board-STATE legs (swimlane/stage on board, service-user
        // membership) stay WARN (DL-220 split — a transient/empty kanban read must not
        // FAIL the install check). What stays here is derivation: which agents have the
        // block enabled, the bearer index, the SECOND kanban client, and the ssh subset.
        $ctx->boardToolsEnabled = array_values(array_filter(
            $configs,
            fn (AgentConfig $c) => $c->boardTools !== null && $c->boardTools->enabled,
        ));

        // FIRST, and OUTSIDE the enabled-subset guard below: a suppressed block is
        // enabled=false, so a fleet whose only board_tools agent is suppressed has an
        // EMPTY subset and this is the only place its failure surfaces.
        if (! $this->emitReport($runner->run(CheckSlot::BoardToolsSuppression, $ctx))) {
            $ok = false;
        }

        if ($ctx->boardToolsEnabled !== []) {
            // DERIVATION, NOT ASSERTION (plan constraint (c)), for the same reason the
            // AgentRegistry build above is: the resolver READS each enabled HTTP agent's
            // token file and LOGS every collision at CONSTRUCTION, and problems() only
            // returns what the build already found. A check building its own would re-read
            // those secrets and re-log every collision — invisible to this migration's
            // output contract, which is exactly why it cannot move.
            $ctx->boardToolsResolver = new BoardToolAgentResolver($configs);
            if (! $this->emitReport($runner->run(CheckSlot::BoardToolsBearer, $ctx))) {
                $ok = false;
            }

            // THE THIRD FAIL-SOFT ENVELOPE, INLINE, AND NARROW ON PURPOSE. Its realistic
            // thrower is the factory call it wraps; widening it around the loops below
            // would make any inner throw print the client-unavailable diagnosis — a
            // CHANGED DIAGNOSIS, not a refactor. A failure here skips the board-state legs
            // AND the ssh legs, which is what the inline code's `return` did.
            try {
                $ctx->boardToolsClient = WritebackClientFactory::make();
            } catch (Throwable $e) {
                $noClient = 'the board-tools kanban client is unavailable (see the warning above)';
                $runner
                    ->noteNotRun(CheckSlot::BoardToolsState, $noClient)
                    ->noteNotRun(CheckSlot::BoardToolsSsh, $noClient)
                    ->noteNotRun(CheckSlot::BoardToolsSshAdvisory, $noClient);
                $this->warn('board_tools: enabled for '.count($ctx->boardToolsEnabled).' agent(s) but the kanban writeback client is unavailable ('.$e->getMessage().') — the tools read/write via the least-privilege writeback token; place it (chmod 600) or the tools will fail at call time.');
            }

            if ($ctx->boardToolsClient !== null) {
                foreach ($ctx->boardToolsEnabled as $cfg) {
                    if (! $this->emitReport($runner->runForAgent(CheckSlot::BoardToolsState, $cfg, $ctx))) {
                        $ok = false;
                    }
                }

                // The SSH-transport pinned-line probe (card 4952) — offline, runs in the
                // default bridge:check. A present-but-bad forced-command line (grants
                // pty/forwarding), an ambiguous/absent-authoritative line, or a
                // FIPS-rejected key FAILs; an UNVERIFIABLE (non-root / relocated keyfile)
                // leg WARNs and names the `sudo bridge:check` cert step — never a false
                // OK, never a hard red. The board-tools security boundary is this pinned
                // line plus the live round-trip, never the account's sshd posture: card
                // 5091 retired the account-level hardening because the ssh-account
                // routinely doubles as the operator's interactive login.
                $sshAgents = array_values(array_filter(
                    $ctx->boardToolsEnabled,
                    fn (AgentConfig $c) => $c->boardTools?->transport === 'ssh',
                ));
                if ($sshAgents === []) {
                    $noSsh = 'no enabled board_tools agent uses the ssh transport';
                    $runner
                        ->noteNotRun(CheckSlot::BoardToolsSsh, $noSsh)
                        ->noteNotRun(CheckSlot::BoardToolsSshAdvisory, $noSsh);
                }

                foreach ($sshAgents as $cfg) {
                    $report = $runner->runForAgent(CheckSlot::BoardToolsSsh, $cfg, $ctx);
                    // DERIVATION FROM A CHECK'S RESULTS — the one place this command
                    // derives context from the registry rather than from the install (see
                    // CheckContext::$sshSetupIncomplete). Selected BY ID, not by walking
                    // the whole report: a second check migrated into this slot later must
                    // not silently start feeding the DL-225 advisory.
                    foreach ($report->results as $result) {
                        if ($result->id !== SshPinnedLineCheck::ID) {
                            continue;
                        }
                        foreach ($result->findings as $finding) {
                            if (self::severityMeansSetupIncomplete($finding->severity)) {
                                $ctx->sshSetupIncomplete[$cfg->agentName] = true;
                            }
                        }
                    }
                    if (! $this->emitReport($report)) {
                        $ok = false;
                    }
                }

                // The DL-225 advisory emits AFTER the whole loop above, so it is a SECOND
                // pass over the same agents rather than a check in that slot — folding it
                // in would print each agent's advisory before the next agent's
                // pinned-line result.
                foreach ($sshAgents as $cfg) {
                    if (! $this->emitReport($runner->runForAgent(CheckSlot::BoardToolsSshAdvisory, $cfg, $ctx))) {
                        $ok = false;
                    }
                }
            }
        } else {
            // The board-tools planes are the second-largest not-run population on a
            // healthy install (4 of the 13 on `minimal`). An ELSE rather than a
            // complementary `if`: an inverted copy of this condition would be a second
            // place to keep in step, and one more predicate in the coverage table.
            $noBoardTools = 'no agent has an enabled board_tools block';
            $runner
                ->noteNotRun(CheckSlot::BoardToolsBearer, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsState, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsSsh, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsSshAdvisory, $noBoardTools);
        }

        // DL-217: opt-in live board-tools probe. Offline by default (like the rest of
        // this command's local checks); when --probe-tools names the endpoint the
        // channel server will use, exercise the real loopback path end to end. A
        // non-2xx or an isolation mismatch is a HARD failure (it certifies a broken
        // enablement), unlike the offline warns above. Migrated to
        // BoardToolsHttpProbeCheck; run unconditionally — the check holds the flag and is
        // silent when it was not passed (plan constraint (a)).
        if (! $this->emitReport($runner->run(CheckSlot::ProbeTools, $ctx))) {
            $ok = false;
        }

        // card 4952: opt-in live ssh round-trip. Certifies the SSH-forced-command
        // transport end to end (reachable, JSON-clean stdout, ok:true, scope header
        // matches a configured ssh agent's lane). A failure is a HARD failure.
        // Migrated to SshLiveProbeCheck; run unconditionally — the check holds the flag
        // and is silent when it was not passed (plan constraint (a)).
        if (! $this->emitReport($runner->run(CheckSlot::ProbeToolsSsh, $ctx))) {
            $ok = false;
        }

        // DL-242 STAGE 8: THE EXACT INVENTORY, replacing the card-5170 tally's
        // "floor, not an inventory" disclaimer. That disclaimer had to disclaim because
        // the only thing this command could count was findings carrying one severity,
        // and a tally blind to a check that was never invoked is an inventory of nothing.
        // It is now derived from the REGISTRATION LIST, so it accounts for all of them.
        //
        // ALWAYS PRINTED, unlike the tally it replaces. A coverage statement the operator
        // gets only sometimes is one they cannot rely on, which is the whole defect: the
        // value here is that a clean run now says WHAT it covered.
        $this->emitInventory($runner->inventory());

        // The card-5170 tally SURVIVES, narrowed to the one thing it still says. Stage 8
        // did NOT make the severity vocabulary precise: a leg that could not measure may
        // still report `warn` rather than `unvalidated` (a dozen-odd sites in this
        // command, plus ChannelSnapshotProbe's could-not-measure findings). That
        // re-assignment is card#5291's and is separately gated, so the number remains a
        // floor FOR THAT QUESTION even though the registry above is now exact — two
        // different claims, and conflating them is what the old wording did.
        if ($this->unvalidatedCount > 0) {
            $this->line("{$this->unvalidatedCount} check(s) reported `unvalidated` — not a failure, and not a pass either: those checks could not run, so this run says nothing about what they would have found (see the lines above). This count is a floor: a leg that could not measure sometimes reports `warn` instead, so it is not the full population of what went unmeasured.");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Build the check registry — the DEFINITIVE list of what `bridge:check` inspects.
     *
     * EXTRACTED IN STAGE 8 SO THE REGISTERED SET CAN BE PINNED. Before that, nothing
     * asserted which checks this command registers (the plan's disproved-claims item 7):
     * a check dropped from the list below was caught only if its absence changed golden
     * output, so a check silent on the fixture set could be unregistered silently — the
     * same green-because-never-looked shape the registry exists to remove, one level up.
     * `CheckCommandRegistrationTest` now asserts this exact id set, and it reaches this
     * method rather than re-deriving the list, so the test cannot agree with a copy of
     * itself.
     *
     * Deliberately a private method on the command and NOT a new class: the plan's target
     * design shrinks `handle()` to build-context/run/render/exit and collapses the slot
     * enum, and that structural commitment belongs to the final stage making it
     * deliberately — not to stage 8 as a side effect.
     *
     * REGISTRATION IS UNCONDITIONAL (plan constraint (a)) — an opt-in probe's flag is a
     * CONSTRUCTOR ARGUMENT, never an `if` around register(). Built per run: the container
     * can hand this command back for a second Artisan::call, and a retained runner would
     * re-register into the same id namespace.
     *
     * THE TWO FLAG VALUES ARE PARAMETERS, not `strOption()` reads from inside. What is
     * registered must not depend on console input state this method reaches for itself:
     * that made the registered set unobservable without booting an input, which is a
     * property of the thing being pinned, not of the test.
     */
    private function registry(SshProbeEnvironment $sshEnv, ?string $probeTools, ?string $probeToolsSsh): CheckRunner
    {
        return (new CheckRunner)
            ->register(CheckSlot::Install, new InstallConfigDirCheck, new InstallSecretDirCheck)
            ->register(CheckSlot::Database, new DatabaseConnectivityCheck, new InstallSuffixDsnCheck)
            ->register(CheckSlot::Inbox, new InboxSurfacingConfigCheck)
            ->register(CheckSlot::Retention, new RetentionPostureCheck)
            ->register(CheckSlot::Providers, new InstallEndpointUrlsCheck, new InstallProviderAdaptersCheck)
            ->registerPerAgent(CheckSlot::AgentClassifier, new AgentClassifierResolvableCheck)
            ->registerPerAgent(CheckSlot::AgentPolicy, new CiFailureFilterCheck, new WakeMembershipCheck)
            ->registerPerAgent(
                CheckSlot::AgentConfig,
                new AgentWebhookSecretCheck,
                new AgentApiTokenCheck,
                new ChannelTokenPathCheck,
                new ChannelTransportCheck($this->laravel->make(ChannelProbeEnvironment::class)),
                new ChannelSnapshotCheck(base_path('examples/channel-servers')),
            )
            ->register(
                CheckSlot::AgentRoster,
                new AgentIdentityCollisionsCheck,
                new AgentTreatAsSignalCheck,
                new AgentDefaultAgentCheck,
                new SharedIdentitiesCheck,
            )
            ->register(
                CheckSlot::Writeback,
                new WritebackConfigCheck,
                new WritebackIdentityCheck,
                new WritebackAlertChannelCheck,
                new WritebackTokenCheck,
                new ReconcileRepoTokensCheck,
                new WritebackMappingConfigCheck,
            )
            ->register(
                CheckSlot::WritebackProbe,
                new WritebackByRefCheck,
                new WritebackBoardStateCheck,
                new WritebackSourceCoverageCheck,
            )
            ->register(CheckSlot::EventConsumer, new EventFollowsConsumerCheck)
            ->register(CheckSlot::BoardToolsSuppression, new BoardToolsSuppressedCheck)
            ->register(CheckSlot::BoardToolsBearer, new BoardToolsBearerCheck)
            ->registerPerAgent(CheckSlot::BoardToolsState, new BoardToolsBoardStateCheck)
            ->registerPerAgent(CheckSlot::BoardToolsSsh, new SshPinnedLineCheck($sshEnv))
            ->registerPerAgent(CheckSlot::BoardToolsSshAdvisory, new BoardToolsSshDefaultAdvisoryCheck)
            ->register(CheckSlot::ProbeTools, new BoardToolsHttpProbeCheck($probeTools))
            ->register(CheckSlot::ProbeToolsSsh, new SshLiveProbeCheck($sshEnv, $probeToolsSsh));
    }

    /**
     * Whether a pinned-line finding's severity means the agent's ssh setup is
     * INCOMPLETE (feeds the DL-225 advisory only). POSITIVE membership,
     * deliberately: the `!== 'ok'` proxy it replaces silently absorbs any severity
     * added to the vocabulary later — card 5170's `unvalidated` would have flagged
     * an agent's setup incomplete on the strength of a check nobody ran. Exhaustive
     * `match` (card 5178): a fifth {@see Severity} case is a phpstan error here, not
     * a value that silently picks a side.
     */
    private static function severityMeansSetupIncomplete(Severity $severity): bool
    {
        return match ($severity) {
            Severity::Warn, Severity::Fail => true,
            Severity::Ok, Severity::Unvalidated => false,
        };
    }

    /**
     * Render one check's {@see CheckReport} and report whether it was fail-free.
     *
     * This is the text renderer stage 9 replaces with a {@see Check}-
     * aware pair (text + json). It walks findings rather than results because today's
     * output has no per-check framing to render — the id is carried for the inventory,
     * not for the operator.
     *
     * `@phpstan-impure` states a fact the analyser does not derive: this call can change
     * `$this->unvalidatedCount` (via {@see self::emitFinding()} → {@see
     * self::emitUnvalidated()}). Purity is inferred from the DIRECTLY called method only
     * — measured, by annotating the two deeper methods instead and watching the error
     * survive both — so without this, `handle()` keeps the `= 0` it assigned and phpstan
     * calls the tally's `> 0` guard dead. It is not dead: two golden fixtures render that
     * line. Nothing was masking this until DL-242 stage 7a; the advisory it migrated was
     * simply the one direct call between the report renderer and the tally that phpstan
     * DID infer impure.
     *
     * @phpstan-impure
     */
    private function emitReport(CheckReport $report): bool
    {
        $ok = true;
        foreach ($report->findings() as $finding) {
            if (! $this->emitFinding($finding)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Render one probe {@see Finding} through the existing info/warn/error
     * convention. Returns false (→ flip the caller's $ok) ONLY on a `fail` — the exit
     * contract is that one arm, so a new severity can never change what `bridge:check`
     * exits.
     *
     * IT TAKES NO PREFIX. Stage 1 migrated every prefixing call site into a
     * {@see Check}, and a check yields display-ready messages: a
     * Finding has no scope field, and one check's two message shapes
     * (`board_tools ssh: …` and `board_tools ssh probe: …`) cannot share a render-time
     * prefix anyway.
     *
     * `unvalidated` (card 5170) renders PLAIN: green would read as certified by a
     * check that never ran, and yellow would nag a documented-correct population
     * (a multi-host install is TOLD to leave `channel.server_path` unset) with no
     * action available to silence it.
     *
     * BOTH arms are exhaustive `match`es over the enum, the RETURN included
     * (card 5178). The render arm alone would leave the exit decision as a
     * fall-through, which is the shape that let an unknown severity print green in
     * the first place — one level over. A fifth case reds phpstan at both.
     */
    private function emitFinding(Finding $finding): bool
    {
        $message = $finding->message;

        match ($finding->severity) {
            Severity::Fail => $this->error($message),
            Severity::Warn => $this->warn($message),
            Severity::Unvalidated => $this->emitUnvalidated($message),
            Severity::Ok => $this->info($message),
        };

        return match ($finding->severity) {
            Severity::Fail => false,
            Severity::Warn, Severity::Unvalidated, Severity::Ok => true,
        };
    }

    /**
     * Plain line + the run's tally. Counted HERE — the single chokepoint every probe
     * finding flows through, so any future probe emitting the severity is tallied
     * without touching its call site.
     */
    private function emitUnvalidated(string $message): void
    {
        $this->unvalidatedCount++;
        $this->line($message);
    }

    /**
     * Render the run's exact per-check account (DL-242 stage 8) — a DISPATCH LOOP over
     * {@see self::inventoryOutput()} and nothing else.
     *
     * IT DECIDES NOTHING, WHICH IS THE POINT AND WAS THE DEFECT. This method first shipped
     * calling two pure renderers and deciding HERE whether the internal-defect disclosure
     * was one of them — with those renderers tested exhaustively and directly. Replacing
     * that call with `null` therefore deleted the `bridge:check internal:` disclosure from
     * every operator's output and left the full suite green (observed, 1798/1798): testing
     * a pure method proves its COMPOSITION and says nothing about whether anything still
     * CALLS it. The decision now lives inside the seam, where a test can see it.
     *
     * WHAT IS STILL NOT WITNESSED END TO END, stated rather than rounded up: the `line`
     * channel's dispatch is exercised by all 33 golden fixtures; the `warn` channel's by
     * none of them, and NO INSTALL SHAPE CAN REACH IT — every conditional slot in
     * `handle()` records a not-run reason, by design, so `unexplainedNotRun()` is empty on
     * every real run. The composition AND the emit decision are proven; the `warn`
     * channel's DISPATCH is proven only at the seam, because nothing else can reach it.
     */
    private function emitInventory(CheckInventory $inventory): void
    {
        foreach ($this->inventoryOutput($inventory) as [$channel, $message]) {
            match ($channel) {
                'line' => $this->line($message),
                'warn' => $this->warn($message),
            };
        }
    }

    /**
     * Everything the inventory prints, in emission order, as `[channel, message]` pairs.
     *
     * ONE METHOD RATHER THAN A RENDERER PER LINE, so that *whether* the internal-defect
     * disclosure is emitted — and on which channel — is a property of a returned VALUE
     * rather than control flow at a call site no test could see (the survivor
     * {@see self::emitInventory()} describes). The golden corpus renders neither zero-arm
     * and never the disclosure, so every arm here still needs a test that hands this method
     * an inventory no install produces; what changed is that the arms now include the emit
     * decision. Stage 9 splits the text/json pair along this same seam.
     *
     * THE WORDING LIVES HERE, NOT ON {@see CheckInventory}, because that split makes the
     * inventory what BOTH renderers read — a sentence on the value object would put this
     * renderer's voice inside the json one.
     *
     * WHAT THE LINE CLAIMS IS BOUNDED ON PURPOSE, because a coverage claim stated stronger
     * than its evidence is this program's own recurring defect. It accounts for the
     * REGISTERED set, so it cannot see a leg nobody wrote as a check; a not-run REASON is
     * the reporting envelope's claim about itself; and `ran` is keyed by check id, so a
     * per-agent check that ran for two of three agents (the third aborted at the classifier
     * gate) counts once, as `ran`, with nothing here scoping it to the agents it reached.
     * All three bounds are the plan's, recorded on {@see CheckInventory}; the sentences
     * composed here are written to not exceed them.
     *
     * @return list<array{0: 'line'|'warn', 1: string}>
     */
    private function inventoryOutput(CheckInventory $inventory): array
    {
        $reported = $inventory->count(CheckDisposition::Reported);
        $silent = $inventory->count(CheckDisposition::Silent);
        $notRequested = $inventory->count(CheckDisposition::NotRequested);

        $parts = ["checks: {$inventory->registered()} registered"];
        $parts[] = "{$inventory->ran()} ran ({$reported} reported above, {$silent} with nothing to report)";
        if ($notRequested > 0) {
            $parts[] = $notRequested === 1
                ? '1 opt-in probe not requested'
                : "{$notRequested} opt-in probes not requested";
        }
        $notRun = $inventory->count(CheckDisposition::NotRun);
        if ($notRun > 0) {
            // `did not run`, NOT `not applicable here`. Several of the reasons the
            // envelopes record are COULD-NOT-LOOK rather than does-not-apply — a
            // malformed agent YAML and an unloadable writeback.json both leave the plane
            // fully applicable and merely unmeasured — so the label that covers the union
            // is the weaker one. Nothing is lost: the parenthetical already carries the
            // cause.
            $reasons = $inventory->notRunReasons();
            $parts[] = $reasons === []
                ? "{$notRun} did not run"
                : "{$notRun} did not run (".implode('; ', $reasons).')';
        }

        // The counts SUM to the registered total by construction, which is deliberate:
        // the arithmetic is the line's own control, so a reader can see nothing fell out
        // of it without trusting this method.
        $out = [['line', implode(' · ', $parts)
            .". All {$inventory->registered()} are accounted for — nothing was skipped uncounted."]];

        // THE INTERNAL-DEFECT DISCLOSURE: a check the run accounted for but could not
        // explain. NOT a silent hole — the check is still counted on the line above — but
        // the operator is owed the reason, and an envelope that gained a skip path without
        // saying why is a real defect. Disclosed at runtime rather than only in CI because
        // the shape a test never reaches is exactly the one that would otherwise stay
        // quiet; the exit code is deliberately NOT flipped (that is an accept/reject
        // change, and out of this stage's scope). WARN and not `line`: it is the one thing
        // the inventory says that the operator is asked to act on.
        $unexplained = $inventory->unexplainedNotRun();
        if ($unexplained !== []) {
            $out[] = ['warn', 'bridge:check internal: '.count($unexplained).' registered check(s) did not run and this command did not record why ('
                .implode(', ', $unexplained).'). The run above is still complete, but that is a bug in bridge:check — please report it.'];
        }

        return $out;
    }
}
