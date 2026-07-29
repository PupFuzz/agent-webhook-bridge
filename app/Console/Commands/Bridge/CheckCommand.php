<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
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

        // DL-242 stage 1: the check registry's first wiring. Registration is
        // UNCONDITIONAL (plan constraint (a)) — the opt-in probe's flag is a
        // constructor argument, never an `if` around register(). Built per run: the
        // container can hand this command back for a second Artisan::call, and a
        // retained runner would re-register into the same id namespace.
        $sshEnv = $this->laravel->make(SshProbeEnvironment::class);
        $runner = (new CheckRunner)
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
            ->register(CheckSlot::ProbeTools, new BoardToolsHttpProbeCheck($this->strOption('probe-tools')))
            ->register(CheckSlot::ProbeToolsSsh, new SshLiveProbeCheck($sshEnv, $this->strOption('probe-tools-ssh')));
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
                        // about three checks' callees. Its realistic thrower is
                        // WritebackClientFactory::make() above — derivation, inline anyway.
                        $this->warn('writeback: skipped board-visibility probe — '.$e->getMessage());
                    }
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
                // nine checks' callees.
                $this->error('writeback.json: '.$e->getMessage());
                $ok = false;
            }
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

        // card 5170: a green exit means nothing FAILED — it says nothing about the
        // checks that never ran. Silent at zero: an install where everything was
        // measured has nothing to disclaim, and an install that deliberately leaves
        // `channel.server_path` unset is correct (docs/multi-host.md instructs it),
        // so this discloses, it does not scold.
        //
        // The wording is bounded to what the counter can support, and the bound is
        // NOT "reports a severity" — that is true but inoperative, and its converse
        // reads as a guarantee this number cannot give. The did-not-run population
        // that ISN'T here is large and mostly DOES carry a severity: a dozen-odd
        // couldn't-probe sites in this command report `warn` (many bypassing
        // emitFinding entirely), and ChannelSnapshotProbe's three could-not-measure
        // findings come through emitFinding as `warn` and are still not tallied (it
        // was four until DL-237 deleted the completeness leg's unenumerable-reference
        // warn along with the leg). So the operative bound is the severity itself, and
        // the tally is a floor.
        if ($this->unvalidatedCount > 0) {
            $this->line("{$this->unvalidatedCount} check(s) reported `unvalidated` — not a failure, and not a pass either: those checks did not run, so this run says nothing about what they would have found (see the lines above). This is a floor, not an inventory: only checks reporting `unvalidated` are counted, and a check that could not run usually reports `warn` instead — so no tally line does NOT mean every leg ran.");
        }

        return $ok ? self::SUCCESS : self::FAILURE;
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
}
