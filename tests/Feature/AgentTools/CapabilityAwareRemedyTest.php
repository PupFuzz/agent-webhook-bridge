<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardMyCardsTool;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\CallerClient;
use App\Bridge\Tools\ClientCapabilities;
use App\Bridge\Tools\ClientDeclaration;
use App\Bridge\Tools\RemedyText;
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
 * card#10566 / DL-426 — a caller whose channel client is too old to have DECLARED an argument
 * is told so, in the text it already receives, WITHOUT losing the escape — and is told that
 * updating the client is the reliable fix, never that passing the argument works: an old
 * client's own schema (`additionalProperties: false`) may stop it being sent (DL-426).
 *
 * ⛔ TEXT ONLY. Every test here asserts on a message or a `remedy`, and every status it asserts
 * is the status the same call answered before this existed — the version is never a reason to
 * refuse, accept or reshape.
 *
 * The TODAY constants are the literal pre-change sentences copied from `origin/dev` 20f3d70, NOT
 * rebuilt from the tool's constants: a client that declares the arguments must get these bytes,
 * and a baseline derived from the code under test would move with it.
 */
class CapabilityAwareRemedyTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY_LANE_REMEDY = 'this list was cut to the newest `limit` of `total` cards; to see more, narrow with `stage` (one column: an id or name from `board_stages`) or raise `limit` (the response grows in proportion)';

    private const TODAY_COORD_REMEDY = 'this list was cut to the newest `limit` of `total` cards; to see more, raise `limit` (the response grows in proportion). `stage` does not narrow this list: these cards are on the coordination board, whose columns are not yours';

    private const TODAY_LIMIT_REFUSAL = 'board_my_cards: `limit` must be an integer of at least 1 when provided — it is the number of CARDS each list is cut to (default 52). Raising it raises the response size in proportion; narrow with `stage` instead where you can.';

    private const TODAY_UNDECLARED_REFUSAL = 'board_my_cards: unknown argument `lmit`. This tool accepts: `include_description`, `stage`, `limit`, `tag`, `include_terminal`. Nothing was sent to the board — no card was read or written.';

    /** How each form of the clause opens — the version-named one and the could-not-read one. */
    private const CLAUSES = [' ⚠ Your channel client is version ', ' ⚠ This bridge could not read a client version '];

    private string $dir;

    private string $token = 'tools-bearer-abc123';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/capability-remedy-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        $this->writeAgent();
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

    private function writeAgent(string $extra = ''): void
    {
        $tokenFile = $this->dir.'/me-tools-token';
        File::put($tokenFile, $this->token);
        chmod($tokenFile, 0o600);
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 7\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$tokenFile}\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n".$extra);
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

    /**
     * Own lane, shared lane 9 and the coordination board 12, each with `$count` cards; stage 52
     * is `lane_type: done`, so it is the terminal column the `tag` read leaves out.
     */
    private function fakeBoard(int $count): void
    {
        $rows = static function (int $lane, int $board, int $stage) use ($count): array {
            $out = [];
            for ($id = 1; $id <= $count; $id++) {
                $out[] = ['id' => $lane * 1000 + $id, 'name' => "card {$id}", 'workflow_stage_id' => $stage, 'swimlane_id' => $lane,
                    'tags' => ['lane:A', 'repo:me'], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => $board];
            }

            return $out;
        };
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'backlog_inventory'],
                ['id' => 52, 'name' => 'Shipped', 'position' => 2, 'lane_type' => 'done'],
            ]]]]]),
            '*/boards/12/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 70, 'name' => 'Inbox', 'position' => 1]]]]]]),
            '*/tasks/search.json*' => function ($request) use ($rows) {
                $url = urldecode($request->url());
                if (str_contains($url, 'board_id=12')) {
                    return Http::response(['data' => $rows(4, 12, 70), 'links' => ['next' => null]]);
                }
                if (str_contains($url, '[count]') || str_contains($url, 'per_page=1&')) {
                    return Http::response(['data' => [], 'meta' => ['total' => 0], 'links' => ['next' => null]]);
                }

                return Http::response(['data' => $rows(str_contains($url, 'swimlane_id=9') ? 9 : 4, 10, 50), 'links' => ['next' => null]]);
            },
        ]);
    }

    private function current(): string
    {
        return ClientCapabilities::bundled()->currentClientVersion;
    }

    /** The one sentence a test is about: the escape, then everything after it. */
    private static function splitAtClause(string $text): array
    {
        foreach (self::CLAUSES as $opening) {
            $at = strpos($text, $opening);
            if ($at !== false) {
                return [substr($text, 0, $at), substr($text, $at)];
            }
        }

        return [$text, ''];
    }

    // ─── the design's four named cases ──────────────────────────────────────────────

    /**
     * ⭐ THE CASE THIS EXISTS FOR, as a client older than 0.9.15 actually produces it: told by a
     * truncated window to "raise `limit`", with no schema saying what `limit` is, it sends a
     * string. The refusal keeps its sentence and adds what the caller could not know — the
     * type, the version that declares it, and why the bridge cannot tell which version it is.
     */
    public function test_a_client_reporting_no_version_sending_a_string_limit_is_told_the_type_and_why(): void
    {
        Http::fake();

        $error = (string) $this->callTool('board_my_cards', ['limit' => '50'], null)->assertStatus(422)->json('error');

        [$head, $clause] = self::splitAtClause($error);
        $this->assertSame(self::TODAY_LIMIT_REFUSAL, $head, 'the escape was reworded or removed');
        $this->assertStringContainsString('This bridge could not read a client version for this call', $clause);
        $this->assertStringContainsString('`limit` (an unquoted integer; first declared by client 0.9.16)', $clause);
        $this->assertStringContainsString('`stage` (an unquoted integer or a string; first declared by client 0.9.16)', $clause);
        $this->assertStringContainsString('update your channel client, which is the reliable fix', $clause);
        $this->assertStringNotContainsString('works when passed', $clause, 'the clause may not assert what an old schema can falsify (r1-m3)');
        Http::assertNothingSent();
    }

    /**
     * r1-m2: a version the door REFUSED as a value (over-long, a character outside the whitelist)
     * reaches the bridge as null, exactly like none sent — and the clause says only what the
     * bridge knows, so it is true for both, and for a hand-run caller with no client at all.
     */
    public function test_a_version_the_door_refused_gets_the_could_not_read_clause_naming_no_cause(): void
    {
        Http::fake();

        foreach ([str_repeat('9', 40), '0.9.12 beta', null] as $version) {
            [, $clause] = self::splitAtClause((string) $this->callTool('board_my_cards', ['limit' => '50'], $version)->assertStatus(422)->json('error'));
            $this->assertStringStartsWith(' ⚠ This bridge could not read a client version for this call, so it cannot tell whether your tool schema offers ', $clause, var_export($version, true));
            $this->assertStringNotContainsString('older than', $clause);
            $this->assertStringNotContainsString('package.json', $clause);
        }
    }

    public function test_a_client_reporting_0_9_12_is_told_its_own_version_and_the_one_that_declares_the_argument(): void
    {
        Http::fake();

        $error = (string) $this->callTool('board_my_cards', ['limit' => '50'], '0.9.12')->assertStatus(422)->json('error');

        [$head, $clause] = self::splitAtClause($error);
        $this->assertSame(self::TODAY_LIMIT_REFUSAL, $head);
        $this->assertStringContainsString('Your channel client is version 0.9.12, which does not declare', $clause);
        $this->assertStringContainsString('`limit` (an unquoted integer; first declared by client 0.9.16)', $clause);
        $this->assertStringNotContainsString('could not read a client version', $clause);
    }

    public function test_a_truncated_window_for_an_old_client_keeps_the_escape_and_adds_the_gap_clause(): void
    {
        $this->fakeBoard(60);

        $window = $this->callTool('board_my_cards', [], '0.9.12')->assertStatus(200)->json('result.cards_window');

        $this->assertTrue($window['truncated']);
        [$head, $clause] = self::splitAtClause((string) $window['remedy']);
        $this->assertSame(self::TODAY_LANE_REMEDY, $head, 'the `stage`/`limit` escape must survive whole — the bridge accepts them from this client (whether it sends them is unmeasured, DL-426)');
        $this->assertStringContainsString('version 0.9.12', $clause);
        $this->assertStringContainsString('`limit` (an unquoted integer; first declared by client 0.9.16)', $clause);
        $this->assertStringContainsString('`stage` (an unquoted integer or a string; first declared by client 0.9.16)', $clause);
    }

    /**
     * The regression half: a client that declares what the text names gets today's bytes — on
     * the window, the tool refusal and the dispatcher's own refusal. Asserted at the FIRST
     * version declaring `stage` and `limit` (the boundary) and at the current one.
     */
    public function test_a_client_that_declares_the_arguments_gets_todays_text_byte_for_byte(): void
    {
        foreach (['0.9.16', $this->current()] as $version) {
            $this->fakeBoard(60);
            $this->writeAgent("  coord_board_id: 12\n  address_tags:\n    - repo:me\n");

            $result = $this->callTool('board_my_cards', [], $version)->assertStatus(200)->json('result');
            $this->assertSame(self::TODAY_LANE_REMEDY, $result['cards_window']['remedy'], "own lane at {$version}");
            $this->assertSame(self::TODAY_COORD_REMEDY, $result['coord_cards_window']['remedy'], "coord at {$version}");

            $this->assertSame(self::TODAY_LIMIT_REFUSAL, $this->callTool('board_my_cards', ['limit' => '50'], $version)->assertStatus(422)->json('error'), "limit refusal at {$version}");
            $this->assertSame(self::TODAY_UNDECLARED_REFUSAL, $this->callTool('board_my_cards', ['limit' => 5, 'lmit' => 5], $version)->assertStatus(422)->json('error'), "undeclared refusal at {$version}");
        }
    }

    // ─── the dispatcher's safety net ────────────────────────────────────────────────

    /**
     * An undeclared-argument refusal ends with the clause for the ACCEPTED keys this call sent
     * that its client does not declare — `limit` here — and names nothing else: the accepted-set
     * list is not fed to it, so `tag` and `include_terminal` (not sent) stay out.
     */
    public function test_the_undeclared_argument_refusal_names_the_accepted_keys_the_call_sent_that_its_client_lacks(): void
    {
        Http::fake();

        $error = (string) $this->callTool('board_my_cards', ['limit' => 5, 'lmit' => 5], '0.9.12')->assertStatus(422)->json('error');

        [$head, $clause] = self::splitAtClause($error);
        $this->assertSame(self::TODAY_UNDECLARED_REFUSAL, $head);
        $this->assertStringContainsString('does not declare `limit` (an unquoted integer; first declared by client 0.9.16), so your tool schema does not offer it.', $clause);
        $this->assertStringNotContainsString('`tag`', $clause);
        $this->assertStringNotContainsString('`include_terminal`', $clause);
        Http::assertNothingSent();
    }

    // ─── where there is nothing honest to add ───────────────────────────────────────

    /**
     * A reported version the table cannot answer for — newer than any this checkout records, or
     * not bare `X.Y.Z` — cannot be shown to lack anything, so it gets today's text. It is NOT the
     * "could not read a client version" case: the client did report one the door kept.
     */
    public function test_a_version_the_table_cannot_answer_for_gets_todays_text(): void
    {
        Http::fake();

        foreach (['99.0.0', 'v0.9.12', '0.9.12-beta'] as $version) {
            $this->assertSame(self::TODAY_LIMIT_REFUSAL, $this->callTool('board_my_cards', ['limit' => '50'], $version)->assertStatus(422)->json('error'), $version);
        }
    }

    /**
     * r2-M1: the table fails to load INSIDE a real dispatch — `base_path()` re-pointed at a directory
     * with no `resources/client-capabilities.json`, so `ClientCapabilities::bundled()` throws where
     * the deploy would. The call keeps its status and today's bytes, and the broken deploy is logged.
     * Each call is first made with the table present, so the clause the degrade drops is seen there.
     */
    public function test_an_unreadable_capability_table_degrades_a_real_dispatch_to_todays_text_not_a_failed_call(): void
    {
        Http::fake();
        $calls = [[['limit' => '50'], null, self::TODAY_LIMIT_REFUSAL], [['limit' => '50'], '0.9.12', self::TODAY_LIMIT_REFUSAL], [['limit' => 5, 'lmit' => 5], '0.9.12', self::TODAY_UNDECLARED_REFUSAL]];
        foreach ($calls as [$args, $version, $today]) {
            $this->assertNotSame($today, $this->callTool('board_my_cards', $args, $version)->assertStatus(422)->json('error'), 'the control: with the table readable, this call carries a clause');
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
        } finally {
            $this->app->setBasePath($base);
            $this->app->useStoragePath($base.'/storage');
        }

        Log::shouldHaveReceived('warning')->times(count($calls))->withArgs(fn (string $message, array $context): bool => $message === 'agent-tools: client capability table unreadable; remedy text carries no client-version clause'
            && str_contains((string) ($context['error'] ?? ''), ClientCapabilities::TABLE.' did not read'));
        Http::assertNothingSent();
    }

    /** The unit half: `RemedyText` handed no table adds nothing, and handed one, adds the clause. */
    public function test_remedy_text_adds_no_clause_when_handed_no_table(): void
    {
        $tool = (new BoardToolsRegistry)->resolve('board_my_cards');
        $this->assertNotNull($tool);

        $this->assertSame(self::TODAY_LIMIT_REFUSAL, RemedyText::advise(new CallerClient(null, null), $tool, self::TODAY_LIMIT_REFUSAL));
        $this->assertNotSame(self::TODAY_LIMIT_REFUSAL, RemedyText::advise(new CallerClient(null, ClientCapabilities::bundled()), $tool, self::TODAY_LIMIT_REFUSAL), 'the control: with the table, the same call does carry a clause');
    }

    /**
     * An operator-registered tool is in no client's history, so no version can be said to lack
     * its arguments — and asking the table about it would throw, turning a refusal into a 500.
     */
    public function test_an_operator_registered_tool_gets_no_clause_and_does_not_throw(): void
    {
        $tool = new class implements Tool
        {
            public function name(): string
            {
                return 'operator_tool';
            }

            public function acceptedArguments(): array
            {
                return ['wanted'];
            }

            public function argumentTypes(): array
            {
                return ['wanted' => 'string'];
            }

            public function refusedArgumentReason(string $key): ?string
            {
                return null;
            }

            public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName, CallerClient $caller): array
            {
                return [];
            }
        };

        foreach (['0.5.0', null] as $version) {
            $this->assertSame('', RemedyText::gapClause(new CallerClient($version, ClientCapabilities::bundled()), $tool, ['wanted']));
        }
    }

    /**
     * PR #801 r1-m1: `BoardToolsRegistry::register()` replaces a tool BY NAME, so an operator tool
     * can shadow `board_my_cards` and declare types the clause cannot phrase — here, none for
     * `limit`. Asking the table by NAME would call that tool shipped and throw out of the
     * refusal arm, turning a 422 into a 500. Both refusal paths must answer exactly as they would
     * without the clause.
     */
    public function test_an_operator_tool_shadowing_a_shipped_name_gets_its_refusal_unchanged(): void
    {
        $shadow = new class implements Tool
        {
            public function name(): string
            {
                return 'board_my_cards';
            }

            public function acceptedArguments(): array
            {
                return ['limit'];
            }

            public function argumentTypes(): array
            {
                return [];
            }

            public function refusedArgumentReason(string $key): ?string
            {
                return null;
            }

            public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName, CallerClient $caller): array
            {
                throw new ToolRefusalException('board_my_cards: the shadow refuses `limit`.');
            }
        };
        $registry = new BoardToolsRegistry;
        $registry->register($shadow);
        $this->app->instance(BoardToolsRegistry::class, $registry);
        $this->app->forgetInstance(BoardToolDispatcher::class);
        Http::fake();

        $this->assertSame('board_my_cards: the shadow refuses `limit`.', $this->callTool('board_my_cards', ['limit' => 5], '0.9.15')->assertStatus(422)->json('error'));
        $this->assertSame(
            'board_my_cards: unknown argument `lmit`. This tool accepts: `limit`. Nothing was sent to the board — no card was read or written.',
            $this->callTool('board_my_cards', ['limit' => 5, 'lmit' => 5], '0.9.15')->assertStatus(422)->json('error'),
        );
    }

    // ─── the guard: every remedy, against the oldest client ─────────────────────────

    /**
     * ⭐ THE GUARD. Against a client at 0.5.0 — the first client with any board tool — every text
     * a caller can be handed that backticks an argument newer than 0.5.0 must carry that
     * argument's gap clause, with its type and the version that declares it.
     *
     * THE POPULATION, and how each part is reached:
     *  - every `remedy` anywhere in a `board_my_cards` body, found by walking the whole result
     *    (so a new window that grows a remedy is covered with no edit here), on a board where
     *    every list truncates — own lane, shared lane, `tag_cards`, the coordination board — and
     *    again narrowed by `stage`;
     *  - tool refusals: every `board_my_cards` refusal this fixture can reach that names an
     *    argument, and the missing-`card_id` refusal of each tool that takes one. These all flow
     *    through the dispatcher's one `ToolRefusalException` arm, which is what makes a refusal
     *    added later covered; this list samples that arm, it does not enumerate throw sites;
     *  - the dispatcher's undeclared-argument refusal, for every registered tool, carrying every
     *    accepted key plus one unknown one. ⚠ Its predicate is the KEYS SENT, not every backticked
     *    name: its accepted-set list backticks every argument by design, and the clause is about
     *    what the call sent (DL-426).
     */
    public function test_every_remedy_against_a_0_5_0_client_carries_the_gap_clause_for_every_newer_argument_it_names(): void
    {
        $registry = new BoardToolsRegistry;
        $caps = ClientCapabilities::bundled();
        $checked = 0;

        $assertCovered = function (string $tool, string $text, ?array $only = null) use ($caps, $registry, &$checked): void {
            [$head, $clause] = self::splitAtClause($text);
            preg_match_all('/`([a-z_][a-z0-9_]*)(?=[`:])/', $head, $m);
            $named = $only ?? array_values(array_unique(array_filter($m[1], fn (string $a): bool => in_array($a, $registry->resolve($tool)?->acceptedArguments() ?? [], true))));
            foreach ($named as $argument) {
                if ($caps->declares('0.5.0', $tool, $argument) === ClientDeclaration::Yes) {
                    continue;
                }
                $since = $caps->since($tool, $argument);
                $checked++;
                $this->assertMatchesRegularExpression(
                    '/`'.$argument.'` \([^;]+; first declared by client '.preg_quote($since, '/').'\)/',
                    $clause,
                    "{$tool}: a 0.5.0 client is handed `{$argument}` (declared from {$since}) with no gap clause for it:\n{$text}",
                );
            }
        };

        $this->writeAgent("  shared_swimlane_id: 9\n  coord_board_id: 12\n  address_tags:\n    - repo:me\n");
        foreach ([[], ['stage' => 50], ['tag' => 'lane:A'], ['tag' => 'lane:A', 'stage' => 50]] as $args) {
            $this->fakeBoard(60);
            $result = $this->callTool('board_my_cards', $args + ['limit' => 2], '0.5.0')->assertStatus(200)->json('result');
            $remedies = [];
            array_walk_recursive($result, function (mixed $value, string|int $key) use (&$remedies): void {
                if ($key === 'remedy') {
                    $remedies[] = (string) $value;
                }
            });
            $this->assertNotEmpty($remedies, 'the fixture truncated nothing — the guard would pass on an empty population');
            foreach ($remedies as $remedy) {
                $assertCovered('board_my_cards', $remedy);
            }
        }

        $refusals = [
            ['board_my_cards', ['limit' => '50']],
            ['board_my_cards', ['include_terminal' => true]],
            ['board_my_cards', ['include_terminal' => 'yes', 'tag' => 'lane:A']],
            ['board_my_cards', ['tag' => 5]],
            ['board_my_cards', ['include_description' => 'yes']],
            ['board_my_cards', ['stage' => '']],
            ['board_my_cards', ['stage' => 'Nowhere']],
            ['board_my_cards', ['tag' => 'lane:A', 'stage' => 52]],
            ['board_correct_card', ['name' => 'x']],
            ['board_take_card', []],
            ['board_comment_card', ['content' => 'x']],
        ];
        foreach ($refusals as [$tool, $args]) {
            $this->fakeBoard(1);
            $error = (string) $this->callTool($tool, $args, '0.5.0')->assertStatus(422)->json('error');
            $this->assertMatchesRegularExpression('/`[a-z_]+`/', $error, "{$tool} refusal named no argument — it samples nothing");
            $assertCovered($tool, $error);
        }

        foreach ($registry->known() as $tool) {
            $accepted = $registry->resolve($tool)?->acceptedArguments() ?? [];
            $error = (string) $this->callTool($tool, array_fill_keys([...$accepted, 'not_an_argument'], 1), '0.5.0')->assertStatus(422)->json('error');
            $assertCovered($tool, $error, $accepted);
        }

        $this->assertGreaterThan(0, $checked, 'no argument newer than 0.5.0 was ever checked — the guard measured nothing');
    }

    /** The limit refusal's `stage` escape is a {@see BoardMyCardsTool} constant the window shares; pinned so the TODAY baseline cannot drift from the source silently. */
    public function test_the_today_baseline_is_the_shipped_default_cap(): void
    {
        $this->assertStringContainsString('(default '.BoardMyCardsTool::DEFAULT_MAX_CARDS.')', self::TODAY_LIMIT_REFUSAL);
    }

    /**
     * r3-m3: the `tag` read refusal names `` `tag` `` only to say which read failed — the
     * failure is the board's (a 403 on the search) or the install's, never the caller's
     * arguments. An old client must NOT be told to update its client over a fault it cannot fix
     * that way; it gets exactly the pre-DL-426 text, unchanged since `origin/dev` 20f3d70.
     */
    public function test_a_tag_read_that_the_board_refuses_gets_todays_text_with_no_update_client_clause(): void
    {
        $today = 'board_my_cards: the bridge could not read the cards carrying your `tag` on your board 10 (the board answered 403) — so NO cards were returned — this is not an empty window. This is an INSTALL fault, not something your arguments can fix: '
            ."the bridge's writeback token was recognised but not permitted to READ — kanban gates the API on per-token abilities, and this one lacks `read` (on a card SEARCH board membership does NOT produce a 403: kanban floors the query to the caller's own boards and answers zero rows instead). Retrying will not change it; report it to your operator.";

        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'Backlog', 'position' => 1, 'lane_type' => 'backlog_inventory'],
            ]]]]]),
            '*/tasks/search.json*' => function ($request) {
                $url = urldecode($request->url());

                return str_contains($url, 'tags:"lane:A"') ? Http::response(['message' => 'Forbidden'], 403) : Http::response(['data' => [], 'links' => ['next' => null]]);
            },
        ]);

        foreach ([null, '0.9.12'] as $version) {
            $error = (string) $this->callTool('board_my_cards', ['tag' => 'lane:A'], $version)->assertStatus(422)->json('error');
            $this->assertSame($today, $error, var_export($version, true));
            $this->assertStringNotContainsString('update your channel client', $error);
            $this->assertStringNotContainsString('This bridge accepts', $error);
            foreach (self::CLAUSES as $opening) {
                $this->assertStringNotContainsString($opening, $error);
            }
        }
    }
}
