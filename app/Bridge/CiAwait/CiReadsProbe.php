<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Exceptions\CiRunsReadException;

/**
 * What {@see CiAwaitService::probeReads()} found: the runs read failed, or it answered and listed no
 * run (so no jobs read was made), or the jobs read of the run it listed failed, or both answered.
 * At most one of the two failures is set, and `$runId` is set wherever the jobs read was made.
 */
final class CiReadsProbe
{
    private function __construct(
        public readonly ?CiRunsReadException $runsFailure,
        public readonly ?CiRunsReadException $jobsFailure,
        public readonly ?int $runId,
    ) {}

    public static function runsFailed(CiRunsReadException $e): self
    {
        return new self($e, null, null);
    }

    public static function noRun(): self
    {
        return new self(null, null, null);
    }

    public static function jobsFailed(int $runId, CiRunsReadException $e): self
    {
        return new self(null, $e, $runId);
    }

    public static function read(int $runId): self
    {
        return new self(null, null, $runId);
    }
}
