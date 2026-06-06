<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Exceptions\ConfigException;
use Symfony\Component\Process\Process;

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

        try {
            $instance = new $class;
        } catch (\Error $e) {
            // SF-1 (#2053): `new $class` ArgumentCountErrors for a constructor that
            // requires args — a classifier must be newable with none. Wrap the
            // opaque Error in a ConfigException so it's catchable (treatment-A at
            // dispatch / a clean bridge:check line), not an uncaught 500.
            throw new ConfigException("classifier {$class} is not newable with no constructor arguments (agent {$config->agentName}): ".$e->getMessage());
        }
        if (! $instance instanceof Classifier) {
            throw new ConfigException("classifier {$class} must implement ".Classifier::class);
        }

        return self::$cache[$class] = $instance;
    }

    /**
     * Validate that a classifier class can be loaded + is a Classifier WITHOUT
     * loading it in THIS process — an out-of-date classify() signature is an
     * UNCATCHABLE E_COMPILE_ERROR ("Declaration must be compatible") that would
     * kill the caller (#2053). A child php process loads it in isolation; its
     * exit code tells us whether it's safe to load here. Returns null when OK,
     * else an operator-readable reason. Intended for bridge:check (CLI, where
     * PHP_BINARY is the php cli) as the pre-deploy gate — once it passes, for()
     * can load the class in-process without risk.
     */
    public static function probeLoadable(string $class): ?string
    {
        $script = <<<'PHP'
            require $argv[1];
            $c = $argv[2];
            if (!class_exists($c)) { fwrite(STDERR, 'class not found'); exit(2); }
            if (!in_array('App\\Bridge\\Contracts\\Classifier', class_implements($c) ?: [], true)) { fwrite(STDERR, 'does not implement Classifier'); exit(3); }
            try { new $c; } catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(4); }
            exit(0);
            PHP;

        $proc = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $class]);
        $proc->run();
        $code = $proc->getExitCode() ?? 1;
        if ($code === 0) {
            return null;
        }
        $stderr = trim($proc->getErrorOutput());

        return match ($code) {
            2 => "classifier class {$class} not found",
            3 => "classifier {$class} must implement ".Classifier::class,
            4 => "classifier {$class} is not newable with no constructor arguments: {$stderr}",
            // A compile fatal (incompatible signature) crashes the child with a
            // non-zero/255 exit — the headline #2053 case.
            default => "classifier {$class} failed to load — most likely an out-of-date classify() signature (it now takes a single ClassifyContext, DL-025), incompatible with ".Classifier::class.($stderr !== '' ? ": {$stderr}" : ''),
        };
    }

    /**
     * Clear the per-process instance cache (test isolation).
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
