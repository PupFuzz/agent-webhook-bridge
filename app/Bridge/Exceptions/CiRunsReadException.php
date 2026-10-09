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
 * The CLASS of the failure (card#11600 / DL-468):
 *  - `$repoUnreadable`: final at once — GitHub answered 404, or no read token resolves for any
 *    reader. The caller tells the seat now instead of retrying until the await expires.
 *  - `$needsConfirmation`: GitHub answered 401, or a 403 that is not a rate limit by its headers or
 *    body. Final only when a read at least 60 s later answers the same status: one such answer can
 *    be a secondary rate limit that names no reset, or a token being rotated.
 *  - neither: retryable (a rate limit, any other status, no answer, an unreadable body, a token this
 *    process could not read).
 * `$status` is GitHub's HTTP status, null when no request was answered.
 */
final class CiRunsReadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?DateTimeInterface $retryNotBefore = null,
        public readonly bool $repoUnreadable = false,
        public readonly ?int $status = null,
        public readonly bool $needsConfirmation = false,
    ) {
        parent::__construct($message);
    }
}
