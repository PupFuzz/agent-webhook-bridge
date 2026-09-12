<?php

namespace App\Bridge\Provision;

use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubTokenResolver;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Does a github repo still carry a webhook that delivers to THIS install's receiver?
 * (card#9150)
 *
 * ⭐ WHY IT EXISTS AT ALL. `bridge:provision` cannot see github — *"Only the `kanban`
 * provider is provisionable via API; other providers (e.g. GitHub, whose webhooks are
 * configured in repo settings) are skipped with a non-zero exit."* So for every github
 * subscription the DECLARED config and the LIVE remote state had no comparison anywhere in
 * the product. Measured 2026-09-09 on a live seat: a repo's webhook had been DELETED and
 * every bridge-side surface still looked healthy — the subscription was declared, the
 * classifier resolved, the channel socket was live, the per-scope HMAC secret was on disk,
 * and other traffic dispatched normally. `bridge:check` reported nothing, and the agent went
 * deaf for as long as nobody happened to ask.
 *
 * ⛔ IT IS A READ, AND ONLY A READ. It never creates, edits or deletes a hook: a github
 * webhook is repo-settings state whose creation needs `admin:repo_hook` and the per-scope
 * HMAC secret, and minting one from a diagnostic command would be an outward-facing write
 * nobody asked for. What it produces is a verdict; the remedy is the operator's.
 *
 * ⭐ THE THREE-WAY SPLIT IS THE WHOLE DESIGN, and getting it backwards is the named trap.
 * A read that COMPLETED and found nothing is a deaf agent — a broken install. A read that
 * could not happen — no token, a 403, a token without `admin:repo_hook` on that repo, a
 * network failure — MEASURED NOTHING, and rendering it as a fault would red every install
 * whose token cannot enumerate hooks, which is a configuration this product permits. The
 * severity assignment is the consumer's — `App\Bridge\Check\Checks\GitHubWebhookSubscriptionCheck`,
 * NAMED and never `{@see}`-linked, because pint's docblock fixer turns a fully-qualified
 * `{@see}` into a real import and an import there would invert the layer: a provisioning
 * primitive must not depend on the check that consumes it. What this class owns is that the
 * two answers are never the same value.
 *
 * Resolution precedence is {@see GitHubTokenResolver}'s (DL-184/185) and the request shape is
 * {@see GitHubReadClient}'s — reused rather than re-implemented so a token this probe says it
 * used is the token `bridge:reconcile` would use (canon #5). Non-throwing.
 */
final class GitHubWebhookProbe
{
    private GitHubTokenResolver $resolver;

    public function __construct(?GitHubTokenResolver $resolver = null)
    {
        // One resolver per probe instance so its per-repo memoization is shared across a
        // command's scope loop, exactly as GitHubRepoProbe does it.
        $this->resolver = $resolver ?? new GitHubTokenResolver;
    }

    /**
     * Resolve a token for `$repo` and ask whether any of its webhooks delivers to
     * `$receiverUrl`. Never throws.
     */
    public function probe(string $repo, string $receiverUrl): GitHubWebhookProbeResult
    {
        $resolution = $this->resolver->resolveFor($repo);
        if (! $resolution->ok()) {
            return GitHubWebhookProbeResult::unresolvable((string) $resolution->problem);
        }

        $source = (string) $resolution->source;   // non-null after ok()
        $client = new GitHubReadClient((string) $resolution->token);

        try {
            $found = $client->hasRepoWebhookFor($repo, $receiverUrl);
        } catch (RequestException $e) {
            $status = $e->response->status();

            return GitHubWebhookProbeResult::http($status, self::hintFor($status), $source);
        } catch (Throwable $e) {   // timeout / connection — the read never happened
            return GitHubWebhookProbeResult::unreadable('the request to GitHub did not complete ('.$e->getMessage().')', $source);
        }

        return match ($found) {
            true => GitHubWebhookProbeResult::present($source),
            false => GitHubWebhookProbeResult::absent($source),
            // The client's third answer: a 200 that was not a hook list, or a pagination
            // bound reached. It owns why each of those is not an absence.
            null => GitHubWebhookProbeResult::unreadable("GitHub answered 200 but this run could not enumerate {$repo}'s webhooks from it", $source),
        };
    }

    /**
     * The operator hint for a HOOK-LIST status.
     *
     * ⚠ DELIBERATELY NOT {@see GitHubRepoProbe::hintFor}, AND THE REASON IS THE ENDPOINT AND
     * NOT A PREFERENCE (canon #12 — argue the mechanism, not the label). That table is
     * DL-186's, written for `GET /repos/{repo}`, and it sends a 403/404 to *"needs `repo`
     * scope"*. On `GET /repos/{repo}/hooks` both statuses are overwhelmingly a token WITHOUT
     * `admin:repo_hook`, so sharing that table would print a confidently wrong remedy on the
     * arm this leg reaches most. Two tables is one decision per ENDPOINT, not one behaviour
     * implemented twice.
     *
     * ⛔ BOTH 403 AND 404 ARE LIVE ARMS AND NEITHER MAY BE DROPPED. An earlier revision of this
     * paragraph argued the divergence by saying GitHub *"404s a hook list it will not serve
     * rather than 403-ing it"* — which reads as *the 403 arm is dead*, and a maintainer acting
     * on it would delete the arm this leg's most important test drives (a real GitHub 403 body,
     * `Must have admin rights to Repository.`). GitHub answers BOTH depending on what the token
     * can see: a 403 where it can see the repo and not its hooks, a 404 where it may not admit
     * the repo exists. The 404 hint therefore says the two causes are indistinguishable from
     * here rather than naming one.
     */
    public static function hintFor(int $status): string
    {
        return match ($status) {
            401 => ' (token expired/revoked)',
            403 => ' (the token may not enumerate this repo\'s webhooks — that needs `admin:repo_hook`)',
            404 => ' (the token cannot see this repo, OR it lacks `admin:repo_hook` — GitHub 404s a hook list it will not serve, so the two are indistinguishable from here)',
            default => '',
        };
    }
}
