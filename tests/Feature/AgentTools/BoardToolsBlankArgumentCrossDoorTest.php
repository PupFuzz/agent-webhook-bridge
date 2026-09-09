<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardToolArgs;
use App\Bridge\Tools\ToolsCallStdio;
use App\Bridge\Writeback\KanbanFieldLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeToolsCallStdio;
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

        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: ".crc32('me')."\nsubscriptions: []\n"
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
        $this->writeAgent('http');
        $before = Http::recorded()->count();

        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode($call));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['ok' => $response->status() === 200, 'body' => $body, 'requests' => $this->requestsSince($before)];
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
        $this->writeAgent('ssh');
        $before = Http::recorded()->count();

        $fake = new FakeToolsCallStdio((string) json_encode($call));
        $this->app->instance(ToolsCallStdio::class, $fake);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => 'me'])->run();

        /** @var array<string, mixed> $body */
        $body = json_decode($fake->capturedOut(), true);

        return ['ok' => $exit === 0, 'body' => $body, 'requests' => $this->requestsSince($before)];
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

    /**
     * ⛔ THE DIVERGENCES THIS CHANGE DELIBERATELY DOES NOT CLOSE, PINNED SO THE DISCLOSURE
     * IS FALSIFIABLE RATHER THAN A SENTENCE IN A CHANGELOG (DL-367 § Bounds).
     *
     * Three guards still run against the value AS SENT — `idempotency_key`'s charset, a
     * tag's charset, and `title`/`name`'s length cap — so a value whose PADDING is what
     * trips one is refused here and accepted (as its trimmed self) over HTTP, where the
     * middleware removed the padding before any guard ran. ⚠ In every case the SSH DOOR IS
     * THE STRICTER ONE, and closing the gap means making it ACCEPT input it refuses today:
     * a permissive change, which is its own operator gate and not the one card#9155
     * answered. Nothing wrong is written in the meantime — the strict door refuses.
     *
     * ⚑ This arm reds if somebody closes one of them WITHOUT ruling on it, which is the
     * only way that could happen quietly.
     */
    public function test_the_divergences_this_change_deliberately_does_not_close(): void
    {
        $overLong = str_repeat('a', KanbanFieldLimits::NAME_MAX);

        foreach ([
            'a padded idempotency_key' => ['title' => 'a real title', 'idempotency_key' => '  abc  '],
            'a tag padded with invisible characters' => ['title' => 'a real title', 'tags' => ["\u{00A0}needs-review"]],
            'a title at the cap, padded past it' => ['title' => '  '.$overLong.'  '],
        ] as $label => $args) {
            $this->fakeUncorrelatedBoard();
            $call = ['tool' => 'board_create_card', 'args' => $args];

            $http = $this->throughHttpDoor($call);
            $ssh = $this->throughSshDoor($call);

            $this->assertTrue($http['ok'], "{$label}: the HTTP door is expected to ACCEPT this — ".json_encode($http['body']));
            $this->assertFalse($ssh['ok'], "{$label}: the ssh door is expected to REFUSE this. If it now accepts, the divergence was closed — which is a PERMISSIVE change to what this door accepts and needs an operator ruling, not a green test.");
        }
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
}
