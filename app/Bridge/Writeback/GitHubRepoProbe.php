<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
 * and severity — reconcile errors + skips + sets hadError; check `warn`s a token problem,
 * reports a network blip as `unvalidated` (not a token-validity signal, but not a verdict
 * either), and is silent only on Ok.
 *
 * {@see access()} is the receiver's half (card#12968): the token `resolveFor()` gives the repo —
 * what the runtime legs use, never `GH_TOKEN` — asked for the repo, each read its legs declare and,
 * where a leg writes, a comment write that creates nothing. One verdict per leg.
 */
final class GitHubRepoProbe
{
    /** Per request of {@see access()}, which `bridge:check` runs once per repo. */
    private const ACCESS_TIMEOUT_SECONDS = 10;

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
        $resolution = $this->resolver->resolveForCli($repo);
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
            // ⛔ ESCAPED HERE, AT THE PRODUCER (card#9200, DL-366), so
            // `GitHubRepoProbeResult::$networkMessage` is a `string` that MEANS "safe to
            // print" for both of its consumers and any third.
            // ⚠ WHETHER REMOTE BYTES CAN REACH THIS ARM AT ALL IS *PROBABLE, NOT PROVEN*, and
            // it is escaped BECAUSE it is unproven rather than because a path was measured:
            // the arm above takes `$e->response->status()` (an `int`) plus bridge prose, so a
            // Laravel `RequestException` — the shape that DOES embed a response-body summary
            // — never lands here; what lands here is a `ConnectionException` whose message is
            // cURL/Guzzle prose. Ruling it a non-member would require enumerating every
            // `Throwable` Guzzle can raise, which was NOT done. One escape of a value nobody
            // prints raw costs nothing; a wrong non-membership ruling costs a live defect.
            return GitHubRepoProbeResult::network(UntrustedText::forOperator(RedactedErrorText::of($e)), $source);
        }
    }

    /**
     * The repo, `$reads` and (where `$writes`) a comment write, each asked of the token the RECEIVER
     * resolves for `$repo`; null when none resolves (`github.token_file` reports that). Never throws.
     *
     * The write is asked LAST and judged against the repo read: a 404 to the write probe is "can
     * write" only after the same token read the repo, and for a classic token only where its scopes
     * cover the repo — GitHub answers 404, not 403, to a classic token missing a scope.
     *
     * @param  list<GitHubReadLeg>  $reads
     */
    public function access(string $repo, array $reads, bool $writes): ?GitHubRepoAccess
    {
        $resolution = $this->resolver->resolveFor($repo);
        if (! $resolution->ok()) {
            return null;
        }
        $token = (string) $resolution->token;
        $client = new GitHubReadClient($token, self::ACCESS_TIMEOUT_SECONDS);

        $view = null;
        try {
            $view = $client->probeRepo($repo);
            $legs = [self::leg('read the repo', 'access to the repo', GitHubAccess::Granted, 'HTTP 200')];
        } catch (Throwable $e) {
            $legs = [self::judged('read the repo', 'access to the repo', $e)];
        }
        foreach ($reads as $read) {
            $legs[] = self::read($client, $repo, $read, $view);
        }
        if ($writes) {
            $legs[] = self::write(new GitHubWriteClient($token, self::ACCESS_TIMEOUT_SECONDS), $repo, $view, $legs[0]['access']);
        }

        return new GitHubRepoAccess($resolution->sourceLabel(), $legs);
    }

    /** @return array{what: string, needs: string, access: GitHubAccess, answer: string} */
    private static function read(GitHubReadClient $client, string $repo, GitHubReadLeg $read, ?GitHubRepoView $view): array
    {
        $branch = $view?->defaultBranch;
        if ($read === GitHubReadLeg::Compare && $branch === null) {
            return self::leg($read->describe(), $read->permission(), GitHubAccess::Unmeasured, 'not asked: the repo read named no default branch to compare');
        }
        try {
            match ($read) {
                GitHubReadLeg::IssueComments => $client->probeIssueComments($repo),
                GitHubReadLeg::Pulls => $client->probePulls($repo),
                GitHubReadLeg::Compare => $client->probeCompare($repo, (string) $branch),
            };
        } catch (Throwable $e) {
            return self::judged($read->describe(), $read->permission(), $e);
        }

        return self::leg($read->describe(), $read->permission(), GitHubAccess::Granted, 'HTTP 200');
    }

    /** @return array{what: string, needs: string, access: GitHubAccess, answer: string} */
    private static function write(GitHubWriteClient $client, string $repo, ?GitHubRepoView $view, GitHubAccess $repoRead): array
    {
        $what = 'create comments and labels';
        $needs = 'Issues and Pull requests WRITE';
        if ($view === null) {
            return $repoRead === GitHubAccess::Refused
                ? self::leg($what, $needs, GitHubAccess::Refused, 'not asked: the token cannot see the repo')
                : self::leg($what, $needs, GitHubAccess::Unmeasured, 'not asked: the repo read did not answer, and without it a 404 here cannot be told from a token that cannot see the repo');
        }
        try {
            $response = $client->probeCommentWrite($repo);
        } catch (Throwable $e) {
            return self::judged($what, $needs, $e);
        }
        $status = $response->status();
        if ($status === 403 && ! GitHubApi::saysRateLimit($response)) {
            return self::leg($what, $needs, GitHubAccess::Refused, 'HTTP 403');
        }
        if ($status !== 404) {
            return self::leg($what, $needs, GitHubAccess::Unmeasured, self::status($response));
        }
        if ($view->scopes === null) {
            return self::leg($what, $needs, GitHubAccess::Granted, 'HTTP 404 to an issue that does not exist — past the permission check, nothing written');
        }
        $covered = in_array('repo', $view->scopes, true) || ($view->private === false && in_array('public_repo', $view->scopes, true));

        return $covered
            ? self::leg($what, $needs, GitHubAccess::Granted, 'HTTP 404 to an issue that does not exist, with a classic scope that covers the repo — nothing written')
            : self::leg($what, $needs, GitHubAccess::Refused, 'HTTP 404, and the classic token carries neither `repo` nor, on a public repo, `public_repo` — GitHub answers a classic token missing a scope with 404');
    }

    /**
     * A probe's exception as a verdict: a 403 that is not a rate limit, or a 404, is a refusal;
     * every other status, and no answer, measured nothing.
     *
     * @return array{what: string, needs: string, access: GitHubAccess, answer: string}
     */
    private static function judged(string $what, string $needs, Throwable $e): array
    {
        if (! $e instanceof RequestException) {
            return self::leg($what, $needs, GitHubAccess::Unmeasured, 'no answer ('.UntrustedText::forOperator(RedactedErrorText::of($e)).')');
        }
        $refused = ! GitHubApi::saysRateLimit($e->response) && in_array($e->response->status(), [403, 404], true);

        return self::leg($what, $needs, $refused ? GitHubAccess::Refused : GitHubAccess::Unmeasured, self::status($e->response));
    }

    private static function status(Response $response): string
    {
        return 'HTTP '.$response->status().(GitHubApi::saysRateLimit($response) ? ', rate limited' : '');
    }

    /** @return array{what: string, needs: string, access: GitHubAccess, answer: string} */
    private static function leg(string $what, string $needs, GitHubAccess $access, string $answer): array
    {
        return ['what' => $what, 'needs' => $needs, 'access' => $access, 'answer' => $answer];
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
