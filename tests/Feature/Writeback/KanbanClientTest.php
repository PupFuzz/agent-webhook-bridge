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
    private function client(): KanbanClient
    {
        return new KanbanClient('https://kanban.example.com/api/v3', 'wb-token');
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

    public function test_find_card_by_dl_number_matches_on_numeric_part(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => '042']],     // leading-zero form → numeric 42
            ['id' => 5, 'payload' => ['dl_number' => 'DL-9']],
            ['id' => 9, 'payload' => ['dl_number' => ['oops']]],  // non-scalar → skipped, not fatal
        ]])]);

        $c = $this->client();
        $this->assertSame(7, $c->findCardByDlNumber(8, 'DL-42'));   // "042" == 42 (leading zero)
        $this->assertSame(5, $c->findCardByDlNumber(8, 'dl-9'));    // case-insensitive token
        $this->assertNull($c->findCardByDlNumber(8, 'DL-420'));     // exact numeric, not substring
        $this->assertNull($c->findCardByDlNumber(8, 'no-digits'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tasks/search.json')
            && str_contains(urldecode($r->url()), 'board_id=8')
            && str_contains(urldecode($r->url()), 'limit=200'));
    }

    public function test_blind_token_zero_cards_logs_a_warning_and_returns_null(): void
    {
        // DL-026: a 0-card board read (token's user not a board member / wrong
        // board_id) is a degraded-but-not-erroring state — make it LOUD, but still
        // return null (the caller's no-op path is unchanged).
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);
        Log::spy();

        $this->assertNull($this->client()->findCardByDlNumber(8, 'DL-42'));

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, '0 cards'));
    }

    public function test_blind_token_warning_also_fires_on_the_dependabot_pr_finder(): void
    {
        // M1: findCardByPrNumber (the dependabot create/move path) shares the
        // primitive, so the same 0-card guard covers it — there a blind token would
        // otherwise CREATE a duplicate card, not just no-op.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);
        Log::spy();

        $this->assertNull($this->client()->findCardByPrNumber(8, 77));

        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, '0 cards'));
    }

    public function test_finds_a_card_beyond_the_first_page(): void
    {
        // DL-028: a card past #200 must still correlate. Page 1 is a full 200
        // non-matching cards; the match sits on page 2.
        $page1 = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, 200));
        $page2 = [['id' => 7777, 'payload' => ['dl_number' => '9999']]];
        Http::fakeSequence('*/tasks/search.json*')
            ->push(['data' => $page1])
            ->push(['data' => $page2]);
        Log::spy();

        $this->assertSame(7777, $this->client()->findCardByDlNumber(8, 'DL-9999'));
        // Fully read across pages, no degradation → silent.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_truncation_warning_fires_at_the_max_pages_ceiling(): void
    {
        // Every page comes back full ⇒ the page walk hits the MAX_PAGES ceiling;
        // cards beyond it are unread — the DL-026 silent-loss class, warned (DL-028).
        $full = array_map(fn (int $i) => ['id' => $i, 'payload' => ['dl_number' => (string) $i]], range(1, KanbanClient::SEARCH_LIMIT));
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => $full])]);
        Log::spy();

        $this->assertNull($this->client()->findCardByDlNumber(8, 'DL-99999'));   // no match in 1..200

        Http::assertSentCount(KanbanClient::MAX_PAGES);   // stopped at the ceiling, didn't run away
        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $msg) => str_contains($msg, 'ceiling'));
    }

    public function test_short_page_break_keys_on_raw_batch_length_not_the_filtered_count(): void
    {
        // Lock the must-fix: a non-array row on a FULL page must not desync the
        // short-page break — 200 RAW rows (199 arrays + 1 junk) is still "full",
        // so page 2 is fetched; the read is complete (not truncated).
        $page1 = array_map(fn (int $i) => ['id' => $i, 'payload' => []], range(1, KanbanClient::SEARCH_LIMIT - 1));
        $page1[] = 'not-an-array-row';   // 200th raw row, filtered out of the result
        Http::fakeSequence('*/tasks/search.json*')
            ->push(['data' => $page1])
            ->push(['data' => [['id' => 5000, 'payload' => []]]]);   // short page ⇒ stop
        Log::spy();

        $read = $this->client()->boardVisibility(8);

        $this->assertSame(200, count($read->cards));   // 199 + 1
        $this->assertFalse($read->truncated);
        Http::assertSentCount(2);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_genuine_no_match_logs_nothing(): void
    {
        // N>0 cards, none matched → a real "PR has no card" — must stay QUIET.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 7, 'payload' => ['dl_number' => '11']],
            ['id' => 8, 'payload' => ['dl_number' => '12']],
        ]])]);
        Log::spy();

        $this->assertNull($this->client()->findCardByDlNumber(8, 'DL-42'));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_board_visibility_returns_filtered_cards_and_no_truncation(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 1, 'payload' => []],
            ['id' => 2, 'payload' => []],
            'not-an-array-row',   // filtered out
        ]])]);

        $read = $this->client()->boardVisibility(8);

        $this->assertSame(2, count($read->cards));
        $this->assertFalse($read->truncated);   // 3 raw rows < 200 ⇒ single page, complete
    }

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

    public function test_board_swimlane_ids_reads_the_preload_endpoint(): void
    {
        Http::fake(['*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [
            ['id' => 31, 'name' => 'repo-a'], ['id' => 32, 'name' => 'repo-b'], 'bad-row',
        ]]])]);

        $this->assertSame([31, 32], $this->client()->boardSwimlaneIds(8));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/boards/8/preload.json'));
    }
}
