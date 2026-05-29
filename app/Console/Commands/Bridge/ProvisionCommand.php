<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Provision\KanbanProvisionClient;
use App\Bridge\Provision\ProvisionResult;
use App\Bridge\Provision\WebhookProvisioner;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ensure each declared (provider, scope) subscription exists on kanban-board,
 * pointing at the agent's receiver URL with a per-scope HMAC secret. Idempotent
 * (existing subscriptions are detected by receiver-URL match and left alone).
 *
 * Only the `kanban` provider is provisionable via API; other providers (e.g.
 * GitHub, whose webhooks are configured in repo settings) are skipped with a
 * non-zero exit. `--reconcile` fixes inactive / filter-drifted subscriptions
 * (delete + recreate reusing the secret); without it, drift is reported with a
 * non-zero exit. URL-drift orphan cleanup is manual (no local registry — the
 * live API is the source of truth).
 */
class ProvisionCommand extends Command
{
    protected $signature = 'bridge:provision {--agent= : limit to one agent} {--dry-run : preview, change nothing} {--list : show live subscriptions and exit} {--reconcile : fix inactive/filter-drifted subscriptions (delete + recreate, reusing the secret)}';

    protected $description = 'Register webhook subscriptions on kanban-board for each agent config';

    public function handle(): int
    {
        $configDir = (string) config('bridge.config_dir');
        $secretDir = (string) config('bridge.secret_dir');
        if ($configDir === '' || $secretDir === '') {
            $this->error('bridge.config_dir and bridge.secret_dir must be configured');

            return self::FAILURE;
        }

        $agents = (new SubscriptionRegistry($configDir))->agentConfigs();
        $only = $this->option('agent');
        if (is_string($only) && $only !== '') {
            $agents = array_values(array_filter($agents, fn (AgentConfig $a) => $a->agentName === $only));
        }

        $provisioner = new WebhookProvisioner($secretDir);
        $rc = self::SUCCESS;

        foreach ($agents as $agent) {
            foreach ($agent->subscriptions as $sub) {
                $label = "[{$agent->agentName}] {$sub->provider}:{$sub->scopeId}";

                if ($sub->provider !== 'kanban') {
                    $this->warn("{$label} SKIP — provider '{$sub->provider}' is not API-provisionable");
                    $rc = self::FAILURE;

                    continue;
                }

                $token = $this->readToken($agent, $sub->provider);
                if ($token === null) {
                    $this->warn("{$label} SKIP — token unreadable (api.{$sub->provider}.token_path)");
                    $rc = self::FAILURE;

                    continue;
                }

                $client = new KanbanProvisionClient($agent->api[$sub->provider]->baseUrl, $token);
                $receiverUrl = "{$agent->receiverBaseUrl}/{$sub->provider}?b={$sub->scopeId}";

                try {
                    if ($this->option('list')) {
                        $this->listScope($client, $label, $sub->scopeId);

                        continue;
                    }

                    $result = $provisioner->ensure(
                        $client, $sub->provider, $sub->scopeId, $receiverUrl,
                        $sub->eventFilter ?: null, (bool) $this->option('dry-run'), (bool) $this->option('reconcile'),
                    );
                    $this->reportResult($label, $result, $receiverUrl);
                    if (in_array($result->status, ['drift', 'cannot_reconcile'], true)) {
                        $rc = self::FAILURE;   // operator must act (re-run with --reconcile, or fix the secret)
                    }
                } catch (Throwable $e) {
                    $this->error("{$label} API error: {$e->getMessage()}");
                    $rc = self::FAILURE;
                }
            }
        }

        return $rc;
    }

    private function readToken(AgentConfig $agent, string $provider): ?string
    {
        if (! isset($agent->api[$provider])) {
            return null;
        }
        $path = $agent->api[$provider]->tokenPath;
        if (! is_file($path)) {
            return null;
        }
        $token = trim((string) file_get_contents($path));

        return $token !== '' ? $token : null;
    }

    private function reportResult(string $label, ProvisionResult $result, string $url): void
    {
        match ($result->status) {
            'exists' => $this->info("{$label} EXISTS — webhook id={$result->webhookId} → {$url}"),
            'created' => $this->info("{$label} CREATED — webhook id={$result->webhookId} → {$url}"),
            'would_create' => $this->line("{$label} DRY-RUN — would create → {$url}"),
            'reconciled' => $this->info("{$label} RECONCILED ({$result->detail}) — webhook id={$result->webhookId} → {$url}"),
            'would_reconcile' => $this->line("{$label} DRY-RUN — would reconcile ({$result->detail}) → {$url}"),
            'cannot_reconcile' => $this->error("{$label} CANNOT RECONCILE — {$result->detail}; delete the live webhook and re-run to create fresh"),
            default => $this->warn("{$label} DRIFT ({$result->detail}) — webhook id={$result->webhookId}; re-run with --reconcile to fix"),
        };
    }

    private function listScope(KanbanProvisionClient $client, string $label, string $scopeId): void
    {
        $subs = $client->listWebhooks($scopeId);
        if ($subs === []) {
            $this->line("{$label} (no subscriptions)");

            return;
        }
        foreach ($subs as $sub) {
            $active = ($sub['active'] ?? false) ? 'active' : 'INACTIVE';
            $this->line("{$label} id={$sub['id']} {$active} → ".($sub['url'] ?? '?'));
        }
    }
}
