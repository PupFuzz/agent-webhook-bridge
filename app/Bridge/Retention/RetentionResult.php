<?php

namespace App\Bridge\Retention;

/**
 * What one {@see RetentionService::prune()} pass did (or, under `$dry`, would have
 * done). A leg that did not run is null — distinct from `0`, which means the leg ran
 * and matched nothing. Callers format their own output: the service is silent so it
 * can run from a webhook, where there is no console to print to (DL-199).
 */
final class RetentionResult
{
    public function __construct(
        public readonly ?int $eventsDeleted = null,
        public readonly ?int $inboxLinesRemoved = null,
        public readonly ?int $inboxFilesTrimmed = null,
        public readonly ?int $payloadsNulled = null,
    ) {}
}
