<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Provision\GitHubWebhookProbe;
use App\Bridge\Provision\GitHubWebhookProbeKind;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ReceiverUrl;
use App\Bridge\Support\SecretPath;
use Illuminate\Routing\Router;

/**
 * Does every DECLARED github subscription still have a LIVE webhook on the repo pointing at
 * this install's receiver? (card#9150)
 *
 * ⭐ THE GAP IT CLOSES, measured on a live seat 2026-09-09. A repo's webhook had been DELETED
 * and nothing anywhere said so. Every bridge-side surface was healthy — the subscription was
 * declared in the agent's YAML, the classifier resolved, the channel socket was live, the
 * per-scope HMAC secret was on disk, and the bridge dispatched other traffic normally — and
 * `bridge:check` reported NOTHING. The seat's threads arrived late, through a periodic sweep,
 * instead of by wake: degraded rather than dead, which is why it survived unnoticed until
 * somebody thought to ask. `inbox.jsonl` held 217 historical intents for that scope, so the
 * subscription had demonstrably worked before. The structural cause is that
 * `bridge:provision` cannot see github at all, so for a github subscription the DECLARED
 * config and the LIVE remote state had no comparison anywhere in the product.
 *
 * ⛔ THE SEVERITY SPLIT IS THE CARD, AND INVERTING IT IS THE NAMED TRAP.
 *   - A hook list this run READ TO THE END with no matching hook is a `fail`. The exit code
 *     moves, and that was chosen deliberately with the consequence in view: a confirmed
 *     missing hook is a DEAF AGENT, which is a broken install and not an advisory.
 *   - A read that could not happen — no token, a 403, a token without `admin:repo_hook` on
 *     that repo, a network failure, a 200 that was not a hook list — is `unvalidated`. Those
 *     MEASURED NOTHING. Rendering them as `fail` would red every install whose token cannot
 *     enumerate hooks, which is a configuration this product permits, and is exactly the
 *     did-not-measure-reported-as-a-verdict shape `App\Bridge\Support\Severity` exists to
 *     make unrepresentable (limb (a): the measurement did not complete).
 *   `Present` reports `ok` rather than falling silent, on that class's corollary (B): *the
 *   webhook is live* is a fact ONLY this read establishes, and the green line is the witness
 *   that the read happened at all — which is load-bearing precisely because the `fail`
 *   beside it rests on the same read.
 *
 * ⛔ IT MATCHES BY RECEIVER URL, in the shared {@see ReceiverUrl} primitive — and NEVER by
 * event list or hook id. A hook's event list is the operator's to choose
 * (`docs/writeback.md` § *The repo webhook* names two different correct answers), and a hook
 * id is not knowable from config.
 *
 * ⭐ IT USES {@see ReceiverUrl::deliversTo()}, NOT `bridge:provision`'s BYTE-EQUAL PREDICATE,
 * AND THE DIVERGENCE IS DELIBERATE (card#9150 r1). The first cut shared provision's exact
 * match and printed the bound on the `fail` line. That bound turned out to be REACHABLE on a
 * live consumer install, which registers `?b=PupFuzz%2Fmezzanine`: the receiver routes that
 * identically to the unencoded spelling, so the hook was healthy and this leg called it
 * MISSING — a `fail` that reds a working install, which inverts the ruling the whole leg
 * rests on. ⛔ WHAT `deliversTo()` TREATS AS ONE HOOK IS NOT RESTATED HERE, and the copy that
 * stood here was WRONG — it said the endpoint was compared *byte for byte*, which stopped being
 * true when the predicate started folding scheme/host case, the default port, trailing slashes
 * and percent-encoding in the path (r3/r4). {@see ReceiverUrl::deliversTo()} owns the rule, its
 * evidence, and the residual it does NOT close; a second copy of it here is a claim nothing reds
 * on when the predicate moves.
 *
 * ⛔ AND IT ASKS WHETHER THE URL IT COMPOSES REACHES THIS APP AT ALL, BEFORE IT ASKS GITHUB
 * ANYTHING (card#9150 r6). {@see ReceiverUrl::deliversTo()} is SYMMETRIC — it compares two URLs
 * and cannot ask whether either of them is this install's receiver — so a mis-set
 * `BRIDGE_RECEIVER_BASE_URL` produced a SILENT FALSE-`ok`: the operator pastes the payload URL
 * exactly as the `fail` line below and `docs/writeback.md` instruct, which makes the hook GitHub
 * holds byte-equal to the URL this install composes, the comparison says YES, and every delivery
 * answers 404 forever. Measured: base `https://bridge.example.com` (no receiver path) and
 * `…/webhooks/webhooks` (the doubled path `docs/writeback.md` records as a shipped defect) both
 * compared equal and both reach no route. {@see ReceiverUrl::reachesThisInstall()} is asked once
 * per scope, against this app's own router, and a scope whose composed URL does not reach it is
 * NOT probed.
 *
 * ⚠ THAT ARM IS `unvalidated` AND DELIBERATELY NOT `fail`, under the severity rule's limb (a) —
 * a read, probe or query that threw or was SKIPPED — because for an unreachable scope this leg
 * asks GitHub NOTHING and no comparison ever runs. ⛔ IT IS NOT LIMB (c), WHICH THIS SAID UNTIL
 * r7, AND THE MISSING-BASE-URL ARM BELOW IS NOT THE SAME LIMB: there the comparand is ABSENT,
 * which is limb (c) exactly; here it resolves to exactly one perfectly comparable URL and the
 * leg declines to USE it, because comparing a repo's hooks against a receiver that is not this
 * install's would measure the wrong subject. Same config key, same verdict, different limb.
 * The install IS broken, and the line says so in as many words; what this leg did not do is
 * MEASURE the repo's webhook, so it must not claim to have. ⛔ The second reason is that the
 * route table is not the whole delivery path: an install behind a proxy that REWRITES the
 * request path would answer `false` here and deliver perfectly, and nothing on this box can
 * measure that hop — so a `fail` would red a healthy install on a premise this leg cannot
 * establish, which is the exact inversion r1 shipped and was corrected for.
 *
 * ⛔ IT NEVER ENUMERATES THE FLEET. The repo's hook list carries every OTHER install's
 * receiver endpoint; the match happens inside `App\Bridge\Writeback\GitHubReadClient` and
 * only a verdict comes back, so no other endpoint can reach a finding, a log or a traceback
 * from here. Nothing below prints the configured `receiver_base_url` VALUE either — the
 * remedy names the SHAPE (`<BRIDGE_RECEIVER_BASE_URL>/github?b=<scope>`), which is what
 * `docs/writeback.md` tells the operator to paste and is the one form that cannot echo a
 * credential someone put in that URL's userinfo.
 *
 * ⛔ IT IS NOT ON THE BOOT PATH, AND THAT WAS A DESIGN DECISION RATHER THAN A PLACEMENT. A
 * GitHub API call at application boot would run on every request under FPM, put a remote
 * dependency on the receiver's hot path, and fail closed during a GitHub outage — turning
 * somebody else's downtime into this bridge's. `bridge:check` already means *validate this
 * install*, already holds the per-agent subscription list, and is already where an operator
 * looks.
 *
 * ONE PROBE PER SCOPE, NOT PER AGENT: the receiver URL is a function of `(provider, scope)`
 * and nothing else, so two agents subscribed to one repo ask one question. The agents are
 * NAMED in the finding, because which seats go deaf is the operator-relevant half.
 */
