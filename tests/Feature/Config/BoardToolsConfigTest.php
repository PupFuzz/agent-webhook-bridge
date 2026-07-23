<?php

namespace Tests\Feature\Config;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DL-217 default-ON (v7) truth table. Classification precedes parsing: an EXPLICIT
 * block (enabled: true) is fail-loud at load; a DEFAULT-class block (no enabled key,
 * or a non-array / non-bool one) NEVER throws — it either enables (when satisfiable)
 * or suppresses (enabled=false + suppressedReason, which bridge:check FAILs on).
 */
class BoardToolsConfigTest extends TestCase
{
    /**
     * An HTTP channel that carries a channel token — the default tools bearer.
     *
     * @var array<string, mixed>
     */
    private array $httpChannel = [
        'url' => 'http://127.0.0.1:8788',
        'auth' => ['token_path' => '/secrets/channel-token'],
    ];

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>|null  $channel
     */
    private function config(array $extra, ?array $channel = null): AgentConfig
    {
        $base = [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ];
        if ($channel !== null) {
            $base['channel'] = $channel;
        }

        return AgentConfig::fromArray('me', array_merge($base, $extra));
    }

    // ─── row 1: absent (PRESERVED, byte-identical) ───────────────────────────

    public function test_absent_board_tools_is_a_no_op(): void
    {
        $this->assertNull($this->config([])->boardTools);
    }

    // ─── row 2: enabled:false → disabled no-op ───────────────────────────────

    public function test_disabled_block_needs_no_scope_fields(): void
    {
        $bt = $this->config(['board_tools' => ['enabled' => false]])->boardTools;
        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertNull($bt->tokenPath);
        $this->assertNull($bt->suppressedReason);
    }

    // ─── row 3 / default-flip: default block on HTTP channel → ON, channel bearer ─

    public function test_default_block_on_http_channel_defaults_enabled_reusing_channel_token(): void
    {
        $bt = $this->config(
            ['board_tools' => ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            $this->httpChannel,
        )->boardTools;

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertSame('/secrets/channel-token', $bt->tokenPath);
        $this->assertTrue($bt->bearerFromChannel);
        $this->assertNull($bt->suppressedReason);
        $this->assertSame(10, $bt->boardId);
        $this->assertSame(4, $bt->swimlaneId);
        $this->assertSame(55, $bt->createStageId);
    }

    // ─── row 4: default HTTP, no bearer → suppressed (INERT, not a throw) ─────

    public function test_default_block_http_no_bearer_suppresses_with_reason(): void
    {
        $bt = $this->config(
            ['board_tools' => ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            ['url' => 'http://127.0.0.1:8788'],   // HTTP, but no channel token, no alias
        )->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertNull($bt->tokenPath);
        $this->assertNotNull($bt->suppressedReason);
        $this->assertStringContainsString('no bearer', $bt->suppressedReason);
    }

    // ─── row 5: default block, no HTTP channel → suppressed (UDS never defaults on) ─

    public function test_default_block_without_http_channel_suppresses(): void
    {
        $bt = $this->config(
            ['board_tools' => ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            // no channel block → no url, no token
        )->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('no channel token', (string) $bt->suppressedReason);
    }

    // ─── row 6: explicit enabled:true + alias → enabled, bearer = alias ───────

    public function test_enabled_block_parses_the_full_scope(): void
    {
        $bt = $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/secrets/tools-token'],
            'board_id' => 10,
            'swimlane_id' => 4,
            'create_stage_id' => 55,
            'shared_swimlane_id' => 9,
            'coord_board_id' => 11,
            'address_tags' => ['repo:me'],
        ]])->boardTools;

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertSame('/secrets/tools-token', $bt->tokenPath);
        $this->assertFalse($bt->bearerFromChannel);
        $this->assertSame(10, $bt->boardId);
        $this->assertSame(4, $bt->swimlaneId);
        $this->assertSame(55, $bt->createStageId);
        $this->assertSame(9, $bt->sharedSwimlaneId);
        $this->assertSame(11, $bt->coordBoardId);
        $this->assertSame(['repo:me'], $bt->addressTags);
    }

    public function test_alias_token_path_wins_over_channel_token(): void
    {
        $bt = $this->config(
            ['board_tools' => [
                'enabled' => true,
                'auth' => ['token_path' => '/secrets/alias-token'],
                'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
            ]],
            $this->httpChannel,
        )->boardTools;

        $this->assertSame('/secrets/alias-token', $bt->tokenPath);   // alias honored FIRST
        $this->assertFalse($bt->bearerFromChannel);
    }

