<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallProviderAdaptersCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The provider/adapter coverage leg (B-15, DL-242 stage 6).
 *
 * THE FAILING CASE IS ALREADY COVERED at the command level and by one golden fixture, so
 * this file deliberately does not re-prove it — it covers the three states those cannot
 * see, each of which is a predicate that would otherwise be justified by reading alone:
 * the all-supported case (the silence that makes the failure meaningful), a `providers`
 * key of the wrong SHAPE, and a numerically-keyed entry.
 *
 * THE NUMERIC KEY IS NOT A HYPOTHETICAL. `bridge.providers` is a map in the shipped
 * config, but an operator writing it as a YAML/env list produces integer keys, and PHP
 * would coerce one into `supports()`'s string parameter rather than reject it — so
 * dropping the `is_string()` guard turns a shape mistake into a confident report that
 * provider `0` has no adapter.
 */
class InstallProviderAdaptersCheckTest extends TestCase
{
    public function test_every_configured_provider_having_an_adapter_yields_nothing(): void
    {
        config(['bridge.providers' => ['kanban' => ['api_base_url' => 'https://kanban.example.com'], 'github' => []]]);

        $this->assertSame([], $this->findings());
    }

    public function test_a_configured_provider_without_an_adapter_is_named_with_the_supported_list(): void
    {
        config(['bridge.providers' => ['kanban' => [], 'gitlab' => ['api_base_url' => 'https://gitlab.example.com/api/v4']]]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            'bridge.providers.gitlab is configured but has no adapter (WebhookAdapterFactory::SUPPORTED = kanban, github)',
            $findings[0]->message,
        );
    }

    public function test_a_providers_value_that_is_not_an_array_yields_nothing(): void
    {
        config(['bridge.providers' => 'kanban']);

        $this->assertSame([], $this->findings());
    }

    public function test_a_numerically_keyed_providers_entry_is_not_reported_as_an_unsupported_provider(): void
    {
        config(['bridge.providers' => [['api_base_url' => 'https://kanban.example.com']]]);

        $this->assertSame([], $this->findings());
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return iterator_to_array((new InstallProviderAdaptersCheck)->run(new CheckContext), false);
    }
}
