<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\GitHubDeliveryHistoryCheck;
use App\Bridge\Check\NextStep;
use App\Bridge\Check\NextSteps;
use App\Bridge\Check\NextStepState;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\DbClock;
use App\Bridge\Support\SubscriptionConfig;
use App\Console\Commands\Bridge\CheckCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\AssertsDocPointers;
use Tests\Support\AssertsNoLiveControlByte;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * `bridge:check`'s passive delivery-history leg (DL-382).
 *
 * ⭐ THE DISCRIMINATION EVERY TEST HERE SERVES is *this scope has gone quiet past what its own record calls routine*
 * versus *it is delivering* versus *this run cannot say what routine is*. They take different actions — look at the
 * repo's webhook, nothing, and nothing-but-do-not-read-this-as-healthy — so each arm is asserted by its severity AND by
 * whether it reached the NEXT STEPS block, read out of the `--format=json` document rather than by the absence of a
 * string.
 *
 * ⛔ EVERY VERDICT IS ASSERTED TO CARRY {@see GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY}. The leg cannot see a
 * delivery that is recorded and then dropped before any agent wakes, and a line that did not say so would read as an
 * all-clear for exactly that class.
 */
class GitHubDeliveryHistoryCheckTest extends TestCase
{
    use AssertsDocPointers;
    use AssertsNoLiveControlByte;
    use BootsGoldenInstall;
    use MaterializesChecks;
    use RefreshDatabase;

    private const SCOPE = 'owner/repo';

    private const HOUR = 3600;