    // ─── row 7: explicit enabled:true + no alias + HTTP channel token → channel bearer ─

    public function test_explicit_enabled_reuses_channel_token_when_no_alias(): void
    {
        $bt = $this->config(
            ['board_tools' => ['enabled' => true, 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            $this->httpChannel,
        )->boardTools;

        $this->assertTrue($bt->enabled);
        $this->assertSame('/secrets/channel-token', $bt->tokenPath);
        $this->assertTrue($bt->bearerFromChannel);
    }

    // ─── row 8: explicit enabled:true, no alias, no HTTP channel → THROW ──────

    public function test_enabled_without_token_path_throws_at_load(): void
    {
        // requireBearer semantics (was requireTokenPath): an EXPLICIT enabled block
        // with no alias and no channel token to reuse is unsatisfiable → fail-loud.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no channel token exists to reuse');
        $this->config(['board_tools' => [
            'enabled' => true,
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }

    // ─── row 12: explicit enabled:true, HTTP channel, no bearer → THROW ──────

    public function test_explicit_enabled_http_no_bearer_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('no bearer');
        $this->config(
            ['board_tools' => ['enabled' => true, 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            ['url' => 'http://127.0.0.1:8788'],   // HTTP, no token, no alias
        );
    }

    // ─── row 10a: explicit + incomplete scope → THROW ────────────────────────

    public function test_enabled_without_swimlane_throws_at_load(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => 10, 'create_stage_id' => 55,
        ]]);
    }

    // ─── row 10b: default + incomplete scope → suppressed ────────────────────

    public function test_default_block_incomplete_scope_suppresses_with_reason(): void
    {
        $bt = $this->config(
            ['board_tools' => ['board_id' => 10, 'create_stage_id' => 55]],   // no swimlane_id
            $this->httpChannel,
        )->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('swimlane_id', (string) $bt->suppressedReason);
    }

    // ─── row 9 (v7 INVERSION): non-bool enabled → suppressed, NOT a throw ─────

    public function test_non_boolean_enabled_suppresses(): void
    {
        // v7 reclassifies a non-bool enabled from throw → suppress: a "false"-string
        // classifying as default-then-on would fail OPEN on a typo, so it suppresses.
        $bt = $this->config(['board_tools' => [
            'enabled' => 'true',
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]], $this->httpChannel)->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('boolean', (string) $bt->suppressedReason);
    }

    public function test_bare_enabled_null_suppresses(): void
    {
        // bare `enabled:` → YAML null. array_key_exists discriminates absent (attempt)
        // from present-null (suppress); `??` would misclassify null as absent.
        $bt = $this->config(['board_tools' => [
            'enabled' => null,
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]], $this->httpChannel)->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('boolean', (string) $bt->suppressedReason);
    }

    // ─── non-mapping block (v7 INVERSION): suppressed, NOT a throw ────────────

    public function test_non_mapping_block_suppresses(): void
    {
        $bt = $this->config(['board_tools' => 'yes'])->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('must be a mapping', (string) $bt->suppressedReason);
    }

    // ─── explicit-block malformations stay fail-loud (unchanged) ─────────────

    public function test_address_tags_without_coord_board_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
            'address_tags' => ['repo:me'],
        ]]);
    }

    public function test_string_id_field_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => '10', 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }

    // ─── transport (card 4952) ───────────────────────────────────────────────

    public function test_transport_defaults_to_http_when_absent(): void
    {
        $bt = $this->config(
            ['board_tools' => ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]],
            $this->httpChannel,
        )->boardTools;
        $this->assertSame('http', $bt->transport);
    }

    public function test_ssh_transport_enables_without_a_bearer_or_channel(): void
    {
        // ssh identity is the forced-command --agent — no bearer, no channel needed. A
        // default-class ssh block ENABLES even with no channel (unlike http, which
        // suppresses without a bearer).
        $bt = $this->config(['board_tools' => [
            'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]])->boardTools;

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertSame('ssh', $bt->transport);
        $this->assertNull($bt->tokenPath);
        $this->assertFalse($bt->bearerFromChannel);
        $this->assertNull($bt->suppressedReason);
    }

    public function test_ssh_transport_keeps_int_checks_fail_loud(): void
    {
        // The narrow-load-shape: ssh lifts ONLY the bearer — a bad board_id still throws.
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true, 'transport' => 'ssh', 'board_id' => 'x', 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }

    public function test_bad_transport_value_throws_on_explicit_path(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('transport');
        $this->config(['board_tools' => [
            'enabled' => true, 'transport' => 'grpc', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]], $this->httpChannel);
    }

    public function test_bad_transport_value_suppresses_on_default_path(): void
    {
        $bt = $this->config(['board_tools' => [
            'transport' => 'grpc', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]], $this->httpChannel)->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertStringContainsString('transport', (string) $bt->suppressedReason);
    }

    // ─── DR2-1 contradiction predicate: ssh + ANY auth key fails-closed ───────

    /**
     * The four `auth` shapes that must ALL trip the ssh contradiction (an ssh block
     * carries no bearer): bare `auth:` (null), `auth: {}`, `auth: {token_path: null}`,
     * and a real token_path.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function sshAuthShapes(): array
    {
        return [
            'bare auth null' => [null],
            'empty auth map' => [[]],
            'auth token_path null' => [['token_path' => null]],
            'auth real token_path' => [['token_path' => '/secrets/tools-token']],
        ];
    }

    #[DataProvider('sshAuthShapes')]
    public function test_ssh_with_any_auth_key_throws_on_explicit_path(mixed $auth): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true, 'transport' => 'ssh', 'auth' => $auth,
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }

    #[DataProvider('sshAuthShapes')]
    public function test_ssh_with_any_auth_key_suppresses_on_default_path(mixed $auth): void
    {
        $bt = $this->config(['board_tools' => [
            'transport' => 'ssh', 'auth' => $auth,
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]])->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled, 'an ssh+auth contradiction must not enable');
        $this->assertNull($bt->tokenPath);
        $this->assertNotNull($bt->suppressedReason);
    }

    #[DataProvider('sshAuthShapes')]
    public function test_http_with_the_same_auth_shapes_never_fires_the_ssh_rule(mixed $auth): void
    {
        // The contradiction is ssh-only: under http, these auth shapes keep their
        // existing behavior (bare/empty/null → fall through to the channel token; a
        // real token_path → that alias). None throws for the ssh reason.
        $bt = $this->config(
            ['board_tools' => [
                'enabled' => true, 'auth' => $auth,
                'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
            ]],
            $this->httpChannel,
        )->boardTools;

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertSame('http', $bt->transport);
    }

    // ─── the fleet-outage regression: every default-class malformation LOADS ─
    // ─── (no throw) + carries a case-specific suppressedReason ───────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: string}>
     */
    public static function defaultClassMalformations(): array
    {
        $http = ['url' => 'http://127.0.0.1:8788', 'auth' => ['token_path' => '/secrets/channel-token']];
        $scope = ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55];

        return [
            // null block + scalar block SHARE one path (non-array → "must be a mapping"),
            // kept as two inputs deliberately.
            'null block' => [['board_tools' => null], $http, 'must be a mapping'],
            'scalar block' => [['board_tools' => 'yes'], $http, 'must be a mapping'],
            'non-bool enabled' => [['board_tools' => array_merge(['enabled' => 'nope'], $scope)], $http, 'boolean'],
            'partial scope' => [['board_tools' => ['board_id' => 10, 'create_stage_id' => 55]], $http, 'swimlane_id'],
            'no bearer' => [['board_tools' => $scope], ['url' => 'http://127.0.0.1:8788'], 'no bearer'],
            'bad optional int' => [['board_tools' => array_merge($scope, ['shared_swimlane_id' => 'x'])], $http, 'shared_swimlane_id'],
            'bad address_tags' => [['board_tools' => array_merge($scope, ['coord_board_id' => 11, 'address_tags' => 'x'])], $http, 'address_tags'],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>|null  $channel
     */
    #[DataProvider('defaultClassMalformations')]
    public function test_default_class_malformation_loads_without_throwing_and_suppresses(array $extra, ?array $channel, string $expected): void
    {
        // The fleet-outage guarantee: a default-class malformation must NOT throw at
        // load (a throw reaches DispatchService via the all-or-nothing
        // SubscriptionRegistry → a 5xx storm for EVERY agent). It suppresses instead,
        // carrying a case-specific reason bridge:check renders as a FAIL.
        $bt = $this->config($extra, $channel)->boardTools;

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled, 'a default-class malformation must not enable');
        $this->assertNull($bt->tokenPath);
        $this->assertNotNull($bt->suppressedReason);
        $this->assertStringContainsString($expected, $bt->suppressedReason);
    }
}
