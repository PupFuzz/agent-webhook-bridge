<?php

namespace App\Bridge\Scheduling;

use RuntimeException;

/**
 * A refused insert (card#8425 / DL-325). Thrown by {@see JobSpec} for a malformed instance
 * and by {@see JobRegistry::insert()} for one naming a handler that may not be invoked.
 *
 * ⭐ IT THROWS RATHER THAN RETURNING FALSE because the caller is a program, not a person: a
 * boolean return is silently ignorable, and an ignored refusal here means an install that
 * believes it scheduled a job and did not — which is the exact failure this whole subsystem
 * exists to make impossible.
 */
final class JobSpecException extends RuntimeException {}
