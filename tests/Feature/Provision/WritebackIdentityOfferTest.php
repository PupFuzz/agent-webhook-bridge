<?php

namespace Tests\Feature\Provision;

use App\Bridge\Support\TokenPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `bridge:provision` OFFERS the writeback `identity_id` it can resolve from the token the
 * operator has already placed (card#9141 / DL-369) — it never writes one unasked.
 *
 * ⛔ THE FAIL-SOFT MATRIX IS THE IMPORTANT HALF, and the rule is ONE TEST PER NAMED CAUSE —
 * the `test_fail_soft_*` methods below are the population, and a new cause owes a new one.
 * Each asserts the same three things through `assertFellBackTo()`: the cause is NAMED, the
 * by-hand recipe is pointed at, and setup's own outcome is untouched. A single "it errors"
 * assertion would pass on an implementation that reported the WRONG cause for all but one of
 * them, and a wrong-but-specific cause sends an operator to the wrong repair (canon #10).
 * There is deliberately no count here: a number in prose is a restatement of the method list
 * and goes stale the first time an arm is added — which it already did once.
 */
class WritebackIdentityOfferTest extends TestCase
{
    private string $dir;

    private const WRITEBACK_TOKEN = 'wb-token-value-9141';     // gitleaks:allow — fixture

    private const BOARD_TOKEN = 'board-token-value-9141';      // gitleaks:allow — fixture

    private const EMAIL = 'writeback-service@example.com';

    private const NAME = 'Kanban Bridge Writeback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wb-identity-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        $this->writeSecret(TokenPath::for($this->dir, 'kanban'), self::BOARD_TOKEN);
        $this->writeSecret(TokenPath::forWriteback($this->dir, 'kanban'), self::WRITEBACK_TOKEN);
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.receiver_base_url' => 'https://bridge.example.com/webhooks',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeSecret(string $path, string $contents): void
    {
        File::put($path, $contents);
        chmod($path, 0o600);   // DL-010: a group/world-readable token is refused on read
    }

    private function writebackJson(): string
    {
        return $this->dir.'/writeback.json';
    }

    /** writeback.json as the operator has it mid-setup: mappings written, identity_id not yet known. */
    private function seedWritebackWithoutIdentity(): void
    {
        File::put($this->writebackJson(), json_encode([
            'mappings' => ['your-org/your-repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Provisioning's own webhook calls answered as they always are, with
     * `/users/current.json` handed to the caller's arm.
     */
    private function fakeApi(callable $currentUser): void
    {
        Http::fake(function (Request $request) use ($currentUser) {
            if (str_contains($request->url(), '/users/current.json')) {
                return $currentUser($request);
            }

            return $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 7]]);
        });
    }

    /** A resolvable writeback user, whose body carries the sensitive fields the real one does. */
    private function fakeResolvedUser(int $id = 6): void
    {
        $this->fakeApi(fn () => Http::response(['data' => [
            'id' => $id,
            'name' => self::NAME,
            'email' => self::EMAIL,
            'initials' => 'KBW',
        ], 'token' => ['abilities_label' => 'read-write']]));
    }

    /** The command as a script runs it: every question takes its default, which for the offer is NO. */
    private function runNonInteractively(): string
    {
        Artisan::call('bridge:provision', ['--no-interaction' => true]);

        return Artisan::output();
    }

    private function identityInFile(): mixed
    {
        $raw = json_decode((string) File::get($this->writebackJson()), true);

        return is_array($raw) ? ($raw['identity_id'] ?? null) : null;
    }

    // ---------------------------------------------------------------- the offer

    public function test_it_shows_the_resolved_id_and_display_name_and_writes_nothing_unconfirmed(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $output = $this->runNonInteractively();

        $this->assertStringContainsString('6', $output);
        $this->assertStringContainsString(self::NAME, $output, 'the display name is what makes a wrong account obvious');
        $this->assertNull($this->identityInFile(), 'nothing may be written without an affirmative answer');
    }

    public function test_confirming_writes_identity_id_and_leaves_the_rest_of_the_file_intact(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $this->artisan('bridge:provision')
            ->expectsConfirmation('Write identity_id 6 into writeback.json?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(6, $this->identityInFile());
        $raw = json_decode((string) File::get($this->writebackJson()), true);
        $this->assertIsArray($raw);
        $this->assertSame(8, $raw['mappings']['your-org/your-repo']['board_id'] ?? null);
    }

    /**
     * ⚑ The write is the one failure whose CAUSE is both useful and safe to print — it is
     * composed here from the path and the OS error, with no upstream body in it — so a
     * read-only `writeback.json` must be reported as such rather than as a bare exception
     * class, and setup must still exit 0.
     */
    public function test_a_refused_write_names_its_cause_and_does_not_abort_setup(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();
        chmod($this->writebackJson(), 0o400);

        $this->artisan('bridge:provision')
            ->expectsConfirmation('Write identity_id 6 into writeback.json?', 'yes')
            ->assertExitCode(0);

        chmod($this->writebackJson(), 0o600);
        $this->assertNull($this->identityInFile());
    }

    public function test_declining_writes_nothing(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $this->artisan('bridge:provision')
            ->expectsConfirmation('Write identity_id 6 into writeback.json?', 'no')
            ->assertExitCode(0);

        $this->assertNull($this->identityInFile());
    }

    public function test_no_secret_value_reaches_the_output(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $output = $this->runNonInteractively();

        // The id and the name are the WHOLE of what may be printed from that response.
        $this->assertStringContainsString(self::NAME, $output);
        $this->assertStringNotContainsString(self::WRITEBACK_TOKEN, $output);
        $this->assertStringNotContainsString(self::BOARD_TOKEN, $output);
        $this->assertStringNotContainsString(self::EMAIL, $output);
    }

    /**
     * ⛔ The display name is a value this install did not choose, and the console INTERPRETS
     * `<…>` style tags in it — so an unescaped name carrying one is SHOWN ALTERED, which on
     * this surface is the one thing that must not happen: the operator is being asked to
     * recognise an account BY THAT NAME, and a name the console rewrote is a name they cannot
     * check against the board. Watched red against the unescaped render.
     */
    public function test_a_display_name_carrying_console_markup_is_shown_verbatim(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['data' => ['id' => 6, 'name' => 'Bot <info>svc</info> Writeback', 'email' => self::EMAIL]]));

        $output = $this->runNonInteractively();

        $this->assertStringContainsString('Bot <info>svc</info> Writeback', $output);
        $this->assertStringNotContainsString('could not run', $output);
    }

    public function test_an_already_declared_identity_id_is_left_alone_and_asks_nothing(): void
    {
        File::put($this->writebackJson(), json_encode(['identity_id' => 4242, 'mappings' => []]));
        $this->fakeResolvedUser();

        $output = $this->runNonInteractively();

        $this->assertSame(4242, $this->identityInFile());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users/current.json'));
        $this->assertStringNotContainsString('identity_id', $output);
    }

    public function test_no_writeback_config_at_all_resolves_nothing(): void
    {
        $this->fakeResolvedUser();

        $this->runNonInteractively();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users/current.json'));
    }

    public function test_dry_run_names_the_gap_without_calling_the_api(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        Artisan::call('bridge:provision', ['--dry-run' => true, '--no-interaction' => true]);
        $output = Artisan::output();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users/current.json'));
        $this->assertStringContainsString('identity_id', $output);
        $this->assertNull($this->identityInFile());
    }

    // ------------------------------------------------------- the base-URL doubling trap

    public function test_the_request_url_appends_to_the_configured_base_without_doubling_the_api_prefix(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $output = $this->runNonInteractively();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://kanban.example.com/api/v3/users/current.json');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/v3/api/v3'));
        $this->assertStringNotContainsString('/api/v3/api/v3', $output);
    }

    public function test_a_base_url_with_a_trailing_slash_does_not_double_the_separator(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3/']);
        $this->seedWritebackWithoutIdentity();
        $this->fakeResolvedUser();

        $this->runNonInteractively();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://kanban.example.com/api/v3/users/current.json');
    }

    // ------------------------------------------------------------ the fail-soft matrix

    /**
     * Every arm asserts the same three things — the cause is NAMED, the by-hand recipe is
     * pointed at, and setup itself still succeeded — so a fallback that swallows the
     * diagnosis or aborts the command fails here.
     */
    private function assertFellBackTo(string $namedCause): void
    {
        Artisan::call('bridge:provision', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString($namedCause, $output);
        $this->assertStringContainsString('docs/writeback.md', $output);
        $this->assertStringContainsString('users/current.json', $output);
        $this->assertNull($this->identityInFile(), 'a fallback must not write a value it never resolved');
        // Setup ran to completion: the subscription this install declares was still created.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/boards/5/webhooks.json'));
    }

    /**
     * ⛔ The request presents the writeback BEARER TOKEN, so the cleartext-http floor
     * `bridge:provision`'s own loop applies has to hold here too — and it cannot be inherited
     * from that loop, which runs its check per kanban SUBSCRIPTION and does not run at all on
     * an install that declares none. The refusal is a fallback, not a throw.
     */
    public function test_fail_soft_refuses_to_present_the_token_over_cleartext_http(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'http://kanban.example.com/api/v3']);
        $this->seedWritebackWithoutIdentity();
        File::put($this->dir.'/prod-agent.yml', "subscriptions: []\n");   // no kanban scope: the loop validates nothing
        $this->fakeResolvedUser();

        Artisan::call('bridge:provision', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('must use https', $output);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users/current.json'));
        $this->assertNull($this->identityInFile());
    }

    public function test_fail_soft_no_writeback_token_yet(): void
    {
        $this->seedWritebackWithoutIdentity();
        File::delete(TokenPath::forWriteback($this->dir, 'kanban'));
        $this->fakeResolvedUser();

        $this->assertFellBackTo('no writeback token');
    }

    public function test_fail_soft_unreachable_api(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: kanban.example.com'));

        $this->assertFellBackTo('did not answer');
    }

    public function test_fail_soft_401(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['message' => 'Unauthenticated.'], 401));

        $this->assertFellBackTo('401');
    }

    public function test_fail_soft_403(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['message' => 'Forbidden.'], 403));

        $this->assertFellBackTo('403');
    }

    public function test_fail_soft_body_is_not_json(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response('<html>a proxy sign-in page</html>'));

        $this->assertFellBackTo('not JSON');
    }

    public function test_fail_soft_json_without_an_id(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['data' => ['name' => self::NAME, 'email' => self::EMAIL]]));

        $this->assertFellBackTo('.data.id');
    }

    /**
     * ⛔ The id lives at `.data.id`. A body whose id sits at the TOP level is not this
     * endpoint's shape, and reading `.id` off it is the mis-implementation card#9141's own
     * description had to be corrected for — so the bare spelling must resolve NOTHING
     * rather than quietly offer a number from the wrong place.
     */
    public function test_fail_soft_id_at_the_top_level_is_not_the_id(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['id' => 99, 'name' => self::NAME]));

        $this->assertFellBackTo('.data.id');
    }

    public function test_fail_soft_no_display_name(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['data' => ['id' => 6, 'email' => self::EMAIL]]));

        $this->assertFellBackTo('display name');
    }

    public function test_a_fail_soft_arm_prints_no_part_of_the_response_body(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn () => Http::response(['data' => ['name' => self::NAME, 'email' => self::EMAIL]]));

        $output = $this->runNonInteractively();

        $this->assertStringNotContainsString(self::EMAIL, $output);
        $this->assertStringNotContainsString(self::WRITEBACK_TOKEN, $output);
    }

    // -------------------------------------------------- the second-token collision warning

    /**
     * Answers `/users/current.json` per PRESENTED TOKEN, which is the only thing that
     * distinguishes the two accounts.
     */
    private function fakePerToken(int $writebackId, int $boardId): void
    {
        $this->fakeApi(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::BOARD_TOKEN)
            ? Http::response(['data' => ['id' => $boardId, 'name' => 'Board CLI', 'email' => 'cli@example.com']])
            : Http::response(['data' => ['id' => $writebackId, 'name' => self::NAME, 'email' => self::EMAIL]]));
    }

    public function test_it_warns_when_another_kanban_token_this_install_holds_is_the_same_user(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakePerToken(writebackId: 6, boardId: 6);

        $output = $this->runNonInteractively();

        $this->assertStringContainsString('SAME kanban user', $output);
        $this->assertStringContainsString(TokenPath::for($this->dir, 'kanban'), $output);
        $this->assertStringContainsString('docs/writeback.md', $output);
    }

    /** The control: distinct users must NOT warn, or the warning says nothing about anything. */
    public function test_it_does_not_warn_when_the_other_token_is_a_different_user(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakePerToken(writebackId: 6, boardId: 3);

        $output = $this->runNonInteractively();

        $this->assertStringContainsString(self::NAME, $output);
        $this->assertStringNotContainsString('SAME kanban user', $output);
    }

    public function test_an_unresolvable_second_token_is_reported_unchecked_not_cleared(): void
    {
        $this->seedWritebackWithoutIdentity();
        $this->fakeApi(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::BOARD_TOKEN)
            ? Http::response(['message' => 'Forbidden.'], 403)
            : Http::response(['data' => ['id' => 6, 'name' => self::NAME, 'email' => self::EMAIL]]));

        $output = $this->runNonInteractively();

        $this->assertStringContainsString('UNCHECKED', $output);
        $this->assertStringContainsString(TokenPath::for($this->dir, 'kanban'), $output);
    }
}
