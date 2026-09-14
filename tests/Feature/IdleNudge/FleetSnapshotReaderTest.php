<?php

namespace Tests\Feature\IdleNudge;

use App\Bridge\IdleNudge\FleetSnapshotReader;
use App\Bridge\IdleNudge\IdleNudgeConfig;
use App\Bridge\IdleNudge\IdleNudgeUnmeasured;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every way the one Mezzanine read can fail to MEASURE, against the documented refusal shapes
 * (rt#479 issuecomment-5657433728). Each is a pass-level unmeasured, never an empty fleet.
 *
 * ⛔ The canary token carries `.`, `~` and `/`: a redactor that stopped at a guessed charset
 * would leave its tail readable, and an absence assertion over a token that never reached the
 * wire proves nothing — so every test that can see the request also asserts it WAS sent.
 */
class FleetSnapshotReaderTest extends TestCase
{
    public const CANARY = 'mzr_canary.a~b/c-9';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/fleet-reader-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/fleet-token', self::CANARY."\n");
        chmod($this->dir.'/fleet-token', 0o600);
        config([
            'bridge.idle_nudge.enabled' => true,
            'bridge.idle_nudge.base_url' => 'https://mezzanine.example',
            'bridge.idle_nudge.token_path' => $this->dir.'/fleet-token',
            'bridge.idle_nudge.install' => 'inst-a',
            'bridge.idle_nudge.timeout' => '5',
            'bridge.idle_nudge.default_after' => '1800',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public static function envelope(array $over = []): array
    {
        return array_merge([
            'api_version' => 1,
            'server_time' => '2026-09-14T12:00:00.000Z',
            'fleet' => ['db' => 'ok', 'fold' => 'ok', 'sweep' => 'ok', 'seats_total' => 1, 'seats_live' => 1],
            'installs' => [['install_id' => 'inst-a', 'seats' => [['install_id' => 'inst-a', 'seat_id' => 's1', 'render_state' => 'working']]]],
        ], $over);
    }

    private function read(): void
    {
        (new FleetSnapshotReader)->read(IdleNudgeConfig::fromConfig());
    }

    private function assertUnmeasured(string $expectedReason): void
    {
        try {
            $this->read();
            $this->fail('expected an unmeasured pass');
        } catch (IdleNudgeUnmeasured $e) {
            $this->assertSame($expectedReason, $e->reason);
            $this->assertStringNotContainsString('canary', $e->getMessage());
        }
    }

    public function test_a_valid_envelope_parses_and_sends_the_bearer(): void
    {
        Http::fake(['mezzanine.example/api/fleet/snapshot' => Http::response(self::envelope(), 200)]);

        $snapshot = (new FleetSnapshotReader)->read(IdleNudgeConfig::fromConfig());

        $this->assertCount(1, $snapshot->seats);
        $this->assertSame(1789387200000, $snapshot->serverTimeMs);
        $this->assertGreaterThanOrEqual(0.0, $snapshot->transitS);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://mezzanine.example/api/fleet/snapshot'
            && $r->header('Authorization') === ['Bearer '.self::CANARY]);
    }

    /** @return iterable<string, array{0: int, 1: mixed, 2: string}> */
    public static function refusals(): iterable
    {
        yield '401 unauthenticated' => [401, ['error' => 'unauthenticated', 'message' => 'no', 'server_time' => 'x'], 'the fleet snapshot answered HTTP 401 (unauthenticated)'];
        yield '401 token_revoked' => [401, ['error' => 'token_revoked', 'revoked_at' => 'x'], 'the fleet snapshot answered HTTP 401 (token_revoked)'];
        yield '401 token_expired' => [401, ['error' => 'token_expired', 'expired_at' => 'x'], 'the fleet snapshot answered HTTP 401 (token_expired)'];
        yield '401 token_wrong_surface' => [401, ['error' => 'token_wrong_surface'], 'the fleet snapshot answered HTTP 401 (token_wrong_surface)'];
        yield '401 undocumented error is not echoed' => [401, ['error' => 'echo '.self::CANARY], 'the fleet snapshot answered HTTP 401 (other)'];
        yield '403 two_factor_required' => [403, ['error' => 'two_factor_required'], 'the fleet snapshot answered HTTP 403 (two_factor_required)'];
        yield '429 rate_limited' => [429, ['error' => 'rate_limited', 'retry_after_s' => 12, 'limit' => 120, 'window_s' => 60], 'the fleet snapshot answered HTTP 429 (rate_limited, retry after 12s)'];
        yield '503 fleet_unavailable' => [503, ['error' => 'fleet_unavailable', 'message' => 'down'], 'the fleet snapshot answered HTTP 503 (fleet_unavailable)'];
        yield '500 uncaught message' => [500, ['message' => 'Server Error'], 'the fleet snapshot answered HTTP 500 (any 5xx body is unmeasured)'];
        yield '502 maintenance page' => [502, '<html>maintenance</html>', 'the fleet snapshot answered HTTP 502 (any 5xx body is unmeasured)'];
        yield '302 is not followed' => [302, '', 'the fleet snapshot answered HTTP 302 (other)'];
        yield '404' => [404, ['message' => 'nope'], 'the fleet snapshot answered HTTP 404 (other)'];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_is_a_named_unmeasured_pass(int $status, mixed $body, string $reason): void
    {
        $headers = $status === 302 ? ['Location' => 'https://elsewhere.example/steal'] : [];
        Http::fake(['mezzanine.example/*' => Http::response($body, $status, $headers)]);

        $this->assertUnmeasured($reason);
        Http::assertSentCount(1);
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function malformed(): iterable
    {
        yield 'not json' => ['<html>ok</html>', 'the fleet snapshot body is not a JSON object'];
        yield 'a list' => [[1, 2], 'the fleet snapshot body is not a JSON object'];
        yield 'api_version 2' => [self::envelope(['api_version' => 2]), 'the fleet snapshot api_version is not 1 — this build reads only version 1'];
        yield 'api_version "1"' => [self::envelope(['api_version' => '1']), 'the fleet snapshot api_version is not 1 — this build reads only version 1'];
        yield 'no server_time' => [self::envelope(['server_time' => null]), 'the fleet snapshot server_time is absent or not an rfc3339 instant'];
        yield 'fold lagging' => [self::envelope(['fleet' => ['fold' => 'lagging']]), "Mezzanine's fold is lagging (fleet.fold), so no seat's state can be trusted to be current"];
        yield 'fold stalled' => [self::envelope(['fleet' => ['fold' => 'stalled']]), "Mezzanine's fold is stalled (fleet.fold), so no seat's state can be trusted to be current"];
        yield 'fold absent' => [self::envelope(['fleet' => []]), 'the fleet snapshot fleet.fold is absent or unrecognised'];
        yield 'installs not a list' => [self::envelope(['installs' => ['a' => 1]]), 'the fleet snapshot installs member is not a list'];
        yield 'seats not a list' => [self::envelope(['installs' => [['install_id' => 'inst-a', 'seats' => 'x']]]), 'an installs[] entry of the fleet snapshot carries no seats list'];
        yield 'a seat not an object' => [self::envelope(['installs' => [['install_id' => 'inst-a', 'seats' => ['x']]]]), 'a seat in the fleet snapshot is not a JSON object'];
        yield 'configured install absent' => [self::envelope(['installs' => [['install_id' => 'inst-b', 'seats' => []]]]), 'configured install not present in snapshot (BRIDGE_IDLE_NUDGE_INSTALL)'];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_200_is_a_named_unmeasured_pass(mixed $body, string $reason): void
    {
        Http::fake(['mezzanine.example/*' => Http::response($body, 200)]);

        $this->assertUnmeasured($reason);
    }

    public function test_a_timeout_is_unmeasured(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertUnmeasured('the fleet snapshot request did not complete (connect error or timeout after 5s)');
    }

    public function test_an_insecure_token_file_is_refused_before_any_request(): void
    {
        chmod($this->dir.'/fleet-token', 0o644);
        Http::fake();

        $this->assertUnmeasured("the fleet token file at {$this->dir}/fleet-token is group/world-readable — chmod 600");
        Http::assertNothingSent();
    }

    public function test_an_absent_or_empty_token_file_is_refused_before_any_request(): void
    {
        Http::fake();
        File::put($this->dir.'/fleet-token', "  \n");
        $this->assertUnmeasured("the fleet token file at {$this->dir}/fleet-token is empty");

        File::delete($this->dir.'/fleet-token');
        $this->assertUnmeasured("the fleet token file is absent, unreachable, or not a regular file at {$this->dir}/fleet-token (BRIDGE_IDLE_NUDGE_TOKEN_PATH)");
        Http::assertNothingSent();
    }
}
