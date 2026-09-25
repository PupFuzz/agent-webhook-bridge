<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\RedactedErrorText;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a {@see PrCorrelationComment} — at most once per pull request and outcome — and is the one
 * place either handler reaches GitHub for it (DL-390): `KanbanMoveCardHandler` for the refusals it
 * makes, `GitHubPrCorrelationCommentHandler` for the failures the classifier decides. (Named, not
 * `{@see}`-linked: pint turns a docblock FQCN into a real `use`, and this namespace depends on no
 * handler.)
 *
 * ⛔ NOTHING HERE THROWS, RETRIES OR ALERTS. The comment is a REPORT of a writeback outcome that has
 * already been decided — a refusal already logged and alerted, or a no-op already warned — so a
 * failure to post it must not become a 5xx (a redelivery storm, and a retried move) and must not
 * change what `writeback_move_failed` said. Every way it can fail is ONE `Log::warning` whose message
 * starts `pr_correlation_comment: NOT posted` and whose `reason` names the step: `token_unresolved`,
 * `dedupe_read_refused` (an HTTP error answer, any 4xx or 5xx, with `status`) / `dedupe_read_failed` /
 * `dedupe_read_incomplete`, `post_refused` (an HTTP error answer, any 4xx or 5xx, with `status` — a
 * 403 is a token without Issues or Pull requests WRITE), `post_failed` (the POST did not complete, a
 * transport failure included), `post_unconfirmed` (the POST was ACCEPTED and GitHub's answer does not
 * carry the comment), `unexpected` (anything outside those steps). Error text goes through
 * {@see RedactedErrorText} (DL-389).
 *
 * ⭐ BUT A DECIDED COMMENT THAT DID NOT LAND IS REMEMBERED (card#10365 / DL-422). "The next event for
 * the same pull request and outcome tries again" is true only of an outcome that RECURS, and a pull
 * request MERGES ONCE: a refused merge comment has no later event to re-attempt it. So every arm
 * after the comment is rendered settles {@see GitHubWriteDebt} — the record DL-419 built for the
 * `protocol:invalid` label, one record for both — with the BODY this event rendered, and
 * `bridge:github-owed --fix` posts it once the cause clears. Nothing re-attempts on its own: the
 * retry is an operator act, so a permanent refusal never becomes an unbounded retry. The repair
 * goes through the same dedupe read as the event, so a comment that DID land is found rather than
 * posted again — with the read-then-POST race below: two repairs are serialised
 * ({@see GitHubWriteDebt::whileRepairing()}), a repair and a delivery are not.
 *
 * ⭐ A 2xx IS THE SERVER'S CLAIM, NOT THE OUTCOME. `POST .../comments` answers with the comment it
 * created, so the post CONFIRMS itself out of that answer — its body must start with this
 * outcome's marker, the one thing the dedupe and the repair key on — and a 2xx that does not is
 * `post_unconfirmed`: owed, never `posted`. A body the client cannot read reads as unconfirmed, and
 * costs the repair one dedupe read that then finds the comment.
 *
 * ⛔ ONE POSTING IDENTITY ON EVERY PATH. The token is the receiver's placed file and nothing else
 * ({@see GitHubTokenResolver::resolveFromFile()}). `bridge:replay` runs these handlers from a shell,
 * where the credential store and `GH_TOKEN` would otherwise resolve, and a comment posted from there
 * would carry an identity the receiver never uses. With no readable file, nothing posts.
 *
 * ⭐ THE DEDUPE IS A READ OF THE PULL REQUEST ITSELF, not a row of ours: a comment STARTING WITH the
 * marker is looked for before posting. That keeps the record where the harm is, needs no migration,
 * and holds across a DB reset and a replay. Its costs, accepted: one extra read per failure event; a
 * comment a human deletes is re-posted by the next event for that outcome; anyone who can comment on
 * the pull request can pre-empt the bridge's by posting a comment that starts with the marker; and the
 * read and the POST are not atomic, so two deliveries processed at once (a second install mapped to
 * the same repo, or an overlapping redelivery) can both find no marker and both post. When the read
 * cannot establish absence, NOTHING is posted — a missed comment is logged, a duplicate would repeat
 * on every delivery.
 */
final class PrCorrelationCommenter
{
    /**
     * Per request to GitHub. One attempt makes at most `GitHubReadClient::COMMENT_PAGE_LIMIT` GETs and
     * one POST, each bounded by this, so it can add (COMMENT_PAGE_LIMIT + 1) × TIMEOUT_SECONDS to the
     * delivery it runs in. {@see $attempted} holds that to one attempt per pull request and outcome
     * per instance, and one event reaches only one of the two reporting handlers for its pull request.
     */
    public const TIMEOUT_SECONDS = 4;

    /**
     * The arms. The two success values first; every `REASON_*` is a `reason` on this class's warnings
     * AND the census {@see retriable()} rules over — one vocabulary, so an arm cannot be added to the
     * log without the repair rule being asked about it.
     */
    public const POSTED = 'posted';

    public const ALREADY_POSTED = 'already_posted';

    public const REASON_TOKEN_UNRESOLVED = 'token_unresolved';

    public const REASON_DEDUPE_READ_REFUSED = 'dedupe_read_refused';

    public const REASON_DEDUPE_READ_FAILED = 'dedupe_read_failed';

    public const REASON_DEDUPE_READ_INCOMPLETE = 'dedupe_read_incomplete';

    public const REASON_POST_REFUSED = 'post_refused';

    public const REASON_POST_FAILED = 'post_failed';

    public const REASON_POST_UNCONFIRMED = 'post_unconfirmed';

    public const REASON_UNEXPECTED = 'unexpected';

    /** Claimed but not attempted: this instance already tried this pull request and outcome — {@see $attempted}. */
    public const DEDUPED = 'deduped';

    /**
     * Every (pull request, outcome) this instance has already attempted, WHATEVER came of it —
     * {@see OncePerKey} owns that rule and its lifetime. Here the key is what a REPEAT within one
     * delivery would be: a bundled DL's cards and each subscribed agent ask again for the same
     * marker, and a second attempt could only repeat a refusal or timeout just logged, or miss a
     * comment just posted from a list GitHub has not caught up with.
     */
    private OncePerKey $attempted;

    public function __construct(private readonly GitHubTokenResolver $tokens = new GitHubTokenResolver)
    {
        $this->attempted = new OncePerKey;
    }

    /**
     * Can the identical comment still land? Re-derived for THIS surface rather than inherited from
     * the label's (card#10365), from GitHub's REST reference for the two requests it makes:
     *  - *Create an issue comment* documents `403`, `404`, `410`, and `422` — "Validation failed, OR
     *    THE ENDPOINT HAS BEEN SPAMMED". The second reading is a throttle that time clears, and the
     *    response does not say which; the bridge's body is a fixed, whitelist-rendered comment that
     *    a validation failure has no reason to refuse, so `422` is OWED here, where the label's is
     *    terminal — the expiry bounds the other reading, as it bounds `404`'s.
     *  - *List issue comments* documents `404` and `410`; the shared split reads both as it does
     *    for any request ({@see GitHubWriteDebt::retriableStatus()}).
     *  - `410 Gone` stays terminal on both: the thread is gone, which no operator act restores.
     *
     * A reason this does not name is OWED — the label's reasoned default, and the arm it serves
     * here is `dedupe_read_incomplete`: the list could not be read to the end, which is transient
     * for an unreadable body and permanent for a thread past the page bound, and the response does
     * not say which. The cost of the permanent reading is one bounded, expiring row.
     */
    public static function retriable(string $reason, ?int $status): bool
    {
        return match ($reason) {
            self::REASON_POST_REFUSED => $status !== null && (GitHubWriteDebt::retriableStatus($status) || $status === 422),
            self::REASON_DEDUPE_READ_REFUSED => $status !== null && GitHubWriteDebt::retriableStatus($status),
            default => true,
        };
    }

    /**
     * Report a cause decided against $mapping — the mapping the caller actually wrote (or refused)
     * against, NOT one re-derived from the repo here.
     *
     * ⛔ THE MAPPING IS THE CALLER'S TO PASS (card#9850 / DL-404). The move handler NARROWS its
     * mapping onto the declared board the card was established on, so every cause it decides
     * after that point is about THAT board and its stage map. Re-reading the repo's mapping here
     * would hand the comment the repo's mapped board and that board's stage id instead — a wrong
     * board and a stage id meaningless on the card's own board, on a public pull-request page.
     * Before the narrowing the caller's mapping IS the repo mapping, so a cause decided there
     * renders exactly as it always did.
     *
     * ⛔ A FAILURE TO RENDER IS NOT OWED. Until the body exists nothing was decided that could be
     * finished later, and re-rendering at repair time would re-decide it against today's config.
     *
     * @param  array<string, mixed>  $payload  the target payload, carrying the classifier's evidence
     * @param  string  $cause  a refusal reason or a classifier cause; anything {@see PrCorrelationComment::isCause()} rejects posts nothing
     * @param  array<string, mixed>  $refusalContext
     */
    public function report(array $payload, string $cause, WritebackMapping $mapping, array $refusalContext = []): void
    {
        if (! PrCorrelationComment::isCause($cause) || ! isset($payload[PrCorrelationComment::EVIDENCE_KEY])) {
            return;
        }

        try {
            $comment = PrCorrelationComment::fromPayload($payload, $cause, $mapping, $refusalContext);
            if ($comment === null) {
                return;
            }
            $body = $comment->body();
        } catch (Throwable $e) {
            $this->unexpected(['repo' => $payload['repo'] ?? null, 'cause' => $cause], $e);

            return;
        }
        $this->attempt($comment->repo, $comment->prNumber, $comment->outcome, $body, $cause);
    }

    /**
     * Report a cause decided before any card was resolved — the classifier's, where no board has
     * been chosen and the repo's own mapping is the only one there is. Loads it, and posts
     * nothing for a repo this install does not map.
     *
     * @param  array<string, mixed>  $payload  the target payload, carrying the classifier's evidence
     */
    public function reportForRepo(array $payload, string $cause): void
    {
        if (! PrCorrelationComment::isCause($cause) || ! isset($payload[PrCorrelationComment::EVIDENCE_KEY])) {
            return;
        }

        try {
            $repo = $payload['repo'] ?? null;
            $mapping = is_string($repo) ? WritebackConfig::loadDefault()?->mappingFor($repo) : null;
        } catch (Throwable $e) {
            $this->unexpected(['repo' => $payload['repo'] ?? null, 'cause' => $cause], $e);

            return;
        }
        if ($mapping !== null) {
            $this->report($payload, $cause, $mapping);
        }
    }

    /**
     * Finish an OWED comment: the body an earlier event rendered, through the same dedupe read and
     * the same confirmation as the event path. `bridge:github-owed --fix` is the caller; whether
     * the repo is still mapped is its question to ask first, not this method's.
     */
    public function repost(string $repo, int $number, string $outcome, string $body): GitHubWriteAttempt
    {
        return $this->attempt($repo, $number, $outcome, $body, null);
    }

    private function attempt(string $repo, int $number, string $outcome, string $body, ?string $cause): GitHubWriteAttempt
    {
        $context = ['repo' => $repo, 'pr' => $number, 'outcome' => $outcome, 'cause' => $cause];
        try {
            return $this->post($repo, $number, $outcome, $body, $context);
        } catch (Throwable $e) {
            $this->unexpected($context, $e);

            // ⛔ OWED EVEN THOUGH THE POST MAY HAVE LANDED — this arm can fire after a successful
            // write. The repair reads the pull request before it posts, so a debt recorded here for
            // a comment that DID land costs one read and then clears, not a second comment.
            return $this->settled($repo, $number, $outcome, $body, self::REASON_UNEXPECTED, null);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function unexpected(array $context, Throwable $e): void
    {
        Log::warning('pr_correlation_comment: NOT posted — an unexpected failure; the writeback outcome is unchanged', [
            'catalog_id' => 'pr_correlation_comment.unexpected_failure',
        ] + $context + ['reason' => self::REASON_UNEXPECTED, 'error' => RedactedErrorText::of($e)]);
    }

    /**
     * Record this arm against the pull request and outcome — forgotten when it landed or can never
     * land, owed otherwise — and say which arm it was.
     */
    private function settled(string $repo, int $number, string $outcome, string $body, string $arm, ?int $status): GitHubWriteAttempt
    {
        $landed = $arm === self::POSTED || $arm === self::ALREADY_POSTED;
        $owed = ! $landed && self::retriable($arm, $status);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_COMMENT, $repo, $number, ['outcome' => $outcome, 'body' => $body], $arm, $status, $owed);

        return new GitHubWriteAttempt($arm, $landed, $owed, $status);
    }

    /** @param  array<string, mixed>  $context */
    private function post(string $repo, int $number, string $outcome, string $body, array $context): GitHubWriteAttempt
    {
        $marker = PrCorrelationComment::markerFor($outcome);
        if (! $this->attempted->claim($repo, $number, $marker)) {
            // Not settled: the first attempt in this instance already recorded what came of it.
            return new GitHubWriteAttempt(self::DEDUPED, landed: false, owed: self::retriable(self::DEDUPED, null));
        }

        $resolution = $this->tokens->resolveFromFile();
        if (! $resolution->ok()) {
            Log::warning('pr_correlation_comment: NOT posted — no GitHub token file resolves (only the receiver\'s token file is used here, never the credential store or GH_TOKEN); the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.no_token'] + $context + [
                'reason' => self::REASON_TOKEN_UNRESOLVED, 'problem' => $resolution->problem,
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_TOKEN_UNRESOLVED, null);
        }
        $token = (string) $resolution->token;

        try {
            $present = (new GitHubReadClient($token, self::TIMEOUT_SECONDS))->hasIssueCommentStartingWith($repo, $number, $marker);
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub answered the read of this pull request\'s comments with an HTTP error, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_http_error'] + $context + [
                'reason' => self::REASON_DEDUPE_READ_REFUSED, 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_DEDUPE_READ_REFUSED, $e->response->status());
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the read of this pull request\'s comments failed, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_failed'] + $context + [
                'reason' => self::REASON_DEDUPE_READ_FAILED, 'error' => RedactedErrorText::of($e),
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_DEDUPE_READ_FAILED, null);
        }
        if ($present === null) {
            Log::warning('pr_correlation_comment: NOT posted — this pull request\'s comments could not be read to the end, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_incomplete'] + $context + [
                'reason' => self::REASON_DEDUPE_READ_INCOMPLETE,
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_DEDUPE_READ_INCOMPLETE, null);
        }
        if ($present) {
            Log::info('pr_correlation_comment: already on the pull request for this outcome; not posted again', ['catalog_id' => 'pr_correlation_comment.already_posted'] + $context);

            return $this->settled($repo, $number, $outcome, $body, self::ALREADY_POSTED, null);
        }

        try {
            $answered = (new GitHubWriteClient($token, self::TIMEOUT_SECONDS))->createIssueComment($repo, $number, $body);
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub answered the comment with an HTTP error (a 403 is a token without Issues or Pull requests WRITE); not retried in this run, and the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.post_http_error'] + $context + [
                'reason' => self::REASON_POST_REFUSED, 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_POST_REFUSED, $e->response->status());
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the comment could not be sent to GitHub; not retried in this run, and the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.post_failed'] + $context + [
                'reason' => self::REASON_POST_FAILED, 'error' => RedactedErrorText::of($e),
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_POST_FAILED, null);
        }
        if ($answered === null || ! str_starts_with($answered, $marker)) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub ACCEPTED the comment and its answer does not carry it, so the post is not confirmed; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.post_unconfirmed'] + $context + [
                'reason' => self::REASON_POST_UNCONFIRMED,
            ]);

            return $this->settled($repo, $number, $outcome, $body, self::REASON_POST_UNCONFIRMED, null);
        }
        Log::info('pr_correlation_comment: posted', ['catalog_id' => 'pr_correlation_comment.posted'] + $context);

        return $this->settled($repo, $number, $outcome, $body, self::POSTED, null);
    }
}
