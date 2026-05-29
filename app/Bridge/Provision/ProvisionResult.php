<?php

namespace App\Bridge\Provision;

/**
 * Outcome of ensuring one subscription. status is one of:
 *  - exists          a live webhook already points at our receiver URL, active,
 *                    with a matching filter (no-op)
 *  - created         a new webhook was registered
 *  - would_create    dry-run: a webhook is missing and would be created
 *  - drift           a live webhook at our URL is inactive or filter-drifted,
 *                    and --reconcile was NOT passed (reported, not fixed)
 *  - reconciled      --reconcile fixed the drift (delete + recreate, secret reused)
 *  - would_reconcile dry-run + --reconcile: the drift that would be fixed
 *  - cannot_reconcile drift, but the on-disk secret is missing so a
 *                    no-rotation-window fix is impossible (operator intervenes)
 *
 * `detail` carries the drift kind (inactive / filter_drifted) for the drift /
 * reconcile statuses.
 */
final class ProvisionResult
{
    private function __construct(
        public readonly string $status,
        public readonly string $webhookId,
        public readonly string $detail,
    ) {}

    public static function exists(string $webhookId): self
    {
        return new self('exists', $webhookId, '');
    }

    public static function created(string $webhookId): self
    {
        return new self('created', $webhookId, '');
    }

    public static function wouldCreate(): self
    {
        return new self('would_create', '', '');
    }

    public static function drift(string $kind, string $webhookId): self
    {
        return new self('drift', $webhookId, $kind);
    }

    public static function reconciled(string $kind, string $webhookId): self
    {
        return new self('reconciled', $webhookId, $kind);
    }

    public static function wouldReconcile(string $kind): self
    {
        return new self('would_reconcile', '', $kind);
    }

    public static function cannotReconcile(string $kind, string $secretPath): self
    {
        return new self('cannot_reconcile', '', "{$kind} (secret missing at {$secretPath})");
    }
}
