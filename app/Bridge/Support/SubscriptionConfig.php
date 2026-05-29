<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Validation\ProviderName;
use App\Bridge\Validation\ScopeId;

/**
 * One (provider, scope_id, event_filter) subscription. A single YAML entry may
 * declare multiple scopes; expand() validates and fans them out. Validating
 * provider + scope here (same regexes as the receiver) is defense-in-depth:
 * a typo/malicious value in the YAML is rejected at load instead of writing a
 * secret to an unexpected path.
 */
final class SubscriptionConfig
{
    /**
     * @param  list<string>  $eventFilter  globs; empty = all events
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $scopeId,
        public readonly array $eventFilter,
    ) {}

    /**
     * @param  array<mixed>  $data
     * @return list<SubscriptionConfig>
     */
    public static function expand(array $data): array
    {
        if (! isset($data['provider']) || ! is_scalar($data['provider'])) {
            throw new ConfigException("subscription entry missing 'provider'");
        }
        $provider = (string) $data['provider'];
        if (! ProviderName::matches($provider)) {
            throw new ConfigException("subscription provider '{$provider}' is invalid");
        }

        $scopes = $data['scopes'] ?? null;
        if (! is_array($scopes) || $scopes === []) {
            throw new ConfigException("subscription entry missing non-empty 'scopes'");
        }

        $eventFilterRaw = $data['event_filter'] ?? [];
        if (! is_array($eventFilterRaw)) {
            throw new ConfigException('subscription.event_filter must be a list');
        }
        $eventFilter = array_values(array_map(
            fn (mixed $e): string => is_scalar($e) ? (string) $e : '',
            $eventFilterRaw,
        ));

        $out = [];
        foreach (array_values($scopes) as $idx => $scope) {
            $scopeStr = is_scalar($scope) ? (string) $scope : '';
            if (! ScopeId::matches($scopeStr)) {
                throw new ConfigException("subscriptions[…].scopes[{$idx}] '{$scopeStr}' is invalid");
            }
            $out[] = new self($provider, $scopeStr, $eventFilter);
        }

        return $out;
    }
}
