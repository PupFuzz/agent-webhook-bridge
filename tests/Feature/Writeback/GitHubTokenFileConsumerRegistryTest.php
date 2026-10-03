<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Check\Checks\GitHubTokenFileCheck;
use App\Bridge\Writeback\GitHubTokenFileConsumer;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Is every class that resolves a GitHub token from the RUNTIME declared to
 * `GitHubTokenFileCheck::CONSUMERS`? (card#11201)
 *
 * ⭐ WHY. `bridge:check` can warn about a missing token file only for the legs it knows about,
 * and the incident behind the card was exactly a leg it did not know about: the only probe of the
 * file was scoped to one consumer, and two others dropped their writes for weeks. So the
 * population is derived from the TREE — every `->resolveFromFile(` and `->resolveFor(` call under
 * `app/`, over comment-stripped tokens ({@see SourceScan}) — and each caller must be either
 * registered or ruled CLI-only here, with the reason.
 *
 * ⛔ BOTH METHODS ARE THE POPULATION, not `resolveFromFile()` alone. The promote-on-release leg
 * calls `resolveFor()` and is a file consumer all the same: under PHP-FPM the store helper and
 * `GH_TOKEN` resolve nothing (DL-184), so the file is all it ever gets. A census of
 * `resolveFromFile()` callers alone would have missed it; this one makes a new `resolveFor()`
 * caller choose a side.
 *
 * WHAT IT DOES NOT DO: it cannot tell whether a class ruled CLI-only below has since been wired
 * into the runtime — the ruling is prose, and a reviewer reads it. It is lexical: a call through a
 * variable method name would be counted nowhere (none exists in `app/` today).
 */
class GitHubTokenFileConsumerRegistryTest extends TestCase
{
    private const RESOLVER_FILE = 'Bridge/Writeback/GitHubTokenResolver.php';

    /**
     * Classes that call `resolveFor()` and are NOT token-file consumers, each with the reason.
     *
     * @var array<class-string, string>
     */
    private const CLI_ONLY = [
        // Constructed only by `ReconcileRepoTokensCheck` (bridge:check) and `ReconcileCommand`
        // (bridge:reconcile) — both artisan, where the store and GH_TOKEN legs are meant to resolve.
        'App\Bridge\Writeback\GitHubRepoProbe' => 'bridge:reconcile / bridge:check only',
        // Constructed only by `GitHubWebhookSubscriptionCheck` (bridge:check).
        'App\Bridge\Provision\GitHubWebhookProbe' => 'bridge:check only',
        'App\Console\Commands\Bridge\ClientPackInstallCommand' => 'an artisan command',
        'App\Console\Commands\Bridge\WritebackExposureCommand' => 'an artisan command',
    ];

    /**
     * Classes that call `resolveFor()` from BOTH the receiver and the CLI, so the file is not the
     * only way they resolve a token and a missing file does not make them inert: registering one
     * would have `github.token_file` FAIL, calling it INERT, on an install where the CLI reaches
     * GitHub through the credential store or `GH_TOKEN`. Each names the leg that reports it.
     *
     * @var array<class-string, string>
     */
    private const RECEIVER_AND_CLI = [
        // The receiver reads on a `workflow_run` delivery; the `ci-await-sweep` job (bridge:tick, a
        // CLI) and a registration (the ssh tool door, a CLI) read from where the store and GH_TOKEN
        // resolve. A head whose reads fail is kept and read again, and `ci_await.awaits` (bridge:check)
        // warns with the read's own error — "no GitHub read token: …" — when none answers.
        'App\Bridge\CiAwait\CiAwaitService' => 'receiver delivery, bridge:tick sweep and ssh registration; reported by ci_await.awaits',
    ];

    /** The check reads the file to ask about the consumers; it is not one. */
    private const THE_CHECK_ITSELF = GitHubTokenFileCheck::class;

    public function test_every_runtime_caller_of_the_resolver_is_a_registered_consumer(): void
    {
        $callers = self::callers();
        $this->assertNotEmpty($callers['resolveFromFile'], 'the scan found no resolveFromFile() caller at all — the scan is broken, not the code');
        $this->assertNotEmpty($callers['resolveFor'], 'the scan found no resolveFor() caller at all — the scan is broken, not the code');

        $registered = GitHubTokenFileCheck::CONSUMERS;
        $unregistered = [];
        foreach ($callers['resolveFromFile'] as $class) {
            if (! in_array($class, $registered, true) && $class !== self::THE_CHECK_ITSELF) {
                $unregistered[] = "{$class} calls resolveFromFile()";
            }
        }
        foreach ($callers['resolveFor'] as $class) {
            if (! in_array($class, $registered, true) && ! array_key_exists($class, self::CLI_ONLY) && ! array_key_exists($class, self::RECEIVER_AND_CLI)) {
                $unregistered[] = "{$class} calls resolveFor()";
            }
        }

        $this->assertSame([], $unregistered,
            "a class resolves a GitHub token and is not declared.\n"
            .'If it runs in the RECEIVER, it is a token-FILE consumer: implement GitHubTokenFileConsumer and add it to '
            ."GitHubTokenFileCheck::CONSUMERS, or bridge:check will say nothing while its writes are dropped.\n"
            .'If it runs only from artisan, rule it CLI-only in this test, with the reason. If it runs in the receiver AND '
            .'from the CLI, so the file is not its only way to a token, rule it RECEIVER_AND_CLI and name the check that reports it.');
    }

    public function test_every_registered_consumer_still_resolves_a_token_and_declares_itself(): void
    {
        $callers = self::callers();
        $resolving = array_merge($callers['resolveFromFile'], $callers['resolveFor']);

        foreach (GitHubTokenFileCheck::CONSUMERS as $consumer) {
            $this->assertContains($consumer, $resolving, "{$consumer} is registered but no longer calls the resolver — a stale entry names a leg that cannot be inert");
            $this->assertTrue(is_subclass_of($consumer, GitHubTokenFileConsumer::class), "{$consumer} is registered but does not implement GitHubTokenFileConsumer");
        }
        foreach (array_keys(self::CLI_ONLY) as $class) {
            $this->assertContains($class, $callers['resolveFor'], "{$class} is ruled CLI-only but no longer calls resolveFor() — drop the ruling");
        }
        foreach (array_keys(self::RECEIVER_AND_CLI) as $class) {
            $this->assertContains($class, $callers['resolveFor'], "{$class} is ruled RECEIVER_AND_CLI but no longer calls resolveFor() — drop the ruling");
        }
    }

    public function test_the_check_counts_drops_under_the_one_reason_both_writers_record(): void
    {
        // The check filters the owed-writes record on ONE reason string. Both writers must
        // record a missing token under it, or the count silently halves.
        $this->assertSame(PrCorrelationCommenter::REASON_TOKEN_UNRESOLVED, ProtocolInvalidLabeler::REASON_TOKEN_UNRESOLVED);
    }

    /**
     * The classes under `app/` that call each resolving method, by `->name(` call site.
     *
     * @return array{resolveFromFile: list<string>, resolveFor: list<string>}
     */
    private static function callers(): array
    {
        $out = ['resolveFromFile' => [], 'resolveFor' => []];
        foreach (SourceScan::appFiles() as $path) {
            $relative = SourceScan::relativeToApp($path);
            if ($relative === self::RESOLVER_FILE) {
                continue;
            }
            $sites = SourceScan::sites((string) file_get_contents($path), $relative,
                fn (array $tokens, int $i): ?string => SourceScan::methodCallAt($tokens, $i, ['resolveFromFile', 'resolveFor']));
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            foreach (array_unique($sites) as $method) {
                $out[$method][] = $class;
            }
        }

        return $out;
    }
}
