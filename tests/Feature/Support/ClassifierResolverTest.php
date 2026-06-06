<?php

namespace Tests\Feature\Support;

use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierResolver;
use Tests\Fixtures\CtorRequiringClassifier;
use Tests\TestCase;

class ClassifierResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        parent::tearDown();
    }

    public function test_probe_passes_a_valid_classifier(): void
    {
        $this->assertNull(ClassifierResolver::probeLoadable(InboxOnlyClassifier::class));
    }

    public function test_probe_reports_a_missing_class(): void
    {
        $this->assertStringContainsString('not found', (string) ClassifierResolver::probeLoadable('App\\Nope\\DoesNotExist'));
    }

    public function test_probe_reports_a_non_classifier(): void
    {
        $this->assertStringContainsString('must implement', (string) ClassifierResolver::probeLoadable(\stdClass::class));
    }

    public function test_probe_reports_an_out_of_date_classify_signature(): void
    {
        // The headline #2053 case: a classifier on the pre-DL-025 positional
        // signature is an UNCATCHABLE E_COMPILE_ERROR on declaration. Reference
        // it ONLY by string so this (parent) process never autoloads it — the
        // probe loads it out-of-process and must map the child's compile-fatal
        // to the "out-of-date signature" reason.
        $reason = (string) ClassifierResolver::probeLoadable('Tests\\Fixtures\\StaleSignatureClassifier');

        $this->assertStringContainsString('out-of-date classify() signature', $reason);
    }

    public function test_for_wraps_a_constructor_requiring_classifier_in_a_config_exception(): void
    {
        // SF-1 (#2053): a constructor that needs args ArgumentCountErrors on
        // `new $class` — it must surface as a catchable ConfigException, not an
        // uncaught 500.
        $cfg = AgentConfig::fromArray('a', [
            'classifier' => ['class' => CtorRequiringClassifier::class],
            'subscriptions' => [],
        ]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/not newable/');

        ClassifierResolver::for($cfg);
    }
}
