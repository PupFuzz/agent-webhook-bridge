<?php

namespace App\Bridge\Exceptions;

use App\Bridge\CiAwait\CiAwaitService;
use DateTimeInterface;
use RuntimeException;

/**
 * The workflow-run read behind `ci_settled` did not produce a complete run list (card#11200 /
 * DL-452). The message is what is recorded on the await and sent to the seat — so
 * {@see CiAwaitService} composes it from a status, a token SOURCE and file path, or an
 * already-redacted error, never from a response body or a token.
 *
 * `$retryNotBefore` is when a RATE-LIMITED read said its quota returns, so the sweep's retry waits
 * for it; null for every other failure.
 *
 * `$repoUnreadable` is the CLASS of the failure (card#11600): true when reading again cannot
 * answer — no GitHub read token resolved, or GitHub answered 401, 404, or a 403 that is not a rate
 * limit — so the caller tells the seat now instead of retrying until the await expires. False for
 * every failure a later read may get past (a rate limit, a 5xx, no answer, an unreadable body).
 * `$status` is GitHub's HTTP status, null when no request was answered.
 */
final class CiRunsReadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?DateTimeInterface $retryNotBefore = null,
        public readonly bool $repoUnreadable = false,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
