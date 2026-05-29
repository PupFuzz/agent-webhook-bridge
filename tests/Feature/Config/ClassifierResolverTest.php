<?php

namespace Tests\Feature\Config;

use App\Bridge\Classifiers\EventDrivenClassifier;
use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierResolver;
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

    /**
     * @param  array<mixed>  $classifier
     */
    private function config(array $classifier): AgentConfig
    {
        return AgentConfig::fromArray('a', [
            'identity' => ['self' => 'a'],
            'api' => ['kanban' => ['base_url' => 'https://k.example.com', 'token_path' => '/t']],
            'receiver' => ['base_url' => 'https://b.example.com/webhooks'],
            'subscriptions' => [],
            'classifier' => $classifier,
        ]);
    }

    public function test_resolves_default_inbox_only(): void
    {
        $classifier = ClassifierResolver::for($this->config([]));
        $this->assertInstanceOf(InboxOnlyClassifier::class, $classifier);
    }

    public function test_resolves_custom_class(): void
    {
        $classifier = ClassifierResolver::for($this->config(['class' => EventDrivenClassifier::class]));
        $this->assertInstanceOf(EventDrivenClassifier::class, $classifier);
    }

    public function test_unknown_class_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierResolver::for($this->config(['class' => 'App\\Nope\\DoesNotExist']));
    }

    public function test_non_classifier_class_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierResolver::for($this->config(['class' => \stdClass::class]));
    }

    public function test_instances_are_cached_per_class(): void
    {
        $a = ClassifierResolver::for($this->config([]));
        $b = ClassifierResolver::for($this->config([]));
        $this->assertSame($a, $b);
    }
}
