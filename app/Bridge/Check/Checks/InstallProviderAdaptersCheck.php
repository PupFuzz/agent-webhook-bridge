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
 * SILENT WHEN EVERY CONFIGURED PROVIDER IS SUPPORTED, and silent when the config is not a
 * list at all: a `providers` key of the wrong shape is a config-schema fault that reaches
 * the operator through the legs that read the individual provider values, and inventing a
 * second verdict for it here would report one fault twice.
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
