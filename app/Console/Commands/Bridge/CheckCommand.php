<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckInventory;
use App\Bridge\Check\CheckJsonRenderer;
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
use App\Bridge\Check\Checks\BoardToolsClientHalfCheck;
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
use App\Bridge\Check\EventConsumers\EventConsumerReconciler;
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
                            {--probe-tools-ssh= : round-trip a live board_my_cards over ssh to this <user@host> (opt-in — certifies the SSH-forced-command board-tools transport end to end; card 4952)}
                            {--format=text : output format — `text` (the operator report) or `json` (a versioned machine-readable document; see docs/check-json-contract.md). The checks that run, and the exit code, are identical either way.}';

    protected $description = 'Validate the bridge install config (dirs, DB connectivity, agent YAMLs)';

    private const FORMAT_TEXT = 'text';

    private const FORMAT_JSON = 'json';

    /**
     * How many findings this run reported as `unvalidated` — checks that did NOT
     * run, so a zero exit says nothing about them (card 5170). Reset per run in
     * {@see self::handle()}: the container can hand back the same instance to a
     * second `Artisan::call`, and a leaked count would over-report.
     */
    private int $unvalidatedCount = 0;

    /**
     * Whether this run renders the JSON document instead of the operator report
     * (DL-249 stage 9). Reset per run for the same reason the tally is.
     */
    private bool $json = false;

    /**
     * Findings this method's fail-soft envelopes produced, which belong to NO registered
     * check — the JSON document's `findings_outside_registry` (DL-249 stage 9).
     *
     * THEY ARE CAPTURED RATHER THAN JUST PRINTED because two of the four flip the exit
     * code. A document that omitted them would report every check clean and `ok: false`,
     * with nothing in it naming the cause. Reset per run.
     *
     * @var list<Finding>
     */
    private array $unattributed = [];

    public function handle(): int
    {
        $format = $this->strOption('format') ?? self::FORMAT_TEXT;
        if (! in_array($format, [self::FORMAT_TEXT, self::FORMAT_JSON], true)) {
            // FAIL CLOSED. Falling back to text would hand a machine consumer the
            // operator report on stdout with a zero exit — a typo'd --format silently
            // producing unparseable output is the false-clean shape one layer up.
            $this->error("bridge:check: unknown --format '{$format}' (expected: ".self::FORMAT_TEXT.', '.self::FORMAT_JSON.')');

            return self::FAILURE;
        }

        $ok = true;
        $this->json = $format === self::FORMAT_JSON;
        $this->unvalidatedCount = 0;
        $this->unattributed = [];

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
        // free of side effects, which is why this differs from the shared-identities read,
        // which LOGS: stage 5c preserved that one doubled under the byte-identical output
        // contract and card#5546 later collapsed it to a single read published on the
        // context. A second config() lookup has nothing to duplicate.
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
                    $this->emitUnattributed(Finding::fail("agent config {$name}: ".$e->getMessage()));
                    $ok = false;
                    // NULL, NOT AN EMPTY LIST (card#5698): the load is what would have told
                    // us which scopes this agent subscribes to, so nothing about its
                    // coverage is known and every scope's absence from the maps below is in
                    // doubt. The empty list would claim it covers none.
                    $ctx->agentScopeCoverage->recordUnread($name, null);

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
                    // The config PARSED, so this abort knows exactly which github scopes it
                    // is withholding (card#5698) — an agent subscribed to none of them
                    // cannot be the missing driver of any, and its abort must not cast doubt
                    // on an unrelated mapping's finding.
                    $abortedScopes = [];
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $abortedScopes[] = $sub->scopeId;
                        }
                    }
                    $ctx->agentScopeCoverage->recordUnread($name, $abortedScopes);

                    continue;
                }

                // Every SPELLING this agent subscribed a github scope with (card#7124
                // review). UNCONDITIONAL — unlike the three maps below, which are gated on
                // a classifier or a family: the dispatcher's exact-spelling match is
                // classifier-independent, so the leg that reports a spelling split must not
                // inherit a gate that would silence it on the very install it is for.
                foreach ($cfg->subscriptions as $sub) {
                    if ($sub->provider === 'github') {
                        $ctx->githubScopeSpellings[CheckContext::canonicalScope($sub->scopeId)][] = $sub->scopeId;
                    }
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
                            $ctx->writebackEmittingScopes[CheckContext::canonicalScope($sub->scopeId)] = true;
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
                            $ctx->coordCardMoveScopes[CheckContext::canonicalScope($sub->scopeId)] = true;
                        }
                    }
                }

                // card#6393: the same record for the coord-card-relane family, and the same
                // raw-config membership test is the resolved answer for the same reason (it
                // is never a default either). Kept separate from the map above because the
                // two families are independently enabled.
                if (in_array('coord-card-relane', $cfg->classifierConfig->strings('families'), true)) {
                    foreach ($cfg->subscriptions as $sub) {
                        if ($sub->provider === 'github') {
                            $ctx->coordCardRelaneScopes[CheckContext::canonicalScope($sub->scopeId)] = true;
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
                // catch(Throwable) → a classifier whose declaration could not be read
                // contributes nothing to `consumed` (conservative — at worst a false WARN,
                // never a false clean). A classifier NOT implementing the interface is
                // recorded as `declared:false` so the check can disambiguate a possible
                // false positive (sola's #22 note).
                //
                // `declared` IS TRI-STATE, and `null` is the throw path (card#5698,
                // DL-257): the catch used to write `false`, turning *"it implements the
                // interface but we could not ask it"* into *"it does not declare"* — a
                // definite claim on evidence that cannot tell the two apart. `consumed`
                // is still empty either way; what changes is that nothing downstream may
                // now assert the classifier declares nothing. Third state over a bool for
                // the same reason as AgentScopeCoverage's null scopes (DL-255) and
                // KanbanClient::idList()'s null (DL-256).
                $declares = ClassifierResolver::probeImplements($cfg->classifierClass, DeclaresConsumedEvents::class);
                $consumed = [];
                if ($declares) {
                    try {
                        $instance = ClassifierResolver::for($cfg);
                        $consumed = $instance instanceof DeclaresConsumedEvents
                            ? $instance->consumedEventTypes($cfg->classifierConfig)
                            : [];
                        // Not the throw path: an instance that is not the probed interface
                        // is a measured in-process fact, and stays `false`.
                        $declares = $instance instanceof DeclaresConsumedEvents;
                    } catch (Throwable) {
                        $declares = null;
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
        //
        // THE FILE READ IS THE SAME SHAPE AND SITS OUTSIDE THE `$configs` HALF ON PURPOSE
        // (card#5546): reading it LOGS — a permissions fault, a non-object, one line per
        // wrongly-shaped entry — so the run reads it exactly once and publishes the state,
        // and SharedIdentitiesCheck reports on that rather than reading it again. It is
        // gated only on the config dir because that check must answer for an install whose
        // agent YAMLs all failed to parse; the registry keeps its own `$configs` gate,
        // because a roster of no agents is not a roster.
        if ($ctx->configDir !== null) {
            $shared = AgentRegistry::readSharedIdentities($ctx->configDir);
            $ctx->sharedIdentities = $shared;
            if ($configs !== []) {
                $ctx->registry = AgentRegistry::fromAgentConfigs($configs, $shared->identities);
            }
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
                        // ONE FINDING" a property of this method rather than an assumption
                        // about this slot's checks' callees. Its realistic thrower is
                        // WritebackClientFactory::make() above — derivation, inline anyway.
                        //
                        // THE REASON IS PITCHED AT THE ENVELOPE, NOT AT THAT ONE THROWER.
                        // Naming the client construction would be a confident diagnosis the
                        // arm cannot support: a check throwing after the client built lands
                        // here too, and the line below would then print a different cause on
                        // the same screen. That width is also WHY the finding is
                        // `unvalidated` and not `warn` (DL-251): an envelope that cannot
                        // name its cause did not answer anything.
                        $runner->noteNotRun(CheckSlot::WritebackProbe, 'the writeback board-visibility probe could not be set up (see the warning above)');
                        $this->emitUnattributed(Finding::unvalidated('writeback: skipped board-visibility probe — '.$e->getMessage()));
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
                $this->emitUnattributed(Finding::fail('writeback.json: '.$e->getMessage()));
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
        // still not defensive code: DL-251 re-assigned severities across this command
        // (cards#5291/#5292) and left this check's set unchanged, but a call site that
        // ignored the return because "this one only warns" would swallow the first `fail`
        // the next re-assignment gives it, silently.
        //
        // DERIVATION, NOT ASSERTION (plan constraint (c)) — and hoisted here in DL-249
        // stage 9 for the reason the AgentRegistry build above is: TWO renderers read it.
        // The check turns it into prose; the JSON document emits it as data. A check
        // deriving its own would re-run the per-scope query behind the other renderer's
        // back and could disagree with it (card#5229).
        $eventConsumers = (new EventConsumerReconciler)->reconcile($ctx->githubScopeConsumers);
        $ctx->eventConsumers = $eventConsumers;
        if (! $this->emitReport($runner->run(CheckSlot::EventConsumer, $ctx))) {
            $ok = false;
        }

        // DL-217 (default-ON per v7): board-tools health, migrated to the registry in
        // DL-242 stage 7b. A default-on block that could not be satisfied
        // (suppressedReason) and a dead/ambiguous bearer FAIL (a broken enablement, not
        // opt-in); the board-STATE legs (swimlane/stage on board, service-user
        // membership) NEVER FAIL (DL-220 split — a transient/empty kanban read must not
        // FAIL the install check). They said "stay WARN" until DL-251 split them: `warn`
        // where the leg answered badly, `unvalidated` where the read never resolved.
        // What stays here is derivation: which agents have the block enabled, the bearer
        // index, the SECOND kanban client, and the ssh subset.
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
            // CHANGED DIAGNOSIS, not a refactor.
            //
            // IT SKIPS THE BOARD-STATE LEGS ONLY (DL-275). The inline code's `return` took
            // the ssh legs with it and stage 7b preserved that; card#5474 is that the ssh
            // legs READ NOTHING FROM THIS CLIENT — the pinned-line probe is offline
            // (authorized_keys + the agent's own config) and the advisory reads a map the
            // ssh loop itself fills. Skipping them here made an absent writeback secret
            // silently disarm the ssh security certification: a `pty`-granting
            // forced-command line exits 1 with the token present, and USED TO exit 0,
            // saying nothing, without it. The skip set is now exactly this client's
            // dependents.
            try {
                $ctx->boardToolsClient = WritebackClientFactory::make();
            } catch (Throwable $e) {
                $runner->noteNotRun(CheckSlot::BoardToolsState, 'the board-tools kanban client is unavailable (see the warning above)');
                $this->emitUnattributed(Finding::warn('board_tools: enabled for '.count($ctx->boardToolsEnabled).' agent(s) but the kanban writeback client is unavailable ('.$e->getMessage().') — the tools read/write via the least-privilege writeback token; place it (chmod 600) or the tools will fail at call time.'));
            }

            if ($ctx->boardToolsClient !== null) {
                foreach ($ctx->boardToolsEnabled as $cfg) {
                    if (! $this->emitReport($runner->runForAgent(CheckSlot::BoardToolsState, $cfg, $ctx))) {
                        $ok = false;
                    }
                }
            }

            // The CLIENT-half report (card#7756 / DL-313). Every plane above and below
            // observes the BRIDGE side of the door; this is the only leg that says
            // anything about the CALLING SEAT — and it can, only because the seat already
            // self-reports by calling.
            //
            // ITS OWN LOOP, OUTSIDE THE CLIENT GUARD ABOVE, for the DL-275 reason: it reads
            // one row of this bridge's own database and nothing the board-tools kanban
            // client produces, so a missing writeback token must not decide whether the
            // client half gets reported. INSIDE the enabled-subset guard, because an agent
            // with no enabled block has no client half to ask about.
            foreach ($ctx->boardToolsEnabled as $cfg) {
                if (! $this->emitReport($runner->runForAgent(CheckSlot::BoardToolsClientHalf, $cfg, $ctx))) {
                    $ok = false;
                }
            }

            // The SSH-transport pinned-line probe (card 4952) — offline, runs in the
            // default bridge:check. A present-but-bad forced-command line (grants
            // pty/forwarding), an ambiguous/absent-authoritative line, or a
            // FIPS-rejected key FAILs; an UNVERIFIABLE (non-root / relocated keyfile)
            // leg reports `unvalidated` (it WARNed until DL-251 — it may have read the
            // wrong file, so it answered nothing) and names the `sudo bridge:check`
            // cert step — never a false OK, never a hard red. The board-tools
            // security boundary is this pinned
            // line plus the live round-trip, never the account's sshd posture: card
            // 5091 retired the account-level hardening because the ssh-account
            // routinely doubles as the operator's interactive login.
            //
            // OUTSIDE THE CLIENT GUARD ABOVE (DL-275, card#5474): this plane is offline and
            // reads nothing the failed construction would have produced, so a missing
            // writeback token must not decide whether the ssh boundary gets certified. It
            // stays INSIDE the enabled-subset guard because that dependency is real — with
            // no enabled block there is no ssh agent to certify. `SshLiveProbeCheck`, the
            // heavier opt-in LIVE round-trip, has always run outside this guard; the
            // offline probe needing strictly less was the one gated on the client.
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
        } else {
            // The board-tools planes are the second-largest not-run population on a
            // healthy install (4 of the 13 on `minimal`). An ELSE rather than a
            // complementary `if`: an inverted copy of this condition would be a second
            // place to keep in step, and one more predicate in the coverage table.
            $noBoardTools = 'no agent has an enabled board_tools block';
            $runner
                ->noteNotRun(CheckSlot::BoardToolsBearer, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsState, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsClientHalf, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsSsh, $noBoardTools)
                ->noteNotRun(CheckSlot::BoardToolsSshAdvisory, $noBoardTools);
        }

        // DL-217: opt-in live board-tools probe. Offline by default (like the rest of
        // this command's local checks); when --probe-tools names the endpoint the
        // channel server will use, exercise the real loopback path end to end. A
        // non-2xx or an isolation mismatch is a HARD failure (it certifies a broken
        // enablement), unlike the offline legs above, which never fail. Migrated to
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

        // DL-249 STAGE 9: the machine document, and the ONLY thing this run writes to
        // stdout when it was asked for — every other emitter in this method is gated on
        // the format, so nothing can land beside it and make the stream unparseable.
        //
        // IT READS THE SAME `$ok` THE RETURN BELOW DOES, which is what makes "the exit
        // contract is untouched" true by construction rather than by agreement: the
        // document's verdict and the exit code are one variable, so no renderer can
        // compute one of them differently.
        if ($this->json) {
            $this->line((new CheckJsonRenderer)->encode(
                $ok,
                $runner->results(),
                $runner->inventory(),
                $this->unattributed,
                $eventConsumers,
                $ctx->agentScopeCoverage,
            ));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        // DL-242 STAGE 8: THE EXACT INVENTORY, replacing the card-5170 tally's
        // "floor, not an inventory" disclaimer. That disclaimer had to disclaim because
        // the only thing this command could count was findings carrying one severity,
        // and a tally blind to a check that was never invoked is an inventory of nothing.
        // It is now derived from the REGISTRATION LIST, so it accounts for all of them.
        //
        // ALWAYS PRINTED, unlike the tally it replaces. A coverage statement the operator
        // gets only sometimes is one they cannot rely on, which is the whole defect: the
        // value here is that a clean run now says WHAT it covered. (The json document
        // above carries the same account per check, which is why that branch returns
        // rather than printing this line into a JSON stream.)
        $this->emitInventory($runner->inventory());

        // The card-5170 tally SURVIVES, NARROWED AGAIN (DL-251) rather than deleted. Stage
        // 8 narrowed it once, to "the vocabulary is imprecise, so this is a floor"; stage
        // 10 swept the 21 could-not-measure sites that made it one, so that sentence is now
        // false and the disclosure has to move rather than go away.
        //
        // WHAT IT DISCLOSES NOW is the residual the sweep cannot reach, and it is a
        // different claim from the one it replaced: the rule this vocabulary follows is
        // keyed on what a leg CONCLUDED, so it can only make DISCLOSED blindness precise.
        // `App\Bridge\Support\Severity`'s docblock owns what that does and does not buy —
        // read it there rather than trusting a restatement here. Deleting the line
        // outright would have read as "everything unmeasured is now counted", which is a
        // stronger claim than this change earns.
        //
        // THE TAIL SAYS "NOT COUNTED HERE", NOT "SILENT", AND THE DIFFERENCE IS THE WHOLE
        // POINT. A leg that failed to notice it measured nothing can also report the
        // conclusion it would have drawn — a confidently WRONG `warn`, not an absence
        // (`EventFollowsConsumerCheck`'s advisory on the swallowed-throw path; card#5698).
        // Telling an operator the residual is silence implies everything they DO see is
        // precise, which is the same overclaim one narrowing up. It also stops saying
        // "the disclosed population": `emitInventory()` prints one line above this and
        // discloses the NOT-RUN checks, which are not in this count — two adjacent lines
        // cannot both own that phrase.
        //
        // `finding(s)`, NOT `check(s)`: {@see self::emitFinding()} counts FINDINGS, and
        // a per-agent check yields one per agent — `ChannelSnapshotCheck` on a two-agent
        // install with no `channel.server_path` declared produces two, from one check id.
        // The old noun put "2 check(s)" directly beneath an inventory line that is keyed by
        // id and counts that same check ONCE, so the two lines contradicted each other on
        // exactly the population this one exists to describe.
        if ($this->unvalidatedCount > 0) {
            $this->line("{$this->unvalidatedCount} finding(s) reported `unvalidated` — not a failure, and not a pass either: those legs could not answer their own question, so this run says nothing about what they would have found (see the lines above). This counts the legs that REPORTED being unable to measure; a leg that failed to notice it measured nothing is not counted here — it may say nothing, or say what it would have concluded.");
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
            ->registerPerAgent(CheckSlot::BoardToolsClientHalf, new BoardToolsClientHalfCheck)
            ->registerPerAgent(CheckSlot::BoardToolsSsh, new SshPinnedLineCheck($sshEnv))
            ->registerPerAgent(CheckSlot::BoardToolsSshAdvisory, new BoardToolsSshDefaultAdvisoryCheck)
            ->register(CheckSlot::ProbeTools, new BoardToolsHttpProbeCheck($probeTools))
            ->register(CheckSlot::ProbeToolsSsh, new SshLiveProbeCheck($sshEnv, $probeToolsSsh));
    }

    /**
     * Whether a pinned-line finding's severity means the agent's ssh setup is
     * INCOMPLETE (feeds the DL-225 advisory only).
     *
     * ⚠ AFTER DL-251 THIS IS EXTENSIONALLY `$severity !== Severity::Ok` — the very proxy
     * DL-236 (f) replaced — AND THAT IS SAID OUT LOUD RATHER THAN LEFT TO BE DISCOVERED.
     * Exactly one thing still separates them, and it is the thing the proxy was rejected
     * for: this is an exhaustive `match`, so a FIFTH {@see Severity} case is a phpstan
     * error here and has to be assigned deliberately, where `!== Ok` would sweep it in
     * silently. The form is doing its job precisely when it looks redundant.
     *
     * `Unvalidated` MOVED TO `true` IN DL-251, IN THE SAME CHANGE AS THE SWEEP AND
     * NECESSARILY SO. The two findings this method's only caller can see are
     * `SshTransportProbe::probePinnedLine()`'s, and its two `warn`s — an unreadable
     * `authorized_keys` and no matching line at a non-authoritative path — are exactly
     * the sites the sweep re-assigns. Left at `false` this would have gone from
     * flagging both to flagging neither, silently dropping the DL-225 advisory on the
     * install shape it was written for (the `board-tools-ssh-default-transport-advisory`
     * golden carries both lines, so omitting the widening reds it).
     *
     * ⚠ THIS NARROWS THE card-5170 HAZARD, IT DOES NOT DISCHARGE IT — the guard below
     * is a TRIPWIRE, NOT A PROOF. The rejected `!== 'ok'` proxy was rejected because a
     * later severity would be swept in without anyone deciding; post-sweep,
     * `unvalidated` on this path means "could not read authorized_keys" (setup IS
     * incomplete) and would equally mean the opposite for any future structurally-
     * unmeasurable finding — the SAME enum value, which no severity assertion can
     * separate. `CheckCommandSeverityContractTest` therefore pins the severities
     * `SshPinnedLineCheck` actually emits and reds when that set MOVES; it cannot
     * classify what moved into it. Recorded as DL-238(g), narrowed, not closed.
     *
     * ROOT CAUSE, NAMED AND OUT OF SCOPE (canon #2): the advisory infers an install
     * FACT from a SEVERITY. Having `probePinnedLine()` report setup-incompleteness
     * directly removes the coupling instead of re-tuning this map — DL-251.
     */
    private static function severityMeansSetupIncomplete(Severity $severity): bool
    {
        return match ($severity) {
            Severity::Warn, Severity::Fail, Severity::Unvalidated => true,
            Severity::Ok => false,
        };
    }

    /**
     * Render one check's {@see CheckReport} and report whether it was fail-free.
     *
     * THE TEXT RENDERER, one of the pair DL-249 stage 9 completed — {@see
     * CheckJsonRenderer} is the other, and it reads {@see CheckRunner::results()} rather
     * than going through here. This one walks findings rather than results because the
     * operator's output has no per-check framing to render: the id is carried for the
     * inventory and the JSON document, not for the terminal.
     *
     * `@phpstan-impure` states a fact the analyser does not derive: this call can change
     * `$this->unvalidatedCount` (via {@see self::emitFinding()}). The tally's `> 0` guard
     * in `handle()` is not dead — two golden fixtures render that line.
     *
     * ⚠ IT IS NOT WHAT KEEPS THAT GUARD ALIVE, AND HAS NOT BEEN FOR SOME TIME. This
     * docblock used to say the analysis fails without it, attributed to purity being
     * inferred from the directly-called method only. MEASURED AT STAGE 9, on an
     * unmodified `dev` checkout as well as on this one: removing the annotation leaves
     * phpstan at 0 errors, and it still does with the tally moved a hop deeper — so the
     * claim was already stale before this stage touched the method, and the stage did not
     * isolate what covers for it now. Recorded in the plan's § Disproved claims. The
     * annotation STAYS: it states a true fact about this method, and deleting it on the
     * strength of an unidentified substitute is how the guard comes back dead on an
     * unrelated edit.
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
     *
     * ONLY THE RENDER ARM IS GATED ON THE FORMAT (DL-249 stage 9); the tally and the
     * RETURN run either way, and that asymmetry is the exit contract's guarantee. A
     * `--format=json` run walks the identical decision path — the same checks, the same
     * findings, the same `fail`-only flip — and differs solely in which bytes reach
     * stdout. Skipping the call in json mode instead would have made the verdict depend
     * on the renderer, which is the coupling card#5229 warned this stage could introduce.
     */
    private function emitFinding(Finding $finding): bool
    {
        $message = $finding->message;

        // Counted HERE — the single chokepoint every probe finding flows through, so any
        // future probe emitting the severity is tallied without touching its call site.
        if ($finding->severity === Severity::Unvalidated) {
            $this->unvalidatedCount++;
        }

        if (! $this->json) {
            match ($finding->severity) {
                Severity::Fail => $this->error($message),
                Severity::Warn => $this->warn($message),
                Severity::Unvalidated => $this->line($message),
                Severity::Ok => $this->info($message),
            };
        }

        return match ($finding->severity) {
            Severity::Fail => false,
            Severity::Warn, Severity::Unvalidated, Severity::Ok => true,
        };
    }

    /**
     * Render a finding this method's fail-soft envelopes produced — one belonging to no
     * registered check — and RECORD it for the JSON document (DL-249 stage 9).
     *
     * IT DELIBERATELY DOES NOT TOUCH `$ok`. The two `fail` sites set it themselves, one
     * line below their call, exactly as they did when they called `error()` directly:
     * routing the exit decision through this would have moved a control-flow fact into a
     * helper for no gain, and the property that matters is asserted instead —
     * `CheckJsonContractTest` requires `ok === false` on any document carrying a `fail`
     * anywhere, so a future envelope that forgets its `$ok = false` reds rather than
     * shipping a document that contradicts itself.
     */
    private function emitUnattributed(Finding $finding): void
    {
        $this->unattributed[] = $finding;
        $this->emitFinding($finding);
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
     * channel's dispatch is exercised by every golden fixture; the `warn` channel's by none
     * of them, so its DISPATCH is proven only at the seam. The reason is no longer
     * "nothing can reach it", and that changed with card#5596: the not-run half still
     * cannot fire on a real install (every conditional slot in `handle()` records a
     * reason, by design, so `unexplainedNotRun()` is empty on every real run), but the
     * undeclared-silence half CAN — whether a silent path executes is a fact about the
     * operator's install. No fixture reaches it because every path the corpus exercises
     * is declared, which is asserted rather than assumed.
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
     * decision.
     *
     * THE WORDING LIVES HERE, NOT ON {@see CheckInventory}, because that split makes the
     * inventory what BOTH renderers read — a sentence on the value object would put this
     * renderer's voice inside the json one. DL-249 stage 9 is where that stopped being a
     * forecast: {@see CheckJsonRenderer} reads the same inventory and emits every
     * disposition per check, so this method's counts and its sentences are one of two
     * views over one value rather than the only view.
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

        // THE SECOND INTERNAL-DEFECT DISCLOSURE (card#5596): a check that ran, said
        // nothing, and never declared it meant to. Same channel and same posture as the
        // one above — counted on the line, exit code untouched, `warn` because it is
        // something to act on — but NOT the same reachability, and the difference is why
        // it is worth printing at runtime rather than only asserting in CI. Every
        // conditional slot records a not-run reason by design, so the disclosure above
        // cannot fire on any real install; this one can, because whether a given silent
        // path executes is a fact about the OPERATOR'S install, and a path no fixture
        // reaches is exactly the one that would otherwise stay quiet.
        $undeclaredSilent = $inventory->undeclaredSilent();
        if ($undeclaredSilent !== []) {
            $out[] = ['warn', 'bridge:check internal: '.count($undeclaredSilent).' registered check(s) ran, reported nothing, and did not declare that silence ('
                .implode(', ', $undeclaredSilent).'). Their result is counted above but unjudged — that is a bug in bridge:check, please report it.'];
        }

        return $out;
    }
}