final class GitHubWebhookSubscriptionCheck implements Check
{
    public const ID = 'github.webhook_subscription';

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $scopes = $this->subscribedScopes($ctx);
        if ($scopes === []) {
            yield Silence::because('no agent this run could read declares a github subscription, so there is no repo webhook to look for');

            return;
        }

        $receiverBaseUrl = (string) config('bridge.receiver_base_url');
        if ($receiverBaseUrl === '') {
            // A COMPARISON LEG WHOSE COMPARAND DOES NOT RESOLVE (Severity limb (c)): without
            // a receiver base URL there is no URL to look for, so nothing was measured. The
            // setting itself is InstallEndpointUrlsCheck's to judge; this says only that its
            // absence cost this leg its answer.
            yield Finding::unvalidated('github webhook: this install has no bridge.receiver_base_url (BRIDGE_RECEIVER_BASE_URL), so there is no receiver URL to look for — the live webhooks of '.count($scopes).' subscribed github repo(s) were NOT checked, and this run says nothing about whether they still deliver here. Set it and re-run bridge:check.');

            return;
        }

        // ⛔ THE COMPOSED URL IS CHECKED AGAINST THIS APP'S OWN ROUTER BEFORE ANY OF IT IS
        // COMPARED TO ANYTHING. See the class docblock for the silent false-`ok` this closes.
        // It is per SCOPE rather than once per run because the composition is per scope — the
        // scope rides in the query the receiver reads — and the verdict is REPORTED once,
        // because the cause is one config value and the operator has one action to take.
        $routes = app(Router::class)->getRoutes();
        $reachable = [];
        $unreachable = [];
        foreach ($scopes as $rawScope => $agents) {
            $scope = (string) $rawScope;
            $receiverUrl = ReceiverUrl::for($receiverBaseUrl, 'github', $scope);
            if (ReceiverUrl::reachesThisInstall($receiverUrl, 'github', $scope, $routes)) {
                $reachable[] = [$scope, $agents, $receiverUrl];

                continue;
            }
            $unreachable[] = $scope;
        }

