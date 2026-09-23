<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardToolArgs;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ClientVersion;
use App\Bridge\Tools\DispatchOutcome;
use App\Bridge\Tools\ToolCallBody;
use App\Bridge\Tools\ToolsCallStdio;
use App\Bridge\Writeback\KanbanFieldLimits;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\Support\JsonTypeArms;
use Tests\TestCase;

/**
 * ⭐ THE TWO BOARD-TOOLS DOORS MUST GIVE THE SAME ANSWER TO THE SAME CALL, AND THIS IS
 * THE ONLY CLASS THAT CAN MEASURE THAT (card#9155). Every other test in this directory
 * drives ONE door, so a divergence is invisible to it by construction: the HTTP leg and
 * the ssh leg can each be green about their own behaviour while the pair disagrees.
 *
 * ⛔ THE DIVERGENCE IS A MIDDLEWARE ONLY ONE DOOR HAS. Laravel's global `TrimStrings` +
 * `ConvertEmptyStringsToNull` rewrite `args` before the HTTP controller reads them;
 * `bridge:tools-call` json_decodes STDIN itself and hands the tool the literal string.
 * `TrimStrings` trims with `Str::trim`, whose `Str::INVISIBLE_CHARACTERS` set includes
 * `\x{00A0}`, `\x{200B}` and `\x{FEFF}`; PHP's ASCII `trim()` strips none of them. So a
 * title of one non-breaking space arrived NULL at the HTTP door (⇒ refused) and survived
 * `trim($title) === ''` at the ssh door (⇒ a card with a visually blank title). Same call,
 * two meanings, decided by how the seat happens to be wired.
 *
 * The fix is one shared primitive ({@see BoardToolArgs}) that delegates
 * to `Str::trim` BY IDENTITY — the middleware's own function, never a copied character
 * class, which would drift the moment the framework adds a codepoint.
 *
 * ⚑ RED-WHEN-REVERTED: put PHP's bare `trim()` back at any migrated site and the ssh leg
 * of the matching arm below accepts what the HTTP leg refuses; the `assertSame` on the two
 * envelopes is what catches it, and no single-door test can.
 */
class BoardToolsBlankArgumentCrossDoorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $token = 'tools-bearer-xdoor';   // gitleaks:allow — test fixture

    /** The one agent both doors authenticate as; a test about the agent name's length sets its own. */
    private string $agent = 'me';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-xdoor-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);

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

    /**
     * ⚑ ONE agent name, rewritten between the two legs, rather than two agents. The
     * `created-by:<agent>` stamp rides in the create body this test compares byte for
     * byte, so two differently-named agents would make every comparison fail for a
     * reason that has nothing to do with the subject.
     */
    private function writeAgent(string $transport): void
    {
        $auth = $transport === 'http' ? "  auth:\n    token_path: {$this->dir}/me-tools-token\n" : '';

        File::put($this->dir.'/'.$this->agent.'.yml', "identity:\n  kanban_user_id: ".crc32($this->agent)."\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: {$transport}\n".$auth
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
    }

    /**
     * One call through the LOOPBACK HTTP door — the one Laravel's global middleware runs on.
     *
     * @param  array<string, mixed>  $call
     * @return array{ok: bool, body: array<string, mixed>, requests: list<array<string, mixed>>}
     */
    private function throughHttpDoor(array $call): array
    {
        return $this->rawThroughHttpDoor((string) json_encode($call));
    }

    /**
     * The same door fed the caller's literal bytes — the request-body layer, where the two
     * doors each parse for themselves (card#10106).
     *
     * @return array{ok: bool, status: int, body: array<string, mixed>, requests: list<array<string, mixed>>}
     */
    private function rawThroughHttpDoor(string $rawBody): array
    {
        // Every test in this class drives BOTH doors, which in production is two processes —
        // and the seat seal is per-process (card#9170).
        CallingSeatSeal::forANewServingProcess();

        $this->writeAgent('http');
        $before = Http::recorded()->count();

        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $rawBody);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['ok' => $response->status() === 200, 'status' => $response->status(), 'body' => $body, 'requests' => $this->requestsSince($before)];
    }

    /**
     * The same call through the SSH forced-command door, which json_decodes STDIN itself
     * and therefore sees the caller's literal bytes.
     *
     * @param  array<string, mixed>  $call
     * @return array{ok: bool, body: array<string, mixed>, requests: list<array<string, mixed>>}
     */
    private function throughSshDoor(array $call): array
    {
        return $this->rawThroughSshDoor((string) json_encode($call));
    }

    /**
     * @return array{ok: bool, exit: int, body: array<string, mixed>, requests: list<array<string, mixed>>}
     */
    private function rawThroughSshDoor(string $stdin): array
    {
        CallingSeatSeal::forANewServingProcess();

        $this->writeAgent('ssh');
        $before = Http::recorded()->count();

        $fake = new FakeToolsCallStdio($stdin);
        $this->app->instance(ToolsCallStdio::class, $fake);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => $this->agent])->run();

        /** @var array<string, mixed> $body */
        $body = json_decode($fake->capturedOut(), true);

        return ['ok' => $exit === 0, 'exit' => $exit, 'body' => $body, 'requests' => $this->requestsSince($before)];
    }

    /**
     * The board requests one door made, reduced to method + path + decoded body — what
     * actually reached (or did not reach) the card.
     *
     * @return list<array<string, mixed>>
     */
    private function requestsSince(int $before): array
    {
        return Http::recorded()
            ->slice($before)
            ->map(fn ($pair) => [
                'method' => $pair[0]->method(),
                'path' => (string) parse_url($pair[0]->url(), PHP_URL_PATH),
                'body' => json_decode((string) $pair[0]->body(), true),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function writesIn(array $requests): array
    {
        return array_values(array_filter($requests, fn (array $r): bool => in_array($r['method'], ['POST', 'PATCH'], true)));
    }

    private function fakeCreatableBoard(): void
    {
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/search.json*' => Http::response(['data' => [[
                'id' => 42, 'board_id' => 10, 'swimlane_id' => 4, 'tags' => ['created-by:me'],
            ]]]),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);
    }

    /**
     * Values that are NOT EMPTY to PHP's ASCII `trim()` and ARE empty to the framework's
     * `Str::trim` — i.e. exactly the class the HTTP door has always collapsed to null and
     * the ssh door has always passed through. The last two are the mixed cases: real
     * whitespace either side of invisible characters, which neither primitive strips
     * completely on its own.
     *
     * @return array<string, array{0: string}>
     */
    public static function visuallyBlankValues(): array
    {
        return [
            'a non-breaking space' => ["\u{00A0}"],
            'a zero-width space' => ["\u{200B}"],
            'a zero-width no-break space (BOM)' => ["\u{FEFF}"],
            'invisible characters mixed with real spaces' => [" \u{00A0} \u{200B}\u{FEFF} "],
            'ASCII whitespace only' => ['   '],
        ];
    }

    /**
     * Values a seat legitimately sends — the CONTROL against over-tightening. Each is
     * accepted on both doors and stored as its trimmed self, which is what the HTTP door
     * has always done and what the ssh door must now do too. Non-ASCII letters are here
     * because a hand-rolled "strip everything unusual" fix would eat them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legitimateValues(): array
    {
        return [
            'padded with real whitespace' => ['   Fix the parser   ', 'Fix the parser'],
            'accented characters' => ['Café façade — ünïcode', 'Café façade — ünïcode'],
            'CJK, padded' => [' 日本語のカード ', '日本語のカード'],
            'tab and newline padding around non-ASCII' => ["\tCafé 日本語\n", 'Café 日本語'],
            'invisible characters INSIDE the value are untouched' => ["a\u{00A0}b", "a\u{00A0}b"],
        ];
    }

    // ─── board_create_card: `title` ──────────────────────────────────────────

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_title_is_refused_on_both_doors(string $blank): void
    {
        $this->fakeCreatableBoard();

        $http = $this->throughHttpDoor(['tool' => 'board_create_card', 'args' => ['title' => $blank]]);
        $ssh = $this->throughSshDoor(['tool' => 'board_create_card', 'args' => ['title' => $blank]]);

        $this->assertFalse($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertFalse($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body'], 'the two doors must answer a blank title identically');
        $this->assertSame([], $this->writesIn($http['requests']), 'HTTP door wrote a card');
        $this->assertSame([], $this->writesIn($ssh['requests']), 'ssh door wrote a card');
    }

    #[DataProvider('legitimateValues')]
    public function test_a_legitimate_title_is_accepted_and_stored_trimmed_on_both_doors(string $sent, string $stored): void
    {
        $this->fakeCreatableBoard();

        $http = $this->throughHttpDoor(['tool' => 'board_create_card', 'args' => ['title' => $sent]]);
        $ssh = $this->throughSshDoor(['tool' => 'board_create_card', 'args' => ['title' => $sent]]);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body']);

        $httpWrite = $this->writesIn($http['requests'])[0];
        $sshWrite = $this->writesIn($ssh['requests'])[0];
        $this->assertSame($stored, $httpWrite['body']['name']);
        $this->assertSame($stored, $sshWrite['body']['name']);
        $this->assertSame($httpWrite['body'], $sshWrite['body'], 'the two doors must write the same card body');
    }

    // ─── board_create_card: `description` ────────────────────────────────────

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_description_writes_no_description_at_create_on_both_doors(string $blank): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'description' => $blank]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));

        $httpWrite = $this->writesIn($http['requests'])[0];
        $sshWrite = $this->writesIn($ssh['requests'])[0];
        $this->assertArrayNotHasKey('description', $httpWrite['body']);
        $this->assertArrayNotHasKey('description', $sshWrite['body'], 'the ssh door wrote a body of invisible characters');
        $this->assertSame($httpWrite['body'], $sshWrite['body']);
    }

    // ─── board_correct_card: `name` ──────────────────────────────────────────

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_name_correction_is_refused_on_both_doors(string $blank): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => $blank]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertFalse($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertFalse($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body'], 'the two doors must answer a blank name identically');
        $this->assertSame([], $this->writesIn($http['requests']), 'HTTP door renamed the card');
        $this->assertSame([], $this->writesIn($ssh['requests']), 'ssh door renamed the card to a visually blank title');
    }

    #[DataProvider('legitimateValues')]
    public function test_a_legitimate_name_correction_is_accepted_and_stored_trimmed_on_both_doors(string $sent, string $stored): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => $sent]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame(['name' => $stored], $this->writesIn($http['requests'])[0]['body']);
        $this->assertSame(['name' => $stored], $this->writesIn($ssh['requests'])[0]['body']);
    }

    // ─── board_correct_card: `description` ───────────────────────────────────

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_description_correction_clears_the_field_on_both_doors(string $blank): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'description' => $blank]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame(['description' => ''], $this->writesIn($http['requests'])[0]['body']);
        $this->assertSame(['description' => ''], $this->writesIn($ssh['requests'])[0]['body'], 'the ssh door wrote invisible characters as the card body');
    }

    // ─── the shared tag vocabulary ───────────────────────────────────────────

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_tag_is_refused_on_both_doors(string $blank): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'tags' => [$blank]]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertFalse($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertFalse($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body'], 'the two doors must answer a blank tag identically');
        $this->assertSame([], $this->writesIn($http['requests']));
        $this->assertSame([], $this->writesIn($ssh['requests']));
    }

    // ─── a board 422's own reason (DL-384) ───────────────────────────────────

    private const PLANTED = 'planted-not-a-credential'; // gitleaks:allow — synthetic value the scrubber must remove

    private static function board422Body(): string
    {
        return (string) json_encode([
            'message' => 'The payload field is invalid.',
            'errors' => ['payload' => ['The payload field is invalid (access_token='.self::PLANTED.').']],
        ]);
    }

    /**
     * ⭐ rt#484's SHAPE: a title and tags inside both mirrored caps, the board answers 422 for a
     * field the bridge does not check, and the refusal used to tell the seat to shorten its
     * title, description or tags. It must name the board's own field and message instead —
     * redacted — and say the bridge's own checks passed, identically on both doors.
     */
    public function test_a_board_422_on_a_create_that_passed_the_bridge_checks_relays_the_boards_reason_on_both_doors(): void
    {
        Http::fake(['*/tasks.json' => Http::response(self::board422Body(), 422)]);
        $call = ['tool' => 'board_create_card', 'args' => [
            'title' => str_repeat('t', 159), 'description' => str_repeat('d', 4000), 'tags' => ['priority:high'],
        ]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertSame($http['body'], $ssh['body']);
        foreach (['http' => $http, 'ssh' => $ssh] as $door => $r) {
            $this->assertFalse($r['ok'], $door);
            $this->assertCount(1, $this->writesIn($r['requests']), "{$door}: the create must have reached the board");
            $error = (string) $r['body']['error'];
            $this->assertStringContainsString('`payload`: The payload field is invalid (access_token=[REDACTED]', $error, $door);
            $this->assertStringNotContainsString(self::PLANTED, $error, $door);
            $this->assertStringContainsString('NO card was created', $error, $door);
            $this->assertStringContainsString('checks passed before it sent', $error, $door);
            $this->assertStringNotContainsString('Shorten', $error, "{$door}: the bridge's checks passed, so no length may be blamed");
        }
    }

    /**
     * ⛔ A TAG THE BRIDGE WRITES ITSELF AND NO CHECK BOUNDS, SO ITS SENTENCE MUST NOT CLAIM THE
     * BOARD REFUSED "SOMETHING OTHER THAN" THE BOUNDS. `created-by:<agent>` is bounded only by
     * the agent's configured name, so an agent name long enough makes it longer than kanban's
     * tag cap. The fake applies kanban's `tags.*` rule to whatever the create actually sent, so
     * the refused index is the real one. (Until card#9588 the fixture was an over-long
     * `idempotency_key`; that stamp is now bounded before any request — the next test.)
     */
    public function test_a_board_422_on_a_bridge_stamped_tag_is_not_blamed_on_something_else_on_both_doors(): void
    {
        $this->agent = str_repeat('a', KanbanFieldLimits::TAG_MAX - strlen('created-by:') + 1);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/tasks/search.json')) {
                return Http::response(['data' => []]);
            }
            $tags = $request->method() === 'POST' ? ($request->data()['tags'] ?? []) : [];
            foreach (is_array($tags) ? $tags : [] as $i => $tag) {
                if (is_string($tag) && mb_strlen($tag) > KanbanFieldLimits::TAG_MAX) {
                    return Http::response(['message' => "The tags.{$i} field must not be greater than ".KanbanFieldLimits::TAG_MAX.' characters.', 'errors' => [
                        "tags.{$i}" => ["The tags.{$i} field must not be greater than ".KanbanFieldLimits::TAG_MAX.' characters.'],
                    ]], 422);
                }
            }

            return Http::response(['data' => ['id' => 42]], 201);
        });
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'tags' => ['priority:high']]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertSame($http['body'], $ssh['body']);
        foreach (['http' => $http, 'ssh' => $ssh] as $door => $r) {
            $this->assertFalse($r['ok'], $door);
            $writes = $this->writesIn($r['requests']);
            $this->assertCount(1, $writes, "{$door}: the create must have reached the board");
            $sent = $writes[0]['body']['tags'];
            $index = array_search('created-by:'.$this->agent, $sent, true);
            $this->assertIsInt($index, "{$door}: fixture drift — the create no longer sends the over-cap created-by tag");

            $error = (string) $r['body']['error'];
            $this->assertStringContainsString("`tags.{$index}`: The tags.{$index} field must not be greater than ".KanbanFieldLimits::TAG_MAX.' characters.', $error, $door);
            $this->assertStringContainsString('each tag you passed within '.KanbanFieldLimits::TAG_MAX, $error, $door);
            $this->assertStringContainsString('`created-by:`', $error, "{$door}: the sentence must say the bridge's unchecked stamp is not checked");
            $this->assertStringNotContainsString('`idem:`', $error, "{$door}: the `idem:` stamp IS bounded before any request (card#9588), so naming it unchecked is false");
            $this->assertStringNotContainsString('something other than', $error, "{$door}: the checks do not establish what the board refused");
        }
    }

    /**
     * card#9588: an `idempotency_key` one character past what `idem:<agent>:` leaves of kanban's
     * tag cap is refused before any request, with one envelope on both doors.
     */
    public function test_an_idempotency_key_past_the_agents_effective_cap_is_refused_before_any_request_on_both_doors(): void
    {
        $this->fakeUncorrelatedBoard();
        $cap = KanbanFieldLimits::TAG_MAX - strlen('idem:me:');
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'idempotency_key' => str_repeat('k', $cap + 1)]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertSame($http['body'], $ssh['body']);
        foreach (['http' => $http, 'ssh' => $ssh] as $door => $r) {
            $this->assertFalse($r['ok'], $door);
            $this->assertSame([], $r['requests'], "{$door}: an over-cap key must be refused before any request");
            $this->assertStringContainsString("at most {$cap} for agent `me`", (string) $r['body']['error'], $door);
        }
    }

    /**
     * The other half of DL-384's rule: what the bridge's own check DID establish is still named.
     * A title over the mirrored cap is refused naming the cap, before any request, on both doors.
     */
    public function test_a_title_over_the_mirrored_cap_is_refused_naming_the_cap_before_any_request_on_both_doors(): void
    {
        Http::fake(['*' => Http::response(self::board422Body(), 422)]);
        $call = ['tool' => 'board_create_card', 'args' => ['title' => str_repeat('t', KanbanFieldLimits::NAME_MAX + 1)]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertSame($http['body'], $ssh['body']);
        foreach (['http' => $http, 'ssh' => $ssh] as $door => $r) {
            $this->assertFalse($r['ok'], $door);
            $this->assertSame([], $r['requests'], "{$door}: an over-long title must be refused before any request");
            $error = (string) $r['body']['error'];
            $this->assertStringContainsString('`title` is '.(KanbanFieldLimits::NAME_MAX + 1).' characters', $error, $door);
            $this->assertStringContainsString('at most '.KanbanFieldLimits::NAME_MAX, $error, $door);
            $this->assertStringNotContainsString('payload', $error, $door);
        }
    }

    // ─── board_comment_card: `content` ───────────────────────────────────────

    private function fakeCommentableBoard(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [[
                'id' => 42, 'board_id' => 10, 'swimlane_id' => 99, 'tags' => [], 'assigned_user_id' => null,
            ]]]),
            '*/tasks/42/comments.json' => Http::response(['data' => ['id' => 9, 'task_id' => 42]], 201),
        ]);
    }

    #[DataProvider('visuallyBlankValues')]
    public function test_a_visually_blank_comment_is_refused_on_both_doors(string $blank): void
    {
        $this->fakeCommentableBoard();
        $call = ['tool' => 'board_comment_card', 'args' => ['card_id' => 42, 'content' => $blank]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertFalse($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertFalse($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body'], 'the two doors must answer a blank comment identically');
        $this->assertStringStartsWith('board_comment_card: `content` is required and must be a non-empty string', (string) $http['body']['error']);
        $this->assertSame([], $http['requests'], 'HTTP door reached the board');
        $this->assertSame([], $ssh['requests'], 'ssh door posted a comment of nothing but its attribution line');
    }

    #[DataProvider('legitimateValues')]
    public function test_a_legitimate_comment_is_posted_trimmed_and_attributed_identically_on_both_doors(string $sent, string $stored): void
    {
        $this->fakeCommentableBoard();
        $call = ['tool' => 'board_comment_card', 'args' => ['card_id' => 42, 'content' => $sent]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame($http['body'], $ssh['body']);

        foreach (['HTTP' => $http, 'ssh' => $ssh] as $door => $leg) {
            $this->assertSame([[
                'method' => 'POST',
                'path' => '/api/v3/tasks/42/comments.json',
                'body' => ['content' => "FROM: me\n\n{$stored}"],
            ]], $this->writesIn($leg['requests']), "{$door} door");
        }
    }

    /**
     * ⛔ THE DIVERGENCES THIS CHANGE DELIBERATELY DOES NOT CLOSE, PINNED SO THE DISCLOSURE
     * IS FALSIFIABLE RATHER THAN A SENTENCE IN A CHANGELOG (DL-367 § Bounds).
     *
     * Guards that still run against the value AS SENT — `idempotency_key`'s charset, a
     * tag's charset, `title`/`name`'s length cap — refuse a value whose PADDING is what
     * trips them, where HTTP accepts its trimmed self because the middleware removed the
     * padding before any guard ran. ⚠ On those arms the SSH DOOR IS THE STRICTER ONE, and
     * closing the gap means making it ACCEPT input it refuses today: a permissive change,
     * its own operator gate, and not the one card#9155 answered. Nothing wrong is written
     * in the meantime — the strict door refuses.
     *
     * ⛔ THE STRICTER DOOR IS NOT ALWAYS THE SSH ONE, AND A ROW SHAPE THAT ASSUMED SO COULD
     * NOT HAVE SAID THIS (card#10106 review). `args: null` is the counter-example: HTTP
     * refuses it and ssh RUNS THE TOOL, because `input('args', [])` returns the stored null
     * — `Arr::get` finds a key that EXISTS, so the default is never reached — and the
     * dispatcher's `! is_array($rawArgs)` refuses it, while `$decoded['args'] ?? []`
     * coalesces the same null to `[]`. So each row now declares which door accepts, and the
     * denominator covers BOTH directions rather than one.
     *
     * ⭐ THE LAST ARM IS NOT IN `args` AT ALL, AND THAT IS THE POINT. `TrimStrings` cleans
     * the WHOLE decoded HTTP body, so it rewrites the ENVELOPE keys — `tool`,
     * `client_version` — as well as the argument values, while `bridge:tools-call` reads
     * `$decoded['tool']` literally and {@see BoardToolsRegistry::resolve}
     * is an exact lookup. So `"board_create_card\u{00A0}"` CREATES A CARD over HTTP and is
     * refused over ssh. DL-367's census was derived from the tools' `args` object and could
     * not have seen that by construction (canon #7: the guarantee is a property of the HOP,
     * and one end's argument object is not the hop). The envelope is now part of the
     * population both here and in the drift guard.
     *
     * ⚠ THE ASCII-PADDED `tool` ROW IS NOT A DUPLICATE OF THE NBSP ONE — IT DISCRIMINATES A
     * HALF-FIX. Both are the same mechanism today, but the two part company under the
     * obvious repair: give the ssh door PHP's ASCII `trim()` on the envelope and the ASCII
     * row closes while the NBSP row stays open — DL-367's defect re-minted one layer up.
     * Only delegating to `Str::trim` BY IDENTITY closes both, and that is what these two
     * rows together can tell apart.
     *
     * ⚑ THIS IS THE DENOMINATOR, NOT A PROSE COUNT. Every doc that used to say how many
     * divergences remain now points at this method instead, so the answer is whatever this
     * asserts and cannot go stale in a sentence somebody forgets to update.
     */
    public function test_the_divergences_this_change_deliberately_does_not_close(): void
    {
        $overLong = str_repeat('a', KanbanFieldLimits::NAME_MAX);
        $creates = fn () => $this->fakeUncorrelatedBoard();
        $reads = fn () => $this->fakeReadableWindow();

        foreach ([
            // ── in the ARGUMENT VALUES, where the guards read the value as sent ──
            'a padded idempotency_key' => [['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'idempotency_key' => '  abc  ']], true, false, $creates],
            'a tag padded with invisible characters' => [['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'tags' => ["\u{00A0}needs-review"]]], true, false, $creates],
            'a title at the cap, padded past it' => [['tool' => 'board_create_card', 'args' => ['title' => '  '.$overLong.'  ']], true, false, $creates],
            // ── in the request ENVELOPE, which is a different population entirely ──
            'a padded `tool` key' => [['tool' => "board_create_card\u{00A0}", 'args' => ['title' => 'a real title']], true, false, $creates],
            'a `tool` key padded with ASCII spaces' => [['tool' => ' board_my_cards ', 'args' => []], true, false, $reads],
            // ── and the one the ssh door is the PERMISSIVE end of ──
            'a null `args`' => [['tool' => 'board_my_cards', 'args' => null], false, true, $reads],
        ] as $label => [$call, $httpAccepts, $sshAccepts, $board]) {
            $board();

            $http = $this->throughHttpDoor($call);
            $ssh = $this->throughSshDoor($call);

            $this->assertSame($httpAccepts, $http['ok'], "{$label}: the HTTP door is expected to ".($httpAccepts ? 'ACCEPT' : 'REFUSE').' this — '.json_encode($http['body']));
            $this->assertSame($sshAccepts, $ssh['ok'], "{$label}: the ssh door is expected to ".($sshAccepts ? 'ACCEPT' : 'REFUSE').' this — '.json_encode($ssh['body']).'. If this door moved, a cross-door divergence MOVED — which changes what a door accepts and needs an operator ruling, not a green test.');
        }
    }

    /** A board the READ tools can answer from, for a divergence row whose accepting door runs one. */
    private function fakeReadableWindow(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * The envelope divergence that is not a refusal at all, and is therefore the one a
     * refusal-shaped test cannot see: `client_version` is an OBSERVATION, so both doors
     * ACCEPT the call and simply record different things about the seat. `TrimStrings`
     * cleans the whole HTTP body, so a padded version arrives at {@see ClientVersion}
     * already trimmed and is taken; over ssh it reaches the same whitelist with its padding
     * and is dropped to null. Same call, two client-half rows, two `bridge:check` verdicts.
     *
     * ⚠ Recorded and pinned rather than closed, for the same reason as the arms above:
     * making the ssh door take it is the PERMISSIVE direction.
     */
    public function test_a_padded_client_version_is_recorded_on_one_door_and_dropped_on_the_other(): void
    {
        $this->fakeUncorrelatedBoard();
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title'], 'client_version' => " 0.9.20\u{00A0}"];

        $http = $this->throughHttpDoor($call);
        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertSame('0.9.20', BoardToolsClientCall::query()->where('agent', 'me')->value('client_version'));

        BoardToolsClientCall::query()->delete();

        $ssh = $this->throughSshDoor($call);
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertNull(BoardToolsClientCall::query()->where('agent', 'me')->value('client_version'), 'if this door now records the padded version too, the divergence was closed — which needs a ruling, not a green test');
    }

    /** A board on which no idempotency key correlates, so a create always creates. */
    private function fakeUncorrelatedBoard(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);
    }

    public function test_a_tag_padded_with_real_whitespace_is_stored_trimmed_on_both_doors(): void
    {
        $this->fakeCreatableBoard();
        $call = ['tool' => 'board_create_card', 'args' => ['title' => 'a real title', 'tags' => ['  needs-review  ']]];

        $http = $this->throughHttpDoor($call);
        $ssh = $this->throughSshDoor($call);

        $this->assertTrue($http['ok'], 'HTTP door: '.json_encode($http['body']));
        $this->assertTrue($ssh['ok'], 'ssh door: '.json_encode($ssh['body']));
        $this->assertSame(['needs-review', 'created-by:me'], $this->writesIn($http['requests'])[0]['body']['tags']);
        $this->assertSame(['needs-review', 'created-by:me'], $this->writesIn($ssh['requests'])[0]['body']['tags']);
    }

    // ─── the request body itself (card#10106) ────────────────────────────────

    /**
     * Bodies that never parse as the request object, each with the phrase the refusal must
     * carry. The first is rt#538's shape: a `tool` key that IS present, in a body that was
     * cut off — the HTTP door used to answer it "request must carry a non-empty `tool`".
     *
     * ⚑ THIS PROVIDER IS THE CONTROL SET FOR EVERY ARM OF `ToolCallBody::jsonType()`, AND
     * {@see test_every_json_type_arm_is_named_by_a_row_in_this_control_set} IS WHAT KEEPS THAT
     * TRUE. An arm with no row here is a refusal the operator-facing enumeration names and
     * nothing exercises — which is what `true` / `false` were until card#10106's review measured
     * it. Add an arm there, add its row here; `true` and `false` are separate rows because they
     * are separate inputs, not because they produce separate words.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unparseableBodies(): array
    {
        return [
            'truncated after a present `tool`' => ['{"tool":"board_create_card","args":{', 'request body is not valid JSON (Syntax error)'],
            'malformed UTF-8 inside a present `tool`' => ["{\"tool\":\"board_my_cards\",\"args\":{\"tag\":\"\xC3\x28\"}}", 'request body is not valid JSON (Malformed UTF-8'],
            'empty' => ['', 'request body is empty'],
            'whitespace only' => [" \n\t ", 'request body is empty'],
            'a JSON array' => ['[{"tool":"board_my_cards"}]', 'request body is a JSON array, not an object'],
            'a JSON string' => ['"board_my_cards"', 'request body is a JSON string, not an object'],
            'a JSON number' => ['42', 'request body is a JSON number, not an object'],
            'JSON true' => ['true', 'request body is a JSON boolean, not an object'],
            'JSON false' => ['false', 'request body is a JSON boolean, not an object'],
            'JSON null' => ['null', 'request body is a JSON null, not an object'],
        ];
    }

    /**
     * ⛔ THE GUARD BEHIND THE PROVIDER'S UNIVERSAL — a declaration in a test with no mechanism
     * keeping it true is a comment, and the person who adds an arm is editing
     * `ToolCallBody.php`, where nothing points here (card#10106 review, canon #16). The arm
     * words are read out of that method's source every run by {@see JsonTypeArms}, never listed,
     * so a new arm joins the population by existing and reds here until it has a row.
     *
     * ⚠ THE FIRST LEG TESTS THIS GUARD'S OWN PREMISE, because the matching rule is the one
     * thing here that is not derived: a row names a type by carrying the fragment
     * `JSON <word>,` — DELIMITED ON BOTH SIDES, which is not cosmetic. Measured: the undelimited
     * `JSON <word>` reads `boolean`'s row as covering an arm renamed to `bool`, so renaming an
     * arm passed a guard whose entire job is to red on it. The leg asserts the fragment against a
     * refusal the PRIMITIVE composes, and asserts it resolves to exactly ONE arm word, so a
     * reworded refusal or an ambiguous rule reds saying THE RULE is stale — rather than blaming a
     * row for a missing word, which would send the next maintainer to the wrong file.
     */
    public function test_every_json_type_arm_is_named_by_a_row_in_this_control_set(): void
    {
        $fragment = fn (string $word): string => 'JSON '.$word.',';

        $words = JsonTypeArms::words();
        $this->assertNotSame([], $words, 'the arm reader found no arms at all — an empty population, not a covered one');

        $sample = ToolCallBody::parse('[]');
        $this->assertInstanceOf(DispatchOutcome::class, $sample);
        $sampleError = (string) $sample->body()['error'];
        $this->assertCount(1, array_filter($words, fn (string $w): bool => str_contains($sampleError, $fragment($w))),
            'this guard reads a row as naming a type by the fragment `JSON <word>,`, and the refusal '.JsonTypeArms::SUBJECT." composes for a JSON array — {$sampleError} — does not resolve to exactly one arm word under that rule. Re-derive the rule before trusting the coverage below.");

        $phrases = implode("\n", array_column(self::unparseableBodies(), 1));
        foreach ($words as $word) {
            $this->assertStringContainsString($fragment($word), $phrases,
                "`ToolCallBody::jsonType()` can answer `{$word}` and no row in unparseableBodies() exercises it: that refusal is named in the operator-facing enumeration and measured nowhere. Add its row.");
        }
    }

    /**
     * ⚑ RED-WHEN-REVERTED: put `$request->input('tool')` back in front of the shared parse
     * and the HTTP leg answers "request must carry a non-empty `tool`" for every row, which
     * both the `assertSame` on the envelopes and the phrase assertion catch.
     */
    #[DataProvider('unparseableBodies')]
    public function test_a_body_that_does_not_parse_is_refused_in_the_same_words_on_both_doors(string $raw, string $phrase): void
    {
        Http::fake();

        $http = $this->rawThroughHttpDoor($raw);
        $ssh = $this->rawThroughSshDoor($raw);

        $this->assertSame($http['body'], $ssh['body'], 'the two doors must answer the same unparseable body identically');
        $this->assertSame(422, $http['status']);
        $this->assertSame(1, $ssh['exit']);
        $error = (string) $http['body']['error'];
        $this->assertStringStartsWith($phrase, $error);
        $this->assertStringEndsWith('expected a JSON object {tool, args?, client_version?}', $error);
        $this->assertStringNotContainsString('`tool`', $error, 'a body that never parsed must not be blamed on a field');
        $this->assertSame([], $http['requests']);
        $this->assertSame([], $ssh['requests']);
    }

    /**
     * The CONTROL: a body that does parse but genuinely lacks a usable `tool` still gets the
     * `tool` refusal, on both doors, in the one place that owns it (the dispatcher).
     *
     * @return array<string, array{0: string}>
     */
    public static function parsedBodiesWithoutATool(): array
    {
        return [
            'an empty object' => ['{}'],
            'args but no tool' => ['{"args":{}}'],
            'a non-string tool' => ['{"tool":5}'],
            'an empty tool' => ['{"tool":""}'],
        ];
    }

    #[DataProvider('parsedBodiesWithoutATool')]
    public function test_a_parsed_body_without_a_tool_is_refused_for_the_tool_on_both_doors(string $raw): void
    {
        Http::fake();

        $http = $this->rawThroughHttpDoor($raw);
        $ssh = $this->rawThroughSshDoor($raw);

        $this->assertSame($http['body'], $ssh['body']);
        $this->assertSame(422, $http['status']);
        $this->assertSame(1, $ssh['exit']);
        $this->assertSame('request must carry a non-empty `tool`', $http['body']['error']);
    }
}
