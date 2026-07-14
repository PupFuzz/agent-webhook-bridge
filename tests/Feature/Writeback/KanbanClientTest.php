<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KanbanClientTest extends TestCase
{
    private function client(string $correlation = 'scan'): KanbanClient
    {
        return new KanbanClient('https://kanban.example.com/api/v3', 'wb-token', $correlation);
    }

    public function test_get_card_returns_the_data_envelope(): void
    {
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])]);

        $card = $this->client()->getCard(5);

        $this->assertSame(8, $card['board_id']);
        $this->assertSame(49, $card['workflow_stage_id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/5.json')
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
    }

    public function test_move_card_patches_workflow_stage_only(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 5]])]);

        $this->client()->moveCard(5, 52);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 52]);   // column-only, no other fields
    }

    public function test_non_2xx_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);
        $this->expectException(RequestException::class);
        $this->client()->moveCard(5, 52);
    }

    public function test_archive_card_sends_the_action_verb_not_a_field_write(): void
    {
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'archived_at' => '2026-06-19T00:00:00+00:00']])]);

        $this->assertTrue($this->client()->archiveCard(5));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['_action'] === 'archive'      // top-level lifecycle verb, NOT task.archived_at
            && ! isset($r['task']));
    }

    public function test_archive_card_returns_false_when_the_response_shows_it_did_not_archive(): void
    {
        // A 200 with a null archived_at is the silent-no-op contract break (the
        // field-write trap). archiveCard reports it (false) rather than throwing —
        // a deterministic failure must not 5xx-storm; the caller logs + no-ops.
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'archived_at' => null]])]);

        $this->assertFalse($this->client()->archiveCard(5));
    }

    // ---- scan mode (default) ----

    public function test_scan_correlate_dl_matches_on_numeric_part_returns_all(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => '042']],     // leading-zero form → numeric 42
            ['id' => 11, 'payload' => ['dl_number' => 'DL-42']],  // SAME canonical → N:1, both returned
            ['id' => 5, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 9, 'payload' => ['dl_number' => ['oops']]],  // non-scalar → skipped, not fatal
        ]])]);

        $c = $this->client();
        $this->assertEqualsCanonicalizing([7, 11], $c->correlateDl(8, 'DL-42'));   // ALL matches (DL-148)
        $this->assertSame([5], $c->correlateDl(8, 'dl-9'));     // case-insensitive token
        $this->assertSame([], $c->correlateDl(8, 'DL-420'));    // exact numeric, not substring
        $this->assertSame([], $c->correlateDl(8, 'no-digits'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json')
            && str_contains(urldecode($r->url()), 'board_id=8')
            && str_contains(urldecode($r->url()), 'limit=200'));
    }

    public function test_scan_blind_token_zero_cards_logs_a_warning_and_returns_empty(): void
    {
        // DL-026: a 0-card board read (token's user not a board member / wrong
        // board_id) is a degraded-but-not-erroring state — make it LOUD, but still
        // return [] (the caller's no-op path is unchanged).
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);
        Log::spy();

        $this->assertSame([], $this->client()->correlateDl(8, 'DL-42'));

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, '0 cards'));
    }

    public function test_scan_blind_token_warning_also_fires_on_the_dependabot_pr_finder(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);
        Log::spy();

        $this->assertSame([], $this->client()->correlatePr(8, 77));

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, '0 cards'));
    }

    public function test_scan_finds_a_card_beyond_the_first_page(): void
    {
        // DL-028: a card past #200 must still correlate. Page 1 is a full 200
        // non-matching cards (incl. a junk row so the raw-batch-length break is
        // exercised); the match sits on page 2.
        $page1 = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, KanbanClient::SEARCH_LIMIT - 1));
        $page1[] = 'not-an-array-row';   // 200th raw row → still a full page
        $page2 = [['id' => 7777, 'payload' => ['dl_number' => '9999']]];
        Http::fakeSequence('*/tasks/search.json*')->push(['data' => $page1])->push(['data' => $page2]);
        Log::spy();

        $this->assertSame([7777], $this->client()->correlateDl(8, 'DL-9999'));
        Http::assertSentCount(2);
        Log::shouldNotHaveReceived('warning');   // fully read across pages → silent
    }

    public function test_scan_truncation_warning_fires_at_the_max_pages_ceiling(): void
    {
        $full = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, KanbanClient::SEARCH_LIMIT));
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => $full])]);
        Log::spy();

        $this->assertSame([], $this->client()->correlateDl(8, 'DL-99999'));   // no match in 1..200

        Http::assertSentCount(KanbanClient::MAX_PAGES);   // stopped at the ceiling, didn't run away
        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'ceiling'));
    }

    public function test_scan_stops_on_links_next_null_when_the_kanban_serves_links(): void
    {
        // DL-146: when the kanban serves pagination `links`, scan stops on
        // links.next === null rather than the short-page heuristic. A FULL first
        // page carrying a next link pages on; the second page's null next stops it.
        $page1 = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, KanbanClient::SEARCH_LIMIT));
        $page2 = [['id' => 7777, 'payload' => ['dl_number' => '9999']]];
        Http::fakeSequence('*/tasks/search.json*')
            ->push(['data' => $page1, 'links' => ['next' => 'https://kanban.example.com/api/v3/tasks/search.json?page=2']])
            ->push(['data' => $page2, 'links' => ['next' => null]]);
        Log::spy();

        $this->assertSame([7777], $this->client()->correlateDl(8, 'DL-9999'));
        Http::assertSentCount(2);
        Log::shouldNotHaveReceived('warning');   // fully read across pages → silent
    }

    public function test_scan_full_page_with_null_next_stops_without_an_extra_request(): void
    {
        // A board sized at an exact multiple of SEARCH_LIMIT: links.next === null on
        // a FULL page stops immediately — no wasted extra request, and no false
        // truncation warning (the short-page heuristic alone couldn't distinguish
        // this from a truncated read).
        $full = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, KanbanClient::SEARCH_LIMIT));
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => $full, 'links' => ['next' => null]])]);
        Log::spy();

        $this->assertSame([42], $this->client()->correlateDl(8, 'DL-42'));
        Http::assertSentCount(1);                // stopped on links.next, no page 2
        Log::shouldNotHaveReceived('warning');   // full page + null next ⇒ not truncated
    }

    public function test_scan_genuine_no_match_logs_nothing(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => '11']],
            ['id' => 8, 'payload' => ['dl_number' => '12']],
        ]])]);
        Log::spy();

        $this->assertSame([], $this->client()->correlateDl(8, 'DL-42'));

        Log::shouldNotHaveReceived('warning');
    }

    // ---- ref mode (DL-029 cutover) ----

    public function test_ref_correlate_dl_queries_by_ref_and_returns_collection(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [
            ['id' => 42], ['id' => 43],   // N:1 — a bundled DL tracks two cards
        ]])]);

        $ids = $this->client('ref')->correlateDl(8, 'DL-28');

        $this->assertSame([42, 43], $ids);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/boards/8/tasks/by-ref.json')
            && str_contains(urldecode($r->url()), 'system=dl')
            && str_contains(urldecode($r->url()), 'ref=DL-28'));   // raw token; server canonicalizes
    }

    public function test_ref_correlate_pr_queries_by_ref_with_github_pr_system(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 99]]])]);

        $this->assertSame([99], $this->client('ref')->correlatePr(8, 85));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'system=github_pr')
            && str_contains(urldecode($r->url()), 'ref=85'));
    }

    public function test_ref_no_match_returns_empty(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $this->assertSame([], $this->client('ref')->correlateDl(8, 'DL-1'));
    }

    public function test_ref_correlate_passes_repo_as_canonical_source_qualifier(): void
    {
        // DL-167: on a multi-repo board, the repo is sent as the kanban `source`
        // dimension (kanban DL-163), canonicalized (trim + lower-case), so a bare
        // ref that collides across repos resolves to THIS repo's card only.
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 7]]])]);

        $this->client('ref')->correlatePr(8, 85, 'Octo/Web');
        $this->client('ref')->correlateDl(8, 'DL-28', 'Octo/Web');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'system=github_pr')
            && str_contains(urldecode($r->url()), 'source=octo/web'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'system=dl')
            && str_contains(urldecode($r->url()), 'source=octo/web'));
    }

    public function test_ref_correlate_omits_source_when_no_repo_given(): void
    {
        // Back-compat: no repo ⇒ no `source` query key (a pre-DL-163 kanban, and
        // the single-repo case, behave exactly as before).
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);

        $this->client('ref')->correlatePr(8, 85);
        Http::assertSent(fn (Request $r) => ! str_contains(urldecode($r->url()), 'source='));
    }

    public function test_ref_mode_does_not_scan_the_board(): void
    {
        Http::fake([
            '*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 1]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $this->client('ref')->correlateDl(8, 'DL-1');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json'));
    }

    // ---- visibility probe (bridge:check) ----

    public function test_visibility_reads_pagination_total_with_a_single_row(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 137]])]);

        $this->assertSame(['total' => 137, 'exact' => true], $this->client()->visibility(8));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json')
            && str_contains(urldecode($r->url()), 'board_id=8')
            && str_contains(urldecode($r->url()), 'limit=1'));   // cheap — one row
    }

    public function test_visibility_blind_token_reports_zero_total(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->assertSame(['total' => 0, 'exact' => true], $this->client()->visibility(8));
    }

    public function test_visibility_without_meta_falls_back_to_row_count_for_a_healthy_board(): void
    {
        // Pre-DL-146 kanban (no meta): a healthy board (>=1 row) must NOT be
        // misreported as blind — fall back to the row count, exact=false.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]]])]);
        $this->assertSame(['total' => 1, 'exact' => false], $this->client()->visibility(8));
    }

    public function test_visibility_without_meta_reports_zero_for_a_blind_or_empty_board(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);
        $this->assertSame(['total' => 0, 'exact' => false], $this->client()->visibility(8));
    }

    public function test_by_ref_available_true_on_200(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => []])]);
        $this->assertTrue($this->client()->byRefAvailable(8));
    }

    public function test_by_ref_available_false_on_404_route_missing(): void
    {
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->assertFalse($this->client()->byRefAvailable(8));   // pre-by-ref kanban
    }

    public function test_default_correlation_mode_is_ref(): void
    {
        // DL-031: constructed without an explicit mode → ref (hits by-ref, not scan).
        Http::fake(['*/boards/8/tasks/by-ref.json*' => Http::response(['data' => [['id' => 9]]])]);

        $c = new KanbanClient('https://kanban.example.com/api/v3', 'wb-token');   // no 3rd arg
        $this->assertSame([9], $c->correlateDl(8, 'DL-9'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tasks/by-ref.json'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json'));
    }

    // ---- create / swimlane (unchanged) ----

    public function test_create_card_with_swimlane_sends_swimlane_id(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 7]], 201)]);

        $this->client()->createCard(8, 50, 'x', ['pr_number' => 1], ['dependencies'], 31);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ($r['task']['swimlane_id'] ?? null) === 31);
    }

    public function test_create_card_without_swimlane_omits_the_key(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 7]], 201)]);

        $this->client()->createCard(8, 50, 'x', [], []);   // no swimlane

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ! array_key_exists('swimlane_id', $r['task']));
    }

    public function test_create_card_dependabot_caller_omits_the_new_coord_fields(): void
    {
        // DL-198: the dependabot caller passes none of the new trailing params, so
        // the POST body must be byte-identical — no description/priority/external_*.
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 7]], 201)]);

        $this->client()->createCard(8, 50, 'x', ['pr_number' => 1], ['dependencies'], 31);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ! array_key_exists('description', $r['task'])
            && ! array_key_exists('priority', $r['task'])
            && ! array_key_exists('external_id', $r['task'])
            && ! array_key_exists('external_link', $r['task']));
    }

    public function test_create_card_sets_the_new_coord_fields_when_given(): void
    {
        // DL-198: the coord path sets description/priority/external_link as top-level
        // Task fields (external_id is deliberately NOT set — see createCard docblock).
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->client()->createCard(8, 21, '[QUERY] x', [], ['id:QUERY-4', 'type:query'], null, 'Coordination thread o/r#4', 0, 'https://github.com/o/r/issues/4');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['description'] === 'Coordination thread o/r#4'
            && $r['task']['priority'] === 0
            && ! array_key_exists('external_id', $r['task'])
            && $r['task']['external_link'] === 'https://github.com/o/r/issues/4'
            && $r['task']['tags'] === ['id:QUERY-4', 'type:query']
            && ! array_key_exists('swimlane_id', $r['task']));
    }

    public function test_create_card_priority_zero_is_still_sent(): void
    {
        // priority 0 (a non-brief coord card) is a real value, distinct from the
        // dependabot null-omit — it must appear in the POST.
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 9]], 201)]);

        $this->client()->createCard(8, 21, 'x', [], [], null, 'd', 0, 'https://x');

        Http::assertSent(fn (Request $r) => array_key_exists('priority', $r['task']) && $r['task']['priority'] === 0);
    }

    // ---- cardsByTag (DL-198 coord-card adoption key) ----

    public function test_cards_by_tag_searches_by_board_and_tag_and_returns_ids(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 42], ['id' => 7], 'bad-row']])]);

        $ids = $this->client()->cardsByTag(8, 'id:QUERY-4');

        $this->assertSame([42, 7], $ids);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/tasks/search.json')
            && str_contains(urldecode($r->url()), 'board_id=8 tags:"id:QUERY-4"'));
    }

    public function test_cards_by_tag_no_match_returns_empty(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->assertSame([], $this->client()->cardsByTag(8, 'id:TASK-9'));
    }

    public function test_board_swimlane_ids_reads_the_preload_endpoint(): void
    {
        Http::fake(['*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [
            ['id' => 31, 'name' => 'repo-a'], ['id' => 32, 'name' => 'repo-b'], 'bad-row',
        ]]])]);

        $this->assertSame([31, 32], $this->client()->boardSwimlaneIds(8));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/boards/8/preload.json'));
    }
}
