<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private const ALERT_URL = 'http://127.0.0.1:9934/';

    /**
     * The default mapping WITH an alert channel. This handler had no notifier wiring of
     * any kind before card#5968, so none of its refusal arms could signal.
     */
    private function writeMappingWithAlert(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['org/coord' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
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

    /**
     * A ONE-callback fake for the tests that need the tag search answered DIFFERENTLY per
     * call, because a URL-pattern map cannot express it: Laravel invokes every stub for
     * every request and keeps the first non-null answer, so a `search.json*` stub still
     * runs (and a `Http::sequence()` still POPS) for a request another stub answered.
     * With two tag reads sharing that path since DL-296 — the live pre-check and the
     * `archived=1` twin read — a sequence hands the post-create re-read the archived
     * read's element.
     *
     * `$live` is one `data` payload per LIVE tag search in call order (an extra call
     * fails the test rather than silently repeating the last answer); `$archived` answers
     * every `archived=1` tag search; `$rest` is an ordinary pattern => response map for
     * every other URL.
     *
     * @param  list<list<array<string, mixed>>>  $live
     * @param  list<array<string, mixed>>  $archived
     * @param  array<string, mixed>  $rest
     */
    private function searchFake(array $live, array $archived, array $rest = []): Closure
    {
        return function (Request $request) use (&$live, $archived, $rest) {
            $url = $request->url();
            if (str_contains($url, '/tasks/search.json')) {
                if (str_contains($url, 'archived=1')) {
                    return Http::response(['data' => $archived]);
                }
                $this->assertNotSame([], $live, "unexpected extra LIVE tag search: {$url}");

                return Http::response(['data' => array_shift($live)]);
            }
            foreach ($rest as $pattern => $response) {
                if (Str::is(Str::start($pattern, '*'), $url)) {
                    return $response;
                }
            }
            $this->fail("unstubbed request: {$url}");
        };
    }

    public function test_creates_a_card_with_the_locked_tags_and_fields(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),   // no existing card
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ! isset($r['task'])   // DL-219: create body is flat, no task wrapper
            && $r['board_id'] === 8
            && $r['workflow_stage_id'] === 21
            && $r['name'] === '[QUERY] can we ship?'
            && $r['description'] === 'Coordination thread org/coord#4'
            && $r['priority'] === 0
            && ! array_key_exists('external_id', $r->data())   // NOT set — build_create omits it + (board_id,external_id) uniqueness 422 risk
            && $r['external_link'] === 'https://github.com/org/coord/issues/4'
            && $r['tags'] === ['id:QUERY-4', 'type:query']   // id:/type: only — no repo:
            && $r['payload'] === []);
    }

    public function test_brief_gets_priority_one(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle(['sid' => 'BRIEF-4', 'itype' => 'brief']);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['task']) && $r['priority'] === 1
            && $r['tags'] === ['id:BRIEF-4', 'type:brief']);
    }

    public function test_swimlane_id_is_applied_when_mapped(): void
    {
        $this->writeMapping(['board_id' => 8, 'swimlane_id' => 31, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && ! isset($r['task']) && ($r['swimlane_id'] ?? null) === 31);
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
        // A LIVE card answers the question, so the DL-296 archive-side read is never paid.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'archived=1'));
    }

    // ---- The ARCHIVED twin (DL-296) ----
    //
    // Both correlation reads above are live-only, so a thread whose only card was retired
    // reads as un-carded. Observed on the live sandbox board before the fix: card 521
    // archived, `issues.reopened` replayed → card 522 minted over the retire.

    public function test_an_archived_human_retired_twin_suppresses_the_create_and_signals(): void
    {
        // THE POSITIVE. No live card, one archived card carrying the tag and NOT the
        // consumer's reroute tag ⇒ a deliberate retire: no card is minted, and the outcome
        // is REPORTED (durable warning + live push), never a silent no-op.
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            '*/tasks/search.json?*archived=1*' => Http::response(['data' => [['id' => 521, 'tags' => ['id:QUERY-4', 'type:query'], 'archived_at' => '2026-08-21T21:19:19+00:00']]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),   // live: nothing
            // Stubbed so a regression that CREATES reds as a failed assertion below,
            // not as an unstubbed request escaping to the network.
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            self::ALERT_URL.'*' => Http::response('', 204),
        ]);

        $this->handle();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'the only card for this thread is ARCHIVED'))->once();
        Http::assertSent(fn ($r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_archived_twin'
            && $r['outcome'] === 'coord_card_create'
            && $r['repo'] === 'org/coord'
            && $r['issue_number'] === 4
            && $r['card_id'] === null);
    }

    public function test_no_card_at_all_still_creates_after_the_archived_read_comes_back_empty(): void
    {
        // THE NEGATIVE, and the reason it is a separate test: a fix whose only test is the
        // duplicate case cannot tell "stops the duplicate" from "stops carding". A reopen
        // of a thread with NO card — live or archived — still gets one.
        Http::fake([
            '*/tasks/search.json?*archived=1*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'archived=1')
            && str_contains(urldecode($r->url()), 'board_id=8 tags:"id:QUERY-4"'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['tags'] === ['id:QUERY-4', 'type:query']);
    }

    public function test_a_reroute_archived_twin_does_not_suppress_the_create(): void
    {
        // THE FORK, the side the consumer carved out: `kanban-reclass.py` archives a coord
        // twin whose source re-routed to another board and stamps `coord:reroute-archived`.
        // That is framework bookkeeping, not a human retire, so a reroute-BACK must card
        // again — suppressing here would strand the thread on neither board.
        Http::fake([
            '*/tasks/search.json?*archived=1*' => Http::response(['data' => [['id' => 523, 'tags' => ['id:QUERY-4', 'type:query', 'coord:reroute-archived'], 'archived_at' => '2026-08-21T21:24:23+00:00']]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
    }

    /**
     * THE MIXED CELL, and the one place the naive per-card spelling would disagree with
     * the consumer. Its helpers project to STABLE-IDS before subtracting, so a sid with
     * one reroute-tagged archived card and one untagged one is in both sets, the
     * difference removes it, and `reconcile_simple_board` CREATES. A per-card bridge
     * would refuse, alert the operator to unarchive a card, and be undone by the
     * reconcile's next pass. Both row orders are asserted because the implementation
     * returns early on the tagged row: an order-sensitive one would answer differently
     * depending on what kanban happened to return first.
     *
     * @return array<string, array{list<array<string, mixed>>}>
     */
    public static function mixedArchivedSetOrders(): array
    {
        $reroute = ['id' => 524, 'tags' => ['id:QUERY-4', 'coord:reroute-archived'], 'archived_at' => '2026-08-21T21:24:24+00:00'];
        $retired = ['id' => 525, 'tags' => ['id:QUERY-4'], 'archived_at' => '2026-08-21T21:24:24+00:00'];

        return [
            'reroute-tagged row first' => [[$reroute, $retired]],
            'hand-retired row first' => [[$retired, $reroute]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $archivedRows
     */
    #[DataProvider('mixedArchivedSetOrders')]
    public function test_a_mixed_archived_set_creates_because_the_consumer_exempts_the_whole_thread(array $archivedRows): void
    {
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            '*/tasks/search.json?*archived=1*' => Http::response(['data' => $archivedRows]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            self::ALERT_URL.'*' => Http::response('', 204),
        ]);

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
        Http::assertNotSent(fn ($r) => $this->isAlertPush($r) && $r['reason'] === 'coord_card_archived_twin');
        // Keyed on THIS refusal's message, not on `warning` at all: a blanket negative
        // would red on any unrelated warn a future arm adds and say nothing about this one.
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/the only card for this thread is ARCHIVED/'), \Mockery::any()]);
    }

    public function test_the_signal_names_every_hand_retired_twin_not_just_the_first(): void
    {
        // Two hand-retired cards, no reroute tag anywhere: the thread is retired and BOTH
        // ids reach the operator, because either is a card they may need to unarchive.
        // Reds against a `retiredTwins` that returns on its first untagged row — the
        // shape the thread-level exemption's early return makes easy to reach for.
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            '*/tasks/search.json?*archived=1*' => Http::response(['data' => [
                ['id' => 530, 'tags' => ['id:QUERY-4'], 'archived_at' => '2026-08-21T21:24:24+00:00'],
                ['id' => 531, 'tags' => ['id:QUERY-4', 'type:query'], 'archived_at' => '2026-08-21T21:24:25+00:00'],
            ]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            self::ALERT_URL.'*' => Http::response('', 204),
        ]);

        $this->handle();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $m, array $c) => str_contains($m, 'the only card for this thread is ARCHIVED') && $c['archived_card_ids'] === [530, 531],
        )->once();
    }

    public function test_the_archived_read_is_not_issued_on_the_by_ref_only_path(): void
    {
        // STATED GAP, pinned rather than implied: a non-prefixed issue under population=all
        // has no tag, and kanban's by-ref endpoint hard-excludes archived rows with no
        // parameter to include them — so there is no archived-visible key to read and the
        // handler issues no tag search at all. A retired by-ref card is still re-created.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        Http::fake([
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle(['sid' => null, 'itype' => 'task', 'title' => 'a plain non-prefixed title']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/search.json'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
    }

    public function test_post_create_collapse_archives_a_raced_duplicate(): void
    {
        // The check-then-create race: pre-create tag search is empty → create; the
        // post-create re-read sees BOTH (a concurrent delivery / the reconcile also
        // carded) → keep lowest id (99), archive the racer (100).
        // One closure, not a URL map with a sequence in it: Laravel INVOKES every stub for
        // every request and keeps the first non-null answer, so a `search.json*` sequence
        // is popped by the DL-296 `archived=1` read as well and the post-create re-read
        // gets the wrong element (or an exhausted sequence).
        Http::fake($this->searchFake(
            live: [[], [['id' => 100, 'board_id' => 8], ['id' => 99, 'board_id' => 8]]],   // pre-create empty → create; post-create: race surfaced
            archived: [],
            rest: [
                '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'archived_at' => '2026-07-14T00:00:00+00:00']]),
                '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            ],
        ));

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_the_collapse_archive_record_reads_the_rows_own_board_not_a_copy_of_the_mapped_one(): void
    {
        // card#7212's divergence control on this arm. The tag arm's collapse used to be handed
        // `array_fill_keys($live, [])` — ids with no card ROW behind them — so `card_board` was
        // null here, a MEASUREMENT rather than a gap to paper over. DL-298 (card#7211) switched
        // the caller to the row-returning `cardRowsByTag` twin so the rows could be re-checked
        // against the mapped board, and the same rows now feed this record: `card_board` is the
        // archived row's own value.
        //
        // ⛔ The values are forced APART through the accepted INTERVAL (DL-292) — the numeric
        // STRING '8' belongs to a mapping of 8 — because a genuinely foreign row is refused
        // before the collapse now. A "fix" echoing `mapped_board` into both slots gives int 8
        // here and reds. `null` remains the primitive's honest answer for a rows-less caller;
        // no caller inside the mapped-board regime is one any more.
        Http::fake($this->searchFake(
            live: [[], [['id' => 100, 'board_id' => '8'], ['id' => 99, 'board_id' => 8]]],
            archived: [],
            rest: [
                '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'archived_at' => '2026-07-14T00:00:00+00:00']]),
                '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            ],
        ));
        Log::spy();

        $this->handle();

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived duplicate card sharing the same correlation key')
            && $ctx['card_id'] === 100 && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    public function test_no_duplicate_after_create_archives_nothing(): void
    {
        Http::fake($this->searchFake(
            live: [[], [['id' => 99, 'board_id' => 8]]],
            archived: [],
            rest: ['*/tasks.json' => Http::response(['data' => ['id' => 99]], 201)],
        ));

        $this->handle();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
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
            && ! isset($r['task'])
            && $r['tags'] === ['type:task']              // NO id: tag on the by-ref path
            && $r['payload'] === ['issue_number' => 4]   // stamped so it is by-ref findable
            && $r['external_link'] === 'https://github.com/org/coord/issues/4');
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
            && ! isset($r['task'])
            && $r['tags'] === ['id:QUERY-4', 'type:query']
            && $r['payload'] === ['issue_number' => 4]);   // dual-keyed
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

    // =====================================================================
    // The lane-derived create stage (card#6371 / DL-286)
    // =====================================================================
    //
    // With `coord_card_lane_stage_ids` configured, a lane-model-governed issue (one
    // whose TITLE starts with `[TASK]` — `classify_coord`'s own gate) is created in
    // the stage its `stage:*` label declares, instead of the fixed
    // `coord_card_stage_id`. Absent/unrecognized ⇒ Later, mirroring the reconcile's
    // `_task_lane`.

    /** @param array<string, mixed> $overrides */
    private function writeLaneMapping(array $overrides = []): void
    {
        $this->writeMapping(array_merge([
            'board_id' => 8,
            'stages' => ['opened' => 50],
            'create_coord_cards' => true,
            'coord_card_stage_id' => 21,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43],
        ], $overrides));
    }

    private function fakeCreate(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            // The by-ref pre-check, for the legs that run under `issue_population: all`
            // (an un-prefixed / `[PROPOSAL]` title has no tag key). Unused by the
            // tag-keyed legs — an unmatched pattern sends nothing.
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);
    }

    /** @param list<string> $labels */
    private function handleTask(array $labels): void
    {
        $this->handle(['sid' => 'TASK-4', 'itype' => 'task', 'title' => '[TASK] do the thing', 'labels' => $labels]);
    }

    private function assertCreatedInStage(int $stageId): void
    {
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['workflow_stage_id'] === $stageId);
    }

    public function test_stage_now_label_creates_the_card_in_the_now_stage(): void
    {
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['stage:now']);

        $this->assertCreatedInStage(40);
    }

    public function test_stage_later_label_creates_the_card_in_the_later_stage(): void
    {
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['stage:later']);

        $this->assertCreatedInStage(42);
    }

    public function test_no_stage_label_creates_the_card_in_the_later_stage(): void
    {
        // `_task_lane`'s default: an undeclared issue is Later, NOT the fixed create stage.
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['from:pm', 'to:all']);

        $this->assertCreatedInStage(42);
    }

    public function test_absent_labels_key_creates_the_card_in_the_later_stage(): void
    {
        // `kanban_coord_card` is always registered, so a custom classifier can emit this
        // target with no `labels` key at all; that must resolve like an unlabelled issue,
        // not crash and not fall back to the fixed stage. (NOT a staged-target/redelivery
        // case — reaction targets are never persisted; replay re-classifies the raw body.)
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handle(['sid' => 'TASK-4', 'itype' => 'task', 'title' => '[TASK] do the thing']);

        $this->assertCreatedInStage(42);
    }

    public function test_unrecognized_stage_label_creates_the_card_in_the_later_stage(): void
    {
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['stage:someday']);

        $this->assertCreatedInStage(42);
    }

    public function test_multiple_stage_labels_resolve_in_lane_order(): void
    {
        // `_task_lane` iterates now→next→later→maybe, so a doubly-labelled issue lands in
        // the same lane on both movers regardless of the label list's own order.
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['stage:later', 'stage:now']);

        $this->assertCreatedInStage(40);
    }

    public function test_declared_lane_missing_from_the_map_falls_back_to_later_and_warns(): void
    {
        // Requirement: a `stage:*` label that resolves to no stage id on the mapped board
        // is a DELIBERATE fallback, never a fail-quiet — an install whose lane model has
        // no Maybe column still gets a card, and the operator gets told which lane went
        // unmapped.
        Log::spy();
        $this->writeLaneMapping(['coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42]]);
        $this->fakeCreate();

        $this->handleTask(['stage:maybe']);

        $this->assertCreatedInStage(42);
        // The CONTEXT is the operator-facing half of the claim (docs/writeback.md: "a WARN
        // names the unmapped lane(s), the lane used, and the lanes you did map") — asserted
        // key by key, since the message literal is deliberately un-interpolated (DL-285).
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => str_contains($m, 'lane') && str_contains($m, 'not mapped')
            && $c['unmapped_lanes'] === ['maybe']
            && $c['created_in_lane'] === 'later'
            && $c['mapped_lanes'] === ['now', 'next', 'later'])->once();
    }

    public function test_an_unmapped_lane_does_not_end_the_scan_the_next_declared_lane_wins(): void
    {
        // `_task_lane` tests availability INSIDE its loop: on a board with no Now column
        // an issue labelled stage:now + stage:next lands in NEXT, not in the Later
        // default. The warn still fires — the unmapped Now is a real config gap — but it
        // reports a create that went to the next lane the issue itself declared.
        Log::spy();
        $this->writeLaneMapping(['coord_card_lane_stage_ids' => ['next' => 41, 'later' => 42, 'maybe' => 43]]);
        $this->fakeCreate();

        $this->handleTask(['stage:now', 'stage:next']);

        $this->assertCreatedInStage(41);
        // `created_in_lane` is the lane the scan actually landed on, NOT the default — the
        // half of the warn that would silently go wrong if the fallback were reintroduced.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => str_contains($m, 'lane') && str_contains($m, 'not mapped')
            && $c['unmapped_lanes'] === ['now']
            && $c['created_in_lane'] === 'next'
            && $c['mapped_lanes'] === ['next', 'later', 'maybe'])->once();
    }

    public function test_a_fully_mapped_lane_derived_create_does_not_warn(): void
    {
        // The two skip legs assert the warn FIRES; both are equally satisfied by a handler
        // that warns on EVERY create. This pins the other direction — the gate is the
        // declared-but-unmapped set, so a fully-mapped create is silent.
        Log::spy();
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handleTask(['stage:now']);

        $this->assertCreatedInStage(40);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_non_task_title_keeps_the_fixed_create_stage(): void
    {
        // `classify_coord` gates the lane model on the TITLE (`startswith("[TASK]")`); a
        // brief/query/review keeps the mapping's fixed stage, so the two movers agree at
        // create.
        $this->writeLaneMapping();
        $this->fakeCreate();

        $this->handle(['labels' => ['stage:later']]);   // default payload is a [QUERY] title

        $this->assertCreatedInStage(21);
    }

    public function test_an_unprefixed_title_keeps_the_fixed_create_stage_even_though_its_itype_is_task(): void
    {
        // The reason the gate is the TITLE and not `itype`: `coordItype()` (like the
        // consumer's `_itype`) calls an un-prefixed title `task` too. Under
        // `issue_population: all` those issues ARE carded — and the consumer's
        // `classify_coord` sends them to Now, not through `_task_lane`. Lane-deriving
        // them here would create them in Later, and `preserve_stage` would freeze that
        // disagreement.
        $this->writeLaneMapping(['issue_population' => 'all']);
        $this->fakeCreate();

        $this->handle(['sid' => null, 'itype' => 'task', 'title' => 'a plain untitled-prefix issue', 'labels' => ['stage:later']]);

        $this->assertCreatedInStage(21);
    }

    public function test_a_proposal_title_keeps_the_fixed_create_stage_even_though_its_itype_is_task(): void
    {
        // Same blind spot, second member: `coordItype()` has no `[PROPOSAL]` arm, so a
        // proposal reads as itype `task` while `classify_coord` does not lane-derive it.
        $this->writeLaneMapping(['issue_population' => 'all']);
        $this->fakeCreate();

        $this->handle(['sid' => null, 'itype' => 'task', 'title' => '[PROPOSAL] adopt the thing', 'labels' => ['stage:now']]);

        $this->assertCreatedInStage(21);
    }

    public function test_without_the_lane_map_a_labelled_task_keeps_the_fixed_create_stage(): void
    {
        // Opt-in: an install that configured no lane stage ids is byte-identical to DL-198.
        $this->fakeCreate();   // setUp's mapping has no coord_card_lane_stage_ids

        $this->handleTask(['stage:later']);

        $this->assertCreatedInStage(21);
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

    // --- card#5968 / DL-285: this handler's refusals are keyed by the coordination ISSUE,
    //     not by a card — the alert body carries `issue_number` with a null `card_id`. ---

    public function test_kanban_4xx_alerts_with_the_issue_number_and_a_null_card_id(): void
    {
        $this->writeMappingWithAlert();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'bad'], 422),
        ]);

        $this->handle();

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'coord_card_create_4xx'
            && $r['repo'] === 'org/coord'
            && $r['outcome'] === 'coord_card_create'
            && $r['card_id'] === null
            && $r['issue_number'] === 4);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $ctx) => str_contains($m, 'kanban refused (4xx)')
            && $ctx['status'] === 422);
    }

    public function test_malformed_payload_alerts_on_the_empty_repo_key(): void
    {
        // repo/itype/title are what is malformed, so the tuple degrades to '' rather than
        // suppressing the signal; the issue number is still readable and is carried.
        $this->writeMappingWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['repo' => null]);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_payload_invalid'
            && $r['repo'] === ''
            && $r['card_id'] === null
            && $r['issue_number'] === 4);
    }

    public function test_empty_sid_under_prefixed_population_alerts(): void
    {
        $this->writeMappingWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['sid' => '']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'coord_card_no_correlation_key'
            && $r['repo'] === 'org/coord'
            && $r['issue_number'] === 4);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
    }

    public function test_absent_writeback_json_still_logs_and_cannot_push(): void
    {
        // The Branch-#3 degradation: the arm routes through the paired primitive, but the
        // notifier loads its channel from the very file that is absent.
        File::delete($this->dir.'/writeback.json');
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'writeback not configured'));
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    public function test_a_5xx_still_throws_and_never_alerts(): void
    {
        // The transient/permanent split is untouched: only a permanent refusal signals.
        $this->writeMappingWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'boom'], 503),
        ]);

        try {
            $this->handle();
            $this->fail('a 5xx must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
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
            && ! isset($r['task'])
            && $r['workflow_stage_id'] === 21
            && $r['tags'] === ['id:QUERY-4', 'type:query']);
    }

    public function test_full_dispatch_lane_derivation_from_the_webhook_labels(): void
    {
        // The residual risk the per-side legs leave open: every lane leg above hands the
        // handler a `labels` list the TEST wrote, and the classifier legs assert the
        // payload key in isolation — neither exercises the WIRE between them. Here the
        // only input is a real `issues.opened` body (GitHub's `labels: [{name: …}]`
        // shape), so a classifier that stopped stamping `labels`, or renamed the key,
        // reds here even though both sides' own legs stay green.
        //
        // The vector is deliberately `stage:now` and NOT `stage:later`: `later` is the
        // no-labels default, so a broken wire would land the card in the same stage as a
        // working one and this leg could not fail (measured — renaming the classifier's
        // `labels` key left a `stage:later` version of it green).
        $this->writeLaneMapping();
        $this->fakeCreate();
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create']]],
        ]);

        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            'issues.opened',
            ['issue' => [
                'number' => 4,
                'title' => '[TASK] do the thing',
                'html_url' => 'https://github.com/org/coord/issues/4',
                'labels' => [['name' => 'from:pm'], ['name' => 'stage:now']],
            ]],
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

        $this->assertCreatedInStage(40);   // the `now` lane's id — not the fixed 21, and not the `later` default
    }
}
