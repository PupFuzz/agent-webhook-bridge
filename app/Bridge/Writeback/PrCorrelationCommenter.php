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
 * transport failure included), `unexpected` (anything outside those steps). Error text goes through
 * {@see RedactedErrorText} (DL-389).
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
     * Every (pull request, outcome) this instance has already attempted, WHATEVER came of it. The
     * instance lives as long as the handler singleton that owns it: one delivery in the receiver, one
     * `bridge:replay` run. Within that, a bundled DL's cards and each subscribed agent ask again for
     * the same marker, and a second attempt could only repeat a refusal or timeout just logged, or
     * miss a comment just posted from a list GitHub has not caught up with. The next event tries again.
     *
     * @var array<string, true>
     */
    private array $attempted = [];

    public function __construct(private readonly GitHubTokenResolver $tokens = new GitHubTokenResolver) {}

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
            $this->post($comment, $cause);
        } catch (Throwable $e) {
            $this->unexpected($payload, $cause, $e);
        }
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
            $this->unexpected($payload, $cause, $e);

            return;
        }
        if ($mapping !== null) {
            $this->report($payload, $cause, $mapping);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function unexpected(array $payload, string $cause, Throwable $e): void
    {
        Log::warning('pr_correlation_comment: NOT posted — an unexpected failure; the writeback outcome is unchanged', [
            'catalog_id' => 'pr_correlation_comment.unexpected_failure',
            'repo' => $payload['repo'] ?? null, 'cause' => $cause, 'reason' => 'unexpected', 'error' => RedactedErrorText::of($e),
        ]);
    }

    private function post(PrCorrelationComment $comment, string $cause): void
    {
        $marker = $comment->marker();
        $context = ['repo' => $comment->repo, 'pr' => $comment->prNumber, 'outcome' => $comment->outcome, 'cause' => $cause];
        $key = $comment->repo."\x00".$comment->prNumber."\x00".$marker;
        if (isset($this->attempted[$key])) {
            return;
        }
        $this->attempted[$key] = true;

        $resolution = $this->tokens->resolveFromFile();
        if (! $resolution->ok()) {
            Log::warning('pr_correlation_comment: NOT posted — no GitHub token file resolves (only the receiver\'s token file is used here, never the credential store or GH_TOKEN); the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.no_token'] + $context + [
                'reason' => 'token_unresolved', 'problem' => $resolution->problem,
            ]);

            return;
        }
        $token = (string) $resolution->token;

        try {
            $present = (new GitHubReadClient($token, self::TIMEOUT_SECONDS))->hasIssueCommentStartingWith($comment->repo, $comment->prNumber, $marker);
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub answered the read of this pull request\'s comments with an HTTP error, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_http_error'] + $context + [
                'reason' => 'dedupe_read_refused', 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the read of this pull request\'s comments failed, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_failed'] + $context + [
                'reason' => 'dedupe_read_failed', 'error' => RedactedErrorText::of($e),
            ]);

            return;
        }
        if ($present === null) {
            Log::warning('pr_correlation_comment: NOT posted — this pull request\'s comments could not be read to the end, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.comments_read_incomplete'] + $context + [
                'reason' => 'dedupe_read_incomplete',
            ]);

            return;
        }
        if ($present) {
            Log::info('pr_correlation_comment: already on the pull request for this outcome; not posted again', ['catalog_id' => 'pr_correlation_comment.already_posted'] + $context);

            return;
        }

        try {
            (new GitHubWriteClient($token, self::TIMEOUT_SECONDS))->createIssueComment($comment->repo, $comment->prNumber, $comment->body());
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub answered the comment with an HTTP error (a 403 is a token without Issues or Pull requests WRITE); not retried, and the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.post_http_error'] + $context + [
                'reason' => 'post_refused', 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the comment could not be sent to GitHub; not retried, and the writeback outcome is unchanged', ['catalog_id' => 'pr_correlation_comment.post_failed'] + $context + [
                'reason' => 'post_failed', 'error' => RedactedErrorText::of($e),
            ]);

            return;
        }
        Log::info('pr_correlation_comment: posted', ['catalog_id' => 'pr_correlation_comment.posted'] + $context);
    }
}
