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
 * `dedupe_read_refused` / `dedupe_read_failed` / `dedupe_read_incomplete`, `post_refused` (a GitHub
 * 4xx, with `status` — a 403 is a token without Issues or Pull requests WRITE), `post_failed` (the
 * POST did not complete, a transport failure included), `unexpected` (anything outside those steps).
 * Error text goes through {@see RedactedErrorText} (DL-389).
 *
 * ⭐ THE DEDUPE IS A READ OF THE PULL REQUEST ITSELF, not a row of ours: a comment STARTING WITH the
 * marker is looked for before posting. That keeps the record where the harm is, needs no migration,
 * and holds across a DB reset, a replay and a second install mapped to the same repo. Its costs,
 * accepted: one extra GET per failure event; a comment a human deletes is re-posted by the next event
 * for that outcome; and anyone who can comment on the pull request can pre-empt the bridge's by posting
 * a comment that starts with the marker. When the read cannot establish absence, NOTHING is posted — a
 * missed comment is logged, a duplicate would repeat on every delivery.
 */
final class PrCorrelationCommenter
{
    /**
     * Per request to GitHub. Both handlers run inside the webhook request, so the dedupe read and
     * the POST stay inside the upstream's delivery timeout together.
     */
    public const TIMEOUT_SECONDS = 4;

    /**
     * Markers this instance posted or found, request-scoped like the handler singletons that own it:
     * a bundled DL emitting several targets for one event asks again for the same marker, and the list
     * GitHub returns right after a create is not a promise the new comment is in it.
     *
     * @var array<string, true>
     */
    private array $settled = [];

    public function __construct(private readonly GitHubTokenResolver $tokens = new GitHubTokenResolver) {}

    /**
     * @param  array<string, mixed>  $payload  the target payload, carrying the classifier's evidence
     * @param  string  $cause  a refusal reason or a classifier cause; anything {@see PrCorrelationComment::isCause()} rejects posts nothing
     * @param  array<string, mixed>  $refusalContext
     */
    public function report(array $payload, string $cause, array $refusalContext = []): void
    {
        if (! PrCorrelationComment::isCause($cause) || ! isset($payload[PrCorrelationComment::EVIDENCE_KEY])) {
            return;
        }

        try {
            $repo = $payload['repo'] ?? null;
            $writeback = WritebackConfig::loadDefault();
            $mapping = is_string($repo) ? $writeback?->mappingFor($repo) : null;
            $comment = $mapping === null ? null : PrCorrelationComment::fromPayload($payload, $cause, $mapping, $refusalContext);
            if ($comment === null) {
                return;
            }
            $this->post($comment, $writeback->configuredRepoFor($comment->repo) ?? $comment->repo, $cause);
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — an unexpected failure; the writeback outcome is unchanged', [
                'repo' => $payload['repo'] ?? null, 'cause' => $cause, 'reason' => 'unexpected', 'error' => RedactedErrorText::of($e),
            ]);
        }
    }

    private function post(PrCorrelationComment $comment, string $configuredRepo, string $cause): void
    {
        $marker = $comment->marker();
        $context = ['repo' => $comment->repo, 'pr' => $comment->prNumber, 'outcome' => $comment->outcome, 'cause' => $cause];
        $key = $comment->repo."\x00".$comment->prNumber."\x00".$marker;
        if (isset($this->settled[$key])) {
            return;
        }

        // The configured spelling, not the payload's: the credential store's map is case-sensitive
        // (the same ruling KanbanPromoteReleasedHandler applies before resolving).
        $resolution = $this->tokens->resolveFor($configuredRepo);
        if (! $resolution->ok()) {
            Log::warning('pr_correlation_comment: NOT posted — no GitHub token resolves for this repo; the writeback outcome is unchanged', $context + [
                'reason' => 'token_unresolved', 'problem' => $resolution->problem,
            ]);

            return;
        }
        $token = (string) $resolution->token;

        try {
            $present = (new GitHubReadClient($token, self::TIMEOUT_SECONDS))->hasIssueCommentStartingWith($comment->repo, $comment->prNumber, $marker);
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub refused the read of this pull request\'s comments, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', $context + [
                'reason' => 'dedupe_read_refused', 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the read of this pull request\'s comments failed, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', $context + [
                'reason' => 'dedupe_read_failed', 'error' => RedactedErrorText::of($e),
            ]);

            return;
        }
        if ($present === null) {
            Log::warning('pr_correlation_comment: NOT posted — this pull request\'s comments could not be read to the end, so an earlier copy cannot be ruled out; the writeback outcome is unchanged', $context + [
                'reason' => 'dedupe_read_incomplete',
            ]);

            return;
        }
        if ($present) {
            $this->settled[$key] = true;
            Log::info('pr_correlation_comment: already on the pull request for this outcome; not posted again', $context);

            return;
        }

        try {
            (new GitHubWriteClient($token, self::TIMEOUT_SECONDS))->createIssueComment($comment->repo, $comment->prNumber, $comment->body());
        } catch (RequestException $e) {
            Log::warning('pr_correlation_comment: NOT posted — GitHub refused the comment (a 403 is a token without Issues or Pull requests WRITE); not retried, and the writeback outcome is unchanged', $context + [
                'reason' => 'post_refused', 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('pr_correlation_comment: NOT posted — the comment could not be sent to GitHub; not retried, and the writeback outcome is unchanged', $context + [
                'reason' => 'post_failed', 'error' => RedactedErrorText::of($e),
            ]);

            return;
        }
        $this->settled[$key] = true;
        Log::info('pr_correlation_comment: posted', $context);
    }
}
