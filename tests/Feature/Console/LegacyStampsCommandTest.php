<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `bridge:legacy-stamps` (card#11987): the count that says when the legacy `created-by:` / `idem:`
 * tag read can be retired. What this pins: each count is kanban's own total for the exact tag on the
 * agent's own board, on BOTH sides of the archive switch; a board that could not be read is
 * `unmeasured` and never a zero; and the closing line names its population and no fleet.
 */
class LegacyStampsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/legacy-stamps-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'a-token');   // gitleaks:allow — test fixture
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

    private function agent(string $name, int $board): void
    {
        File::put("{$this->dir}/{$name}-tools-token", "t-{$name}");
        chmod("{$this->dir}/{$name}-tools-token", 0o600);
        File::put("{$this->dir}/{$name}.yml", "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n"
            ."  auth:\n    token_path: {$this->dir}/{$name}-tools-token\n  board_id: {$board}\n  swimlane_id: 4\n  create_stage_id: 50\n");
    }

    /** @return array{0: int, 1: string} */
    private function runCommand(): array
    {
        $exit = Artisan::call('bridge:legacy-stamps');

        return [$exit, Artisan::output()];
    }

    public function test_it_counts_each_legacy_tag_on_both_sides_of_the_archive_switch_and_says_what_the_board_registers(): void
    {
        $this->agent('me', 10);
        $totals = [
            'tags:"created-by:me"' => [12, 3],
            'tags:"idem:me:*"' => [4, 0],
        ];
        Http::fake(function (Request $r) use ($totals) {
            $url = urldecode($r->url());
            if (str_contains($url, '/boards/10/custom_fields.json')) {
                return Http::response(['data' => [['key' => 'created_by', 'type' => 'string', 'is_filterable' => true]]]);
            }
            foreach ($totals as $term => [$live, $archived]) {
                if (str_contains($url, 'q=board_id=10 '.$term)) {
                    return Http::response(['data' => [], 'meta' => ['total' => str_contains($url, 'archived=1') ? $archived : $live]]);
                }
            }

            return Http::response('unexpected '.$url, 500);
        });

        [$exit, $out] = $this->runCommand();

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('me (board 10): created-by: 12 live, 3 archived; idem: 4 live, 0 archived; registers created_by: yes, idempotency_key (string, filterable): NO', $out);
        $this->assertStringContainsString('1 agents with a board on this box, 1 measured, 0 unmeasured; 19 legacy-tagged cards, 1 measured boards still minting tags. Fleet-wide: not derivable.', $out);
    }

    public function test_a_board_that_cannot_be_read_is_unmeasured_never_zero_and_the_exit_says_so(): void
    {
        $this->agent('me', 10);
        $this->agent('other', 11);
        Http::fake(function (Request $r) {
            $url = urldecode($r->url());
            if (str_contains($url, '/boards/11/')) {
                return Http::response('down', 503);
            }
            if (str_contains($url, '/custom_fields.json')) {
                return Http::response(['data' => [
                    ['key' => 'created_by', 'type' => 'string', 'is_filterable' => true],
                    ['key' => 'idempotency_key', 'type' => 'string', 'is_filterable' => true],
                ]]);
            }

            return Http::response(['data' => [], 'meta' => ['total' => 0]]);
        });

        [$exit, $out] = $this->runCommand();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('other (board 11): unmeasured', $out);
        $this->assertStringContainsString('me (board 10): created-by: 0 live, 0 archived; idem: 0 live, 0 archived; registers created_by: yes, idempotency_key (string, filterable): yes', $out);
        $this->assertStringContainsString('2 agents with a board on this box, 1 measured, 1 unmeasured; 0 legacy-tagged cards, 0 measured boards still minting tags.', $out);
    }

    public function test_a_kanban_that_gives_no_count_is_unmeasured(): void
    {
        $this->agent('me', 10);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'custom_fields.json')
            ? Http::response(['data' => []])
            : Http::response(['data' => []]));

        [$exit, $out] = $this->runCommand();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('carried no count', $out);
    }

    public function test_an_install_with_no_board_tools_agent_has_no_population_and_exits_non_zero(): void
    {
        File::put("{$this->dir}/plain.yml", "subscriptions: []\n");
        Http::fake();

        [$exit, $out] = $this->runCommand();

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('there is no population to count', $out);
        Http::assertNothingSent();
    }
}
