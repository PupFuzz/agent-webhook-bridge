<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ClientCapabilities;
use App\Bridge\Tools\ClientDeclaration;
use App\Bridge\Tools\ClientVersion;
use App\Bridge\Tools\Tool;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\CallingSeatSeal;
use Tests\TestCase;

/**
 * card#10566 / DL-426 — a board-tools refusal gains one sentence telling the caller to update its
 * channel client when the call SENT an accepted argument its reported client version does not
 * declare. Built from the keys the call sent, never from the refusal's wording.
 *
 * ⛔ TEXT ONLY. Every status asserted here is the status the same call answered before this
 * existed (DL-364 Decision 2).
 *
 * The TODAY constants are the literal pre-change sentences from `origin/dev` 20f3d70, NOT rebuilt
 * from the code under test, which would move with it.
 */
class ClientUpdateClauseTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY_LIMIT_REFUSAL = 'board_my_cards: `limit` must be an integer of at least 1 when provided — it is the number of CARDS each list is cut to (default 52). Raising it raises the response size in proportion; narrow with `stage` instead where you can.';

    private const TODAY_UNDECLARED_REFUSAL = 'board_my_cards: unknown argument `lmit`. This tool accepts: `include_description`, `stage`, `limit`, `tag`, `include_terminal`. Nothing was sent to the board — no card was read or written.';

    private const NO_VERSION_LIMIT = ' This call reported no channel-client version. Channel clients before 0.9.15 report none, and some of them do not declare `limit` (first declared by client 0.9.16); if yours is one, update your channel client so its tool schema describes it.';

    private string $dir;

    private string $token = 'tools-bearer-abc123';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/client-update-clause-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        $tokenFile = $this->dir.'/me-tools-token';
        File::put($tokenFile, $this->token);
        chmod($tokenFile, 0o600);
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 7\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$tokenFile}\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
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

    /** @param array<string, mixed> $args */
    private function callTool(string $tool, array $args, ?string $clientVersion): TestResponse
    {
        CallingSeatSeal::forANewServingProcess();
        $body = ['tool' => $tool, 'args' => (object) $args] + ($clientVersion === null ? [] : ['client_version' => $clientVersion]);

        return $this->call('POST', '/agent-tools/call', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], (string) json_encode($body));
    }

    /** Own lane with `$count` cards on stage 50; stage 52 is `lane_type: done`. */
    private function fakeBoard(int $count): void
    {
        $rows = [];
        for ($id = 1; $id <= $count; $id++) {
            $rows[] = ['id' => 4000 + $id, 'name' => "card {$id}", 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                'tags' => ['lane:A'], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => 10];
        }
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'backlog_inventory'],
                ['id' => 52, 'name' => 'Shipped', 'position' => 2, 'lane_type' => 'done'],
            ]]]]]),
            '*/tasks/search.json*' => function ($request) use ($rows) {
                $url = urldecode($request->url());
                if (str_contains($url, '[count]') || str_contains($url, 'per_page=1&')) {
                    return Http::response(['data' => [], 'meta' => ['total' => 0], 'links' => ['next' => null]]);
                }

                return Http::response(['data' => $rows, 'links' => ['next' => null]]);
            },
        ]);
    }

    private function useRegistry(Tool $tool): void
    {
        $registry = new BoardToolsRegistry;
        $registry->register($tool);
        $this->app->instance(BoardToolsRegistry::class, $registry);
        $this->app->forgetInstance(BoardToolDispatcher::class);
    }

    // ─── the outcome ───────────────────────────────────────────────────────────────

    public function test_a_tool_refusal_names_the_sent_argument_the_reported_version_does_not_declare(): void
    {
        Http::fake();

        $this->assertSame(
            self::TODAY_LIMIT_REFUSAL.' Your channel client, version 0.9.12, does not declare `limit` (first declared by client 0.9.16); update your channel client so its tool schema describes it.',
            $this->callTool('board_my_cards', ['limit' => '50'], '0.9.12')->assertStatus(422)->json('error'),
        );
        Http::assertNothingSent();
    }

    /**
     * Every sent accepted key the version lacks, in the order sent, and no other: `include_description`
     * (0.9.0) is declared at 0.9.12, and `stage` / `include_terminal` were not sent.
     */
    public function test_the_unknown_argument_refusal_names_every_sent_argument_the_version_lacks_and_no_other(): void
    {
        Http::fake();

        $this->assertSame(
            self::TODAY_UNDECLARED_REFUSAL.' Your channel client, version 0.9.12, does not declare `limit` (first declared by client 0.9.16) and `tag` (first declared by client 0.9.23); update your channel client so its tool schema describes them.',
            $this->callTool('board_my_cards', ['include_description' => true, 'limit' => 5, 'lmit' => 5, 'tag' => 'x'], '0.9.12')->assertStatus(422)->json('error'),
        );
        Http::assertNothingSent();
    }

    /**
     * No usable version — none sent, or one the door reduced to null (over-long, a character
     * outside its whitelist) — gets the sentence that is true of every such caller.
     */
    public function test_a_call_with_no_usable_version_gets_the_sentence_true_of_clients_that_report_none(): void
    {
        Http::fake();

        foreach ([null, str_repeat('9', 40), '0.9.12 beta'] as $version) {
            $this->assertSame(self::TODAY_LIMIT_REFUSAL.self::NO_VERSION_LIMIT, $this->callTool('board_my_cards', ['limit' => '50'], $version)->assertStatus(422)->json('error'), var_export($version, true));
        }
        $this->assertSame('0.9.15', ClientVersion::FIRST_REPORTING_SNAPSHOT, 'NO_VERSION_LIMIT spells the first reporting client');
    }

    /**
     * Today's bytes for: a client that declares what it sent (the first declaring version and the
     * current one), a version the table cannot order, and a refusal whose call sent no accepted
     * key at all.
     */
    public function test_no_sentence_where_nothing_sent_is_undeclared(): void
    {
        Http::fake();

        foreach (['0.9.16', ClientCapabilities::bundled()->currentClientVersion, '99.0.0', 'v0.9.12', '0.9.12-beta'] as $version) {
            $this->assertSame(self::TODAY_LIMIT_REFUSAL, $this->callTool('board_my_cards', ['limit' => '50'], $version)->assertStatus(422)->json('error'), $version);
        }
        foreach (['0.9.16', '0.9.12', null] as $version) {
            $this->assertSame(
                'board_my_cards: unknown argument `lmit`. This tool accepts: `include_description`, `stage`, `limit`, `tag`, `include_terminal`. Nothing was sent to the board — no card was read or written.',
                $this->callTool('board_my_cards', ['lmit' => 5], $version)->assertStatus(422)->json('error'),
                var_export($version, true),
            );
        }
    }

    // ─── DL-364 Decision 2: the version never changes an outcome ─────────────────────

    /**
     * The same call at every kind of version answers the same status, and the same body up to the
     * sentence: a success that sends an argument the old client does not declare (accepted, same
     * cards), a tool refusal, an unknown-argument refusal and an install-fault read refusal.
     */
    public function test_the_version_changes_no_status_no_acceptance_and_no_text_before_the_sentence(): void
    {
        $versions = [null, '0.5.0', '0.9.12', '0.9.16', ClientCapabilities::bundled()->currentClientVersion, '99.0.0', 'garbage value'];
        $calls = [
            'success' => ['board_my_cards', ['limit' => 3, 'stage' => 50]],
            'tool refusal' => ['board_my_cards', ['limit' => 0]],
            'unknown argument' => ['board_my_cards', ['limit' => 3, 'lmit' => 3]],
            'other tool' => ['board_take_card', []],
        ];
        foreach ($calls as $label => [$tool, $args]) {
            $baseline = null;
            foreach ($versions as $version) {
                $this->fakeBoard(5);
                $res = $this->callTool($tool, $args, $version);
                $body = (array) $res->json();
                if (isset($body['error'])) {
                    $body['error'] = explode(' Your channel client, version ', explode(' This call reported no channel-client version.', (string) $body['error'])[0])[0];
                }
                $observed = [$res->status(), $body];
                $baseline ??= $observed;
                $this->assertSame($baseline, $observed, "{$label} at ".var_export($version, true));
            }
        }
    }

    // ─── install-fault reads never get it ────────────────────────────────────────────

    public function test_an_install_fault_tag_read_refusal_gets_todays_text(): void
    {
        $today = 'board_my_cards: the bridge could not read the cards carrying your `tag` on your board 10 (the board answered 403) — so NO cards were returned — this is not an empty window. This is an INSTALL fault, not something your arguments can fix: '
            ."the bridge's writeback token was recognised but not permitted to READ — kanban gates the API on per-token abilities, and this one lacks `read` (on a card SEARCH board membership does NOT produce a 403: kanban floors the query to the caller's own boards and answers zero rows instead). Retrying will not change it; report it to your operator.";

        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'backlog_inventory'],
            ]]]]]),
            '*/tasks/search.json*' => fn ($request) => str_contains(urldecode($request->url()), 'tags:"lane:A"')
                ? Http::response(['message' => 'Forbidden'], 403)
                : Http::response(['data' => [], 'links' => ['next' => null]]),
        ]);

        foreach ([null, '0.9.12'] as $version) {
            $this->assertSame($today, $this->callTool('board_my_cards', ['tag' => 'lane:A'], $version)->assertStatus(422)->json('error'), var_export($version, true));
        }
    }

    // ─── degrade and guards ─────────────────────────────────────────────────────────

    /**
     * r2-M1: the table fails to load INSIDE a real dispatch (`base_path()` re-pointed at a directory
     * without it). Each refusal keeps its status and today's bytes, the broken deploy is logged once
     * per refusal, and a refusal that sent no accepted key never reads the table. Each call is
     * first made with the table present, so the sentence the degrade drops is seen there.
     */
    public function test_an_unreadable_capability_table_degrades_a_real_dispatch_to_todays_text_not_a_failed_call(): void
    {
        Http::fake();
        $calls = [[['limit' => '50'], null, self::TODAY_LIMIT_REFUSAL], [['limit' => '50'], '0.9.12', self::TODAY_LIMIT_REFUSAL], [['limit' => 5, 'lmit' => 5], '0.9.12', self::TODAY_UNDECLARED_REFUSAL]];
        foreach ($calls as [$args, $version, $today]) {
            $this->assertNotSame($today, $this->callTool('board_my_cards', $args, $version)->assertStatus(422)->json('error'), 'the control: with the table readable, this call carries the sentence');
        }

        $base = $this->app->basePath();
        $bare = $this->dir.'/bare-base';
        File::ensureDirectoryExists($bare);
        $this->assertFileDoesNotExist($bare.'/'.ClientCapabilities::TABLE);
        Log::spy();
        $this->app->setBasePath($bare);
        $this->app->useStoragePath($base.'/storage');
        try {
            foreach ($calls as [$args, $version, $today]) {
                $this->assertSame($today, $this->callTool('board_my_cards', $args, $version)->assertStatus(422)->json('error'), var_export($version, true));
            }
            $this->callTool('board_my_cards', ['lmit' => 5], '0.9.12')->assertStatus(422);
        } finally {
            $this->app->setBasePath($base);
            $this->app->useStoragePath($base.'/storage');
        }

        Log::shouldHaveReceived('warning')->times(count($calls))->withArgs(fn (string $message, array $context): bool => $message === 'agent-tools: client capability table unreadable; refusal carries no client-update clause'
            && str_contains((string) ($context['error'] ?? ''), ClientCapabilities::TABLE.' did not read'));
        Http::assertNothingSent();
    }

    /** A tool the table does not carry has no client history: its refusals are unchanged, never a 500. */
    public function test_an_operator_tool_the_table_does_not_carry_gets_its_refusals_unchanged(): void
    {
        $this->useRegistry(new class implements Tool
        {
            public function name(): string
            {
                return 'operator_tool';
            }

            public function acceptedArguments(): array
            {
                return ['wanted'];
            }

            public function refusedArgumentReason(string $key): ?string
            {
                return null;
            }

            public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
            {
                throw new ToolRefusalException('operator_tool: refuses `wanted`.');
            }
        });
        Http::fake();

        foreach (['0.5.0', null] as $version) {
            $this->assertSame('operator_tool: refuses `wanted`.', $this->callTool('operator_tool', ['wanted' => 1], $version)->assertStatus(422)->json('error'));
            $this->assertSame(
                'operator_tool: unknown argument `nope`. This tool accepts: `wanted`. Nothing was sent to the board — no card was read or written.',
                $this->callTool('operator_tool', ['wanted' => 1, 'nope' => 1], $version)->assertStatus(422)->json('error'),
            );
        }
    }

    /**
     * An operator tool registered under a shipped name: the table is asked per ARGUMENT, so a
     * tabled one (`limit`) is named — true of the client, whose schema is the channel server's —
     * and an untabled one (`wanted`) is skipped rather than thrown on.
     */
    public function test_an_operator_tool_under_a_shipped_name_is_asked_about_per_argument_and_never_throws(): void
    {
        $this->useRegistry(new class implements Tool
        {
            public function name(): string
            {
                return 'board_my_cards';
            }

            public function acceptedArguments(): array
            {
                return ['wanted', 'limit'];
            }

            public function refusedArgumentReason(string $key): ?string
            {
                return null;
            }

            public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
            {
                throw new ToolRefusalException('board_my_cards: the shadow refuses.');
            }
        });
        Http::fake();

        $this->assertSame(
            'board_my_cards: the shadow refuses. Your channel client, version 0.9.12, does not declare `limit` (first declared by client 0.9.16); update your channel client so its tool schema describes it.',
            $this->callTool('board_my_cards', ['wanted' => 1, 'limit' => 5], '0.9.12')->assertStatus(422)->json('error'),
        );
    }

    /**
     * ⭐ THE GUARD, over both refusal paths at a 0.5.0 client (the first with any board tool):
     * every registered tool's unknown-argument refusal with every accepted key sent, and a sample
     * of `board_my_cards` tool refusals. The expected names come from `declares()` over the keys
     * SENT — not from the sentence builder — and the sentence must name exactly those.
     */
    public function test_every_refusal_path_names_exactly_the_sent_arguments_a_0_5_0_client_lacks(): void
    {
        $caps = ClientCapabilities::bundled();
        $registry = new BoardToolsRegistry;
        $checked = 0;
        $assertNames = function (string $tool, array $args, string $error) use ($caps, &$checked): void {
            $accepted = (new BoardToolsRegistry)->resolve($tool)?->acceptedArguments() ?? [];
            $expected = array_values(array_filter(array_keys($args), fn (string $a): bool => in_array($a, $accepted, true) && $caps->declares('0.5.0', $tool, $a) === ClientDeclaration::No));
            $at = strpos($error, ' Your channel client, version 0.5.0, does not declare ');
            if ($expected === []) {
                $this->assertFalse($at, "{$tool}: a sentence with nothing sent undeclared:\n{$error}");

                return;
            }
            $this->assertNotFalse($at, "{$tool}: sent ".implode(', ', $expected)." undeclared at 0.5.0, and no sentence:\n{$error}");
            preg_match_all('/`([a-z_]+)` \(first declared by client ([0-9.]+)\)/', substr($error, $at), $m);
            $this->assertSame($expected, $m[1], "{$tool}: the sentence names a different set:\n{$error}");
            $this->assertSame(array_map(fn (string $a): string => $caps->since($tool, $a), $expected), $m[2]);
            $checked += count($expected);
        };

        foreach ($registry->known() as $tool) {
            $args = array_fill_keys([...($registry->resolve($tool)?->acceptedArguments() ?? []), 'not_an_argument'], 1);
            $this->fakeBoard(1);
            $assertNames($tool, $args, (string) $this->callTool($tool, $args, '0.5.0')->assertStatus(422)->json('error'));
        }

        foreach ([['limit' => '50'], ['include_terminal' => true], ['include_terminal' => 'yes', 'tag' => 'lane:A'], ['tag' => 5], ['include_description' => 'yes'],
            ['stage' => ''], ['stage' => 'Nowhere'], ['tag' => 'lane:A', 'stage' => 52], ['limit' => 0, 'include_description' => true]] as $args) {
            $this->fakeBoard(1);
            $assertNames('board_my_cards', $args, (string) $this->callTool('board_my_cards', $args, '0.5.0')->assertStatus(422)->json('error'));
        }

        $this->assertGreaterThan(0, $checked, 'no argument newer than 0.5.0 was ever sent — the guard measured nothing');
    }
}
