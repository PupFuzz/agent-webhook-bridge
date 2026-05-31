<?php

namespace App\Bridge\Provision;

use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;
use Throwable;

/**
 * Idempotently ensures a single (provider, scope) webhook subscription exists
 * on the upstream, pointing at this bridge's receiver URL. The upstream's live
 * webhook list is the source of truth — a subscription at our receiver URL is
 * "ours", so no local registry is needed (re-runs are safe by URL match).
 *
 * Secret ordering is load-bearing: the per-scope HMAC secret is written BEFORE
 * the subscription is created, so a create-succeeds-but-secret-write-fails
 * window can't leave the upstream delivering events the receiver can't verify.
 * If the create then fails, the orphaned secret is removed so a re-run starts
 * clean.
 */
final class WebhookProvisioner
{
    public function __construct(private string $secretDir) {}

    /**
     * @param  ?list<string>  $eventFilter
     */
    public function ensure(
        KanbanProvisionClient $client,
        string $provider,
        string $scopeId,
        string $receiverUrl,
        ?array $eventFilter,
        bool $dryRun,
        bool $reconcile = false,
    ): ProvisionResult {
        $match = null;
        foreach ($client->listWebhooks($scopeId) as $live) {
            if (($live['url'] ?? null) === $receiverUrl) {
                $match = $live;
                break;
            }
        }

        if ($match !== null) {
            return $this->ensureMatchedSubscription(
                $client, $provider, $scopeId, $receiverUrl, $eventFilter, $dryRun, $reconcile, $match,
            );
        }

        // Missing → create-if-missing.
        if ($dryRun) {
            return ProvisionResult::wouldCreate();
        }

        return ProvisionResult::created(
            (string) ($this->createWithSecret($client, $provider, $scopeId, $receiverUrl, $eventFilter)['id'] ?? '?'),
        );
    }

    /**
     * A live webhook already points at our URL. If it's active with a matching
     * filter, it's a no-op. Otherwise it has DRIFTED (inactive, or the
     * event_filter no longer matches config) — reported as `drift` unless
     * --reconcile, which fixes it by delete + recreate REUSING the on-disk
     * secret (no rotation window: the receiver keeps verifying the same key).
     *
     * @param  ?list<string>  $eventFilter
     * @param  array<string, mixed>  $match
     */
    private function ensureMatchedSubscription(
        KanbanProvisionClient $client,
        string $provider,
        string $scopeId,
        string $receiverUrl,
        ?array $eventFilter,
        bool $dryRun,
        bool $reconcile,
        array $match,
    ): ProvisionResult {
        $id = (string) ($match['id'] ?? '?');
        $active = (bool) ($match['active'] ?? true);
        $filterOk = $this->filtersMatch($match['event_filter'] ?? null, $eventFilter);

        if ($active && $filterOk) {
            return ProvisionResult::exists($id);
        }

        $kind = ! $active ? 'inactive' : 'filter_drifted';

        if (! $reconcile) {
            return ProvisionResult::drift($kind, $id);
        }
        if ($dryRun) {
            return ProvisionResult::wouldReconcile($kind);
        }

        // Reuse the existing secret so there's no rotation window. If it's
        // gone, refuse rather than silently rotate to a fresh key.
        $secretPath = SecretPath::for($this->secretDir, $provider, $scopeId);
        // Don't reuse + re-push a group/world-readable secret upstream (DL-010):
        // the receiver fail-closes on it anyway, so reconciling with it would
        // recreate a subscription the receiver then 500s. Refuse; operator
        // chmods + re-runs.
        if (SecretFile::isInsecure($secretPath)) {
            return ProvisionResult::cannotReconcile($kind, $secretPath.' (group/world-readable — chmod 600)');
        }
        $secret = is_file($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
        if ($secret === '') {
            return ProvisionResult::cannotReconcile($kind, $secretPath);
        }

        $client->deleteWebhook($match['id'] ?? $id);
        $created = $client->createWebhook($scopeId, $receiverUrl, $secret, $eventFilter);

        return ProvisionResult::reconciled($kind, (string) ($created['id'] ?? '?'));
    }

    /**
     * Generate a secret, write it BEFORE creating the subscription, and clean
     * it up if the create fails.
     *
     * @param  ?list<string>  $eventFilter
     * @return array<string, mixed>
     */
    private function createWithSecret(
        KanbanProvisionClient $client,
        string $provider,
        string $scopeId,
        string $receiverUrl,
        ?array $eventFilter,
    ): array {
        $secretPath = SecretPath::for($this->secretDir, $provider, $scopeId);
        $secret = bin2hex(random_bytes(32));
        $this->writeSecret($secretPath, $secret);

        try {
            return $client->createWebhook($scopeId, $receiverUrl, $secret, $eventFilter);
        } catch (Throwable $e) {
            @unlink($secretPath);   // create failed → don't leave a secret the upstream never saw
            throw $e;
        }
    }

    /**
     * Compare a live event_filter (null/list) to the configured one. null or
     * empty on either side means "all events"; otherwise compare as a set.
     *
     * @param  mixed  $live
     * @param  ?list<string>  $expected
     */
    private function filtersMatch($live, ?array $expected): bool
    {
        $liveSet = is_array($live) ? array_map(strval(...), array_values($live)) : [];
        $expectedSet = $expected ?? [];
        sort($liveSet);
        sort($expectedSet);

        return $liveSet === $expectedSet;
    }

    private function writeSecret(string $path, string $secret): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, $secret, LOCK_EX);
        chmod($path, 0600);
    }
}
