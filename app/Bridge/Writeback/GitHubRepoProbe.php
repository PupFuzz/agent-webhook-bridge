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
 * what the runtime legs use, never `GH_TOKEN` — asked for the repo, each read its legs declare and
 * each write they declare, judged without writing. One verdict per leg.
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
     * The repo, `$reads` and `$writes`, each asked of the token the RECEIVER resolves for `$repo`;
     * null when none resolves (`github.token_file` reports that). Never throws. Each leg carries the
     * `subject` it answers for (`'repo'`, a {@see GitHubReadLeg} or a {@see GitHubWriteLeg}), so a
     * caller can name the runtime legs that depend on it.
     *
     * WRITES ARE JUDGED, NEVER MADE. {@see commentWrite()} posts a body GitHub cannot accept to an
     * existing thread; a label is judged from that answer plus the account's role, because adding
     * one needs both Issues write and at least Triage, and probing it directly would apply a label.
     *
     * @param  list<GitHubReadLeg>  $reads
     * @param  list<GitHubWriteLeg>  $writes
     */
    public function access(string $repo, array $reads, array $writes): ?GitHubRepoAccess
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
            $repoLeg = self::leg('repo', 'read the repo', 'access to the repo', GitHubAccess::Granted, 'HTTP 200');
        } catch (Throwable $e) {
            $repoLeg = self::judged('repo', 'read the repo', 'access to the repo', $e);
        }
        $legs = [$repoLeg];
        foreach ($reads as $read) {
            $legs[] = self::read($client, $repo, $read, $view);
        }
        if ($writes !== []) {
            $comment = self::commentWrite($client, new GitHubWriteClient($token, self::ACCESS_TIMEOUT_SECONDS), $repo, $view, $repoLeg['access']);
            if (in_array(GitHubWriteLeg::Comment, $writes, true)) {
                $legs[] = $comment;
            }
            if (in_array(GitHubWriteLeg::Label, $writes, true)) {
                $legs[] = self::labelWrite($comment, $view);
            }
        }

        return new GitHubRepoAccess($resolution->sourceLabel(), $legs);
    }

    /** @return array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string} */
    private static function read(GitHubReadClient $client, string $repo, GitHubReadLeg $read, ?GitHubRepoView $view): array
    {
        $branch = $view?->defaultBranch;
        if ($read === GitHubReadLeg::Compare && $branch === null) {
            return self::leg($read, $read->describe(), $read->permission(), GitHubAccess::Unmeasured, 'not asked: the repo read named no default branch to compare');
        }
        try {
            match ($read) {
                GitHubReadLeg::IssueComments => $client->probeIssueComments($repo),
                GitHubReadLeg::Pulls => $client->probePulls($repo),
                GitHubReadLeg::Compare => $client->probeCompare($repo, (string) $branch),
            };
        } catch (Throwable $e) {
            return self::judged($read, $read->describe(), $read->permission(), $e);
        }

        return self::leg($read, $read->describe(), $read->permission(), GitHubAccess::Granted, 'HTTP 200');
    }

    /**
     * A comment POST with body `{}` to the repo's latest issue or pull request. Measured on GitHub
     * (card#12968, `DL-500`): `422` from a token with Issues write, `403` from a fine-grained token
     * without it, nothing created either way. `404` is a refusal only for a classic token whose
     * scopes do not cover the repo (GitHub Docs, *Troubleshooting the REST API* — documented, not
     * measured); from any other token it measured nothing.
     *
     * @return array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}
     */
    private static function commentWrite(GitHubReadClient $reader, GitHubWriteClient $writer, string $repo, ?GitHubRepoView $view, GitHubAccess $repoRead): array
    {
        $leg = GitHubWriteLeg::Comment;
        $judge = fn (GitHubAccess $access, string $answer): array => self::leg($leg, $leg->describe(), $leg->permission(), $access, $answer);
        if ($view === null) {
            return $repoRead === GitHubAccess::Refused
                ? $judge(GitHubAccess::Refused, 'not asked: the token cannot see the repo')
                : $judge(GitHubAccess::Unmeasured, 'not asked: the repo read did not answer');
        }
        try {
            $number = $reader->latestIssueNumber($repo);
        } catch (Throwable $e) {
            return $judge(GitHubAccess::Unmeasured, 'not asked: the read of an issue to post against did not answer ('.self::described($e).')');
        }
        if ($number === null) {
            return $judge(GitHubAccess::Unmeasured, 'not asked: the repo has no issue or pull request to post against');
        }
        try {
            $response = $writer->probeCommentWrite($repo, $number);
        } catch (Throwable $e) {
            return $judge(GitHubAccess::Unmeasured, self::described($e));
        }
        $status = $response->status();

        return match (true) {
            $status === 422 => $judge(GitHubAccess::Granted, "HTTP 422 to a comment with no body on #{$number} — past the permission check, nothing created"),
            $status === 403 && ! GitHubApi::saysRateLimit($response) => $judge(GitHubAccess::Refused, "HTTP 403 to a comment on #{$number}"),
            $status === 404 && $view->scopes !== null && ! self::scopeCovers($view) => $judge(GitHubAccess::Refused, 'HTTP 404, and the classic token carries neither `repo` nor, on a public repo, `public_repo` — GitHub answers a classic token missing a scope with 404'),
            $status >= 200 && $status < 300 => $judge(GitHubAccess::Wrote, "HTTP {$status}: GitHub reports it accepted a comment with no body on {$repo}#{$number}, which it documents as impossible — look at that thread, delete anything the probe left, and report this as a bridge defect"),
            default => $judge(GitHubAccess::Unmeasured, self::status($response)),
        };
    }

    /**
     * Adding a label needs the token's Issues or Pull requests write AND an account role of at least
     * Triage. The role comes from the repo read; the token's write from the comment probe. The role
     * is the ACCOUNT's, not the token's, so it can refuse a label but never grant one alone.
     *
     * @param  array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}  $comment
     * @return array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}
     */
    private static function labelWrite(array $comment, ?GitHubRepoView $view): array
    {
        $leg = GitHubWriteLeg::Label;
        [$access, $answer] = match (true) {
            $comment['access'] === GitHubAccess::Refused => [GitHubAccess::Refused, "the token cannot write issues ({$comment['answer']})"],
            $view?->atLeastTriage === false => [GitHubAccess::Refused, "the account's role on the repo is below Triage (`permissions` in the repo read)"],
            $comment['access'] === GitHubAccess::Granted && $view?->atLeastTriage === true => [GitHubAccess::Granted, "the token can write issues ({$comment['answer']}) and the account's role is at least Triage"],
            $comment['access'] === GitHubAccess::Wrote => [GitHubAccess::Wrote, $comment['answer']],
            $comment['access'] === GitHubAccess::Granted => [GitHubAccess::Unmeasured, "the token can write issues ({$comment['answer']}), and the repo read reported no role"],
            default => [GitHubAccess::Unmeasured, "whether the token can write issues was not measured ({$comment['answer']})"],
        };

        return self::leg($leg, $leg->describe(), $leg->permission(), $access, $answer);
    }

    private static function scopeCovers(GitHubRepoView $view): bool
    {
        $scopes = $view->scopes ?? [];

        return in_array('repo', $scopes, true) || ($view->private === false && in_array('public_repo', $scopes, true));
    }

    /**
     * A probe's exception as a verdict: a 403 that is not a rate limit, or a 404, is a refusal;
     * every other status, and no answer, measured nothing.
     *
     * @return array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}
     */
    private static function judged(string|GitHubReadLeg $subject, string $what, string $needs, Throwable $e): array
    {
        $refused = $e instanceof RequestException && ! GitHubApi::saysRateLimit($e->response) && in_array($e->response->status(), [403, 404], true);

        return self::leg($subject, $what, $needs, $refused ? GitHubAccess::Refused : GitHubAccess::Unmeasured, self::described($e));
    }

    private static function described(Throwable $e): string
    {
        return $e instanceof RequestException ? self::status($e->response) : 'no answer ('.UntrustedText::forOperator(RedactedErrorText::of($e)).')';
    }

    private static function status(Response $response): string
    {
        return 'HTTP '.$response->status().(GitHubApi::saysRateLimit($response) ? ', rate limited' : '');
    }

    /** @return array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string} */
    private static function leg(string|GitHubReadLeg|GitHubWriteLeg $subject, string $what, string $needs, GitHubAccess $access, string $answer): array
    {
        return ['subject' => $subject, 'what' => $what, 'needs' => $needs, 'access' => $access, 'answer' => $answer];
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
