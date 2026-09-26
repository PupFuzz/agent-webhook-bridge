<?php

namespace Tests\Unit\IdleNudge;

use App\Bridge\IdleNudge\SeatRecordReader;
use App\Bridge\IdleNudge\SeatRecordUnmeasured;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way a seat offer record (schema v1, rt#562) is read, over real files: one v1 record
 * per `lanes` state, and each way of not getting one, each with its own verdict.
 */
class SeatRecordReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/seat-record-'.uniqid();
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        chmod($this->dir, 0o700);
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_link($f) || is_file($f)) {
                chmod($f, 0o600);
                unlink($f);
            }
        }
        foreach (glob($this->dir.'/*', GLOB_ONLYDIR) ?: [] as $d) {
            chmod($d, 0o700);
            array_map('unlink', glob($d.'/*') ?: []);
            rmdir($d);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * The documented example shape: a non-empty offer.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public static function record(array $over = []): array
    {
        return array_merge([
            'v' => 1,
            'agent' => 'pm',
            'session_id' => 'sess-1',
            'turn_ended_at' => 1789387200.125,
            'horizon_s' => 1800,
            'cooldown_s' => 3600,
            'lanes' => [['lane' => 'impl', 'detail' => 'card#42'], ['lane' => 'review', 'detail' => 'pullable work']],
            'waivers' => [],
            'prompt' => 'Lanes idle with pullable work: impl (card#42), review. Continue working.',
            'reason' => null,
        ], $over);
    }

    private function write(string $raw, string $name = 'offer.json'): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $raw);

        return $path;
    }

    private function verdictOf(string $path): string
    {
        try {
            (new SeatRecordReader)->read($path, 'pm');
        } catch (SeatRecordUnmeasured $e) {
            return $e->verdict;
        }
        $this->fail('the record must not read as an offer');
    }

    public function test_a_v1_offer_is_read_with_turn_ended_at_in_milliseconds(): void
    {
        $offer = (new SeatRecordReader)->read($this->write((string) json_encode(self::record())), 'pm');

        $this->assertSame('sess-1', $offer->sessionId);
        $this->assertSame(1789387200125, $offer->turnEndedAtMs);
        $this->assertSame(1800, $offer->horizonS);
        $this->assertSame(3600, $offer->cooldownS);
        $this->assertSame([['lane' => 'impl', 'detail' => 'card#42'], ['lane' => 'review', 'detail' => 'pullable work']], $offer->lanes);
        $this->assertSame('Lanes idle with pullable work: impl (card#42), review. Continue working.', $offer->prompt);
    }

    public function test_an_integer_turn_ended_at_and_a_null_session_are_v1(): void
    {
        $offer = (new SeatRecordReader)->read($this->write((string) json_encode(self::record(['turn_ended_at' => 1789387200, 'session_id' => null]))), 'pm');

        $this->assertNull($offer->sessionId);
        $this->assertSame(1789387200000, $offer->turnEndedAtMs);
    }

    public function test_the_two_nothing_to_offer_states_stay_apart(): void
    {
        $empty = (new SeatRecordReader)->read($this->write((string) json_encode(self::record(['lanes' => [], 'prompt' => null, 'reason' => 'no lane idle with pullable work']))), 'pm');
        $unmeasured = (new SeatRecordReader)->read($this->write((string) json_encode(self::record(['lanes' => null, 'waivers' => null, 'prompt' => null, 'reason' => 'census timed out'])), 'u.json'), 'pm');

        $this->assertSame([], $empty->lanes);
        $this->assertNull($empty->prompt);
        $this->assertNull($unmeasured->lanes);
        $this->assertNull($unmeasured->prompt);
    }

    public function test_an_absent_record_is_absent(): void
    {
        $this->assertSame('seat_record_absent', $this->verdictOf($this->dir.'/nope.json'));
    }

    public function test_a_record_under_an_untraversable_directory_is_not_visible_rather_than_absent(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root traverses every directory');
        }
        mkdir($this->dir.'/sealed', 0o700);
        file_put_contents($this->dir.'/sealed/offer.json', (string) json_encode(self::record()));
        chmod($this->dir.'/sealed', 0o600);

        $this->assertSame('seat_record_not_visible', $this->verdictOf($this->dir.'/sealed/offer.json'));
    }

    public function test_an_unreadable_record_is_unreadable(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root reads every file');
        }
        $path = $this->write((string) json_encode(self::record()));
        chmod($path, 0o000);

        $this->assertSame('seat_record_unreadable', $this->verdictOf($path));
    }

    public function test_a_symlinked_record_is_refused_as_unreadable(): void
    {
        $target = $this->write((string) json_encode(self::record()), 'real.json');
        symlink($target, $this->dir.'/offer.json');

        $this->assertSame('seat_record_unreadable', $this->verdictOf($this->dir.'/offer.json'));
    }

    /** @return array<string, array{mixed}> */
    public static function unknownVersions(): array
    {
        return [
            'v 2' => [2],
            'v 0' => [0],
            'v as a string' => ['1'],
            'v as a float' => [1.5],
            'v null' => [null],
        ];
    }

    #[DataProvider('unknownVersions')]
    public function test_an_unknown_version_is_its_own_verdict(mixed $v): void
    {
        $this->assertSame('seat_record_unknown_version', $this->verdictOf($this->write((string) json_encode(self::record(['v' => $v])))));
    }

    public function test_a_record_with_no_version_is_an_unknown_version(): void
    {
        $record = self::record();
        unset($record['v']);

        $this->assertSame('seat_record_unknown_version', $this->verdictOf($this->write((string) json_encode($record))));
    }

    /** @return array<string, array{string}> */
    public static function notObjects(): array
    {
        return [
            'not json' => ['{"v": 1, '],
            'a list' => ['[1, 2]'],
            'a scalar' => ['1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notObjects')]
    public function test_a_body_that_is_not_a_json_object_is_malformed(string $raw): void
    {
        $this->assertSame('seat_record_malformed', $this->verdictOf($this->write($raw)));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function brokenV1(): array
    {
        return [
            'agent missing' => [['agent' => null]],
            'agent empty' => [['agent' => '']],
            'session not a string' => [['session_id' => 7]],
            'turn_ended_at a string' => [['turn_ended_at' => '1789387200']],
            'turn_ended_at zero' => [['turn_ended_at' => 0]],
            'turn_ended_at negative' => [['turn_ended_at' => -5]],
            'turn_ended_at past int range' => [['turn_ended_at' => 1e300]],
            'horizon zero' => [['horizon_s' => 0]],
            'horizon a float' => [['horizon_s' => 1800.0]],
            'cooldown negative' => [['cooldown_s' => -1]],
            'cooldown missing' => [['cooldown_s' => null]],
            'lanes an object' => [['lanes' => ['lane' => 'impl', 'detail' => 'x']]],
            'lanes a string' => [['lanes' => 'impl']],
            'lane entry with no lane' => [['lanes' => [['detail' => 'x']]]],
            'lane entry with an empty lane' => [['lanes' => [['lane' => '', 'detail' => 'x']]]],
            'lane entry with no detail' => [['lanes' => [['lane' => 'impl']]]],
            'lane entry a string' => [['lanes' => ['impl']]],
            'lanes offered with no prompt' => [['prompt' => null]],
            'lanes offered with a blank prompt' => [['prompt' => '  ']],
            'prompt not a string' => [['prompt' => ['text']]],
            'a prompt with nothing offered' => [['lanes' => [], 'prompt' => 'Continue working.']],
            'a prompt with an unmeasured census' => [['lanes' => null, 'prompt' => 'Continue working.']],
        ];
    }

    /** @param  array<string, mixed>  $over */
    #[DataProvider('brokenV1')]
    public function test_a_v1_record_outside_its_field_contract_is_malformed(array $over): void
    {
        $this->assertSame('seat_record_malformed', $this->verdictOf($this->write((string) json_encode(self::record($over), JSON_PRESERVE_ZERO_FRACTION))));
    }

    /** @return array<string, array{string}> */
    public static function otherAgents(): array
    {
        return [
            'another seat' => ['impl'],
            'a case variant' => ['PM'],
            'a padded name' => [' pm'],
        ];
    }

    #[DataProvider('otherAgents')]
    public function test_a_record_written_for_another_agent_is_its_own_verdict_not_an_offer(string $agent): void
    {
        $this->assertSame('seat_record_agent_mismatch', $this->verdictOf($this->write((string) json_encode(self::record(['agent' => $agent])))));
    }

    public function test_a_malformed_record_is_malformed_whatever_agent_it_names(): void
    {
        $this->assertSame('seat_record_malformed', $this->verdictOf($this->write((string) json_encode(self::record(['agent' => 'impl', 'horizon_s' => 0])))));
    }
}
