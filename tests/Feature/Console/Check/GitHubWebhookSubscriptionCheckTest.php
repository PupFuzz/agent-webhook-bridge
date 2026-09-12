<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\Checks\GitHubWebhookSubscriptionCheck;
use App\Bridge\Check\NextSteps;
use App\Bridge\Support\ReceiverUrl;
use App\Bridge\Validation\ScopeId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
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

    /**
     * What a correctly-configured hook on this fixture's repo delivers to.
     *
     * ⛔ THE `/webhooks` SEGMENT IS NOT DECORATION, and its absence was a live hole in this
     * file until r6: `BRIDGE_RECEIVER_BASE_URL` ALREADY ENDS IN THE RECEIVER PATH, so the base
     * this fixture used to declare (`https://bridge.example.com`) composed `…/github?b=…`,
     * which reaches NO route in this app. Every assertion here was therefore made over a URL
     * family that could never have delivered to anything — and the leg happily reported `ok`
     * and `fail` about it, which is precisely the defect r6 closes.
     */
    private const RECEIVER = 'https://bridge.example.com/webhooks/github?b=owner/repo';

    /**
     * ANOTHER install's receiver, planted in the fixture's hook list.
     *
     * The repo's hook list is the WHOLE FLEET's, so a leg that reported what it found rather
     * than whether it found OURS would publish every other install's endpoint into an
     * operator log. The value is distinctive so an assertion over the whole output can prove
     * it never escaped.
     */
    private const FOREIGN_RECEIVER = 'https://someone-elses-bridge.example.net/webhooks/github?b=owner/repo';

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

    public function test_a_percent_encoded_hook_url_is_a_live_hook_against_an_unencoded_receiver(): void
    {
        // ⭐ card#9150 r1, AND THE REASON THE PREDICATE DIVERGED FROM `bridge:provision`'s.
        // A live consumer install registers `?b=PupFuzz%2Fmezzanine`. The receiver routes
        // that identically to the unencoded spelling, so the hook is HEALTHY — and under the
        // first cut's byte equality this leg reported it missing and moved the exit code,
        // reddening a working install. That inverts the ruling the whole leg rests on.
        $this->bootGithubInstall($this->hookPage(['https://bridge.example.com/webhooks/github?b=owner%2Frepo']));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit, 'an equivalent spelling is a live hook, and must not red a healthy install');
        $finding = $this->onlyFinding($doc);
        $this->assertSame('ok', $finding['severity']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    /**
     * ⚠ THE MIRROR DIRECTION — an ENCODED configured receiver against a plain hook — IS NOT
     * TESTED HERE, AND THE REASON IS A MEASUREMENT RATHER THAN AN OMISSION.
     *
     * `ReceiverUrl::for()` composes the receiver URL from the agent's declared scope, and a
     * scope carrying a `%` never reaches it: `SubscriptionConfig` runs every scope through
     * `App\Bridge\Validation\ScopeId`, whose character class excludes `%`, so
     * `scopes: ["owner%2Frepo"]` is REFUSED at config load (measured:
     * `ConfigException: subscriptions[…].scopes[0] 'owner%2Frepo' is invalid`). There is
     * therefore no install this command can be given that composes an encoded receiver URL,
     * and a fixture asserting otherwise would be asserting a state the product forbids.
     *
     * The predicate is still symmetric, and that direction is covered where it is meaningful
     * — `Tests\Unit\Support\ReceiverUrlTest` drives `ReceiverUrl::deliversTo()` directly over
     * both directions and the mixed cases.
     */
    public function test_a_double_encoded_hook_url_is_not_equivalent_because_the_receiver_would_refuse_it(): void
    {
        // ⛔ DECIDED FROM THE RECEIVER, NOT FROM TASTE, and stated rather than left to fall
        // out of the implementation. `?b=owner%252Frepo` arrives at
        // `VerifyHmacSignature` as the literal scope `owner%2Frepo`, which `ScopeId` REFUSES
        // (`%` is outside its character class) — so that hook delivers NOTHING and answers
        // `invalid_scope` 400. Reporting it as absent is the CORRECT verdict: it is exactly
        // the deaf-agent state the `fail` arm exists to name, not a false negative.
        $this->bootGithubInstall($this->hookPage(['https://bridge.example.com/webhooks/github?b=owner%252Frepo']));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $this->onlyFinding($doc)['severity']);
    }

    public function test_the_equivalence_class_is_the_receivers_own_routing(): void
    {
        // ⭐ THE JUSTIFICATION, PINNED AGAINST THE RECEIVER RATHER THAN ASSERTED IN PROSE.
        // `deliversTo()` treats `%2F` and `/` as one hook ONLY because
        // `VerifyHmacSignature` reads the scope with `$request->query('b')` and cannot tell
        // them apart. If that ever stops being true the normalisation is wrong and this reds
        // — which is a stronger guard than any restatement of the reason in a docblock.
        //
        // ⚑ The same call establishes the OTHER half: a double-encoded value decodes ONCE, to
        // a literal `ScopeId` refuses, which is why the arm above is a `fail`.
        $decoded = fn (string $url): ?string => Request::create($url, 'POST')->query('b');

        $this->assertSame('owner/repo', $decoded('https://bridge.example.com/webhooks/github?b=owner%2Frepo'));
        $this->assertSame('owner/repo', $decoded('https://bridge.example.com/webhooks/github?b=owner/repo'));

        $double = $decoded('https://bridge.example.com/webhooks/github?b=owner%252Frepo');
        $this->assertSame('owner%2Frepo', $double);
        $this->assertFalse(
            ScopeId::matches((string) $double),
            'a double-encoded scope must be refused by the receiver — that is why this leg reports it ABSENT rather than equivalent',
        );
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

        // The bound the leg can still be wrong on is PRINTED, because the operator is the
        // only one who can see the hook. Since card#9150 r1 that bound is NARROWER than the
        // exact match it replaced — encoding no longer counts as a difference — so the line
        // names what does. What each clause CLAIMS is asserted against the predicate itself in
        // `test_the_fail_lines_normalisation_note_is_true_of_the_predicate`.
        $this->assertStringContainsString('`?b=owner%2Frepo` and `?b=owner/repo` are the same hook', $finding['message']);
        $this->assertStringContainsString('DOUBLE-encoded scope (%252F)', $finding['message']);

        // ⛔ AND THE CLAIM THAT WAS FALSE FROM r1 TO r6 MAY NOT COME BACK. The line said the
        // endpoint was matched *byte for byte* while the predicate had been folding scheme and
        // host case, the default port, trailing slashes and path percent-encoding since r3 —
        // so an operator was told their hook had to be byte-identical when four documented
        // spellings are not. An absence assertion alone would certify whatever replaced it,
        // which is why it sits BESIDE the presence witnesses above.
        $this->assertStringNotContainsString('byte for byte', $finding['message']);
    }

    public function test_the_fail_lines_normalisation_note_is_true_of_the_predicate(): void
    {
        // ⛔ THE GUARD ON THE ONE RESTATEMENT THAT CANNOT BECOME A POINTER (canon #16). The
        // `fail` line is read by an OPERATOR at a terminal, who cannot follow a `{@see}` to
        // `ReceiverUrl::deliversTo()`, so this copy of the normalisation rule is corrected in
        // place — and every copy that carried the FALSE version of it became a pointer to that
        // owner (⚠ except `docs/writeback.md`, which still restates part of the rule beside its
        // pointer, accurately today and unguarded — named rather than glossed). What
        // keeps this one honest is not proofreading: each clause below is asserted BOTH as text
        // in the shipped line AND as behaviour of the predicate the line describes, so the two
        // cannot drift apart in either direction without going red.
        $this->bootGithubInstall($this->hookPage([self::FOREIGN_RECEIVER]));
        [, $doc] = $this->runJson();
        $message = $this->onlyFinding($doc)['message'];

        $receiver = self::RECEIVER;
        foreach ([
            // [what the line says, the URL it says it about, the verdict it claims]
            ['`?b=owner%2Frepo` and `?b=owner/repo` are the same hook', 'https://bridge.example.com/webhooks/github?b=owner%2Frepo', true],
            ['trailing slashes on the path are ignored', 'https://bridge.example.com/webhooks/github/?b=owner/repo', true],
            ['scheme and host are compared case-insensitively', 'HTTPS://BRIDGE.Example.COM/webhooks/github?b=owner/repo', true],
            ['an explicit :443 or :80 is dropped', 'https://bridge.example.com:443/webhooks/github?b=owner/repo', true],
            ['the path is percent-decoded', 'https://bridge.example.com/webhooks/git%68ub?b=owner/repo', true],
            ['so is a URL that ends in a #fragment', 'https://bridge.example.com/webhooks/github?b=owner/repo#frag', true],
            ['a DOUBLE-encoded scope (%252F)', 'https://bridge.example.com/webhooks/github?b=owner%252Frepo', false],
            ['a hook carrying any extra query parameter', 'https://bridge.example.com/webhooks/github?b=owner/repo&x=1', false],
            ['a hook whose URL puts a # BEFORE the ?', 'https://bridge.example.com/webhooks/github#x?b=owner/repo', false],
        ] as [$clause, $url, $claimed]) {
            $this->assertStringContainsString($clause, $message, 'the fail line no longer states this rule');
            $this->assertSame(
                $claimed,
                ReceiverUrl::deliversTo($url, $receiver),
                "the fail line tells the operator `{$clause}`, and the predicate it describes disagrees about {$url}",
            );
        }
    }

    public function test_a_receiver_url_that_reaches_no_route_is_unvalidated_and_asks_github_nothing(): void
    {
        // ⭐ THE r6 DEFECT, END TO END AND FROM THE OPERATOR'S SIDE. `BRIDGE_RECEIVER_BASE_URL`
        // already ends in the receiver path; set to the bare host it composes `…/github?b=…`,
        // which reaches NO route here. The operator then pastes the payload URL exactly as the
        // `fail` line and docs/writeback.md instruct, so GitHub holds a hook whose URL is
        // BYTE-EQUAL to what this install composes — `deliversTo()` is symmetric and says YES —
        // and this leg reported a live webhook while every delivery answered 404. Silent: `ok`,
        // exit 0, deaf agent, nothing anywhere saying so.
        //
        // `null` registers no HTTP stub, so `Http::preventStrayRequests()` is the control: a leg
        // that asked GitHub anything here would throw rather than pass.
        $this->bootGithubInstall(null, receiverBaseUrl: 'https://bridge.example.com');

        [$exit, $doc] = $this->runJson();

        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('reaches NO route in THIS application', $finding['message']);
        $this->assertStringContainsString('BRIDGE_RECEIVER_BASE_URL', $finding['message']);
        $this->assertStringContainsString(self::SCOPE, $finding['message']);

        // ⛔ BOTH WRONG ANSWERS ARE ASSERTED ABSENT, because this state is neither of them: it
        // is not a live hook (the `ok` this shipped as) and it is not a MEASURED absence (the
        // `fail` that would send an operator to re-create a webhook that may be perfectly fine).
        $this->assertStringNotContainsString('a live repo webhook delivers to this install', $finding['message']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);

        // ⚠ THE EXIT CODE DOES NOT MOVE. This leg measured nothing about the repo — and the
        // route table is not the whole delivery path, since a proxy that rewrites it is
        // unmeasurable from here — so `unvalidated` is the limb (c) verdict, not `fail`.
        $this->assertSame(0, $exit);

        // And an unmeasured read publishes no NEXT STEPS webhook entry, for the same reason the
        // 403 arm does not: the remedy it would print is "go add a webhook", which is wrong.
        $this->assertNotContains('github_webhook_missing', array_column($doc['next_steps'], 'state'));
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

    public function test_exhausting_the_page_bound_is_unvalidated_and_never_an_absent_hook(): void
    {
        // ⛔ THE ARM THAT KEEPS "I DID NOT FINISH ENUMERATING" OUT OF `Absent`, and it had no
        // witness until now: mutating the bound's `return null` to `return false` left the
        // whole suite green, because the only multi-page fixture was two pages deep. Ten FULL
        // pages with no match is the shape that reaches it — the read never established an
        // absence, so convicting the install would be a `fail` off nothing.
        $full = array_fill(0, 100, ['config' => ['url' => self::FOREIGN_RECEIVER]]);
        $sequence = Http::sequence();
        for ($page = 1; $page <= 11; $page++) {
            $sequence->push($full, 200);
        }
        $this->bootGithubInstall($sequence);

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit, 'a bound this run stopped at measured no absence, so it must not move the exit code');
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringContainsString('could not enumerate', $finding['message']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_a_hook_entry_with_no_readable_url_is_unvalidated_and_never_an_absent_hook(): void
    {
        // A 200 whose ENTRIES are unreadable is the same cause as a 200 whose BODY is — an
        // upstream shape change — and on a shape change it is EVERY install at once. Before
        // this it fell through the element type tests into the short-page `return false` and
        // convicted them all.
        $this->bootGithubInstall(Http::response([['id' => 1, 'config' => ['endpoint' => 'moved']]], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $finding = $this->onlyFinding($doc);
        $this->assertSame('unvalidated', $finding['severity']);
        $this->assertStringNotContainsString('has NO repo webhook', $finding['message']);
    }

    public function test_a_matching_hook_still_wins_over_an_unreadable_sibling_entry(): void
    {
        // ⚑ THE ASYMMETRY, ASSERTED. An unreadable entry casts doubt on an ABSENCE, never on a
        // HIT — so a list carrying one malformed entry beside a real matching hook is still
        // `ok`. Without this, the fix above could have been written as "any unreadable entry
        // ⇒ unvalidated" and silenced a leg that had already found its answer.
        $this->bootGithubInstall(Http::response([
            ['id' => 1, 'config' => ['endpoint' => 'moved']],
            ['id' => 2, 'config' => ['url' => self::RECEIVER]],
        ], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $this->onlyFinding($doc)['severity']);
    }

    public function test_a_matching_hook_on_a_late_r_page_still_wins_over_an_unreadable_earlier_entry(): void
    {
        // ⛔ THE CROSS-PAGE CASE, WHICH IS WHERE THE *"a match still wins"* CLAIM WAS FALSE
        // (card#9150 r3). The flag was declared INSIDE the page loop, so its `return null`
        // fired at the end of page 1 and pre-empted page 2 entirely — the single-page test
        // beside this one could not see it, and three surfaces asserted the claim
        // unconditionally. Page 1 is FULL so the walk continues; the match is on page 2.
        $page1 = array_fill(0, 99, ['config' => ['url' => self::FOREIGN_RECEIVER]]);
        $page1[] = ['id' => 7, 'config' => ['endpoint' => 'moved']];
        $this->bootGithubInstall(Http::sequence()
            ->push($page1, 200)
            ->push([['id' => 8, 'config' => ['url' => self::RECEIVER]]], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $this->onlyFinding($doc)['severity'], 'an unreadable entry must cast doubt on an ABSENCE, never pre-empt a later page that holds the hook');
    }

    public function test_an_unreadable_entry_on_an_earlier_page_still_unmakes_an_absence(): void
    {
        // THE OTHER HALF OF THE HOIST, and it is what stops the fix above being a licence to
        // ignore the flag: page 1 carries the unreadable entry, page 2 exhausts the list with
        // no match — so the absence is NOT established and this must stay `unvalidated`.
        $page1 = array_fill(0, 99, ['config' => ['url' => self::FOREIGN_RECEIVER]]);
        $page1[] = ['id' => 7, 'config' => ['endpoint' => 'moved']];
        $this->bootGithubInstall(Http::sequence()
            ->push($page1, 200)
            ->push([['id' => 8, 'config' => ['url' => self::FOREIGN_RECEIVER]]], 200));

        [$exit, $doc] = $this->runJson();

        $this->assertSame(0, $exit);
        $this->assertSame('unvalidated', $this->onlyFinding($doc)['severity']);
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
    private function bootGithubInstall(mixed $hooksResponse, bool $withToken = true, ?string $scope = null, ?string $receiverBaseUrl = null): void
    {
        $scope ??= self::SCOPE;
        $this->bootGoldenInstall('github-webhook-subscription', function (GoldenInstall $i) use ($hooksResponse, $withToken, $scope, $receiverBaseUrl) {
            $i->boot()->agent('gh-agent', "identity:\n  github_user_id: 555\n"
                ."subscriptions:\n  - provider: github\n    scopes: [\"{$scope}\"]\n");
            if ($receiverBaseUrl !== null) {
                config(['bridge.receiver_base_url' => $receiverBaseUrl]);
            }
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
