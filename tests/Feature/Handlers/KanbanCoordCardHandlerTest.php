<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KanbanCoordCardHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coordcardh-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $mapping */
    private function writeMapping(array $mapping, string $repo = 'org/coord'): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => [$repo => $mapping],
        ]));
    }

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): void
    {
        $payload = array_merge([
            'repo' => 'org/coord', 'issue_number' => 4, 'sid' => 'QUERY-4', 'itype' => 'query',
            'title' => '[QUERY] can we ship?', 'issue_url' => 'https://github.com/org/coord/issues/4',
        ], $overrides);

        (new KanbanCoordCardHandler)->handle(
            ReactionTarget::make('kanban_coord_card', 'issue-'.$payload['issue_number'], payload: $payload),
            AgentConfig::fromArray('me', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    public function test_creates_a_card_with_the_locked_tags_and_fields(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),   // no existing card
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['board_id'] === 8
            && $r['task']['workflow_stage_id'] === 21
            && $r['task']['name'] === '[QUERY] can we ship?'
            && $r['task']['description'] === 'Coordination thread org/coord#4'
            && $r['task']['priority'] === 0
            && ! array_key_exists('external_id', $r['task'])   // NOT set — build_create omits it + (board_id,external_id) uniqueness 422 risk
            && $r['task']['external_link'] === 'https://github.com/org/coord/issues/4'
            && $r['task']['tags'] === ['id:QUERY-4', 'type:query']   // id:/type: only — no repo:
            && $r['task']['payload'] === []);
    }

    public function test_brief_gets_priority_one(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle(['sid' => 'BRIEF-4', 'itype' => 'brief']);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['task']['priority'] === 1
            && $r['task']['tags'] === ['id:BRIEF-4', 'type:brief']);
    }

    public function test_swimlane_id_is_applied_when_mapped(): void
    {
        $this->writeMapping(['board_id' => 8, 'swimlane_id' => 31, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['task']['swimlane_id'] ?? null) === 31);
    }

    public function test_existing_card_with_the_tag_is_a_skip_no_create(): void
    {
        // Idempotency: correlate-before-create by the id: tag → non-empty → skip.
        // Covers redelivery, opened+reopened, AND the bridge-vs-reconcile race.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]])]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && str_contains(urldecode($r->url()), 'board_id=8 tags:"id:QUERY-4"'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_post_create_collapse_archives_a_raced_duplicate(): void
    {
        // The check-then-create race: pre-create tag search is empty → create; the
        // post-create re-read sees BOTH (a concurrent delivery / the reconcile also
        // carded) → keep lowest id (99), archive the racer (100).
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])                        // pre-create: empty → create
                ->push(['data' => [['id' => 100], ['id' => 99]]]),   // post-create: race surfaced
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'archived_at' => '2026-07-14T00:00:00+00:00']]),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_no_duplicate_after_create_archives_nothing(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [['id' => 99]]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_malformed_payload_logs_and_noops(): void
    {
        Log::spy();
        Http::fake();

        $this->handle(['title' => '']);   // empty title → malformed (always required)

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'malformed payload'))->once();
    }

    public function test_empty_sid_under_prefixed_population_is_a_noop_no_correlation_key(): void
    {
        // #4553 fail-closed: a null/empty-sid target under the default (prefixed) population
        // has no correlation key (no id: tag, no by-ref) — refuse rather than create an
        // uncorrelatable card that would re-mint on every redelivery. The classifier never
        // emits this; the handler guards it defensively.
        Log::spy();
        Http::fake();

        $this->handle(['sid' => '']);   // prefixed default (setUp) + no sid ⇒ no key

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'malformed payload'))->once();
    }

    public function test_non_prefixed_creates_by_ref_card_under_population_all(): void
    {
        // population=all: a non-prefixed issue (null sid) is correlated by github_issue
        // by-ref → pre-check by-ref (empty) → create stamping issue_number in payload and
        // NO id: tag (only type:).
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        Http::fake([
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []]),   // no existing by-ref card
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle(['sid' => null, 'itype' => 'task', 'title' => 'a plain non-prefixed title']);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['tags'] === ['type:task']              // NO id: tag on the by-ref path
            && $r['task']['payload'] === ['issue_number' => 4]   // stamped so it is by-ref findable
            && $r['task']['external_link'] === 'https://github.com/org/coord/issues/4');
        // correlated by-ref, not by tag
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'system=github_issue')
            && str_contains(urldecode($r->url()), 'ref=4'));
        Http::assertNotSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json'));
    }

    public function test_non_prefixed_existing_by_ref_card_skips_create(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $this->handle(['sid' => null, 'itype' => 'task', 'title' => 'a plain non-prefixed title']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_prefixed_under_population_all_is_dual_keyed(): void
    {
        // A prefixed issue under population=all is dual-keyed: id: tag AND issue_number in
        // payload → discoverable by the reconcile (tag) AND by-ref. Pre-check tests BOTH.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),       // no tag card
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []]),   // no by-ref card
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();   // default payload: sid=QUERY-4, itype=query

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['task']['tags'] === ['id:QUERY-4', 'type:query']
            && $r['task']['payload'] === ['issue_number' => 4]);   // dual-keyed
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json'));   // tag pre-check ran
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'system=github_issue'));   // by-ref pre-check ran
    }

    public function test_prefixed_under_all_skips_when_by_ref_finds_it_prefix_change_edge(): void
    {
        // The prefix-change edge: an issue carded non-prefixed (by-ref only) that reopens
        // WITH a prefix — the tag lookup is empty but the by-ref lookup finds the card → skip.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),            // tag: not found
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]]),   // by-ref: found
        ]);

        $this->handle();   // prefixed target

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_unmapped_or_optout_noops(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50]]);   // no create_coord_cards
        Http::fake();

        $this->handle();

        Http::assertNothingSent();
    }

    public function test_kanban_4xx_is_permanent_no_throw(): void
    {
        // A 4xx create is permanent: log + no-op, never a 5xx retry storm.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'bad'], 422),
        ]);

        $this->handle();   // must not throw

        $this->assertTrue(true);
    }

    public function test_kanban_5xx_propagates_for_redelivery(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'boom'], 503),
        ]);

        $this->expectException(RequestException::class);
        $this->handle();
    }

    public function test_full_dispatch_family_emit_registry_resolve_handler_create(): void
    {
        // R6: exercise the whole path — the classifier family emits the target, the
        // registry resolves the handler by name, and the handler creates the card.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create']]],
        ]);

        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            'issues.opened',
            ['issue' => ['number' => 4, 'title' => '[QUERY] can we ship?', 'html_url' => 'https://github.com/org/coord/issues/4']],
            new Actor(id: '99', name: null, isKnownAgent: false),
            'github',
            'org/coord',
            $agent,
        ));

        $this->assertCount(1, $result->targets);
        $target = $result->targets[0];
        $handler = (new HandlerRegistry)->resolve($target->handler);
        $this->assertNotNull($handler);
        $handler->handle($target, $agent);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['workflow_stage_id'] === 21
            && $r['task']['tags'] === ['id:QUERY-4', 'type:query']);
    }
}
