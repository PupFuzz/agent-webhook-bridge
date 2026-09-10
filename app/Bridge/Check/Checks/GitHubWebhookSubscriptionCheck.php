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
 * rests on. `deliversTo()` compares the endpoint byte for byte and the query as PARSED
 * parameters, which is the receiver's own routing; `ReceiverUrl` owns why provision keeps
 * the exact one and why `%252F` still reads as absent.
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

        $probe = new GitHubWebhookProbe;
        foreach ($scopes as $scope => $agents) {
            $scope = (string) $scope;
            $who = 'subscribed by '.implode(', ', $agents);
            $receiverUrl = ReceiverUrl::for($receiverBaseUrl, 'github', $scope);
            $result = $probe->probe($scope, $receiverUrl);

            // AN EXHAUSTIVE `match`, NOT A `switch`, and the difference is the point: a
            // sixth probe kind is a static-analysis error here and has to be assigned a
            // severity deliberately. A `switch` would fall through it silently and this leg
            // would go quiet about a state it had just measured — which is the shape the
            // whole registry exists to remove.
            yield match ($result->kind) {
                GitHubWebhookProbeKind::Present => Finding::ok(
                    "github webhook: {$scope} — a live repo webhook delivers to this install's receiver ({$who}). This run READ the repo's hook list with the token from {$result->source} to establish that."
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
        // the empty-map return above, which declares itself. If a future edit adds a
        // `continue`, the run reports an UNDECLARED silence rather than passing — which is
        // the mechanism working, not a gap.
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

        return Finding::fail("github webhook: {$scope} has NO repo webhook delivering to this install's receiver — this run READ the repo's whole hook list with the token from {$source} and none of its delivery URLs is <BRIDGE_RECEIVER_BASE_URL>/github?b={$scope}. Nothing upstream will wake this install for that scope ({$who}): events reach it late through a periodic sweep, or not at all. bridge:provision CANNOT fix this — it provisions the kanban provider only, and a github webhook lives in the repo's own settings. Add it by hand in the repo's Settings then Webhooks: payload URL <BRIDGE_RECEIVER_BASE_URL>/github?b={$scope}, content type application/json, secret = the per-scope HMAC secret file{$this->secretPathClause($ctx, $scope)} — then re-run bridge:check. See docs/writeback.md section The repo webhook. NOTE the match is on the receiver ENDPOINT byte for byte plus the query's decoded parameters, so `?b=owner%2Frepo` and `?b=owner/repo` are the same hook — but a DOUBLE-encoded scope (%252F) is not, because the receiver would refuse that delivery with invalid_scope, and neither is a hook carrying any extra query parameter.");
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
