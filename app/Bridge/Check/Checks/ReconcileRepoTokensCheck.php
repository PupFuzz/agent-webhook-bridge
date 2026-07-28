<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
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
 * postures (reconcile errors + skips; this warns).
 *
 * WARN, NEVER FAIL (DL-026): the event-driven writeback is unaffected by a reconcile
 * token problem. A resolved-but-invalid token (DL-186) — classically a stale
 * `<secret_dir>/github/token` that SHADOWS the store map — resolves but 401s every repo
 * at reconcile time, so probing here surfaces the shadow at preflight, naming the
 * resolved leg, rather than on the first run. A network blip is NOT a token problem, so
 * it stays silent.
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
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->secretDir === null || $ctx->writeback === null || $ctx->writeback->mappings === []) {
            return;   // stage 8 turns this into a returned disposition
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
                case GitHubRepoProbeKind::Ok:
                case GitHubRepoProbeKind::Network:
                    // Valid, or a network blip (not a token-validity signal) — nothing to warn.
                    break;
            }
        }
    }
}
