<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Support\Finding;

/**
 * Every configured provider must have a registered adapter (B-15), migrated out of
 * `CheckCommand::handle()` (DL-242 stage 6).
 *
 * THE TWO PROVIDER LISTS ARE INDEPENDENT AND DRIFT. `config('bridge.providers')` is what
 * the operator configured; `WebhookAdapterFactory::SUPPORTED` is what the receiver can
 * actually adapt. Nothing couples them, so an `api_base_url` configured for a provider
 * with no adapter is dead config the receiver answers with a 400 (`unknown_provider`) at
 * delivery time — a fault that is invisible until an upstream is already retrying.
 *
 * SILENT WHEN EVERY CONFIGURED PROVIDER IS SUPPORTED. The `is_array()` guard below is a
 * NARROWING guard, not a verdict — `config()` returns `mixed` and the walk needs an array
 * — and it is not reachable from the shipped `config/bridge.php`, where `providers` is a
 * static array literal whose leaf values alone come from `env()`. Were it reached, this
 * check would go silent rather than defer to another leg: no other leg reports the shape
 * either, since a wrong-shaped `providers` makes the endpoint-URL leg read null and skip
 * it. The guard is migrated verbatim under the byte-identical contract, and stage 8's
 * >=1-finding invariant is what would make such a silent run visible.
 *
 * @see CheckSlot::Providers
 */
final class InstallProviderAdaptersCheck implements Check
{
    public function id(): string
    {
        return 'install.provider_adapters';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $providers = config('bridge.providers');
        if (! is_array($providers)) {
            return;
        }

        foreach (array_keys($providers) as $provider) {
            if (is_string($provider) && ! WebhookAdapterFactory::supports($provider)) {
                yield Finding::fail("bridge.providers.{$provider} is configured but has no adapter (WebhookAdapterFactory::SUPPORTED = ".implode(', ', WebhookAdapterFactory::SUPPORTED).')');
            }
        }
    }
}
