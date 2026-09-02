<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClassifierConfig;
use App\Bridge\Support\DlTokenGrammar;
use App\Bridge\Writeback\PinGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GitHubPrCardMoveClassifierTest extends TestCase
{
    private string $dir;

    private AgentConfig $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prcls-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // These tests fake the scan correlation path (board search); pin scan
            // (the default is now `ref`, DL-031). Ref correlation is covered in
            // KanbanClientTest; this suite verifies classifier target emission.
            'bridge.writeback.correlation' => 'scan',
        ]);
        $this->agent = AgentConfig::fromArray('test-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<mixed> $pr */
    private function classify(string $eventType, array $pr, string $repo = 'owner/repo'): ClassifyResult
    {
        return (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            $eventType,
            ['pull_request' => $pr, 'repository' => ['full_name' => $repo]],
            new Actor('999'),
            'github',
            $repo,
            $this->agent,
        ));
    }

    private function fakeBoardCards(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
        ]])]);
    }

    /** @param array<string,mixed> $extra extra keys merged into the owner/repo mapping */
    private function writeMapping(array $extra): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => array_merge(['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ]], $extra)],
        ]));
    }

    /** @return list<ReactionTarget> */
    private function targetsNamed(ClassifyResult $r, string $handler): array
    {
        return array_values(array_filter($r->targets, fn ($t) => $t->handler === $handler));
    }

    public function test_promote_target_emitted_on_bare_release_pr_merged_to_main(): void
    {
        $this->writeMapping(['promote_on_release' => true]);

        // A release PR: closed+merged into main, NO DL/card token in title/head.
        $r = $this->classify('pull_request.closed', [
            'number' => 300, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'chore(release): v0.60.0', 'head' => ['ref' => 'release/v0.60.0'],
        ]);

        $promote = $this->targetsNamed($r, 'kanban_promote_released');
        $this->assertCount(1, $promote);
        $this->assertSame(['repo' => 'owner/repo'], $promote[0]->payload);
        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_promote_target_emitted_alongside_the_dl_move_target(): void
    {
        $this->writeMapping(['promote_on_release' => true]);
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.closed', [
            'number' => 301, 'merged' => true, 'base' => ['ref' => 'main'],
            // The title CLOSES the DL (card#7348 / DL-305): a merge outcome moves a card
            // only on an explicit closing form. Drop the `Closes ` and this asserts the
            // bare-mention no-op instead, which is what
            // `test_a_bare_mention_on_a_merged_pr_moves_nothing` pins.
            'title' => 'folded release, Closes DL-42', 'head' => ['ref' => 'feature/x'],
        ]);

        $this->assertCount(1, $this->targetsNamed($r, 'kanban_promote_released'));
        $this->assertCount(1, $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_promote_target_emitted_even_on_a_dependabot_merge_to_main(): void
    {
        // The Finding-8 edge: a dependabot PR merged to main early-returns before the
        // move/overlay targets — the promote scan must still be appended.
        $this->writeMapping(['promote_on_release' => true, 'create_dependabot_cards' => true]);

        $r = $this->classify('pull_request.closed', [
            'number' => 302, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'Bump lib', 'head' => ['ref' => 'dependabot/npm_and_yarn/lib-1.2.3'],
        ]);

        $this->assertCount(1, $this->targetsNamed($r, 'kanban_promote_released'));
        $this->assertCount(1, $this->targetsNamed($r, 'kanban_dependabot_card'));
    }

    public function test_no_promote_target_on_merge_to_dev(): void
    {
        $this->writeMapping(['promote_on_release' => true]);
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.closed', [
            'number' => 303, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-42 feature', 'head' => ['ref' => 'feature/y'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_promote_released'));
    }

    public function test_no_promote_target_when_flag_off(): void
    {
        $this->writeMapping([]);   // promote_on_release absent ⇒ off

        $r = $this->classify('pull_request.closed', [
            'number' => 304, 'merged' => true, 'base' => ['ref' => 'main'],
            'title' => 'chore(release): v0.60.0', 'head' => ['ref' => 'release/v0.60.0'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_promote_released'));
    }

    public function test_consumed_event_types_are_pull_request_and_push(): void
    {
        // card#4183 (DL-196): the writeback classifier consumes pull_request (the
        // move lifecycle) + push (the DL-160 branch-create `started` trigger),
        // config-independent.
        $events = (new GitHubPrCardMoveClassifier)->consumedEventTypes(ClassifierConfig::empty());

        sort($events);
        $this->assertSame(['pull_request', 'push'], $events);
    }

    public function test_opened_pr_correlates_and_emits_move_to_opened_stage(): void
    {
        $this->fakeBoardCards();

        $result = $this->classify('pull_request.opened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $result->targets);
        $t = $result->targets[0];
        $this->assertSame('kanban_move_card', $t->handler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'opened'], $t->payload);
        $this->assertSame([], $result->intents);   // machine-only, no inbox intent
    }

    public function test_merged_to_main_vs_merged_keys_on_base_ref(): void
    {
        $this->fakeBoardCards();

        // Both titles CLOSE the DL (card#7348 / DL-305) — this test is about which
        // merge STAGE the base ref selects, so it must get past the closure gate to ask.
        $main = $this->classify('pull_request.closed', ['title' => 'Closes DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);
        $this->assertSame('merged_to_main', $main->targets[0]->payload['outcome']);

        $dev = $this->classify('pull_request.closed', ['title' => 'Closes DL-42', 'merged' => true, 'base' => ['ref' => 'dev']]);
        $this->assertSame('merged', $dev->targets[0]->payload['outcome']);
    }

    public function test_closed_unmerged_outcome(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.closed', ['title' => 'DL-42', 'merged' => false]);
        $this->assertSame('closed_unmerged', $r->targets[0]->payload['outcome']);
    }

    public function test_dl_token_from_head_branch_when_title_has_none(): void
    {
        $this->fakeBoardCards();
        $r = $this->classify('pull_request.opened', ['title' => 'no ref here', 'head' => ['ref' => 'fix/DL-42-thing']]);
        $this->assertSame(5, $r->targets[0]->payload['card_id']);
    }

    public function test_unmapped_repo_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42'], repo: 'other/repo');
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // didn't even search
    }

    public function test_no_dl_token_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'no card reference']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_no_matching_card_is_noop(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $r = $this->classify('pull_request.opened', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
    }

    public function test_bundled_dl_emits_one_move_target_per_matching_card(): void
    {
        // DL-148: a DL can track multiple cards (bundled PR) — move them ALL,
        // one target each with the card id as a distinct target_id (no coalesce).
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],   // same canonical 42
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],  // different DL, not matched
        ]])]);

        // `Closes DL-42` closes everything that DL tracks — the claim is made about the
        // DL, and DL-148 makes the resolution one-to-many (card#7348 / DL-305).
        $r = $this->classify('pull_request.closed', ['title' => 'Closes DL-42', 'merged' => true, 'base' => ['ref' => 'main']]);

        $this->assertCount(2, $r->targets);
        $ids = array_map(fn ($t) => $t->payload['card_id'], $r->targets);
        $this->assertEqualsCanonicalizing([5, 6], $ids);
        $this->assertEqualsCanonicalizing(['5', '6'], array_map(fn ($t) => $t->targetId, $r->targets));   // distinct target ids
        foreach ($r->targets as $t) {
            $this->assertSame('kanban_move_card', $t->handler);
            $this->assertSame('merged_to_main', $t->payload['outcome']);
        }
    }

    public function test_non_pull_request_non_push_event_is_noop(): void
    {
        Http::fake();
        $r = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext('issues.opened', [], new Actor('1'), 'github', 'owner/repo', $this->agent));
        $this->assertSame([], $r->targets);
    }

    /** @param array<mixed> $payload */
    private function classifyPush(array $payload, string $repo = 'owner/repo'): ClassifyResult
    {
        return (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            $payload + ['repository' => ['full_name' => $repo]],
            new Actor('999'),
            'github',
            $repo,
            $this->agent,
        ));
    }

    public function test_branch_create_push_with_dl_emits_started_target(): void
    {
        $this->fakeBoardCards();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-thing']);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_move_card', $t->handler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'started'], $t->payload);
        $this->assertSame([], $r->intents);
    }

    public function test_bundled_dl_branch_create_emits_one_started_target_per_card(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-bundle']);

        $this->assertEqualsCanonicalizing([5, 6], array_map(fn ($t) => $t->payload['card_id'], $r->targets));
        foreach ($r->targets as $t) {
            $this->assertSame('started', $t->payload['outcome']);
        }
    }

    public function test_push_to_existing_branch_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => false, 'ref' => 'refs/heads/feat/DL-42-thing']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // not a branch creation → no correlation read
    }

    public function test_branch_create_push_without_dl_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/no-card-ref']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_dependabot_branch_create_push_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/dependabot/composer/DL-1-x']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_tag_create_push_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/tags/DL-42-v1']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_branch_create_push_unmapped_repo_is_noop(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-x'], repo: 'other/repo');
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_branch_create_push_no_matching_card_is_noop(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/DL-42-x']);
        $this->assertSame([], $r->targets);
    }

    public function test_unhandled_pr_action_is_noop(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.synchronize', ['title' => 'DL-42']);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    private function enableDependabot(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ], 'create_dependabot_cards' => true]],
        ]));
    }

    public function test_dependabot_pr_emits_create_or_move_target_when_opted_in(): void
    {
        $this->enableDependabot();
        Http::fake();   // no correlation read on this path

        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x from 1 to 2',
            'number' => 77,
            'head' => ['ref' => 'dependabot/composer/x-2.0'],
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_dependabot_card', $t->handler);
        $this->assertSame('owner/repo', $t->payload['repo']);
        $this->assertSame('opened', $t->payload['outcome']);
        $this->assertSame(77, $t->payload['pr_number']);
        Http::assertNothingSent();   // create/move decided by the durable handler, not here
    }

    public function test_dependabot_pr_falls_through_when_not_opted_in(): void
    {
        // setUp's config has no create_dependabot_cards → no dependabot branch;
        // a dependabot PR has no DL, so the normal path is a no-op.
        Http::fake();
        $r = $this->classify('pull_request.opened', [
            'title' => 'chore(deps): Bump x', 'number' => 77, 'head' => ['ref' => 'dependabot/composer/x-2.0'],
        ]);
        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    /** Write a writeback.json with the given mappings and pin `ref` correlation. */
    private function useRefCorrelation(array $mappings): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => $mappings,
        ]));
        config(['bridge.writeback.correlation' => 'ref']);
    }

    public function test_ref_correlation_omits_source_qualifier_on_a_non_shared_board(): void
    {
        $this->useRefCorrelation(['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]]);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/boards/8/tasks/by-ref.json')
            && ! str_contains(urldecode($r->url()), 'source='));
    }

    public function test_ref_correlation_keeps_source_qualifier_on_a_shared_board(): void
    {
        $this->useRefCorrelation([
            'owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]],
            'owner/other' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'source=owner/repo'));
    }

    public function test_card_token_in_title_correlates_by_native_id_without_a_kanban_read(): void
    {
        Http::fake();
        $result = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame('kanban_move_card', $result->targets[0]->handler);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('opened', $result->targets[0]->payload['outcome']);
        Http::assertNothingSent();   // native-id selection needs no classify-time kanban read
    }

    public function test_card_token_matches_case_insensitively_and_in_head_branch(): void
    {
        Http::fake();
        $result = $this->classify('pull_request.opened', ['title' => 'Fix a thing', 'head' => ['ref' => 'fix/CARD#77-thing']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(77, $result->targets[0]->payload['card_id']);
    }

    /**
     * DL-233 / roundtable #159: the toolkit has accepted the glued `card4524` branch
     * spelling since v0.17.0 while this classifier required a separator, so 14 of 80
     * PRs moved the card locally and died silently at writeback. The separator is now
     * optional. RED-when-reverted: restore `card[-#](\d+)` and the glued cases fail.
     */
    public function test_glued_card_token_correlates(): void
    {
        Http::fake();
        foreach (['Fix a thing card4524', 'fix/CARD4524-slug'] as $i => $text) {
            $result = $this->classify('pull_request.opened', $i === 0
                ? ['title' => $text, 'head' => ['ref' => 'f']]
                : ['title' => 'no token', 'head' => ['ref' => $text]]);

            $this->assertCount(1, $result->targets, "'{$text}' must correlate");
            $this->assertSame(4524, $result->targets[0]->payload['card_id']);
        }
    }

    /**
     * The glued arm's 2-digit floor, adopted from the toolkit rather than invented.
     * It is what stops an ordinary word correlating: `card2go` names no card. A
     * SEPARATED single digit still correlates (`card-3`), so the asymmetry is
     * deliberate and this pins both halves.
     */
    public function test_glued_card_token_requires_two_digits(): void
    {
        Http::fake();
        foreach (['Fix a thing card4', 'Fix a thing card2go'] as $text) {
            $result = $this->classify('pull_request.opened', ['title' => $text, 'head' => ['ref' => 'f']]);
            $this->assertCount(0, $result->targets, "'{$text}' must NOT correlate");
        }

        // Control: a SEPARATED single digit is still accepted.
        $result = $this->classify('pull_request.opened', ['title' => 'Fix a thing card-3', 'head' => ['ref' => 'f']]);
        $this->assertCount(1, $result->targets);
        $this->assertSame(3, $result->targets[0]->payload['card_id']);
    }

    public function test_embedded_words_still_never_correlate_after_widening(): void
    {
        // The \b boundary is what keeps these out, and widening the separator must not
        // weaken it — `discard4524` is the shape most at risk from an optional separator.
        Http::fake();
        foreach (['Refactor the discard4524 helper', 'Use a wildcard-2 match', 'a discard-1 path'] as $text) {
            $result = $this->classify('pull_request.opened', ['title' => $text, 'head' => ['ref' => 'f']]);
            $this->assertCount(0, $result->targets, "'{$text}' must NOT correlate");
        }
    }

    public function test_card_and_dl_tokens_accept_ascii_digits_only(): void
    {
        // DL-231: the ratified DL-201 grammar's digit class is ASCII [0-9] in EVERY engine.
        // PCRE `\d` is ASCII-only unless the pattern carries `/u` — so adding that one
        // character to either token constant would silently widen this grammar to Unicode
        // decimal digits and put this engine out of lockstep with the bash movers, which
        // match `[0-9]` and cannot follow. This is the guard for that one-character edit;
        // it fails if `/u` is ever added. U+0663 is ARABIC-INDIC DIGIT THREE.
        Http::fake();
        $arabicIndicThree = "\u{0663}";

        foreach (["card#{$arabicIndicThree}", "card-{$arabicIndicThree}", "DL-{$arabicIndicThree}"] as $token) {
            $result = $this->classify('pull_request.opened', [
                'title' => "Fix a thing {$token}", 'head' => ['ref' => 'f'],
            ]);

            $this->assertCount(0, $result->targets, "'{$token}' must not correlate to card 3");
        }

        // Positive control: the identical shapes in ASCII DO correlate, so the assertions
        // above are attributable to the digit class and not to the fixture.
        $result = $this->classify('pull_request.opened', ['title' => 'Fix a thing card#3', 'head' => ['ref' => 'f']]);
        $this->assertCount(1, $result->targets);
        $this->assertSame(3, $result->targets[0]->payload['card_id']);
    }

    public function test_dl_wins_when_a_co_present_card_token_names_the_same_card(): void
    {
        // FR-7 precedence (framework v0.2.229): DL-NNN is the ratified, more-specific
        // contract. When a co-present card# names the SAME card the DL resolves to it
        // is redundant — the DL wins and nothing is dropped (logged for the ledger).
        $this->fakeBoardCards();
        Log::spy();

        // DL-9 correlates to card 7; card#7 agrees ⇒ no conflict.
        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-9 thing card#7', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(7, $result->targets[0]->payload['card_id']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'same card'))->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_dl_only_move_targets_the_resolved_card(): void
    {
        // FR-7 (1): a lone resolving DL with no card# → move that card, no warn.
        $this->fakeBoardCards();
        Log::spy();

        // DL-42 correlates to card 5.
        $result = $this->classify('pull_request.opened', ['title' => 'Ship DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(5, $result->targets[0]->payload['card_id']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_conflicting_card_token_overrides_the_dl_and_warns(): void
    {
        // DL-218 / card#4811 incident: a DL in the title resolves to a card DIFFERENT
        // from a co-present explicit card# — a descriptive/foreign DL mention must not
        // hijack the move. The explicit card# is authoritative: move it, not the DL
        // card, and warn LOUDLY. (Revert the fix ⇒ DL-9's card 7 is targeted ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        // "Static guard against DL-9 re-introduction (card#4811)" — DL-9 resolves to
        // card 7, but the intended card is #4811.
        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            // The closing form names the card the DL-218 guard rules AUTHORITATIVE, and
            // the foreign DL-9 is NOT closed — which is the point card#7348 / DL-305 adds
            // here: a `Closes DL-9` in this title would name card 7's work, not this one's,
            // so it is deliberately not what authorizes the move.
            'title' => 'Static guard against DL-9 re-introduction. Closes card#4811', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(4811, $move[0]->payload['card_id']);   // the explicit card#, NOT DL-9's card 7
        $this->assertSame('merged', $move[0]->payload['outcome']);
        $this->assertSame(148, $move[0]->payload['stamp_pr']);   // card# path stamps the PR number
        // The foreign DL-9 (it belongs to card 7) must NOT be stamped onto card#4811,
        // or the move-hijack re-emerges as a correlation poison.
        $this->assertArrayNotHasKey('stamp_dl', $move[0]->payload);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'authoritative'))->once();
    }

    public function test_conflicting_card_token_over_a_bundled_dl_drops_the_other_dl_cards(): void
    {
        // DL-218 edge (the intended ruling, pinned): a ONE-TO-MANY DL (bundled PR) that
        // ALSO carries a card# NOT in the resolved set → the explicit card# is
        // authoritative, so ONLY it moves and the OTHER DL-resolved cards are dropped
        // (the rejected "warn+skip" alternative would have moved none). The warning
        // NAMES the dropped card ids for the ledger, so the drop is diagnosable, not
        // silent. (Revert the fix ⇒ DL-9's cards 7 AND 8 move ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);   // DL-9 → [7, 8]
        Log::spy();

        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-9 bundled fix card#4811', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);                                  // ONLY the card#, not 7 & 8
        $this->assertSame(4811, $move[0]->payload['card_id']);
        $this->assertSame(148, $move[0]->payload['stamp_pr']);
        $this->assertArrayNotHasKey('stamp_dl', $move[0]->payload);    // the bundled foreign DL is not stamped
        // The warning names BOTH dropped DL card ids (7,8) alongside the chosen card#.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, '7,8')
            && str_contains((string) $msg, 'card#4811'))->once();
    }

    // --- card#5287 / DL-270: the title-vs-branch card-token conflict + the
    //     uncorroborated title-only residual ---

    public function test_a_foreign_title_card_token_loses_to_the_head_branchs_own_token(): void
    {
        // THE card#5287 defect. `titleAndHead()` concatenates title-then-head and
        // CardTokenGrammar::parse is a single non-global preg_match, so the LEFTMOST
        // match won — a descriptively-cited foreign card# in the title silently
        // outranked the branch ref this install's own tooling minted. The branch is
        // authoritative; the title token is refused and warned.
        // (Revert the fix ⇒ card 5139 is targeted ⇒ RED.)
        Http::fake();
        Log::spy();

        $result = $this->classify('pull_request.closed', [
            'number' => 130, 'merged' => true, 'base' => ['ref' => 'dev'],
            // The closing form names the BRANCH's card, the descriptive citation does
            // not — which is the shape card#7348 / DL-305 makes routine: a title cites one
            // card and closes another. The title token is still 5139 (leftmost), so the
            // card#5287 rule under test is unchanged.
            'title' => 'Rework the widget (card#5139) — coord #369. Closes card#5287',
            'head' => ['ref' => 'fix/card-5287-title-hijack'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(5287, $move[0]->payload['card_id']);   // the BRANCH's card, not the title's 5139
        // Corroborated by the branch ⇒ no handler-side gate needed.
        $this->assertArrayNotHasKey('card_token_uncorroborated', $move[0]->payload);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#5139')
            && str_contains((string) $msg, 'card#5287')
            && str_contains((string) $msg, 'AUTHORITATIVE'))->once();
    }

    public function test_a_conflicting_titles_sole_dl_is_not_stamped_onto_the_branchs_card(): void
    {
        // Once the title is established FOREIGN, a DL sitting in it is foreign to the
        // branch's card too — stamping it would re-mint the DL-218 correlation poison
        // one surface over. The sole-DL stamp therefore derives from the head ref
        // alone on a conflict. DL-77 resolves to nothing (the board has only DL-9), so
        // without the narrowing it WOULD be stamped.
        // (Revert to titleAndHead ⇒ stamp_dl present ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        Log::spy();

        $result = $this->classify('pull_request.opened', [
            'number' => 131,
            'title' => 'DL-77 rework — supersedes card#5139',
            'head' => ['ref' => 'fix/card-5287-slug'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(5287, $move[0]->payload['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $move[0]->payload);
        $this->assertSame(131, $move[0]->payload['stamp_pr']);   // PR provenance still stamped
    }

    public function test_an_agreeing_title_and_branch_token_is_corroborated_and_silent(): void
    {
        // The ordinary shape: the PR title cites the same card the branch names. Not a
        // conflict, so no warning — and corroborated, so no handler-side gate.
        Http::fake();
        Log::spy();

        $result = $this->classify('pull_request.opened', [
            'number' => 132, 'title' => 'Fix the widget card#5287', 'head' => ['ref' => 'fix/card-5287-slug'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertSame(5287, $move[0]->payload['card_id']);
        $this->assertArrayNotHasKey('card_token_uncorroborated', $move[0]->payload);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_branch_only_card_token_is_corroborated(): void
    {
        // No token in the title at all — the branch alone names the card. This install
        // minted that ref, so it needs no corroboration.
        Http::fake();

        $result = $this->classify('pull_request.opened', [
            'number' => 133, 'title' => 'Fix the widget', 'head' => ['ref' => 'fix/card-5287-slug'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertSame(5287, $move[0]->payload['card_id']);
        $this->assertArrayNotHasKey('card_token_uncorroborated', $move[0]->payload);
    }

    public function test_a_bare_card_id_in_the_head_ref_corroborates_the_titles_token(): void
    {
        // The DOMINANT branch convention here is `<type>/<id>-slug`, which carries no
        // card TOKEN — measured across merged PRs (fix/5915-…, test/5233-…). The ref
        // still names the card, so it corroborates, and the handler gate stays off.
        // Without this, almost every real PR would be uncorroborated and any SECOND PR
        // against a card that already tracks a first would be refused.
        // (Require a full token in the ref ⇒ flag present ⇒ RED.)
        Http::fake();

        $result = $this->classify('pull_request.opened', [
            'number' => 136, 'title' => 'Fix the widget (card#5287)', 'head' => ['ref' => 'fix/5287-widget'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertSame(5287, $move[0]->payload['card_id']);
        $this->assertArrayNotHasKey('card_token_uncorroborated', $move[0]->payload);
    }

    public function test_a_bare_id_in_the_head_ref_never_selects_a_card_on_its_own(): void
    {
        // Corroboration is strictly WIDER than selection. A ref naming a DIFFERENT bare
        // id does not make that id the target (it is not a token — CardTokenGrammar
        // requires the `card` prefix so `chore/2026-cleanup` cannot correlate); it
        // simply fails to corroborate, and the title's token goes to the handler gate.
        Http::fake();

        $result = $this->classify('pull_request.opened', [
            'number' => 137, 'title' => 'Rework (card#5139)', 'head' => ['ref' => 'fix/5287-widget'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertSame(5139, $move[0]->payload['card_id']);   // NOT 5287 — a bare id selects nothing
        $this->assertTrue($move[0]->payload['card_token_uncorroborated']);
    }

    public function test_a_title_only_card_token_is_flagged_uncorroborated_for_the_handler(): void
    {
        // The residual the branch cannot vouch for: the token is prose-only. The
        // classifier does not refuse it (that would break every legitimate title-only
        // PR) — it flags it, and the handler corroborates against the card's own
        // pr_number using the read it already makes.
        // (Revert the fix ⇒ flag absent ⇒ RED.)
        Http::fake();

        $result = $this->classify('pull_request.opened', [
            'number' => 134, 'title' => 'Fix a thing card#3410', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertSame(3410, $move[0]->payload['card_id']);
        $this->assertTrue($move[0]->payload['card_token_uncorroborated']);
    }

    public function test_the_draft_overlay_also_prefers_the_branchs_token_over_a_foreign_title_token(): void
    {
        // canon #5: the overlay shares cardTokenResolution, so the title-vs-branch rule
        // reaches it with no second copy of the predicate. Silent by design (DL-218's
        // ruling: the move path logs the same tokens on an opened-as-draft PR).
        $this->writeMapping(['draft_overlay' => true]);
        Http::fake();

        $result = $this->classify('pull_request.converted_to_draft', [
            'number' => 135, 'title' => 'Rework (card#5139)', 'head' => ['ref' => 'fix/card-5287-slug'],
        ]);

        $overlay = $this->targetsNamed($result, 'kanban_block_reason');
        $this->assertCount(1, $overlay);
        $this->assertSame('5287', $overlay[0]->targetId);   // the branch's card, not the title's
    }

    // --- card#5953: the overlay target carries the corroboration flag too ---

    public function test_an_uncorroborated_title_only_token_flags_the_overlay_target(): void
    {
        // card#5953: the overlay inherited the title-vs-branch RULE (shared resolver) but
        // not the handler-side corroboration gate, because its target carried no flag —
        // so a title-only card# could overlay a block_reason onto a merely-cited card.
        // The classifier now reports the same evidence it reports on the move path, and
        // adds the event's PR number, which is what the handler corroborates against.
        // (Revert blockReasonTargets ⇒ payload has neither key ⇒ RED.)
        $this->writeMapping(['draft_overlay' => true]);
        Http::fake();

        $result = $this->classify('pull_request.converted_to_draft', [
            'number' => 140, 'title' => 'Rework the widget (card#5139)', 'head' => ['ref' => 'f'],
        ]);

        $overlay = $this->targetsNamed($result, 'kanban_block_reason');
        $this->assertCount(1, $overlay);
        $this->assertSame('5139', $overlay[0]->targetId);
        $this->assertSame([
            'repo' => 'owner/repo', 'action' => 'set',
            'card_token_uncorroborated' => true, 'pr_number' => 140,
        ], $overlay[0]->payload);
    }

    public function test_the_clear_overlay_carries_the_flag_too_even_though_the_handler_gates_only_set(): void
    {
        // The classifier reports EVIDENCE; which action the gate applies to is the
        // handler's ruling (it gates `set` only — a clear-if-ours can only remove a
        // marker we ourselves wrote). Emitting the flag on both keeps the two paths one
        // mechanism rather than encoding the handler's policy in the classifier.
        $this->writeMapping(['draft_overlay' => true]);
        Http::fake();

        $result = $this->classify('pull_request.ready_for_review', [
            'number' => 141, 'title' => 'Ship it (card#5139)', 'head' => ['ref' => 'f'],
        ]);

        $overlay = $this->targetsNamed($result, 'kanban_block_reason');
        $this->assertCount(1, $overlay);
        $this->assertSame('clear', $overlay[0]->payload['action']);
        $this->assertTrue($overlay[0]->payload['card_token_uncorroborated']);
    }

    public function test_a_ref_corroborated_overlay_target_is_byte_identical_to_before(): void
    {
        // The gate is scoped to the flag, and the flag is scoped to the residual the
        // branch cannot vouch for. The dominant `<type>/<id>-slug` convention corroborates
        // by bare id (refCorroborates), so ordinary work emits the SAME payload it always
        // did — no flag, no pr_number.
        // (Flag the overlay unconditionally ⇒ two extra keys ⇒ RED.)
        $this->writeMapping(['draft_overlay' => true]);
        Http::fake();

        $result = $this->classify('pull_request.converted_to_draft', [
            'number' => 142, 'title' => 'Rework the widget (card#5287)', 'head' => ['ref' => 'fix/5287-widget'],
        ]);

        $overlay = $this->targetsNamed($result, 'kanban_block_reason');
        $this->assertCount(1, $overlay);
        $this->assertSame('5287', $overlay[0]->targetId);
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $overlay[0]->payload);
    }

    public function test_a_dl_correlated_overlay_target_is_never_flagged(): void
    {
        // The flag belongs to the card#-token residual only. A DL that RESOLVES selects
        // its cards from the board, not from prose, so the overlay it emits carries no
        // flag — mirroring the move path, where moveTargets() never carries one.
        // (Flag on the DL branch ⇒ extra keys ⇒ RED.)
        $this->writeMapping(['draft_overlay' => true]);
        $this->fakeBoardCards();   // DL-42 → card 5

        $result = $this->classify('pull_request.converted_to_draft', [
            'number' => 143, 'title' => 'DL-42 wip', 'head' => ['ref' => 'f'],
        ]);

        $overlay = $this->targetsNamed($result, 'kanban_block_reason');
        $this->assertCount(1, $overlay);
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $overlay[0]->payload);
    }

    public function test_card_token_on_a_branch_create_push_emits_started(): void
    {
        Http::fake();
        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feature/card#88-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $this->assertCount(1, $result->targets);
        $this->assertSame(88, $result->targets[0]->payload['card_id']);
        $this->assertSame('started', $result->targets[0]->payload['outcome']);
    }

    public function test_unresolved_dl_falls_through_to_a_present_card_token(): void
    {
        // FR-7 #112 step (2): DL-42 tracks no card (board has only DL-9), but the
        // PR also carries card#3410 — the resolver must NOT dead-end on the DL; it
        // falls through to the native-id path and moves card 3410.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        Log::spy();

        $result = $this->classify('pull_request.opened', ['title' => 'Fix DL-42 thing card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $result->targets);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('opened', $result->targets[0]->payload['outcome']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'falling through to card#3410'))->once();
    }

    public function test_unresolved_dl_with_no_card_token_warns_loudly_and_noops(): void
    {
        // FR-7 #112 step (4): DL-42 tracks no card and there is no card# fallback —
        // a high-value miss (a decision-logged-but-unstamped card). No move, but a
        // loud warning rather than a silent no-op.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        Log::spy();

        $result = $this->classify('pull_request.opened', ['title' => 'DL-42 only']);

        $this->assertSame([], $result->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'DL-42') && str_contains((string) $msg, 'high-value miss'))->once();
    }

    public function test_unresolved_dl_falls_through_to_card_token_on_a_branch_create_push(): void
    {
        // FR-7 #112 fallthrough on the push (started) path.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);

        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feat/dl-42-card#3410-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $this->assertCount(1, $result->targets);
        $this->assertSame(3410, $result->targets[0]->payload['card_id']);
        $this->assertSame('started', $result->targets[0]->payload['outcome']);
    }

    public function test_conflicting_card_token_overrides_the_dl_on_a_branch_create_push(): void
    {
        // DL-218 sibling (classifyPush, SAME harm — a stage move): a branch like
        // `card-4811-guard-DL-9` where DL-9 resolves to a DIFFERENT card (7) must not
        // hijack the `started` move. The explicit card# is authoritative: move card
        // 4811, warn loudly, and do NOT stamp the foreign DL-9 (no PR on a push, so no
        // stamp_pr either). (Revert the classifier ⇒ DL-9's card 7 is targeted ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $result = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/card-4811-guard-DL-9-reintro']);

        $this->assertCount(1, $result->targets);
        $p = $result->targets[0]->payload;
        $this->assertSame(4811, $p['card_id']);   // the explicit card#, NOT DL-9's card 7
        $this->assertSame('started', $p['outcome']);
        $this->assertArrayNotHasKey('stamp_dl', $p);   // the foreign DL-9 is not stamped
        $this->assertArrayNotHasKey('stamp_pr', $p);   // no PR on a push
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'authoritative'))->once();
    }

    // --- card#6027 / DL-287: a card-SHAPED token that does not parse stops being
    //     read as "no card token" at the DL-win sites ---

    public function test_a_near_miss_card_token_beside_a_resolving_dl_refuses_the_move(): void
    {
        // THE card#6027 repro, measured end-to-end through the real classifier.
        // `card_4811` does not parse (underscore separator), so before DL-287 the
        // conflict predicate saw a null token, read it as "no card token", and the
        // DL-218 arm never ran: DL-9's card 7 MOVED and was stamped with this PR's
        // number — a card this PR never touched — with ZERO warnings.
        // (Revert the predicate to its two-state form — token present or not — ⇒ card 7 moves
        // clean and unflagged ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'Guard against DL-9 (card_4811)', 'head' => ['ref' => 'fix/4811-guard'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        // The DL's card is the only card named — the recovered near-miss id may
        // REFUSE, never SELECT (the DL-287 hard bound).
        $this->assertSame(7, $move[0]->payload['card_id']);
        $this->assertTrue($move[0]->payload['card_token_near_miss']);
        // A refused move writes nothing at all, so it carries no stamp hints either.
        $this->assertArrayNotHasKey('stamp_pr', $move[0]->payload);
        $this->assertArrayNotHasKey('stamp_pr_url', $move[0]->payload);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card(s) 7')
            && str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'REFUSED'))->once();
        Log::shouldHaveReceived('warning')->once();   // TOTAL — one line, nothing else warned
    }

    public function test_the_near_miss_refusal_reaches_the_branch_create_push_too(): void
    {
        // The predicate has three consumers (canon #5): this push path is one of
        // them, and a fix at the move site alone would leave it blind to the same
        // shape with the same harm — a card this branch never named promoted to In
        // Progress. (Fix only the move consumer ⇒ card 7 gets a clean started move
        // ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/card_4811-guard-DL-9']);

        $this->assertCount(1, $r->targets);
        $this->assertSame(7, $r->targets[0]->payload['card_id']);
        $this->assertSame('started', $r->targets[0]->payload['outcome']);
        $this->assertTrue($r->targets[0]->payload['card_token_near_miss']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'card(s) 7')
            && str_contains((string) $msg, 'card#4811')
            && str_contains((string) $msg, 'REFUSED'))->once();
        Log::shouldHaveReceived('warning')->once();   // TOTAL
    }

    public function test_a_near_miss_naming_the_dls_own_card_is_redundant_not_refused(): void
    {
        // THE leg that distinguishes the shipped rule from refuse-on-the-bare-SHAPE:
        // a card-shaped token that does not parse but names the SAME card the DL
        // resolved to drops nothing, so it is REDUNDANT — info, DL wins, no warning
        // and no refusal, mirroring the agreeing arm the parsed token already had.
        // (Refuse on the shape alone ⇒ this move is flagged + warned ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $result = $this->classify('pull_request.opened', [
            'number' => 149, 'title' => 'Fix DL-9 thing (card_7)', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(7, $move[0]->payload['card_id']);
        $this->assertArrayNotHasKey('card_token_near_miss', $move[0]->payload);
        $this->assertSame(149, $move[0]->payload['stamp_pr']);   // an ordinary DL move — it still stamps
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#7')
            && str_contains((string) $msg, 'SAME card the DL resolved to'))->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_redundant_arm_exists_on_the_push_surface_too(): void
    {
        // Both surfaces have both arms — a push whose ref misspells the DL's own card
        // still starts it, and says so once. Without this leg the push redundancy
        // branch is a decoration: nothing would red if it vanished.
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/card_7-guard-DL-9']);

        $this->assertCount(1, $r->targets);
        $this->assertSame(7, $r->targets[0]->payload['card_id']);
        $this->assertSame('started', $r->targets[0]->payload['outcome']);
        $this->assertArrayNotHasKey('card_token_near_miss', $r->targets[0]->payload);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'card#7')
            && str_contains((string) $msg, 'SAME card the DL resolved to'))->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_pure_overlay_event_inherits_the_refusal_with_no_diagnostic(): void
    {
        // The third consumer. A pure-overlay action carries no move outcome, so it
        // returns before every FR-7 diagnostic — it inherits the RULING through the
        // shared predicate (no `block_reason` lands on the DL's card) and stays
        // silent, the same no-double-log split DL-218 made for its own conflict.
        // (Fix only the two move consumers ⇒ card 7 gets the marker ⇒ RED.)
        $this->enableDraftOverlay();
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classify('pull_request.converted_to_draft', [
            'number' => 150, 'title' => 'Guard against DL-9 (card_4811)', 'head' => ['ref' => 'f'],
        ]);

        $this->assertSame([], $r->targets);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    public function test_the_no_move_arm_names_an_unreadable_card_token(): void
    {
        // The adjacent honesty fix: when a DL resolves to NOTHING and there is no
        // card# fallback, nothing moves — so the near-miss line's own "no move"
        // clause is true about this subject and the probe can run there. Before, it
        // sat behind the both-null guard, and this arm reported the missed DL while
        // saying nothing about the card-shaped token sitting beside it.
        $this->fakeBoardCards();   // the board carries DL-9/DL-42; DL-77 resolves to nothing
        Log::spy();

        $r = $this->classify('pull_request.opened', ['title' => 'DL-77 rework (card_4811)', 'head' => ['ref' => 'f']]);

        $this->assertSame([], $r->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'DL-77')
            && str_contains((string) $msg, 'high-value miss'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'name a card')
            && str_contains((string) $msg, 'near-miss'))->once();
        // TOTAL — and no "name a DL" line: the DL in this subject PARSED, so the
        // probe must not claim otherwise (the both-null guard used to make that
        // impossible; the per-stem parse check is what keeps it impossible now).
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_the_push_no_move_arm_names_an_unreadable_card_token_too(): void
    {
        // The same arm on the push surface — both were edited, so both are pinned.
        $this->fakeBoardCards();
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/DL-77-card_4811-guard']);

        $this->assertSame([], $r->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'high-value miss'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'branch ref')
            && str_contains((string) $msg, 'near-miss'))->once();
        Log::shouldHaveReceived('warning')->twice();
    }

    // --- DL-201 / roundtable #48: dash alias + DL-shaped boundary + near-miss warn.
    // The regex decisions are the guard (hostile-input matrix, mutation-checked):
    // reintroducing the trailing \b REDs the underscore tests; dropping the dash
    // alias REDs the card- tests; losing the leading \b REDs the discard/wildcard
    // tests. ---

    public function test_dash_card_token_on_a_branch_create_push_emits_started(): void
    {
        Http::fake();
        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3054-fix']);

        $this->assertCount(1, $r->targets);
        $this->assertSame(3054, $r->targets[0]->payload['card_id']);
        $this->assertSame('started', $r->targets[0]->payload['outcome']);
        Http::assertNothingSent();
    }

    public function test_underscore_after_id_still_correlates_no_trailing_boundary(): void
    {
        // THE roundtable-#48 hole: with a trailing \b, `_` (a word char) after the
        // digits made card#3054_fix a SILENT no-op while DL-200_fix was immune.
        // DL-shaped = both token forms tolerate a word char after the id.
        Http::fake();
        foreach (['refs/heads/feat/card-3054_fix', 'refs/heads/feat/card#3054_fix'] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertCount(1, $r->targets, $ref);
            $this->assertSame(3054, $r->targets[0]->payload['card_id'], $ref);
        }
    }

    public function test_embedded_card_words_do_not_correlate_leading_boundary_holds(): void
    {
        // `discard-1`, `wildcard-2`: "card" preceded by a word char is not a token.
        Http::fake();
        Log::spy();
        foreach (['refs/heads/feat/discard-1-thing', 'refs/heads/fix/wildcard-2-x'] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertSame([], $r->targets, $ref);
        }
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');   // not a near-miss either — no \b'd "card" present
    }

    public function test_dash_card_token_at_start_of_name_and_after_non_word_char(): void
    {
        Http::fake();
        foreach (['refs/heads/card-77-bare' => 77, 'refs/heads/feat-card-88-x' => 88] as $ref => $id) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertCount(1, $r->targets, $ref);
            $this->assertSame($id, $r->targets[0]->payload['card_id'], $ref);
        }
    }

    /**
     * A text that NAMES a card in a shape the token doesn't accept must fail LOUD,
     * not silent — the branch publishes, the card never moves, nobody is told.
     *
     * DRIVEN OFF THE GRAMMAR, not off a list written here (DL-250). The list this
     * replaces held three shapes and reacted to nothing: adding a spelling to the
     * corpus reddened three ties in `PrTitleLintTest` and left this leg — the only
     * one that exercises the runtime surface the whole thing is about — green.
     * The corpus is the SENTENCE rows union the probe's derived separator
     * cross-product, so an edit to either artifact reds here too.
     */
    public function test_the_near_miss_warn_is_driven_by_the_grammar_not_a_hand_written_list(): void
    {
        Http::fake();
        Log::spy();   // Facade::spy() no-ops when already mocked — one spy, count totals

        $corpus = array_values(array_unique(array_merge(
            CardTokenGrammar::VECTORS,
            CardTokenGrammar::probeVectors(),
        )));
        $expectedWarns = $viaBranch = $viaTitle = 0;

        foreach ($corpus as $vector) {
            // Each shape goes to the surface it can actually OCCUR on (DL-250):
            // across printable ASCII `git check-ref-format` rejects exactly space,
            // `*`, `:`, `?`, `[`, `\`, `^`, `~` (measured), so those spellings reach
            // the probe only through a PR title. ALL of them, not just the two
            // today's separators use — narrower, a separator added later routes
            // `card^123` to classifyPush() as a ref git can never produce, and this
            // leg keeps passing while asserting on an impossible input.
            if (preg_match('/[\s:~^?*\[\\\\]/', $vector) === 1) {
                $viaTitle++;
                $r = $this->classify('pull_request.opened', ['title' => "Fix a thing {$vector}", 'head' => ['ref' => 'f']]);
            } else {
                $viaBranch++;
                $r = $this->classifyPush(['created' => true, 'ref' => "refs/heads/feat/{$vector}"]);
            }

            if (CardTokenGrammar::parse($vector) !== null) {
                $this->assertCount(1, $r->targets, "'{$vector}' parses — it must move a card, not warn");

                continue;
            }
            $this->assertSame([], $r->targets, "'{$vector}' must not correlate");
            // The probe's digit class is ASCII (DL-231), so a Unicode-digit token is
            // a KNOWN silent shape. Counted OUT rather than skipped, so every corpus
            // row is accounted for and the total below stays exact.
            $expectedWarns += preg_match('/^[\x20-\x7e]+$/', $vector) === 1 ? 1 : 0;
        }

        $this->assertGreaterThan(0, $viaBranch, 'the branch-ref call site must be exercised');
        $this->assertGreaterThan(0, $viaTitle, 'the PR-title call site must be exercised');
        $this->assertGreaterThan(0, $expectedWarns, 'a corpus expecting no warning at all would assert nothing');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'near-miss'))->times($expectedWarns);
        Log::shouldHaveReceived('warning')->times($expectedWarns);   // and nothing ELSE warned
        Http::assertNothingSent();
    }

    /**
     * The DL half of the same mechanism (card#5310). Until this existed a
     * DL-shaped branch or title that did not parse reached the probe and warned
     * NOTHING — the branch publishes, the card never moves, nobody is told,
     * which is the exact silence DL-234 argued was "as high-value a miss as an
     * unresolvable DL" about the DL token BY NAME.
     *
     * DRIVEN OFF THE GRAMMAR, and it accounts for EVERY row rather than
     * counting only the ones it expects: a row either correlates (and takes the
     * FR-7 high-value-miss path, which warns for a different reason) or it is a
     * near-miss. The totals are asserted separately and then against the whole,
     * so a warning that moved from one class to the other cannot net out.
     */
    public function test_the_dl_near_miss_warn_is_driven_by_the_grammar_not_a_hand_written_list(): void
    {
        Http::fake();
        Log::spy();   // Facade::spy() no-ops when already mocked — one spy, count totals

        $corpus = array_values(array_unique(array_merge(
            DlTokenGrammar::VECTORS,
            DlTokenGrammar::probeVectors(),
        )));
        $expectedNearMiss = $expectedHighValueMiss = $expectedEmptyBoard = $viaBranch = $viaTitle = 0;

        foreach ($corpus as $vector) {
            // Same routing rule as the card leg: across printable ASCII `git
            // check-ref-format` rejects exactly space, `*`, `:`, `?`, `[`, `\`,
            // `^`, `~`, so those spellings reach the probe only through a title.
            if (preg_match('/[\s:~^?*\[\\\\]/', $vector) === 1) {
                $viaTitle++;
                $r = $this->classify('pull_request.opened', ['title' => "Fix a thing {$vector}", 'head' => ['ref' => 'f']]);
            } else {
                $viaBranch++;
                $r = $this->classifyPush(['created' => true, 'ref' => "refs/heads/feat/{$vector}"]);
            }

            $this->assertSame([], $r->targets, "'{$vector}' must not correlate — no board card carries it");

            if (DlTokenGrammar::parse($vector) !== null) {
                // It PARSED: the correlation path ran and resolved nothing, so
                // this row is the FR-7 high-value miss, not a near-miss — and
                // the board read it made warns once on its own account (the
                // empty-board diagnostic, which predates this leg). Counted, not
                // filtered out: an unaccounted-for warning is how a near-miss
                // that moved class would net out against one that vanished.
                $expectedHighValueMiss++;
                $expectedEmptyBoard++;

                continue;
            }
            // Which non-parsing rows the probe can SEE is `DlTokenGrammarTest`'s
            // to pin (the three ratified bounds it cannot); this leg's job is
            // that the runtime surface asks it, on both call sites, and says so.
            $expectedNearMiss += DlTokenGrammar::looksLikeDlToken($vector) ? 1 : 0;
        }

        $this->assertGreaterThan(0, $viaBranch, 'the branch-ref call site must be exercised');
        $this->assertGreaterThan(0, $viaTitle, 'the PR-title call site must be exercised');
        $this->assertGreaterThan(0, $expectedNearMiss, 'a corpus expecting no warning at all would assert nothing');
        $this->assertGreaterThan(0, $expectedHighValueMiss, 'the parsing half must be exercised too');

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'name a DL') && str_contains((string) $msg, 'near-miss'))->times($expectedNearMiss);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'high-value miss'))->times($expectedHighValueMiss);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'board read returned 0 cards'))->times($expectedEmptyBoard);
        Log::shouldHaveReceived('warning')->times($expectedNearMiss + $expectedHighValueMiss + $expectedEmptyBoard);   // and nothing ELSE warned
    }

    /**
     * A subject that is a near-miss on BOTH stems warns once per grammar, each
     * line naming its own accept-set. Pinned because the alternative — one
     * merged line — would have to pick one grammar's sentence or restate both,
     * and it is the shape a later "de-duplicate the warnings" edit would produce.
     */
    public function test_a_subject_that_misses_both_tokens_warns_once_per_grammar(): void
    {
        Http::fake();
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card_123-DL_239']);

        $this->assertSame([], $r->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'name a card'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'name a DL'))->once();
        Log::shouldHaveReceived('warning')->times(2);
        Http::assertNothingSent();
    }

    /**
     * DL-234(e)'s whole-subject limit, applied to the new stem: a subject whose
     * CARD token parses moves its card, so it is not in the silent-failure class
     * this probe exists to catch and must stay silent even beside a malformed
     * DL. The bounded cost — that card loses its `dl_number` stamp with no
     * signal — was card#5961, and this leg is what would red if the NEAR-MISS
     * were ever closed here by accident rather than by decision.
     *
     * CARD#5961 CLOSED THAT COST, BY DECISION, AND THIS LEG WAS UPDATED FOR IT —
     * the file's convention is to name the card that moved a pin. What changed is
     * NOT this guard: the near-miss probe still sits behind the both-null guard and
     * this subject still draws no near-miss line in the bridge log, which is what the assertions
     * below now say specifically. What is new is a DISTINCT warning at the stamp
     * site naming the lost `dl_number` — a different signal, made where it is true.
     * A future edit that closes the near-miss half by widening the guard still reds
     * here, which is the whole point of the pin.
     */
    public function test_a_parsing_card_token_suppresses_the_dl_near_miss_whole_subject(): void
    {
        Http::fake();
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3410-DL_239']);

        $this->assertCount(1, $r->targets, 'the card token correlates — this event emits a move target');
        $this->assertSame(3410, $r->targets[0]->payload['card_id']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'lost DL stamp')
            && ! str_contains((string) $msg, 'near-miss'))->once();
        Log::shouldHaveReceived('warning')->once();   // and nothing ELSE warned — no near-miss line
    }

    public function test_token_less_branch_stays_silent_no_near_miss_spam(): void
    {
        // The warn is a NEAR-MISS detector, not an any-unlinked warn: routine
        // token-less branches (sync/, release/, plain features) and embedded
        // "card"-words followed by non-digits must not log.
        Http::fake();
        Log::spy();
        foreach ([
            'refs/heads/sync/main-to-dev-post-v0.56.0',
            'refs/heads/release/v0.57.0',
            'refs/heads/feat/scorecard_2',          // "card" not \b'd → not a near-miss
            'refs/heads/feat/card-layout-rework',   // token form but no digits → not a near-miss
        ] as $ref) {
            $r = $this->classifyPush(['created' => true, 'ref' => $ref]);
            $this->assertSame([], $r->targets, $ref);
        }
        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public function test_dash_card_token_in_pr_title_correlates_and_near_miss_in_pr_warns(): void
    {
        Http::fake();
        $r = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card-3410', 'head' => ['ref' => 'f']]);
        $this->assertCount(1, $r->targets);
        $this->assertSame(3410, $r->targets[0]->payload['card_id']);

        Log::spy();
        $miss = $this->classify('pull_request.opened', ['title' => 'Fix flaky retry card_3410', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $miss->targets);
        $spaceHash = $this->classify('pull_request.opened', ['title' => 'Fixes card #3410', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $spaceHash->targets);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'near-miss'))->times(2);

        $prose = $this->classify('pull_request.opened', ['title' => 'supports card 2 of the deck', 'head' => ['ref' => 'f']]);
        $this->assertSame([], $prose->targets);
        Log::shouldHaveReceived('warning')->times(2);   // still 2 — bare "card 2" prose is not a near-miss
        Http::assertNothingSent();
    }

    // --- FR #3866: the card# fallback target carries correlation-key stamp hints ---

    public function test_card_fallback_stamps_pr_number_and_url_and_no_dl_when_only_a_card_token_is_present(): void
    {
        // card# only (no DL) → stamp the PR number AND url (card#4852) so release-promote
        // and kanban's by-ref source derivation can correlate; there is no DL to stamp.
        Http::fake();
        $result = $this->classify('pull_request.opened', [
            'title' => 'Fix a thing card#3410', 'head' => ['ref' => 'f'], 'number' => 77,
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertSame(77, $p['stamp_pr']);
        $this->assertSame('https://github.com/owner/repo/pull/77', $p['stamp_pr_url']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_card_fallback_stamps_the_sole_unresolved_dl(): void
    {
        // The core #3866 case: a card created before its DL — the DL is now in the
        // title but resolves to no card (unstamped), so we fall through to card# AND
        // stamp that single DL so the next event correlates by DL.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9']]]])]);
        $result = $this->classify('pull_request.opened', ['title' => 'DL-42 thing card#3410', 'head' => ['ref' => 'f'], 'number' => 88]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertSame('DL-42', $p['stamp_dl']);
        $this->assertSame(88, $p['stamp_pr']);
    }

    public function test_card_fallback_does_not_stamp_a_dl_when_two_or_more_are_present(): void
    {
        // Foreign-DL guard: a bundled / release-shaped PR carrying 2+ DLs must NOT
        // stamp one onto the card# card (it could be a foreign DL). pr_number still stamps.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);   // neither DL resolves
        $result = $this->classify('pull_request.opened', ['title' => 'Release DL-42 and DL-43 card#3410', 'head' => ['ref' => 'f'], 'number' => 90]);

        $p = $result->targets[0]->payload;
        $this->assertSame(3410, $p['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
        $this->assertSame(90, $p['stamp_pr']);
    }

    public function test_dl_resolved_target_carries_pr_refs_but_never_stamp_dl(): void
    {
        // card#4852: a DL-only feature PR (no card#) resolves to card 5. The move target
        // now carries the PR provenance (stamp_pr + stamp_pr_url) so release-promote and
        // kanban's by-ref source derivation can correlate — but NEVER stamp_dl: the card
        // already carries the dl_number that resolved it, so re-stamping delivers nothing
        // and could poison. (Revert branch B to a bare moveTargets ⇒ no stamp_pr/
        // stamp_pr_url ⇒ RED.)
        $this->fakeBoardCards();
        $result = $this->classify('pull_request.opened', [
            'title' => 'DL-42 ship it', 'number' => 77, 'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(5, $p['card_id']);
        $this->assertSame(77, $p['stamp_pr']);
        $this->assertSame('https://github.com/owner/repo/pull/77', $p['stamp_pr_url']);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_multi_dl_title_moves_but_stamps_nothing_on_the_dl_path(): void
    {
        // card#4852 hardening (review consider): a title carrying 2+ DL tokens is
        // bundled/descriptive (release-shaped) — its OWN pr_number/pr_url are foreign
        // to the resolved card, so the DL path must not stamp them; add-if-missing at
        // the handler must not be the only poison guard. The move itself still fires
        // (pre-existing DL-resolution behavior). (Revert the sole-DL gate at the
        // DL-win branch ⇒ stamp_pr/stamp_pr_url appear ⇒ RED.)
        $this->fakeBoardCards();
        $result = $this->classify('pull_request.opened', [
            'title' => 'DL-42 hardening against the DL-218 class', 'number' => 99,
            'html_url' => 'https://github.com/owner/repo/pull/99',
        ]);

        $p = $result->targets[0]->payload;
        $this->assertSame(5, $p['card_id']);   // still moved — the gate is stamp-only
        $this->assertArrayNotHasKey('stamp_pr', $p);
        $this->assertArrayNotHasKey('stamp_pr_url', $p);
        $this->assertArrayNotHasKey('stamp_dl', $p);
    }

    public function test_bundled_dl_stamps_pr_refs_on_every_resolved_card(): void
    {
        // card#4852 + DL-148: a bundled DL resolving to N cards moves them ALL, and every
        // move target carries the PR provenance (stamp_pr + stamp_pr_url), none carries
        // stamp_dl. (Revert branch B ⇒ bare moveTargets ⇒ no stamp refs on any ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);   // DL-9 → [7, 8]

        $result = $this->classify('pull_request.closed', [
            'number' => 148, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'bundled fix, Closes DL-9', 'head' => ['ref' => 'f'],
            'html_url' => 'https://github.com/owner/repo/pull/148',
        ]);

        $move = $this->targetsNamed($result, 'kanban_move_card');
        $this->assertCount(2, $move);
        foreach ($move as $t) {
            $this->assertContains($t->payload['card_id'], [7, 8]);
            $this->assertSame(148, $t->payload['stamp_pr']);
            $this->assertSame('https://github.com/owner/repo/pull/148', $t->payload['stamp_pr_url']);
            $this->assertArrayNotHasKey('stamp_dl', $t->payload);
        }
    }

    public function test_push_card_fallback_stamps_the_sole_branch_dl_and_no_pr(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);   // DL unresolved
        $result = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/feat/DL-55-card#88-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'),
            'github',
            'owner/repo',
            $this->agent,
        ));

        $p = $result->targets[0]->payload;
        $this->assertSame(88, $p['card_id']);
        $this->assertSame('DL-55', $p['stamp_dl']);
        $this->assertArrayNotHasKey('stamp_pr', $p);   // no PR on a push
    }

    // --- card#5961: a LOST `dl_number` stamp is named, where a lost MOVE is not ---

    /**
     * THE SHIPPED CASE. `feat/card-3410-slug-DL_272` moves card 3410 and — because
     * `sole()` returns null on the malformed token — stamps no `dl_number` at all.
     * The near-miss probe is deliberately silent here (DL-234(e), pinned by its own
     * leg above), so before this the stamp simply vanished. The warning fires at the
     * STAMP site instead, and says only what is true THERE, at classify time: a move
     * is EMITTED (the durable handler may still refuse it) and no `dl_number` rides
     * it. The emitted-move half is asserted here as a TARGET, not as a board state,
     * for the same reason the message is worded that way.
     */
    public function test_a_lost_dl_stamp_warns_when_a_card_token_moves_beside_a_malformed_dl(): void
    {
        Http::fake();
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3410-slug-DL_272']);

        $this->assertCount(1, $r->targets, 'the card token correlates — this event emits a move target');
        $this->assertSame(3410, $r->targets[0]->payload['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $r->targets[0]->payload, 'the malformed DL is exactly what is NOT stamped');

        // A DISTINCT signal, not the near-miss line reused: that one says "no move",
        // which would be a false statement about this subject.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'lost DL stamp')
            && ! str_contains((string) $msg, 'near-miss'))->once();
        Log::shouldHaveReceived('warning')->once();   // and nothing ELSE warned
        Http::assertNothingSent();
    }

    /** The PR surface reaches the same stamp site — both call sites, not just the push. */
    public function test_a_lost_dl_stamp_warns_on_the_pr_title_surface_too(): void
    {
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.opened', ['title' => 'Fix a thing card#3410 for DL_272', 'head' => ['ref' => 'f'], 'number' => 91]);

        $this->assertCount(1, $r->targets);
        $this->assertArrayNotHasKey('stamp_dl', $r->targets[0]->payload);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'lost DL stamp'))->once();
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * The other side of the guard: a DL that PARSES beside the card token is stamped,
     * so nothing was lost and nothing is warned. Without this the new warning could be
     * unconditional on the card# path and every leg above would still pass.
     */
    public function test_a_parsing_dl_beside_a_card_token_stamps_and_does_not_warn(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);   // DL unresolved → card# fallback
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3410-slug-DL-272']);

        $this->assertSame('DL-272', $r->targets[0]->payload['stamp_dl']);
        // The empty board the fake serves warns once on its own account (that
        // diagnostic predates this leg). Counted rather than filtered out, so a
        // lost-stamp line appearing here cannot net out against it.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'board read returned 0 cards'))->once();
        Log::shouldHaveReceived('warning')->once();   // and nothing ELSE warned
    }

    /** And a subject with nothing DL-shaped in it at all stays silent. */
    public function test_a_card_token_with_no_dl_shaped_text_does_not_warn_about_a_lost_stamp(): void
    {
        Http::fake();
        Log::spy();

        $r = $this->classifyPush(['created' => true, 'ref' => 'refs/heads/feat/card-3410-slug']);

        $this->assertSame(3410, $r->targets[0]->payload['card_id']);
        $this->assertArrayNotHasKey('stamp_dl', $r->targets[0]->payload);
        Log::shouldNotHaveReceived('warning');
    }

    // --- DL-193: PR draft → block_reason OVERLAY (opt-in `draft_overlay`) ---

    /** Enable the draft overlay on the owner/repo mapping (scan correlation stays pinned from setUp). */
    private function enableDraftOverlay(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => [
                'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49,
            ], 'draft_overlay' => true]],
        ]));
    }

    public function test_converted_to_draft_emits_block_reason_set_when_opted_in(): void
    {
        $this->enableDraftOverlay();
        $this->fakeBoardCards();   // DL-42 → card 5

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42 wip', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_block_reason', $t->handler);
        $this->assertSame('5', $t->targetId);   // card id is the target id
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $t->payload);
        $this->assertSame([], $r->intents);   // machine-only
    }

    public function test_draft_overlay_prefers_the_card_token_on_a_conflict(): void
    {
        // DL-218 sibling (correlatedCardIds, LOWER harm — a block-reason marker, not a
        // stage move): a draft PR whose DL resolves to a DIFFERENT card than a present
        // card# must mark the INTENDED card# blocked, not the foreign-DL card. Same
        // conflict predicate; the overlay path stays SILENT by design (the move path
        // logs — here converted_to_draft carries no move outcome, so nothing logs).
        // (Revert the classifier ⇒ DL-9's card 7 gets the marker ⇒ RED.)
        $this->enableDraftOverlay();
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-9 wip card#4811', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_block_reason', $t->handler);
        $this->assertSame('4811', $t->targetId);   // the explicit card#, NOT DL-9's card 7
        // The fall-through card# came from the TITLE with head `f` backing nothing, so
        // it is the card#5953 residual and is flagged for the handler — exactly as the
        // move path flags its own post-conflict card# fall-through. The event carries no
        // PR number, which the handler fail-closes on.
        $this->assertSame([
            'repo' => 'owner/repo', 'action' => 'set',
            'card_token_uncorroborated' => true, 'pr_number' => null,
        ], $t->payload);
        Log::shouldNotHaveReceived('warning');   // overlay path is silent (no double-log)
    }

    public function test_ready_for_review_emits_block_reason_clear_when_opted_in(): void
    {
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.ready_for_review', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_block_reason', $r->targets[0]->handler);
        $this->assertSame('clear', $r->targets[0]->payload['action']);
    }

    public function test_opened_as_draft_emits_both_the_opened_move_and_the_block_reason_set(): void
    {
        // A PR born a draft: the existing `opened` move STILL fires (card → In Review),
        // and the overlay ADDS a block_reason set — two targets for the same card,
        // distinct handlers (distinct dispatch buckets: handler|debounceKey).
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.opened', ['title' => 'DL-42', 'draft' => true, 'head' => ['ref' => 'f']]);

        $this->assertCount(2, $r->targets);
        $byHandler = [];
        foreach ($r->targets as $t) {
            $byHandler[$t->handler] = $t;
        }
        $this->assertArrayHasKey('kanban_move_card', $byHandler);
        $this->assertArrayHasKey('kanban_block_reason', $byHandler);
        $this->assertSame(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'opened'], $byHandler['kanban_move_card']->payload);
        $this->assertSame(['repo' => 'owner/repo', 'action' => 'set'], $byHandler['kanban_block_reason']->payload);
    }

    public function test_opened_non_draft_emits_no_overlay_even_when_opted_in(): void
    {
        // Not a draft → only the existing `opened` move (byte-identical to today).
        $this->enableDraftOverlay();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.opened', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_move_card', $r->targets[0]->handler);
    }

    public function test_bundled_dl_emits_one_block_reason_target_per_matching_card(): void
    {
        // A DL tracking multiple cards overlays them ALL (one-to-many, like the move
        // path) — each with its card id as a distinct target_id (no coalesce).
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50], 'draft_overlay' => true]],
        ]));
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 5, 'payload' => ['dl_number' => 'DL-42']],
            ['id' => 6, 'payload' => ['dl_number' => '042']],   // same canonical 42
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],  // different DL, not matched
        ]])]);

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertCount(2, $r->targets);
        $this->assertEqualsCanonicalizing(['5', '6'], array_map(fn ($t) => $t->targetId, $r->targets));
        foreach ($r->targets as $t) {
            $this->assertSame('kanban_block_reason', $t->handler);
            $this->assertSame('set', $t->payload['action']);
        }
    }

    public function test_converted_to_draft_via_card_token_overlays_the_native_id(): void
    {
        // Overlay reuses the card# native-id fallback — no kanban read needed.
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'wip card#3410', 'head' => ['ref' => 'f']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_block_reason', $r->targets[0]->handler);
        $this->assertSame('3410', $r->targets[0]->targetId);
        Http::assertNothingSent();   // native-id selection needs no classify-time read
    }

    public function test_converted_to_draft_is_noop_when_draft_overlay_off(): void
    {
        // Default config (setUp) has no draft_overlay → the draft actions are IGNORED,
        // byte-identical to today's behavior (they weren't acted on at all).
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();   // never even correlated
    }

    public function test_ready_for_review_is_noop_when_draft_overlay_off(): void
    {
        Http::fake();

        $r = $this->classify('pull_request.ready_for_review', ['title' => 'DL-42', 'head' => ['ref' => 'f']]);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_draft_overlay_no_card_token_is_noop(): void
    {
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'no card reference']);

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    public function test_draft_overlay_unmapped_repo_is_noop(): void
    {
        $this->enableDraftOverlay();
        Http::fake();

        $r = $this->classify('pull_request.converted_to_draft', ['title' => 'DL-42'], repo: 'other/repo');

        $this->assertSame([], $r->targets);
        Http::assertNothingSent();
    }

    // --- DL-195: Won't-Do-revival (reopened → distinct `reopened` move outcome) ---

    private function enableRevive(bool $withDependabot = false): void
    {
        $mapping = ['board_id' => 8, 'stages' => [
            'opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 77,
        ], 'revive_on_reopen' => true];
        if ($withDependabot) {
            $mapping['create_dependabot_cards'] = true;
        }
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => $mapping],
        ]));
    }

    public function test_reopened_emits_distinct_reopened_outcome_when_revive_on(): void
    {
        $this->enableRevive();
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.reopened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_move_card', $r->targets[0]->handler);
        $this->assertSame('reopened', $r->targets[0]->payload['outcome']);
    }

    public function test_reopened_stays_opened_outcome_when_revive_off(): void
    {
        // setUp's config has no revive_on_reopen → a reopened PR is byte-identical to
        // today: it collapses to the `opened` outcome.
        $this->fakeBoardCards();

        $r = $this->classify('pull_request.reopened', ['title' => 'feat: DL-42 ship it', 'head' => ['ref' => 'feat/x']]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('opened', $r->targets[0]->payload['outcome']);
    }

    public function test_reopened_dependabot_pr_stays_opened_never_reopened(): void
    {
        // SHOULD-FIX 1 regression guard: a reopened dependabot PR (create_dependabot_cards
        // + revive_on_reopen both on) must keep the `opened` outcome on the DEPENDABOT
        // target — dependabot cards archive on close, never park in closed_unmerged, so
        // revival never applies. A `reopened` outcome here would null-stage the dependabot
        // handler and neither move nor create the card.
        $this->enableRevive(withDependabot: true);
        Http::fake();

        $r = $this->classify('pull_request.reopened', [
            'title' => 'chore(deps): Bump x from 1 to 2',
            'number' => 77,
            'head' => ['ref' => 'dependabot/composer/x-2.0'],
            'html_url' => 'https://github.com/owner/repo/pull/77',
        ]);

        $this->assertCount(1, $r->targets);
        $this->assertSame('kanban_dependabot_card', $r->targets[0]->handler);
        $this->assertSame('opened', $r->targets[0]->payload['outcome']);
    }

    // --- card#7348 / DL-305 (widened DL-308): correlation is not completion ---
    //
    // The acceptance witnesses live here and in `ReconcileCommandTest` (the redelivery
    // one, because the "later pass" that would have mass-demoted is the reconciler's).
    // Every pair below differs in exactly ONE thing, so nothing else can explain the
    // difference between them:
    //   1 vs 2  — the closing form            ⇒ isolates the LEXICAL route
    //   1 vs 4  — the head branch ref         ⇒ isolates the STRUCTURAL route (DL-308)
    //   4 vs 5  — the base ref                ⇒ pins the release-merge exclusion
    //
    // ⛔ WITNESSES 1 AND 3a WERE RE-ANCHORED BY DL-308, and the reason is worth stating
    // where the next auditor will read it rather than in a changelog. Their original
    // fixture was a merged-to-dev PR on branch `card-4811-widget` whose title merely
    // mentioned card#4811, asserted to move NOTHING. That fixture is now an ACCEPTED case:
    // it is a PR that IS card 4811's work, merged to the integration branch, which is
    // exactly what DL-308 ruled closes a card. The assertion was not weakened to go green
    // — the accept-set changed by decision, and the witness had to be re-pointed at the
    // vector that is still refused (a title citing a card the BRANCH does not name, which
    // is the peer incident this card was filed for). If you are here because a change made
    // witness 1 pass trivially, check that first.

    /**
     * The FIXTURE these witnesses run: a merged-to-dev PR, with the two closure surfaces —
     * the title and the head ref — as the only variables.
     *
     * ⛔ THE DEFAULT HEAD NAMES NO CARD, deliberately, and that default is load-bearing
     * since DL-308: a branch that named the card would close it structurally, and a
     * witness about the closing FORM would then be passing for the other reason.
     *
     * @return array<string, mixed>
     */
    private function mergedPrTitled(string $title, string $head = 'fix/streaming-timeout', string $base = 'dev'): array
    {
        return [
            'number' => 7348, 'merged' => true, 'base' => ['ref' => $base],
            'title' => $title,
            'head' => ['ref' => $head],
            'html_url' => 'https://github.com/owner/repo/pull/7348',
        ];
    }

    public function test_witness_1_a_bare_mention_on_a_merged_pr_moves_nothing(): void
    {
        // ⛔ WITNESS 1 — THE DEFECT, and since DL-308 it is the FOREIGN-MENTION vector
        // specifically. Before DL-305 this exact event moved card 4811 to the `merged`
        // stage (52) and the release sweep then promoted it to a TERMINAL stage. A peer
        // measured 17 wrong-retirement candidates from this shape in ONE release bundle.
        // Nothing here claims card 4811 is finished: the title CITES it, and the head
        // branch — this install's own artifact — is called `fix/streaming-timeout` and
        // names no card at all. Quoting someone else's card id does not rename your
        // branch, which is the whole reason the DL-308 widening was ruled to preserve the
        // property roundtable #343 endorsed. (Revert either gate ⇒ one kanban_move_card
        // target ⇒ RED.)
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811'));

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
        $this->assertSame([], $r->targets);
        // NEVER A SILENT NO-OP: the operator is told which card did not move and why.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
            && str_contains((string) $msg, '4811')
            && str_contains((string) $msg, 'NO stage move')
            // …AND IT NAMES THE BRANCH IT READ (DL-308). With two closure surfaces, a line
            // quoting only the title sends the operator to rewrite prose when the actual
            // answer is the branch name. (Drop the head ref from the warning ⇒ RED.)
            && str_contains((string) $msg, 'fix/streaming-timeout'))->once();
    }

    public function test_witness_2_the_same_pr_with_a_closing_form_moves_the_card(): void
    {
        // ⛔ WITNESS 2 — one variable changed: THE CLOSING FORM. Same PR, same branch
        // (which names no card, so the DL-308 structural route cannot be what moves this),
        // same card, same base ref — only the title now CLAIMS the card is done, and the
        // move is exactly what it always was. This is what makes witness 1 evidence about
        // closure rather than about some unrelated thing the gate broke, and what keeps
        // the LEXICAL route independently witnessed after DL-308 widened the accept-set.
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, Closes card#4811'));

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(4811, $move[0]->payload['card_id']);
        $this->assertSame('merged', $move[0]->payload['outcome']);
        // The provenance stamps ride the move exactly as before — withholding a move
        // withholds its stamps, and landing one lands them.
        $this->assertSame(7348, $move[0]->payload['stamp_pr']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_witness_3a_a_redelivered_bare_mention_merge_still_moves_nothing(): void
    {
        // ⛔ WITNESS 3, the event-path half (the reconciler holds the other). GitHub
        // redelivers, and `bridge:replay` re-runs stored events, so the SAME merged PR is
        // classified again and again. The gate must keep returning NO TARGET on every pass
        // — never an earlier stage. A rule that returned the pre-merge stage instead would
        // look correct on one card and would mass-demote every already-correct card on the
        // first run after this shipped, because their PRs are bare mentions under the new
        // grammar too. (DL-308 shrank that population — a PR on its own card's branch now
        // closes — but did not empty it: this fixture is the residue, and the no-demotion
        // property is what makes the residue harmless.)
        Http::fake();

        $first = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811'));
        $second = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811'));

        $this->assertSame([], $first->targets);
        $this->assertSame([], $second->targets, 'a later pass must not emit a target either');
        // The claim that matters is not just "no MOVE" but "no target of any kind" — a
        // demotion would arrive as a kanban_move_card carrying an earlier outcome, and
        // asserting only on the move-to-52 would not have seen it.
        Http::assertNotSent(fn () => true);
    }

    // --- card#7348 / DL-308: the structural route ---

    public function test_witness_4_the_same_mention_closes_when_the_branch_names_that_card(): void
    {
        // ⛔ WITNESS 4 — ONE VARIABLE CHANGED FROM WITNESS 1: the head branch ref. The
        // title is byte-identical and still carries no closing verb; the branch is now
        // `card-4811-widget`, the artifact `board-card-start` mints for card 4811. This
        // is the case DL-305 refused and DL-308 accepts, and it is not a marginal one:
        // measured against the shipped title-only accept-set, 0 of 351 correlated merged
        // PRs in this shop closed anything, so without this route the gate freezes every
        // board it guards — quietly, because CI is green and the merge succeeds.
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811', 'card-4811-widget'));

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(4811, $move[0]->payload['card_id']);
        $this->assertSame('merged', $move[0]->payload['outcome']);
        // The provenance stamps ride the structural move exactly as they ride the lexical
        // one — the route decides WHETHER the card moves, never what the move carries.
        $this->assertSame(7348, $move[0]->payload['stamp_pr']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_witness_5_a_release_merge_is_not_widened_by_the_branch(): void
    {
        // ⛔ WITNESS 5 — ONE VARIABLE CHANGED FROM WITNESS 4: the base ref. The structural
        // route is conditioned on a merge into the INTEGRATION branch, because
        // merged-to-integration is the proposition the Shipped stage asserts. A release
        // PR's head is normally a disposable `release/vX` that names no card, so the term
        // would rarely fire here anyway — stating it as a condition is what stops a future
        // release convention that DID name a card from silently acquiring a TERMINAL-stage
        // move nobody approved. (Drop the outcome check from mergeClosesCard ⇒ this card
        // lands in the released stage on a branch name ⇒ RED.)
        Http::fake();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811', 'card-4811-widget', 'main'));

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_witness_6_a_foreign_title_mention_cannot_ride_a_branch_that_names_another_card(): void
    {
        // ⛔⛔ WITNESS 6 — THE NEGATIVE THE WHOLE WIDENING RESTS ON, and the one the
        // agreement is void without. The title cites card#4811 while the branch names
        // card 9999. The structural term must corroborate the card it CLOSES, not merely
        // observe that this merge closed SOMETHING: card 4811 is named by prose alone and
        // must not move, while card 9999 — whose branch this is — does.
        //
        // ⚠ WHICH GUARD THIS ONE ACTUALLY DISCRIMINATES, stated because the mutation run
        // measured it rather than assumed it. On THIS event the title-vs-branch authority
        // rule (card#5287) refuses 4811 at SELECTION, so 4811 never reaches the closure
        // filter at all — and keying the structural term on "the ref names ANY card"
        // leaves this test GREEN. That mutation is caught by
        // `test_the_structural_term_is_keyed_on_this_card_not_on_any_card` (at the
        // predicate), by `test_the_structural_route_closes_only_the_bundled_card_its_branch_names`
        // (where the DL puts two cards in the filter and selection cannot intervene), and
        // by the backstop's own negative in `ReconcileCommandTest`. What this witness
        // pins is the END-TO-END property those three imply and none of them states: on
        // the peer's actual vector, the foreign card does not move and the branch's card
        // does. Do not read it as covering the keying — it does not.
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled('feat: widget rework, follows card#4811', 'card-9999-other'));

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertSame([9999], array_map(fn ($t) => $t->payload['card_id'], $move), 'only the branch\'s own card may move');
        $this->assertNotContains(4811, array_map(fn ($t) => $t->payload['card_id'], $move));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'title-vs-branch conflict, card#5287')
            && str_contains((string) $msg, 'card#4811'))->once();
    }

    // --- card#8306: a revert takes NEITHER route ---
    //
    // ⛔ THE FIXTURES ARE OBSERVED. No revert PR exists on any repo this shop owns (1,607
    // PRs scanned, zero), so the two shapes were read off GitHub's own mint on public
    // repositories: `laravel/framework#61330` is titled `Revert "<the original's title>"`
    // on `revert-61320-fix/contains-strict-comparison`, and #61262 shows the
    // branch-deleted `revert-<n>` form. GitHub's docs state neither format; the API does.
    //
    // Each witness below differs from witness 4 (the accepted structural case) in exactly
    // one thing — the wrapper GitHub adds — so nothing else can explain the difference.

    public function test_witness_7_a_github_revert_moves_nothing_on_either_route(): void
    {
        // ⛔ WITNESS 7 — THE DEFECT card#8294 MINTED. Before this change this exact event
        // emitted one `kanban_move_card` to the `merged` stage (52) for card 4811, on a PR
        // that took card 4811's work OUT. Both routes fired at once and either alone was
        // sufficient: the title inherits `(Closes card#4811)` by quotation, and the ref
        // carries `card-4811` inside GitHub's `revert-<n>-` wrapper. Measured end-to-end on
        // this fixture before the fix — MOVES card#4811 to outcome merged — and the same
        // vector with the lexical half alone, and with the structural half alone, each
        // moved it too. (Delete either conjunct ⇒ RED.)
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (Closes card#4811)"',
            'revert-611-card-4811-widget',
        ));

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
        $this->assertSame([], $r->targets);
        // NEVER A SILENT NO-OP — and the line must not be the DEFAULT one, which is FALSE
        // here: on a revert the ref DOES name the card and the title DOES carry a closing
        // form, so a message asserting otherwise sends the operator to rewrite correct
        // prose. It names the revert, the card, and the way out.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
            && str_contains((string) $msg, '4811')
            && str_contains((string) $msg, 'NO stage move')
            && str_contains((string) $msg, 'REVERT')
            && str_contains((string) $msg, 'card#8306')
            && str_contains((string) $msg, 'OUTSIDE')
            && ! str_contains((string) $msg, 'the HEAD BRANCH REF does not name'))->once();
    }

    public function test_witness_8_each_revert_route_is_refused_on_its_own(): void
    {
        // ⛔ WITNESS 8 — the two halves SEPARATED, because witness 7 is over-determined and
        // a fix to one half alone would leave it green. Row 1 is the LEXICAL half: the
        // post-2026-08-29 house branch (`fix/<id>-slug`) carries no card token, so only the
        // quoted title could close. Row 2 is the STRUCTURAL half: the title carries no
        // closing form at all, so only the wrapped ref could. Each must move nothing.
        Http::fake();

        $lexical = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (Closes card#4811)"', 'revert-611-fix/4811-widget'));
        $this->assertSame([], $this->targetsNamed($lexical, 'kanban_move_card'), 'the quoted closing form must not close');

        $structural = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (card#4811)"', 'revert-611-card-4811-widget'));
        $this->assertSame([], $this->targetsNamed($structural, 'kanban_move_card'), 'the wrapped ref must not close');

        // NESTED — ruled, not left to fall out of the regex. A revert of a revert re-applies
        // the work and STILL does not close: the depth is unparseable (GitHub does not
        // escape the inner quotes) and the card never moved back in the first place, so
        // there is nothing to promote.
        $nested = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "Revert "feat: widget rework (Closes card#4811)""', 'revert-612-revert-611-card-4811-widget'));
        $this->assertSame([], $this->targetsNamed($nested, 'kanban_move_card'), 'a revert of a revert does not close either');
    }

    public function test_witness_9b_a_hand_made_revert_on_an_ordinary_branch_moves_nothing(): void
    {
        // ⛔ WITNESS 9b — THE HALF THE FIRST REVISION OF THIS CHANGE LEFT OPEN, and the
        // reason it is a witness rather than a note. `git revert` pushed to an ordinary
        // branch wraps NO ref: the revert exists only in the title. While the structural
        // route asked the ref alone, every row below was MEASURED still moving card 4811 —
        // and `card-4811-widget` is the spelling `board-card-start` mints, so this was the
        // COMMON branch shape, not an exotic one.
        //
        // It was also SILENT, which is why the warning assertion below is not optional:
        // the card reached the CLOSING set, so `warnMentionWithoutClosure()` — which fires
        // only for withheld cards — never ran. The board asserted the work was done and
        // nothing anywhere said otherwise. (Revert the `isRevert` conjunct in
        // `mergeClosesCard()` to `isRevertRef` ⇒ every row here goes RED.)
        Http::fake();
        Log::spy();

        // ⚠ ONE spy for the whole loop, and each assertion anchored on `head ref: <branch>`
        // rather than on the shared text. `Log::spy()` re-invoked mid-test does NOT reset
        // what the facade has already recorded — measured here: a per-iteration spy with
        // `->once()` saw 1, then 2, then 3 matches for three events that each emitted
        // exactly one line. The anchor makes every assertion match a single distinct
        // message, so `->once()` stays exact and stays per-branch. The `head ref: ` prefix
        // is load-bearing: `card-4811-widget` is a substring of the other two branches.
        foreach (['card-4811-widget', 'revert/card-4811-widget', 'revert-card-4811-widget'] as $branch) {
            $r = $this->classify('pull_request.closed', $this->mergedPrTitled(
                'Revert "feat: widget rework (Closes card#4811)"', $branch));

            $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'), "'{$branch}' must move nothing");
            Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
                && str_contains((string) $msg, '4811')
                && str_contains((string) $msg, 'REVERT')
                && str_contains((string) $msg, "head ref: {$branch}"))->once();
        }

        // THE CONTROL, one variable away: the SAME ordinary branch under an ordinary title
        // still closes, so the rows above turn on the revert and not on the branch.
        $ok = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'feat: widget rework (Closes card#4811)', 'card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($ok, 'kanban_move_card')));
    }

    public function test_witness_9_the_controls_that_discriminate(): void
    {
        // ⛔ WITHOUT THESE, every revert witness above is satisfied by a gate that refuses
        // everything — the DL-305 failure mode exactly (a gate nothing satisfies freezes the
        // board it guards, quietly). Three controls, each one variable away from a witness:
        //
        //  (a) the ordinary lexical PR still closes  — witness 8 row 1 minus the wrapper;
        //  (b) the ordinary structural PR still closes — witness 8 row 2 minus the wrapper;
        //  (c) a revert of a NON-closing original is UNCHANGED — it did not move before
        //      this change either, so it proves the refusal is not what produced (a)/(b)'s
        //      siblings and that nothing regressed on the bare-mention path.
        Http::fake();

        $a = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'feat: widget rework (Closes card#4811)', 'fix/4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($a, 'kanban_move_card')));

        $b = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'feat: widget rework (card#4811)', 'card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($b, 'kanban_move_card')));

        $c = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (card#4811)"', 'revert-611-fix/4811-widget'));
        $this->assertSame([], $this->targetsNamed($c, 'kanban_move_card'));

        // …and the ESCAPE HATCH is reachable end-to-end: a revert whose author writes their
        // OWN closing form outside the quotes closes the card, deliberately. Positional, so
        // it cannot fire by accident — GitHub never writes outside the quotes — and it needs
        // no new vocabulary. ⚠ The parenthetical that read "card#8294's `[no-close]` stays
        // the CI-only inverse it was" is SUPERSEDED by card#8344: the writeback now reads
        // that marker. The ruling here is untouched — a marker meaning *close anyway* is
        // still not minted, and `[no-close]` runs the OTHER way, withholding a move rather
        // than causing one. This hatch is still positional, and a marker in the quoted
        // original does not veto it (`NoCloseGrammarTest`).
        //
        // ⚠ IT RE-CLOSES THE CORRELATED CARD; IT DOES NOT REDIRECT THE MOVE. Closure only
        // FILTERS what correlation already selected, and on a GitHub revert the head ref is
        // authoritative (card#5287) and names the REVERTED card — so a `Closes card#9999`
        // added to this title selects nothing and moves nothing, which the second row pins.
        // Closing a different card from a revert needs a branch that names it, exactly as
        // every other PR here does.
        $hatch = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (Closes card#4811)" — deliberate, this completes it (Closes card#4811)',
            'revert-611-card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($hatch, 'kanban_move_card')));

        $redirect = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'Revert "feat: widget rework (Closes card#4811)" — back it out (Closes card#9999)',
            'revert-611-card-4811-widget'));
        $this->assertSame([], $this->targetsNamed($redirect, 'kanban_move_card'), 'a title cannot redirect the move off the branch\'s card');
    }

    public function test_the_structural_route_closes_only_the_bundled_card_its_branch_names(): void
    {
        // PER CARD, not per event. A branch ref names ONE card, so a bundled DL resolving
        // to two cards on a branch that names one of them closes that one alone — the same
        // filter semantics the `card#` closing form has, and deliberately NOT the DL closing
        // form's whole-set semantics (a claim made about a DL is made about everything the
        // DL tracks; a branch name is a claim about one card). (Return the whole set when
        // the branch names any member ⇒ card 8 retires on card 7's branch ⇒ RED.)
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);
        Log::spy();

        $r = $this->classify('pull_request.closed', [
            'number' => 154, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'bundled fix for DL-9', 'head' => ['ref' => 'card-7-partial'],
        ]);

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertSame([7], array_map(fn ($t) => $t->payload['card_id'], $move));
        // …and the one left behind is still NAMED, on the same warning the lexical partial
        // close emits. A structural partial is as silent as a lexical one without it.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
            && str_contains((string) $msg, 'card(s) 8'))->once();
    }

    public function test_a_foreign_dl_closing_form_still_cannot_ride_the_structural_route(): void
    {
        // ⛔ THE DL-218 GUARD, RE-TESTED THROUGH THE NEW DOOR. DL-9 resolves to card 7; a
        // co-present card#4811 names a different card, so the explicit card# is ruled
        // authoritative and the DL is foreign to it. The branch names neither. Widening the
        // accept-set must not turn the foreign `Closes DL-9` into an authorization for
        // 4811, and must not turn "this merged to dev" into one either: merged-to-dev is
        // half the structural term, never the whole of it.
        $this->fakeBoardCards();   // DL-9 → card 7

        $r = $this->classify('pull_request.closed', [
            'number' => 155, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'Static guard, Closes DL-9, card#4811', 'head' => ['ref' => 'fix/streaming-timeout'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_a_bare_mention_still_moves_on_the_ungated_outcomes(): void
    {
        // THE SCOPE OF THE GATE, stated as a test because the failure direction depends on
        // it: `opened` is reversible and is what STAMPS the card so the reconciler can find
        // the PR later, and `closed_unmerged` is an abandon disposition. Gating those would
        // strand cards rather than protect them. Only the merge outcomes claim completion.
        Http::fake();

        $opened = $this->classify('pull_request.opened', $this->mergedPrTitled('feat: widget rework, follows card#4811'));
        $this->assertSame('opened', $this->targetsNamed($opened, 'kanban_move_card')[0]->payload['outcome']);

        $abandoned = $this->classify('pull_request.closed', [
            'number' => 7349, 'merged' => false,
            'title' => 'feat: widget rework, follows card#4811', 'head' => ['ref' => 'card-4811-widget'],
        ]);
        $this->assertSame('closed_unmerged', $this->targetsNamed($abandoned, 'kanban_move_card')[0]->payload['outcome']);
    }

    public function test_a_started_push_is_untouched_by_the_closure_gate(): void
    {
        // A branch ref cannot carry a closing verb, and `started` promotes only from a
        // narrow allowlist. Gating it would make every branch-create inert — the exact
        // "silently INERT leg" shape bridge:check exists to warn about.
        $this->writeMapping(['started' => 49, 'started_from_stages' => [46]]);
        Http::fake();

        $r = (new GitHubPrCardMoveClassifier)->classify(new ClassifyContext(
            'push',
            ['created' => true, 'ref' => 'refs/heads/card-4811-widget', 'repository' => ['full_name' => 'owner/repo']],
            new Actor('999'), 'github', 'owner/repo', $this->agent,
        ));

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame('started', $move[0]->payload['outcome']);
    }

    public function test_closing_a_dl_closes_every_card_that_dl_tracks(): void
    {
        // DL-148 is one-to-many and the claim is made about the DL, so `Closes DL-9` closes
        // the whole set. The alternative — demanding one `card#` per bundled card — would
        // make a bundled release PR impossible to close at all.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);

        $r = $this->classify('pull_request.closed', [
            'number' => 150, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'bundled fix, Closes DL-9', 'head' => ['ref' => 'f'],
        ]);

        $this->assertEqualsCanonicalizing([7, 8], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($r, 'kanban_move_card')));
    }

    public function test_closing_one_card_of_a_bundled_dl_moves_only_that_card(): void
    {
        // The FILTER, not an all-or-nothing gate: the DL resolves to two cards and the
        // title claims only one of them is done. Moving both would be the same
        // over-reach in miniature that card#7348 is about.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 8, 'payload' => ['dl_number' => 'DL-9']],
        ]])]);

        Log::spy();

        $r = $this->classify('pull_request.closed', [
            'number' => 151, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'DL-9 partial, Closes card#7', 'head' => ['ref' => 'f'],
        ]);

        $move = $this->targetsNamed($r, 'kanban_move_card');
        $this->assertCount(1, $move);
        $this->assertSame(7, $move[0]->payload['card_id']);
        // ⛔ AND THE DROPPED ONE IS NAMED. A partial close moves a card and silently drops
        // the rest — a real event with no move to notice it by, which is the same silence
        // card#7348 is about, in miniature. The warning is keyed on the WITHHELD SET, so
        // it fires here even though something did move. (Key the warning on "nothing
        // closed" instead ⇒ card 8 disappears without a word ⇒ RED.)
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
            && str_contains((string) $msg, 'card(s) 8'))->once();
    }

    public function test_a_foreign_dl_closing_form_cannot_authorize_the_conflicting_card_token(): void
    {
        // ⛔ THE DL-218 GUARD, LEFT INTACT AND NOT RE-OPENED FROM THE OTHER SIDE. DL-9
        // resolves to card 7, a co-present `card#4811` names a different card, so the
        // explicit card# is authoritative — and `Closes DL-9` is then a claim about card
        // 7's work, not card 4811's. It must NOT authorize the move that the guard just
        // routed to 4811. (Pass the resolved DL into the filter on this path ⇒ card 4811
        // moves on someone else's closing form ⇒ the hijack re-emerges ⇒ RED.)
        $this->fakeBoardCards();   // DL-9 → card 7

        $r = $this->classify('pull_request.closed', [
            'number' => 152, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'Static guard, Closes DL-9, card#4811', 'head' => ['ref' => 'f'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
    }

    public function test_the_dl_218_warning_states_the_ruling_and_no_longer_asserts_a_move(): void
    {
        // ⛔ A LOG LINE THAT WENT FALSE WHEN THE GATE LANDED. The foreign-DL-mention warn
        // used to end "moving card#N, not the DL card(s)" — true while only the handler
        // stood between it and a move, and false the moment the closure gate can withhold
        // one. This event is exactly that case: the guard rules card#4811 authoritative and
        // the title closes nothing, so the old wording announced a move that never happened,
        // in the log the operator reads to find out why nothing moved. The line now states
        // the RULING, which is what is unconditionally true where it is emitted.
        $this->fakeBoardCards();   // DL-9 → card 7
        Log::spy();

        $r = $this->classify('pull_request.closed', [
            'number' => 153, 'merged' => true, 'base' => ['ref' => 'dev'],
            'title' => 'Static guard against DL-9, see card#4811', 'head' => ['ref' => 'f'],
        ]);

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'foreign-DL-mention guard, DL-218')
            && str_contains((string) $msg, 'the card token in force is card#4811')
            && ! str_contains((string) $msg, 'moving card#4811'))->once();
    }

    public function test_a_no_close_title_withholds_the_structural_move_and_says_why(): void
    {
        // ⛔ THE card#8344 DEFECT, end to end, on the shape that produced it: a context PR
        // built ON the card's own branch. The structural route (DL-308) reads the ref's
        // IDENTITY, and this PR has exactly the ref a PR that FINISHES the card has — so
        // merging a design note promoted the card into a terminal stage. Nothing in the
        // artifact can tell the two apart; the author's `[no-close]` is the only signal
        // that exists, and until now nothing at runtime read it.
        Http::fake();
        Log::spy();

        $r = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the prior ruling [no-close] (card#4811)', 'card-4811-widget'));

        $this->assertSame([], $this->targetsNamed($r, 'kanban_move_card'));
        // NEVER A SILENT NO-OP, and the line must not be the DEFAULT one, which is FALSE
        // here: this PR's ref DOES name the card. An operator told otherwise would rename a
        // branch to undo a refusal they deliberately asked for.
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'mention-vs-closure')
            && str_contains((string) $msg, '4811')
            && str_contains((string) $msg, 'NO stage move')
            && str_contains((string) $msg, '[no-close]')
            && str_contains((string) $msg, 'card#8344')
            && ! str_contains((string) $msg, 'the HEAD BRANCH REF does not name'))->once();
    }

    public function test_a_no_close_title_withholds_the_lexical_and_dl_routes_too(): void
    {
        // BOTH ROUTES, separated, because the witness above is satisfied by a fix to the
        // structural half alone. Row 1: the author writes a closing form AND the marker —
        // a contradiction, read the recoverable way. Row 2: the DL form, which
        // `bridge:reconcile` also closes on, so a veto spelled in one predicate would be
        // missed by the other.
        $this->fakeBoardCards();   // DL-9 → card 7; stubbed FIRST — `Http::fake()` stacks and the first stub wins

        $lexical = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the ruling [no-close] (closes card#4811)', 'fix/streaming-timeout'));
        $this->assertSame([], $this->targetsNamed($lexical, 'kanban_move_card'), 'a closing form beside the marker must not close');

        $dl = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the ruling [no-close] (closes DL-9)', 'fix/streaming-timeout'));
        $this->assertSame([], $this->targetsNamed($dl, 'kanban_move_card'), 'a closing DL form beside the marker must not close');
    }

    public function test_the_no_close_controls_that_discriminate(): void
    {
        // ⛔ WITHOUT THESE, every marker witness above is satisfied by a gate that refuses
        // everything — the DL-305 failure mode exactly (a gate nothing satisfies freezes
        // the board it guards, quietly). Each control is ONE VARIABLE away: the identical
        // event with the marker deleted.
        $this->fakeBoardCards();   // DL-9 → card 7; stubbed FIRST — `Http::fake()` stacks and the first stub wins

        $structural = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the prior ruling (card#4811)', 'card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($structural, 'kanban_move_card')));

        $lexical = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the ruling (closes card#4811)', 'fix/streaming-timeout'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($lexical, 'kanban_move_card')));

        $dl = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the ruling (closes DL-9)', 'fix/streaming-timeout'));
        $this->assertSame([7], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($dl, 'kanban_move_card')));

        // …and PROSE RESEMBLING the marker still closes: it is a literal, not a grammar
        // (card#8294's ruling, inherited). A fuzzier surface would resume the guessing at
        // intent this gate exists to stop.
        $prose = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the ruling, no close intended (card#4811)', 'card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($prose, 'kanban_move_card')));
    }

    public function test_the_marker_leaves_every_ungated_outcome_byte_identical(): void
    {
        // CORRELATION IS UNTOUCHED, exactly as it is for a revert (card#8306) — and this is
        // the leg that keeps the marker from stranding the card it withholds. The card is
        // still selected, `opened` still fires and still stamps the PR refs, so
        // `bridge:reconcile` keeps the card in its population and an operator who removes
        // the marker (or moves the card by hand) is not fighting a lost correlation.
        Http::fake();

        $opened = $this->classify('pull_request.opened', [
            'number' => 7348, 'merged' => false, 'base' => ['ref' => 'dev'],
            'title' => 'docs: cite the prior ruling [no-close] (card#4811)',
            'head' => ['ref' => 'card-4811-widget'],
            'html_url' => 'https://github.com/owner/repo/pull/7348',
        ]);
        $targets = $this->targetsNamed($opened, 'kanban_move_card');
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $targets));
        $this->assertSame('opened', $targets[0]->payload['outcome']);
        $this->assertSame(7348, $targets[0]->payload['stamp_pr']);
    }

    public function test_the_marker_and_the_card_side_pin_both_hold(): void
    {
        // ⛔ THE PRECEDENCE, asserted at the seam rather than described in prose. The two
        // opt-outs answer different questions on different surfaces and BOTH hold; neither
        // can overturn the other, because both can only WITHHOLD.
        //
        //  - `[no-close]` is read HERE, at classify time, off the event payload's title. Its
        //    refusal is strictly EARLIER: no `kanban_move_card` target is emitted at all, so
        //    the handler — where `PinGuard` lives — is never reached and the card's pin
        //    state cannot matter. That is what this leg measures.
        //  - The pin is read at WRITE time off the card, on every outcome (card#8289), and
        //    `KanbanMoveCardHandlerTest::test_pinned_merge_refusal_alerts()` owns that half.
        //    It is deliberately NOT re-asserted here: a second copy of that witness would be
        //    the duplication this repo keeps paying for.
        //
        // The composition therefore has no order in which the two disagree, and the control
        // below shows the classifier half is live rather than vacuous.
        Http::fake();

        $marked = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the prior ruling [no-close] (card#4811)', 'card-4811-widget'));
        $this->assertSame([], $marked->targets, 'no target reaches the handler, so the pin has nothing left to refuse');

        $this->assertTrue(PinGuard::isPinned(['tags' => ['no-automove']]),
            'the pin predicate must be live, or this leg claims a composition with a dead half');
        $unmarked = $this->classify('pull_request.closed', $this->mergedPrTitled(
            'docs: cite the prior ruling (card#4811)', 'card-4811-widget'));
        $this->assertSame([4811], array_map(fn ($t) => $t->payload['card_id'], $this->targetsNamed($unmarked, 'kanban_move_card')),
            'without the marker the target IS emitted and the pin is what stands between it and a move');
    }
}
