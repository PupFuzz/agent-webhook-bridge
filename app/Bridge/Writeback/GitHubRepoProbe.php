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
     * null when none resolves (`github.token_file` reports that). Never throws.
     *
     * WRITES ARE JUDGED, NEVER MADE, AND ON THE THREAD KIND THEY LAND ON. GitHub lists the comment and
     * label endpoints under both the Issues and the Pull requests permission, so a token can hold one
     * and not the other: each kind a declared write lands on is probed once ({@see commentOn()}), and
     * each write's verdict comes only from its own kinds. A label also needs an account role of at
     * least Triage, read from the repo read; probing it directly would apply a label.
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
            $repoLeg = new GitHubProbedLeg('repo', 'read the repo', 'access to the repo', GitHubAccess::Granted, 'HTTP 200');
        } catch (Throwable $e) {
            $repoLeg = self::judged('repo', 'read the repo', 'access to the repo', $e);
        }
        $legs = [$repoLeg];
        foreach ($reads as $read) {
            $legs[] = self::read($client, $repo, $read, $view);
        }

        /** @var array<string, array{0: GitHubAccess, 1: string}> $byKind */
        $byKind = [];
        $writer = new GitHubWriteClient($token, self::ACCESS_TIMEOUT_SECONDS);
        foreach ($writes as $write) {
            foreach ($write->threadKinds() as $kind) {
                $byKind[$kind->name] ??= self::commentOn($client, $writer, $repo, $kind, $view, $repoLeg->access);
            }
        }
        foreach ($writes as $write) {
            $legs[] = self::writeVerdict($write, $byKind, $view);
        }

        return new GitHubRepoAccess($resolution->sourceLabel(), $legs);
    }

    private static function read(GitHubReadClient $client, string $repo, GitHubReadLeg $read, ?GitHubRepoView $view): GitHubProbedLeg
    {
        $branch = $view?->defaultBranch;
        if ($read === GitHubReadLeg::Compare && $branch === null) {
            return new GitHubProbedLeg($read, $read->describe(), $read->permission(), GitHubAccess::Unmeasured, 'not asked: the repo read named no default branch to compare');
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

        return new GitHubProbedLeg($read, $read->describe(), $read->permission(), GitHubAccess::Granted, 'HTTP 200');
    }

    /**
     * Can the token comment on a thread of `$kind`? A comment POST with body `{}` to the newest
     * unlocked thread of that kind. Measured on GitHub on a pull request (card#12968, `DL-500`):
     * `422` from a token with write, `403` "Resource not accessible by personal access token" from a
     * fine-grained token without it, nothing created either way. A `403` is a refusal only when
     * GitHub's message is that permission refusal (*Troubleshooting the REST API* names it for
     * fine-grained and app tokens); another 403 — a locked thread, an interaction limit, a blocked
     * user, SAML enforcement — measured nothing about the grant. `404` is a refusal only for a
     * classic token whose scopes do not cover the repo (documented there, not measured).
     *
     * @return array{0: GitHubAccess, 1: string} the verdict, and the answer naming the thread
     */
    private static function commentOn(GitHubReadClient $reader, GitHubWriteClient $writer, string $repo, GitHubThreadKind $kind, ?GitHubRepoView $view, GitHubAccess $repoRead): array
    {
        if ($view === null) {
            return $repoRead === GitHubAccess::Refused
                ? [GitHubAccess::Refused, 'not asked: the token cannot see the repo']
                : [GitHubAccess::Unmeasured, 'not asked: the repo read did not answer'];
        }
        $plural = $kind->noun().'s';
        try {
            $number = $reader->unlockedThread($repo, $kind);
        } catch (Throwable $e) {
            return [GitHubAccess::Unmeasured, "not asked: the read of the repo's {$plural}, to find one to post against, ".($e instanceof RequestException ? 'answered '.self::status($e->response) : 'got no answer ('.self::noAnswer($e).')')];
        }
        if ($number === null) {
            return [GitHubAccess::Unmeasured, "not asked: no unlocked {$kind->noun()} among the repo's newest ".GitHubReadClient::THREAD_PAGE_SIZE." {$plural} to post against"];
        }
        $thread = "#{$number}, {$kind->describe()}";
        try {
            $response = $writer->probeCommentWrite($repo, $number);
        } catch (Throwable $e) {
            return [GitHubAccess::Unmeasured, 'no answer ('.self::noAnswer($e).") from {$thread}"];
        }
        $status = $response->status();
        $message = $response->json('message');
        $permissionRefusal = is_string($message) && str_starts_with($message, 'Resource not accessible by');

        return match (true) {
            $status === 422 => [GitHubAccess::Granted, "HTTP 422 to a comment with no body on {$thread} — past the permission check, nothing created"],
            $status === 403 && $permissionRefusal => [GitHubAccess::Refused, "HTTP 403, GitHub's permission refusal, to a comment on {$thread}"],
            $status === 403 && ! GitHubApi::saysRateLimit($response) => [GitHubAccess::Unmeasured, "HTTP 403 to a comment on {$thread}, and the answer is not GitHub's permission refusal (a locked conversation, an interaction limit, a block or SAML enforcement answer 403 too)"],
            $status === 404 && $view->scopes !== null && ! self::scopeCovers($view) => [GitHubAccess::Refused, "HTTP 404 on {$thread}, and the classic token carries neither `repo` nor, on a public repo, `public_repo` — GitHub answers a classic token missing a scope with 404"],
            $status >= 200 && $status < 300 => [GitHubAccess::Wrote, "HTTP {$status}: GitHub reports it accepted a comment with no body on {$repo}#{$number}, {$kind->describe()}, which it documents as impossible — look at that thread, delete anything the probe left, and report this as a bridge defect"],
            default => [GitHubAccess::Unmeasured, self::status($response)." from {$thread}"],
        };
    }

    /**
     * One declared write's verdict, from the probes of the kinds it lands on only. A label also
     * needs an account role of at least Triage; that role is the ACCOUNT's (`permissions` in the
     * repo read), not the token's grant, so it can rule a label out — {@see GitHubAccess::BelowRole},
     * an inference, never a refusal GitHub gave — but never in on its own.
     *
     * @param  array<string, array{0: GitHubAccess, 1: string}>  $byKind
     */
    private static function writeVerdict(GitHubWriteLeg $write, array $byKind, ?GitHubRepoView $view): GitHubProbedLeg
    {
        $kinds = array_map(fn (GitHubThreadKind $kind): array => $byKind[$kind->name], $write->threadKinds());
        $with = fn (GitHubAccess $access): array => array_values(array_filter($kinds, fn (array $probe): bool => $probe[0] === $access));
        $answers = fn (array $probes): string => implode('; ', array_column($probes, 1));
        $label = $write === GitHubWriteLeg::Label;

        [$access, $answer] = match (true) {
            $with(GitHubAccess::Wrote) !== [] => [GitHubAccess::Wrote, $answers($with(GitHubAccess::Wrote))],
            $with(GitHubAccess::Refused) !== [] => [GitHubAccess::Refused, $answers($with(GitHubAccess::Refused))],
            $label && $view?->atLeastTriage === false => [GitHubAccess::BelowRole, "the repo read reports the token's account holds a role below Triage on the repo (`permissions`)"],
            $with(GitHubAccess::Unmeasured) !== [] => [GitHubAccess::Unmeasured, $answers($with(GitHubAccess::Unmeasured))],
            $label && $view?->atLeastTriage !== true => [GitHubAccess::Unmeasured, $answers($kinds).', and the repo read reported no role, which a label also needs (Triage)'],
            default => [GitHubAccess::Granted, $answers($kinds).($label ? ", and the account's role is at least Triage" : '')],
        };

        return new GitHubProbedLeg($write, $write->describe(), $write->permission(), $access, $answer);
    }

    private static function scopeCovers(GitHubRepoView $view): bool
    {
        $scopes = $view->scopes ?? [];

        return in_array('repo', $scopes, true) || ($view->private === false && in_array('public_repo', $scopes, true));
    }

    /**
     * A read's exception as a verdict: a 403 that is not a rate limit, or a 404, is a refusal;
     * every other status, and no answer, measured nothing.
     */
    private static function judged(string|GitHubReadLeg $subject, string $what, string $needs, Throwable $e): GitHubProbedLeg
    {
        if (! $e instanceof RequestException) {
            return new GitHubProbedLeg($subject, $what, $needs, GitHubAccess::Unmeasured, 'no answer ('.self::noAnswer($e).')');
        }
        $refused = ! GitHubApi::saysRateLimit($e->response) && in_array($e->response->status(), [403, 404], true);

        return new GitHubProbedLeg($subject, $what, $needs, $refused ? GitHubAccess::Refused : GitHubAccess::Unmeasured, self::status($e->response));
    }

    private static function noAnswer(Throwable $e): string
    {
        return UntrustedText::forOperator(RedactedErrorText::of($e));
    }

    private static function status(Response $response): string
    {
        return 'HTTP '.$response->status().(GitHubApi::saysRateLimit($response) ? ', rate limited' : '');
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
