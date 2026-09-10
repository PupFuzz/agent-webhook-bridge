<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\Checks\GitHubWebhookSubscriptionCheck;
use App\Bridge\Check\NextSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * `bridge:check`'s github webhook-subscription leg (card#9150).
 *
 * ⭐ WHAT EVERY TEST HERE IS REALLY ABOUT IS ONE DISCRIMINATION, and it is the discrimination
 * the card exists for: *the hook is gone* versus *this run could not look*. They take
 * opposite actions — the first is a broken install and moves the exit code, the second is a
 * supported configuration and must not — so a fixture set that reached only one of them would
 * green on an implementation that collapsed them. Every could-not-look test therefore asserts
 * the `fail` wording ABSENT as well as the `unvalidated` wording present, and the exit code
 * with it.
 *
 * ⛔ THE ASSERTIONS ARE PRESENCE WITNESSES, never absence alone. Severity is read out of the
 * `--format=json` document's `checks[]` entry for this leg, so a run that stopped emitting the
 * finding entirely cannot pass by leaving a string un-printed — an absence-only assertion
 * certifies whatever replaces it.
 *
 * ⚑ THE HTTP LAYER IS REAL. These drive `Http::fake` responses through the actual
 * `GitHubReadClient` request, so the 403 arm is reached the way a live 403 reaches it
 * (`->throw()` raising a `RequestException`) rather than by injecting a probe result. That is
 * what makes the discriminating control below evidence about the shipped path.
 */
class GitHubWebhookSubscriptionCheckTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    private const SCOPE = 'owner/repo';

    /** What a correctly-configured hook on this fixture's repo delivers to. */
    private const RECEIVER = 'https://bridge.example.com/github?b=owner/repo';

    /**
     * ANOTHER install's receiver, planted in the fixture's hook list.
     *
     * The repo's hook list is the WHOLE FLEET's, so a leg that reported what it found rather
     * than whether it found OURS would publish every other install's endpoint into an
     * operator log. The value is distinctive so an assertion over the whole output can prove
     * it never escaped.
     */
    private const FOREIGN_RECEIVER = 'https://someone-elses-bridge.example.net/github?b=owner/repo';

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    public function test_a_hook_delivering_to_this_installs_receiver_reports_ok_and_leaves_the_exit_code_alone(): void
    {
        $this->bootGithubInstall($this->hookPage([self::FOREIGN_RECEIVER, self::RECEIVER]));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $finding = $this->onlyFinding($doc);
        $this->assertSame('ok', $finding['severity']);
        $this->assertStringContainsString('a live repo webhook delivers to this install', $finding['message']);
        $this->assertStringContainsString(self::SCOPE, $finding['message']);
    }

    public function test_a_hook_list_read_to_the_end_without_our_receiver_fails_and_moves_the_exit_code(): void
    {
        $this->bootGithubInstall($this->hookPage([self::FOREIGN_RECEIVER]));

        [$exit, $doc] = $this->runJson();

        // THE EXIT-CODE HALF OF THE RULING, asserted directly: an install whose hook is
        // confirmed gone exits non-zero, which it did not before this card.
        $this->assertSame(1, $exit);
        $this->assertFalse($doc['ok'], 'the document verdict and the exit code are one variable');
        $finding = $this->onlyFinding($doc);
        $this->assertSame('fail', $finding['severity']);
        $this->assertStringContainsString("github webhook: owner/repo has NO repo webhook delivering to this install's receiver", $finding['message']);

        // The REMEDY is the payload of the line — a fail that names the fault and not the
        // cure sends the operator to `bridge:provision`, which cannot do it.
        $this->assertStringContainsString('bridge:provision CANNOT fix this', $finding['message']);
        $this->assertStringContainsString('<BRIDGE_RECEIVER_BASE_URL>/github?b=owner/repo', $finding['message']);
        $this->assertStringContainsString('webhook-secret-scope-owner%2Frepo', $finding['message']);

        // The bound the leg can be wrong on is PRINTED, because the operator is the only one
        // who can see a hook whose URL differs only in spelling.
        $this->assertStringContainsString('match is by EXACT delivery URL', $finding['message']);
    }

    public function test_a_403_on_the_hook_list_is_unvalidated_and_does_not_collapse_into_the_fail_arm(): void
    {
        // ⭐ THE MOST IMPORTANT TEST IN THE CARD. A token that may not enumerate a repo's
        // webhooks is a configuration this product PERMITS, so rendering it as the fail arm
        // would red every such install — and the two arms are only distinguishable here
        // because one of them measured something.
        $this->bootGithubInstall(Http::response(['message' => 'Must have admin rights to Repository.'], 403));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit, 'a read this install was REFUSED measured nothing and must not move the exit code');
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('COULD NOT LOOK', $finding['message']);
        $this->assertStringContainsString('HTTP 403', $finding['message']);
        $this->assertStringContainsString('admin:repo_hook', $finding['message']);
        $this->assertStringContainsString('NOT evidence it is gone', $finding['message']);

        // Both directions on the discrimination: the fail arm's own wording must be absent,
        // or this would pass against a renderer that printed both.
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_a_repo_with_no_resolvable_token_is_unvalidated_and_issues_no_request(): void
    {
        // No `github/token` secret is placed, `GH_TOKEN` is unset by the host pin, and the
        // credential helper is neutralised by the install builder — so resolution fails before
        // any request. `Http::preventStrayRequests()` (TestCase) is the control: a leg that
        // reached the network here would throw rather than pass.
        $this->bootGithubInstall(null, withToken: false);

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('no GitHub token resolved for this repo', $finding['message']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_a_connection_failure_is_unvalidated_and_not_an_absent_hook(): void
    {
        $this->bootGithubInstall(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('the request to GitHub did not complete', $finding['message']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_a_200_that_is_not_a_hook_list_is_unvalidated_and_never_read_as_an_absence(): void
    {
        // A proxy, a cache or an auth portal answering this URL. Reading it as "no such hook"
        // would convict a healthy install on evidence that measured nothing.
        $this->bootGithubInstall(Http::response(['message' => 'sign in to continue'], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('could not enumerate', $finding['message']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_the_enumeration_follows_pages_before_it_will_call_a_hook_absent(): void
    {
        // A FULL page is not the end of the list. Without paging, an install whose hook sits
        // on page 2 would be reported as MISSING — a false `fail` that moves the exit code,
        // the most expensive way this leg can be wrong.
        $full = array_fill(0, 100, ['config' => ['url' => self::FOREIGN_RECEIVER]]);
        $this->bootGithubInstall(Http::sequence()
            ->push($full, 200)
            ->push([['config' => ['url' => self::RECEIVER]]], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $this->onlyFinding($doc)['severity']);
    }

    public function test_no_other_installs_receiver_url_ever_reaches_the_output(): void
    {
        // ⛔ THE FLEET-LEAK CONTROL, over the WHOLE command output rather than one finding:
        // a leg that closed the leak while some neighbouring line echoed the same list would
        // leave the operator exactly where they started.
        $this->bootGithubInstall($this->hookPage([self::FOREIGN_RECEIVER]));

        Artisan::call('bridge:check');
        $output = Artisan::output();

        $this->assertStringContainsString('has NO repo webhook', $output, 'the fixture must reach the leg for this control to mean anything');
        $this->assertStringNotContainsString(self::FOREIGN_RECEIVER, $output);
        $this->assertStringNotContainsString('someone-elses-bridge', $output);
    }

    public function test_a_missing_hook_gets_a_next_steps_entry_naming_the_scope_and_the_remedy(): void
    {
        $this->bootGithubInstall($this->hookPage([]));

        Artisan::call('bridge:check');
        $lines = array_values(array_filter(
            explode("\n", Artisan::output()),
            static fn (string $line): bool => str_starts_with($line, 'NEXT STEPS') || str_starts_with($line, 'next step '),
        ));

        // ⛔ A LEG NOBODY READS IS THE SAME DEFECT AS NO LEG. This install emits on the order
        // of forty lines; the NEXT STEPS block is the surface DL-352 built for "this install
        // is not wired end to end", and the whole card turns on the finding reaching it.
        // TWO ENTRIES FOR ONE AGENT, and that is the block working rather than noise: this
        // fixture's agent has no `board_tools:` block either, so it owes a board-tools
        // question AND a webhook fix. Asserting the count pins that the webhook half is
        // APPENDED to the board-tools half rather than replacing it — a derivation that
        // returned only its own entries would still contain the string this test looks for.
        $this->assertCount(3, $lines, 'the missing hook produced no NEXT STEPS entry');
        $this->assertStringContainsString('NEXT STEPS', $lines[0]);
        $this->assertStringStartsWith('next step 1/2 — gh-agent:', $lines[1]);
        $this->assertStringContainsString('no `board_tools:` block', $lines[1]);

        $this->assertStringStartsWith('next step 2/2 — gh-agent:', $lines[2]);
        $this->assertStringContainsString(self::SCOPE, $lines[2]);
        $this->assertStringContainsString('admin:repo_hook', $lines[2]);
        $this->assertStringContainsString('php artisan bridge:check', $lines[2]);
        $this->assertStringContainsString(NextSteps::WEBHOOK_DOC, $lines[2]);
        $this->assertStringContainsString('That is the FAIL line above, not an advisory', $lines[2]);

        // The heading no longer claims the block is board-tools-only, and no longer claims
        // the run above still passes — both were false the moment this entry could join it.
        $this->assertStringNotContainsString('are not wired end to end for 1 of this install', $lines[0]);
        $this->assertStringContainsString('some of those DO fail the run', $lines[0]);
    }

    public function test_the_json_next_steps_entry_carries_the_scope_and_the_state(): void
    {
        $this->bootGithubInstall($this->hookPage([]));

        [, $doc] = $this->runJson();

        // BOTH halves, in order — the board-tools question this agent also owes, then the
        // webhook fix. `scope` is null on the first and names the repo on the second, which
        // is the whole reason the key exists.
        $this->assertSame([
            [
                'agent' => 'gh-agent',
                'scope' => null,
                'state' => 'no_block',
                'command' => 'php artisan bridge:provision-tools --agent=gh-agent',
                'doc' => NextSteps::DOC,
            ],
            [
                'agent' => 'gh-agent',
                'scope' => self::SCOPE,
                'state' => 'github_webhook_missing',
                'command' => 'php artisan bridge:check',
                'doc' => NextSteps::WEBHOOK_DOC,
            ],
        ], $doc['next_steps']);
        $this->assertFalse($doc['ok'], 'the document verdict and the exit code are one variable');
        $this->assertFalse($doc['ok'], 'the document verdict and the exit code are one variable');
    }

    public function test_an_unmeasured_read_produces_no_next_steps_entry(): void
    {
        // The block instructs; an instruction derived from a read that never happened would
        // send an operator to re-create a webhook that is already there.
        $this->bootGithubInstall(Http::response([], 403));

        [, $doc] = $this->runJson();

        // ⛔ THE ASSERTION IS OVER THE STATES, NOT OVER EMPTINESS. This agent legitimately
        // owes a board-tools entry, so an `assertSame([], …)` here would be asserting
        // something false — and a test written that way would have been "fixed" by loosening
        // it, which is how the claim gets lost. What must be absent is the webhook state.
        $this->assertSame(
            ['no_block'],
            array_column($doc['next_steps'], 'state'),
        );
        $this->assertTrue($doc['ok']);
    }

    /**
     * One agent, one github subscription, and the hook-list stub this fixture answers with.
     *
     * @param  mixed  $hooksResponse  what `GET /repos/owner/repo/hooks` answers; null registers
     *                                no stub at all, for the fixtures that must not reach the
     *                                network
     */
    private function bootGithubInstall(mixed $hooksResponse, bool $withToken = true): void
    {
        $this->bootGoldenInstall('github-webhook-subscription', function (GoldenInstall $i) use ($hooksResponse, $withToken) {
            $i->boot()->agent('gh-agent', "identity:\n  github_user_id: 555\n"
                ."subscriptions:\n  - provider: github\n    scopes: [\"".self::SCOPE."\"]\n");
            if ($withToken) {
                $i->secret('github/token', 'gh-token');
            }
            if ($hooksResponse !== null) {
                Http::fake(['*/repos/owner/repo/hooks*' => $hooksResponse]);
            }
        });
    }

    /**
     * One PAGE of GitHub's hook list, carrying the given delivery URLs.
     *
     * @param  list<string>  $urls
     */
    private function hookPage(array $urls): mixed
    {
        return Http::response(array_map(
            static fn (string $url): array => ['id' => 1, 'config' => ['url' => $url]],
            $urls,
        ), 200);
    }

    /**
     * Run the command ONCE and return its exit code beside the decoded document.
     *
     * ⛑ ONE RUN PER TEST IS LOAD-BEARING, not tidiness. `Http::sequence()` is consumed by
     * the run, so a helper that re-invoked the command to read the document would exhaust the
     * pages the paging fixture exists to walk — and, worse, would let the exit code asserted
     * and the findings inspected come from two different executions.
     *
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
     * The single finding this leg produced, read out of the JSON document.
     *
     * ONE, ASSERTED: the fixture declares one github scope, and a leg emitting two lines for
     * it would be reporting twice on one question — which a `contains`-style read of the
     * text report would not notice.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function onlyFinding(array $doc): array
    {
        $entry = null;
        foreach ($doc['checks'] as $check) {
            if ($check['id'] === GitHubWebhookSubscriptionCheck::ID) {
                $entry = $check;
            }
        }
        $this->assertIsArray($entry, 'the github webhook leg is not in the check inventory at all');
        $this->assertSame('reported', $entry['disposition']);
        $this->assertCount(1, $entry['findings']);

        return $entry['findings'][0];
    }
}