        if ($unreachable !== []) {
            // A MEASUREMENT THAT WAS SKIPPED (Severity limb (a)) — NOT limb (c), and NOT the
            // same limb as the missing-base-url arm above, which has no comparand at all. Here
            // the comparand resolves perfectly well; this leg refuses to ASK GitHub with it,
            // because a receiver URL that is not this install's would report a hook as HEALTHY
            // for deliveries that feed nothing. ⛔ The configured VALUE is not
            // printed, for the reason the whole leg does not print it — the remedy names the
            // SHAPE, which is what the operator was told to paste and is the one form that
            // cannot echo a credential somebody put in that URL's userinfo.
            yield Finding::unvalidated('github webhook: the URL this install composes for its own receiver — <BRIDGE_RECEIVER_BASE_URL>/github?b=<scope> — reaches NO route in THIS application, so a delivery to it is refused before the receiver ever reads the scope and NO webhook anywhere can deliver here. This run did NOT ask GitHub about the live webhook(s) of '.count($unreachable).' subscribed github repo(s) ('.implode(', ', $unreachable).'), because comparing a repo\'s hook URLs against a receiver URL that is not this install\'s would report a hook as HEALTHY for deliveries that feed nothing. Fix BRIDGE_RECEIVER_BASE_URL in this install\'s .env — it is the receiver\'s PUBLIC BASE and ALREADY ENDS IN the receiver path (see .env.example, and docs/writeback.md section The repo webhook) — then re-run bridge:check. If this install is served behind something that REWRITES the request path, this leg cannot see that hop and this line is what that looks like from here.');
        }

        $probe = new GitHubWebhookProbe;
        foreach ($reachable as [$scope, $agents, $receiverUrl]) {
            $who = 'subscribed by '.implode(', ', $agents);
            $result = $probe->probe($scope, $receiverUrl);

            // AN EXHAUSTIVE `match`, NOT A `switch`, and the difference is the point: a
            // sixth probe kind is a static-analysis error here and has to be assigned a
            // severity deliberately. A `switch` would fall through it silently and this leg
            // would go quiet about a state it had just measured — which is the shape the
            // whole registry exists to remove.
            yield match ($result->kind) {
                GitHubWebhookProbeKind::Present => Finding::ok(
                    "github webhook: {$scope} — a live repo webhook delivers to this install's receiver ({$who}). This run READ the repo's hook list with the token from {$result->source} to establish that. NOTE the hook was matched on the PATH and QUERY this app routes: the scheme, host and port of BRIDGE_RECEIVER_BASE_URL are NOT checked against how the world actually reaches this install, and nothing on this box can establish that — so a hook whose host is wrong in the same way the env var is wrong reads as delivering here."
                ),

                // The one arm that flips the exit code, and it is earned by an EXHAUSTED
                // enumeration — see GitHubReadClient::hasRepoWebhookFor for why a short page
                // is the end of the list and why anything less answers `null`. The NEXT STEPS
                // publication happens INSIDE this arm rather than beside the match, so there
                // is one site keyed on the measured absence and not two that could disagree.
                GitHubWebhookProbeKind::Absent => $this->reportMissing($ctx, $scope, $agents, $who, $result->source),

                GitHubWebhookProbeKind::Unresolvable => Finding::unvalidated(
                    "github webhook: {$scope} — COULD NOT LOOK: no GitHub token resolved for this repo ({$result->problem}). This run did NOT check whether the repo's webhook is live, and that is NOT evidence it is gone. Enumerating a repo's webhooks needs a token with `admin:repo_hook` on {$scope}; place one (chmod 600) or map it in the coordination store, then re-run bridge:check."
                ),

                GitHubWebhookProbeKind::Http => Finding::unvalidated(
                    "github webhook: {$scope} — COULD NOT LOOK: the hook-list read returned HTTP {$result->status}{$result->hint} using the token from {$result->source}. This run did NOT check whether the repo's webhook is live, and that is NOT evidence it is gone — an install whose token cannot enumerate hooks is a supported configuration, and this line is what it looks like."
                ),

                GitHubWebhookProbeKind::Unreadable => Finding::unvalidated(
                    "github webhook: {$scope} — COULD NOT LOOK: {$result->reason}, using the token from {$result->source}. This run did NOT check whether the repo's webhook is live, and that is NOT evidence it is gone. Re-run bridge:check once api.github.com answers normally."
                ),
            };
        }

