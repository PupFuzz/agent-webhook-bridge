<?php

namespace App\Bridge\Exceptions;

use App\Bridge\CiAwait\CiAwaitService;
use RuntimeException;

/**
 * The workflow-run read behind `ci_settled` did not produce a complete run list (card#11200 /
 * DL-452). The message is what is recorded on the await and, at expiry, sent to the seat — so
 * {@see CiAwaitService} composes it from a status or an already-redacted error, never from a
 * response body.
 */
final class CiRunsReadException extends RuntimeException {}