    private const DAY = 86400;

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    public function test_a_declared_scope_with_no_recorded_delivery_is_loud_and_reaches_next_steps(): void
    {
        $this->bootInstall();

        [$exit, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('warn', $finding['severity']);
        $this->assertStringContainsString('NO delivery', $finding['message']);
        $this->assertStringContainsString(self::SCOPE, $finding['message']);
        $this->assertStringContainsString(GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY, $finding['message']);
        $this->assertStringContainsString('older than 30d', $finding['message'], 'the record it read is bounded by retention, and the line says so');
        $this->assertContains(
            ['agent' => 'gh-agent', 'scope' => self::SCOPE, 'state' => 'github_delivery_silent', 'command' => 'php artisan bridge:check', 'doc' => NextSteps::DELIVERY_DOC],
            $doc['next_steps'],
        );
        $this->assertSame(0, $exit, 'an inference from silence is a warn and must not move the exit code');
    }

    public function test_the_next_steps_line_names_the_scope_the_remedy_and_the_delivery_side_bound(): void
    {
        $this->bootInstall();

        Artisan::call('bridge:check');
        $lines = array_values(array_filter(
            explode("\n", Artisan::output()),
            static fn (string $line): bool => str_starts_with($line, 'next step ') && str_contains($line, self::SCOPE),
        ));

        $this->assertCount(1, $lines, 'the silent scope produced no NEXT STEPS line');
        $this->assertStringContainsString('DELIVERY side only', $lines[0]);
        $this->assertStringContainsString('Recent Deliveries', $lines[0]);
        $this->assertStringContainsString('php artisan bridge:check', $lines[0]);
        $this->assertStringContainsString(NextSteps::DELIVERY_DOC, $lines[0]);
    }

    public function test_a_scope_heard_from_recently_is_ok_and_shows_its_derivation(): void
    {
        $this->bootInstall();
        $this->recordDeliveries(self::SCOPE, $this->every(6 * self::HOUR, 15 * self::DAY, endingAgo: self::HOUR));

        [$exit, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('ok', $finding['severity']);
        $this->assertStringContainsString('Derivation:', $finding['message']);
        $this->assertStringContainsString('3d (259200s)', $finding['message']);
        $this->assertStringContainsString(GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY, $finding['message']);
        $this->assertNotContains('github_delivery_silent', array_column($doc['next_steps'], 'state'));
        $this->assertSame(0, $exit);
    }

    public function test_a_scope_past_its_derived_threshold_is_loud_with_the_derivation_shown(): void
    {
        $this->bootInstall();
        $this->recordDeliveries(self::SCOPE, $this->every(6 * self::HOUR, 15 * self::DAY, endingAgo: 5 * self::DAY));

        [$exit, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('warn', $finding['severity']);
        $this->assertStringContainsString('SILENT since the last recorded delivery at', $finding['message']);
        $this->assertStringContainsString('Derivation:', $finding['message']);
        $this->assertStringContainsString('the SECOND-longest gap', $finding['message']);
        $this->assertStringContainsString('3d (259200s)', $finding['message']);
        $this->assertStringContainsString(GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY, $finding['message']);
        $this->assertContains('github_delivery_silent', array_column($doc['next_steps'], 'state'));
        $this->assertSame(0, $exit);
    }

    public function test_a_record_too_short_to_derive_from_is_a_named_state_and_never_ok(): void
    {
        $this->bootInstall();
        $this->recordDeliveries(self::SCOPE, $this->every(12 * self::HOUR, self::DAY, endingAgo: self::HOUR));

        [, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('CANNOT DERIVE', $finding['message']);
        $this->assertStringContainsString('NOT a healthy verdict', $finding['message']);
        $this->assertStringContainsString(GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY, $finding['message']);
        $this->assertNotContains('github_delivery_silent', array_column($doc['next_steps'], 'state'));
    }

    public function test_a_record_too_short_to_derive_from_is_still_loud_past_the_floor(): void
    {
        $this->bootInstall();
        $this->recordDeliveries(self::SCOPE, $this->every(12 * self::HOUR, self::DAY, endingAgo: 4 * self::DAY));

        [, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('warn', $finding['severity']);
        $this->assertStringContainsString('CANNOT DERIVE', $finding['message']);
        $this->assertStringContainsString('only the floor', $finding['message']);
        $this->assertStringContainsString(GitHubDeliveryHistoryCheck::DELIVERY_SIDE_ONLY, $finding['message']);
        $this->assertContains('github_delivery_silent', array_column($doc['next_steps'], 'state'));
    }

    public function test_a_delivery_recorded_under_another_spelling_of_the_repo_does_not_count(): void
    {
        // The dispatcher matches a subscription's spelling EXACTLY, so a row recorded as `Owner/Repo` wakes nothing
        // subscribed as `owner/repo`. A case-insensitive collation must not make this leg disagree with it.
        $this->bootInstall();
        $this->recordDeliveries('Owner/Repo', $this->every(6 * self::HOUR, 10 * self::DAY, endingAgo: self::HOUR));

        [, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('warn', $finding['severity']);
        $this->assertStringContainsString('NO delivery', $finding['message']);
    }

    public function test_a_measured_missing_hook_and_a_silent_record_give_one_next_step_not_two(): void
    {
        // The active leg READ the hook list and found nothing: that is the cause, and it already carries the remedy.
        $this->bootInstall(withToken: true);
        Http::fake(['*/repos/owner/repo/hooks*' => Http::response([], 200)]);

        [, $doc] = $this->runJson();

        $this->assertSame('warn', $this->onlyFinding($doc)['severity']);
        $this->assertSame(['no_block', 'github_webhook_missing'], array_column($doc['next_steps'], 'state'));
    }

    public function test_a_delivery_record_that_cannot_be_read_is_unvalidated(): void
    {
        $this->bootInstall();
        Schema::drop('webhook_events');

        [, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('COULD NOT READ', $finding['message']);
        $this->assertStringContainsString(self::SCOPE, $finding['message']);
        $this->assertNotContains('github_delivery_silent', array_column($doc['next_steps'], 'state'));
    }

    public function test_a_scope_string_reaches_the_operator_terminal_escaped(): void
    {
        // ⚠ NOT REACHABLE THROUGH AN AGENT YAML: `SubscriptionConfig::expand()` refuses a scope outside
        // `ScopeId::PATTERN`, so this constructs the subscription directly. What it pins is that the leg's own
        // interpolations go through the escape, so a scope arriving by any other door cannot reach a terminal raw.
        $hostile = "owner/repo\x1B[2K\r\u{202E}github delivery history: ok";
        $base = AgentConfig::fromArray('gh-agent', []);
        $ctx = new CheckContext;
        $ctx->configs = [new AgentConfig(
            agentName: $base->agentName,
            identity: $base->identity,
            subscriptions: [new SubscriptionConfig('github', $hostile, [])],
            echoSuppression: $base->echoSuppression,
            classifierClass: $base->classifierClass,
            classifierConfig: $base->classifierConfig,
            channel: $base->channel,
            tokenPathOverrides: $base->tokenPathOverrides,
            surfaceSilentDropWarnings: $base->surfaceSilentDropWarnings,
            raw: $base->raw,
        )];

        $findings = $this->findingsOf(new GitHubDeliveryHistoryCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertForeignValueEscapedInto($findings[0]->message, $hostile, 'finding');

        $sentence = (new ReflectionMethod(CheckCommand::class, 'nextStepSentence'))->invoke(new CheckCommand, new NextStep(
            agent: 'gh-agent',
            state: NextStepState::GithubDeliverySilent,
            command: 'php artisan bridge:check',
            doc: NextSteps::DELIVERY_DOC,
            scope: $hostile,
        ));
        $this->assertIsString($sentence);
        $this->assertForeignValueEscapedInto($sentence, $hostile, 'next step');
    }

    public function test_the_delivery_doc_pointer_names_a_real_heading(): void
    {
        $this->assertDocPointerNamesARealHeading(NextSteps::DELIVERY_DOC);
    }

    private function bootInstall(bool $withToken = false): void
    {
        $this->bootGoldenInstall('github-delivery-history', function (GoldenInstall $i) use ($withToken) {
            $i->boot()->agent('gh-agent', "identity:\n  github_user_id: 555\n"
                ."subscriptions:\n  - provider: github\n    scopes: [\"".self::SCOPE."\"]\n");
            if ($withToken) {
                $i->secret('github/token', 'gh-token');
            }
        });
    }

    /**
     * Seconds-before-now of one delivery every `$step` seconds across `$span`, the latest `$endingAgo` seconds ago.
     *
     * @return list<int>
     */
    private function every(int $step, int $span, int $endingAgo): array
    {
        return array_reverse(range($endingAgo, $endingAgo + $span, $step));
    }

    /**
     * @param  list<int>  $agesSeconds
     */
    private function recordDeliveries(string $scope, array $agesSeconds): void
    {
        $now = DbClock::now();
        foreach ($agesSeconds as $n => $age) {
            DB::table('webhook_events')->insert([
                'delivery_id' => hash('sha256', $scope.'|'.$n),
                'provider' => 'github',
                'scope_id' => $scope,
                'event_type' => 'issues.opened',
                'actor_id' => null,
                'payload' => null,
                'received_at' => $now->subSeconds($age)->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runJson(): array
    {
        $exit = Artisan::call('bridge:check', ['--format' => 'json']);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'bridge:check --format=json did not emit a JSON object');

        return [$exit, $decoded];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function onlyFinding(array $doc): array
    {
        $entry = null;
        foreach ($doc['checks'] as $check) {
            if ($check['id'] === GitHubDeliveryHistoryCheck::ID) {
                $entry = $check;
            }
        }
        $this->assertIsArray($entry, 'the delivery-history leg is not in the check inventory at all');
        $this->assertSame('reported', $entry['disposition']);
        $this->assertCount(1, $entry['findings']);

        return $entry['findings'][0];
    }
}
