<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubRepoProbeKind;

/**
 * The per-repo GitHub read-token reconcile probe (DL-185/186), migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * `bridge:reconcile` resolves + probes a GitHub read token PER REPO. This runs the SAME
 * shared {@see GitHubRepoProbe} so `bridge:check` cannot drift from what reconcile
 * resolves OR from how it classifies a failure — one resolve+probe+hint table, two error
 * postures (reconcile errors + skips; this reports without ever failing the run).
 *
 * NEVER FAIL (DL-026): the event-driven writeback is unaffected by a reconcile token
 * problem. A resolved-but-invalid token (DL-186) — classically a stale
 * `<secret_dir>/github/token` that SHADOWS the store map — resolves but 401s every repo
 * at reconcile time, so probing here `warn`s at preflight, naming the resolved leg,
 * rather than surfacing on the first run.
 *
 * A NETWORK BLIP IS `unvalidated`, NOT SILENCE, AND THAT IS THE POINT OF THE SPLIT
 * (DL-251). It shared the `Ok` arm until then, so a token this run could not reach GitHub
 * to probe produced exactly the output of one it probed and found good. A blip is still
 * not a token-validity signal — the finding says so — but "we never found out" is a
 * result the operator is owed, and the rule (NAMED, not `{@see}`-linked — pint's docblock
 * fixer turns a fully-qualified `{@see}` into a real import) lives in
 * `App\Bridge\Support\Severity`: a leg that did not answer its own question says
 * `unvalidated` rather than nothing.
 *
 * IT DOES NOT CATCH, AND DOES NOT NEED TO. {@see GitHubRepoProbe::probe} is documented
 * and verified total — the resolver never throws and the probe's own exceptions are
 * mapped to result kinds — so there is no throw here for a catch to soften. That matters
 * beyond this class: a check that threw PART WAY through would lose the findings it had
 * already yielded, because `CheckRunner` (NAMED, not `{@see}`-linked — pint's docblock
 * fixer turns a fully-qualified `{@see}` into a real import) materializes a check's
 * findings before the caller renders any of them. Under the inline code those earlier
 * lines had already been printed. Every leg in this slot is total, so that difference is
 * unreachable here — but it is a live constraint for any future check whose callee can
 * throw.
 */
final class ReconcileRepoTokensCheck implements Check
{
    public function id(): string
    {
        return 'reconcile.repo_tokens';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->secretDir === null || $ctx->writeback === null || $ctx->writeback->mappings === []) {
            yield Silence::because('this install maps no repo to reconcile — no secret dir, no writeback config, or no mappings — so there is no per-repo token to probe');

            return;
        }

        $probe = new GitHubRepoProbe;
        foreach (array_keys($ctx->writeback->mappings) as $repo) {
            $result = $probe->probe((string) $repo);
            switch ($result->kind) {
                case GitHubRepoProbeKind::Unresolvable:
                    yield Finding::warn("reconcile: {$repo}: {$result->problem} — bridge:reconcile will FAIL for this repo until you place a read-only token (chmod 600), map it in the coordination store's [git-credential-map], or export GH_TOKEN; the event-driven writeback is unaffected");
                    break;
                case GitHubRepoProbeKind::Http:
                    yield Finding::warn("reconcile: {$repo}: token from {$result->source} → HTTP {$result->status}{$result->hint} — bridge:reconcile will SKIP this repo. If the source is a <secret_dir>/github/token or BRIDGE_GITHUB_TOKEN_PATH file, it SHADOWS the [git-credential-map] store (a stale single-token-era file is the common upgrade cause) — remove it so each repo resolves its own store token.");
                    break;
                case GitHubRepoProbeKind::Network:
                    // DL-251: this arm shared `Ok`'s silence, so "the token is valid" and
                    // "we never found out" were byte-identical output — the leg did not
                    // answer its question (limb (a): the probe did not complete). It stays
                    // out of the token-problem vocabulary, because a blip is still not a
                    // token-validity signal; what it reports is the ABSENCE of a verdict.
                    yield Finding::unvalidated("reconcile: {$repo}: could NOT reach GitHub to probe the token from {$result->source} ({$result->networkMessage}) — the token was NOT validated for this repo, so this run says nothing about whether bridge:reconcile will work here (it is not evidence the token is bad). Re-run bridge:check once connectivity to api.github.com is restored.");
                    break;
                case GitHubRepoProbeKind::Ok:
                    // Measured and clean — the one arm with nothing to report.
                    break;
            }
        }

        yield Silence::because('every mapped repo resolved a token that GitHub accepted — the Ok arm is the one that reports nothing, and this is what its silence means');
    }
}
