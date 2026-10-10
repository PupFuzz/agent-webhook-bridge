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
 * ⛔ BOTH METHODS ARE THE POPULATION. Since DL-456 every runtime leg calls `resolveFor()` (the
 * repo's override, the coord credential store, the single file) and `resolveFromFile()` is the
 * single file alone; a new caller of either must choose a side.
 *
 * ⛔ A RUNTIME LEG NEVER ASKS FOR `GH_TOKEN`. `resolveForCli()` is the CLI form; a
 * registered consumer that called it would resolve a token in a shell `bridge:replay` that the
 * receiver never has, and post as an identity the receiver never uses. Checked lexically below.
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
     * Runtime classes that call `resolveFor()` but cannot be registered consumers, because the repos
     * they reach GitHub for are not in `writeback.json`: they are registered at runtime, so
     * `GitHubTokenFileConsumer::fileTokenRepos()` — which answers from `writeback.json` alone — has
     * nothing to list, and `github.token_file` could neither name them nor resolve a token for them.
     * They resolve exactly as a registered consumer does (no `GH_TOKEN`, in the receiver or the
     * CLI), so each names the check that reports a missing token for those repos instead.
     *
     * @var array<class-string, string>
     */
    private const RECEIVER_AND_CLI = [
        // Its repos are the ones seats register heads for with `ci_await`, stored in the database,
        // never in writeback.json. It reads in the receiver (a `workflow_run` delivery, an HTTP-door
        // registration) and from the CLI (the bridge:tick sweep, an ssh-door registration), the same
        // way in both. A head whose read can be retried is kept and read again, and `ci_await.awaits`
        // (bridge:check) warns with the read's own error when none answers; a repo no reader has a
        // token for, or whose token GitHub refuses, ends the await at once (card#11600), and
        // `ci_await.awaits` reads every received repo once and FAILs naming it — "no GitHub read token: …".
        'App\Bridge\CiAwait\CiAwaitService' => 'receiver delivery and HTTP-door registration (both inside the receiver), bridge:tick sweep and ssh-door registration (CLI); reported by ci_await.awaits',
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
            if (! in_array($class, $registered, true) && ! array_key_exists($class, self::CLI_ONLY) && ! array_key_exists($class, self::RECEIVER_AND_CLI) && $class !== self::THE_CHECK_ITSELF) {
                $unregistered[] = "{$class} calls resolveFor()";
            }
        }
        foreach ($callers['resolveForCli'] as $class) {
            if (! array_key_exists($class, self::CLI_ONLY)) {
                $unregistered[] = "{$class} calls resolveForCli() and is not ruled CLI-only";
            }
        }

        $this->assertSame([], $unregistered,
            "a class resolves a GitHub token and is not declared.\n"
            .'If it runs in the RECEIVER, it is a token-FILE consumer: implement GitHubTokenFileConsumer and add it to '
            ."GitHubTokenFileCheck::CONSUMERS, or bridge:check will say nothing while its writes are dropped.\n"
            .'If it runs only from artisan, rule it CLI-only in this test, with the reason. If it runs in the receiver for repos '
            .'writeback.json does not list (registered at runtime), rule it RECEIVER_AND_CLI and name the check that reports it.');
    }

    public function test_every_registered_consumer_still_resolves_a_token_and_declares_itself(): void
    {
        $callers = self::callers();
        $resolving = array_merge($callers['resolveFromFile'], $callers['resolveFor'], $callers['resolveForCli']);

        foreach (GitHubTokenFileCheck::CONSUMERS as $consumer) {
            $this->assertContains($consumer, $resolving, "{$consumer} is registered but no longer calls the resolver — a stale entry names a leg that cannot be inert");
            $this->assertTrue(is_subclass_of($consumer, GitHubTokenFileConsumer::class), "{$consumer} is registered but does not implement GitHubTokenFileConsumer");
        }
        foreach (array_keys(self::CLI_ONLY) as $class) {
            $this->assertContains($class, array_merge($callers['resolveFor'], $callers['resolveForCli']), "{$class} is ruled CLI-only but no longer calls the resolver — drop the ruling");
        }
        foreach (array_keys(self::RECEIVER_AND_CLI) as $class) {
            $this->assertContains($class, $callers['resolveFor'], "{$class} is ruled RECEIVER_AND_CLI but no longer calls resolveFor() — drop the ruling");
        }
    }

    public function test_no_registered_consumer_asks_for_the_ambient_gh_token(): void
    {
        foreach (GitHubTokenFileCheck::CONSUMERS as $consumer) {
            $file = app_path(str_replace('\\', '/', substr($consumer, strlen('App\\'))).'.php');
            $words = array_column(SourceScan::significantTokens((string) file_get_contents($file)), 1);
            $this->assertContains('resolveFor', $words, "the scan read {$file} and found no resolveFor call — the scan is broken, not the code");
            $this->assertNotContains('resolveForCli', $words, "{$consumer} runs in the receiver and must resolve as the receiver does — never with GH_TOKEN");
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
     * @return array{resolveFromFile: list<string>, resolveFor: list<string>, resolveForCli: list<string>}
     */
    private static function callers(): array
    {
        $out = ['resolveFromFile' => [], 'resolveFor' => [], 'resolveForCli' => []];
        foreach (SourceScan::appFiles() as $path) {
            $relative = SourceScan::relativeToApp($path);
            if ($relative === self::RESOLVER_FILE) {
                continue;
            }
            $sites = SourceScan::sites((string) file_get_contents($path), $relative,
                fn (array $tokens, int $i): ?string => SourceScan::methodCallAt($tokens, $i, ['resolveFromFile', 'resolveFor', 'resolveForCli']));
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            foreach (array_unique($sites) as $method) {
                $out[$method][] = $class;
            }
        }

        return $out;
    }
}
