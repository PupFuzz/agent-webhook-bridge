<?php

namespace App\Bridge\CiAwait;

use Illuminate\Contracts\Foundation\Application;

/**
 * Where an inbound `workflow_run.completed` reaches the `ci_settled` detector (card#11200 /
 * DL-452). Install-wide rather than per agent: the seat awaiting a head need not be one of the
 * agents subscribed to its repo, so this runs once per delivery, beside the dispatch loop.
 *
 * ⭐ AFTER THE RESPONSE. The detector may make one paginated GitHub read, and GitHub never
 * redelivers a webhook (DL-183), so nothing it does is worth delaying or failing the
 * delivery's answer — {@see CiAwaitService::onWorkflowRunCompleted()} never throws.
 */
final class CiAwaitGate
{
    private const FULL_SHA = '/\A[0-9a-f]{40}\z/';

    public function __construct(
        private readonly Application $app,
        private readonly CiAwaitService $awaits,
    ) {}

    /** @param  array<mixed>  $payload */
    public function schedule(string $provider, string $eventType, string $scopeId, array $payload): void
    {
        if ($provider !== 'github' || $eventType !== 'workflow_run.completed') {
            return;
        }
        $run = $payload['workflow_run'] ?? null;
        $headSha = is_array($run) ? ($run['head_sha'] ?? null) : null;
        if (! is_string($headSha) || preg_match(self::FULL_SHA, $headSha) !== 1) {
            return;
        }

        $this->app->terminating(fn () => $this->awaits->onWorkflowRunCompleted($scopeId, $headSha));
    }
}
