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
 *  - cannot_reconcile drift, but the on-disk secret is unusable so a
 *                    no-rotation-window fix is impossible (operator intervenes) —
 *                    missing, group/world-readable, or present-but-unreadable by this
 *                    process; `detail` names WHICH
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

    /**
     * $reason is the CALLER'S, rendered verbatim. It used to be a path the template wrapped
     * in "secret missing at …", which was true for exactly one of the callers: the
     * insecure-perms refusal was already passing a full message, so a present 0644 secret
     * was being reported as a missing one, and card#5789 added a third cause (present but
     * unreadable by this process). A template that asserts absence for all three is a
     * wrong-but-specific cause — it sends the operator to re-provision a secret that is
     * sitting right there.
     */
    public static function cannotReconcile(string $kind, string $reason): self
    {
        return new self('cannot_reconcile', '', "{$kind} ({$reason})");
    }
}