        // NO TRAILING `Silence` DECLARATION, deliberately: the `match` above is exhaustive
        // and every arm is yielded, and the loop is only entered when the scope map is
        // non-empty, so there is no silent exit path here to declare. The one silent path is
        // the empty-map return above, which declares itself. ⚠ THE r6 PARTITION DOES NOT OPEN A
        // SECOND ONE: a scope leaves `$reachable` only into `$unreachable`, and a non-empty
        // `$unreachable` has already yielded — so the run can reach the end of this method
        // having said nothing only when it said nothing about nothing. If a future edit adds a
        // `continue` that lands in neither list, the run reports an UNDECLARED silence rather
        // than passing — which is the mechanism working, not a gap.
    }

    /**
     * The github scopes this run can see, in first-seen order, each with the agents that
     * subscribe it.
     *
     * ⚠ ITS POPULATION IS THE AGENTS WHOSE YAML PARSED AND WHOSE CLASSIFIER RESOLVED
     * ({@see CheckContext::$configs}) — narrower than the agents on disk. An agent that did
     * not get that far has its own `fail` line and is recorded in
     * {@see CheckContext::$agentScopeCoverage}; what this leg must not do is read its absence
     * as "nobody subscribes that repo".
     *
     * THE SCOPE IS THE RAW SPELLING, never a canonicalized one: the receiver URL is composed
     * from it byte for byte, and the whole point of this leg is to compare the string the
     * install would register against the string GitHub holds. Two agents spelling one repo
     * differently therefore ask two questions, which is correct — they registered two URLs,
     * and GitHub would hold two hooks.
     *
     * ⚠ WHAT THAT DOES *NOT* BUY, stated because the obvious assumption is wrong: nothing
     * here reports the SPLIT ITSELF. `WritebackMappingConfigCheck` carries the card#7124
     * `SPELLING SPLIT` leg, and its comparand is a `writeback.json` MAPPING KEY — so on an
     * install with no writeback config (a pure coordination agent is the ordinary case) two
     * agents spelling one repo differently produce two independent verdicts here and no line
     * anywhere saying they are one repo.
     *
     * @return array<string, list<string>>
     */
    private function subscribedScopes(CheckContext $ctx): array
    {
        $scopes = [];
        foreach ($ctx->configs as $cfg) {
            foreach ($cfg->subscriptions as $sub) {
                if ($sub->provider !== 'github') {
                    continue;
                }
                $existing = $scopes[$sub->scopeId] ?? [];
                if (! in_array($cfg->agentName, $existing, true)) {
                    $existing[] = $cfg->agentName;
                }
                $scopes[$sub->scopeId] = $existing;
            }
        }

        return $scopes;
    }

    /**
     * Record a MEASURED-absent hook for the NEXT STEPS block and compose its `fail`.
     *
     * ⛔ THIS MESSAGE RESTATES {@see ReceiverUrl::deliversTo()}'s RULE AND HAS TO, which is why
     * it is GUARDED rather than replaced by a pointer (canon #16): the reader is an operator
     * staring at a terminal, and they cannot follow a `{@see}`. Every copy that carried the FALSE
     * version of it became a pointer to the owner. ⚠ `docs/writeback.md` § *The repo webhook*
     * carried a PARTIAL restatement beside its pointer until r7 — every clause TRUE and the SET
     * incomplete, since it omitted the percent-DECODED path and the fragment rule, so a reader
     * enumerating from it concluded `/webhooks/git%68ub` was absent while the predicate answers
     * present. It was DELETED and pointed at the owner rather than re-synced into a sixth copy,
     * which is what `docs/config-schema.md` already did; this message is the only restatement
     * that survives anywhere, and it is the one that cannot become a pointer.
     * This one is corrected in place and
     * `GitHubWebhookSubscriptionCheckTest::test_the_fail_lines_normalisation_note_is_true_of_the_predicate`
     * asserts each clause of it AGAINST THE PREDICATE, so the text cannot drift from the
     * behaviour it describes without something going red. ⚠ It said *byte for byte* of the
     * endpoint from r1 until r6 while the predicate had folded case, the default port, trailing
     * slashes and path encoding since r3 — an operator reading it was told their hook must be
     * byte-identical while four documented spellings are not.
     *
     * ⛔ THE PUBLICATION AND THE FINDING ARE ONE ACT. `CheckContext::$githubWebhooksMissing`
     * is what the NEXT STEPS block reads to instruct an operator to go add a webhook, and an
     * entry published for anything other than a completed read would send them to re-create a
     * hook that is already there. Writing it here — inside the one arm that earns the `fail` —
     * is what makes *the block's population is the fail arm's population* true by
     * construction rather than by two call sites agreeing.
     *
     * @param  list<string>  $agents
     */
    private function reportMissing(CheckContext $ctx, string $scope, array $agents, string $who, ?string $source): Finding
    {
        $ctx->githubWebhooksMissing[] = ['scope' => $scope, 'agents' => $agents];

        return Finding::fail("github webhook: {$scope} has NO repo webhook delivering to this install's receiver — this run READ the repo's whole hook list with the token from {$source} and none of its delivery URLs is <BRIDGE_RECEIVER_BASE_URL>/github?b={$scope}. Nothing upstream will wake this install for that scope ({$who}): events reach it late through a periodic sweep, or not at all. bridge:provision CANNOT fix this — it provisions the kanban provider only, and a github webhook lives in the repo's own settings. Add it by hand in the repo's Settings then Webhooks: payload URL <BRIDGE_RECEIVER_BASE_URL>/github?b={$scope}, content type application/json, secret = the per-scope HMAC secret file{$this->secretPathClause($ctx, $scope)} — then re-run bridge:check. See docs/writeback.md section The repo webhook. NOTE the match is on WHAT THIS RECEIVER WOULD ROUTE, not on the bytes: scheme and host are compared case-insensitively, an explicit :443 or :80 is dropped, trailing slashes on the path are ignored, the path is percent-decoded, and the query is compared as decoded parameters — so `?b=owner%2Frepo` and `?b=owner/repo` are the same hook, and so is a URL that ends in a #fragment. These are NOT the same hook, each because the receiver would refuse the delivery: a DOUBLE-encoded scope (%252F), which arrives as a literal % and is answered invalid_scope; a hook carrying any extra query parameter; and a hook whose URL puts a # BEFORE the ?, since a fragment is never transmitted and that delivery carries no scope at all.");
    }

    /**
     * The `at <path>` clause naming the per-scope HMAC secret FILE — never its contents, and
     * never any command that would resolve them (`docs/config-schema.md` § *Handling a secret
     * VALUE*).
     *
     * Empty when this install's secret dir did not resolve: the leg above already reports
     * that, and a remedy naming a path built from nothing would be worse than one that names
     * no path at all.
     */
    private function secretPathClause(CheckContext $ctx, string $scope): string
    {
        return $ctx->secretDir === null
            ? ''
            : ' at '.SecretPath::for($ctx->secretDir, 'github', $scope);
    }
}
