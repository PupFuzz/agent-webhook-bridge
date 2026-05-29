<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Exceptions\ConfigException;

/**
 * Resolves an agent's classifier FQCN (config `classifier.class`) to a shared
 * instance. Instances are cached per class for the process lifetime (FPM
 * workers reuse them across requests), so per-event mutable state must live in
 * classify() locals, not instance fields.
 */
final class ClassifierResolver
{
    /**
     * @var array<string, Classifier>
     */
    private static array $cache = [];

    public static function for(AgentConfig $config): Classifier
    {
        $class = $config->classifierClass;

        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        if (! class_exists($class)) {
            throw new ConfigException("classifier class {$class} not found (config classifier.class for agent {$config->agentName})");
        }

        $instance = new $class;
        if (! $instance instanceof Classifier) {
            throw new ConfigException("classifier {$class} must implement ".Classifier::class);
        }

        return self::$cache[$class] = $instance;
    }

    /**
     * Clear the per-process instance cache (test isolation).
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
