<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The single authority for the drift-prone "resolve a GitHub read token for a repo →
 * probe it → classify the outcome" decision shared by bridge:reconcile
 * (ReconcileCommand) and bridge:check (CheckCommand). Both must diagnose a token
 * problem the SAME way, or an operator gets one root-cause hint from the run and a
 * different (or absent) one from the preflight — which is exactly what had drifted:
 * check carried no status branching at all while reconcile classified 401 vs 403/404.
 *
 * Resolution precedence lives in {@see GitHubTokenResolver} (DL-184/185); the request
 * shape in {@see GitHubReadClient}. This owns only the compose: resolve → construct →
 * {@see GitHubReadClient::probeRepo} → map the exception to a {@see GitHubRepoProbeKind},
 * with the canonical 401/403/404 → hint table ({@see self::hintFor}) living here ONCE.
 * Non-throwing (the resolver is total; the probe's exceptions are caught) so a consumer
 * can map the result to its own posture. Each consumer keeps its own message wording
 * and severity — reconcile errors + skips + sets hadError; check warns and stays silent
 * on Ok / a network blip (not a token-validity signal).
 */
final class GitHubRepoProbe
{
    private GitHubTokenResolver $resolver;

    public function __construct(?GitHubTokenResolver $resolver = null)
    {
        // One resolver per probe instance so its per-repo memoization is shared across
        // a command's mapping loop (construct the probe once, probe each repo once).
        $this->resolver = $resolver ?? new GitHubTokenResolver;
    }

    /**
     * Resolve + probe a repo (the RAW writeback.json mapping key — NOT canonicalized;
     * [git-credential-map] is case-sensitive). Never throws.
     */
    public function probe(string $repo): GitHubRepoProbeResult
    {
        $resolution = $this->resolver->resolveFor($repo);
        if (! $resolution->ok()) {
            return GitHubRepoProbeResult::unresolvable((string) $resolution->problem);
        }

        $client = new GitHubReadClient((string) $resolution->token);
        $source = (string) $resolution->source;   // non-null after ok()

        try {
            $client->probeRepo($repo);

            return GitHubRepoProbeResult::resolved($client, $source);
        } catch (RequestException $e) {
            $status = $e->response->status();

            return GitHubRepoProbeResult::http($status, self::hintFor($status), $source);
        } catch (Throwable $e) {   // timeout / connection — NOT a token-validity signal
            return GitHubRepoProbeResult::network($e->getMessage(), $source);
        }
    }

    /**
     * The canonical operator hint for a probe status (DL-186): 401 ⇒ the token
     * expired/revoked; 403/404 ⇒ the token can't see this private repo (both a
     * missing-scope and a not-a-member token 404 a private repo). Empty for any other
     * status — the bare "HTTP {status}" carries the whole signal. Both consumers append
     * this, so the guidance for one condition can't diverge between them.
     */
    public static function hintFor(int $status): string
    {
        return match ($status) {
            401 => ' (token expired/revoked)',
            403, 404 => ' (token lacks access to this private repo — needs `repo` scope)',
            default => '',
        };
    }
}
