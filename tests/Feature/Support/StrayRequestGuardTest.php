<?php

namespace Tests\Feature\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The stray-request guard `Tests\TestCase` installs (DL-303), pinned where it can FAIL.
 *
 * The guard is one method call in a base class, and nothing else in the suite reds if it is
 * deleted: every test would simply go back to letting an unstubbed request leave the
 * process, which is the state card#7300 measured and which stayed green for the life of the
 * repo. A posture that no test asserts is a posture the next refactor drops silently, so it
 * is asserted here — by exercising it, not by reading a flag off the factory.
 *
 * The middle case is the one that matters: a bare `Http::fake()` and a `'*'` closure answer
 * everything, so it is the URL-PATTERN ARRAY form — the form nearly every test in this repo
 * uses — that lets an unmatched request fall through, and that is the form pinned here.
 * `tests/TestCase.php` holds the guard itself; `CLAUDE_GOTCHAS.md` G-020 says why a later
 * `Http::fake()` cannot be relied on to add the missing pattern.
 */
class StrayRequestGuardTest extends TestCase
{
    private const UNSTUBBED = 'https://kanban.example.com/api/v3/tasks/1.json';

    public function test_an_unstubbed_request_throws_instead_of_leaving_the_process(): void
    {
        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage(self::UNSTUBBED);   // the guard NAMES the url it stopped

        Http::get(self::UNSTUBBED);
    }

    public function test_a_request_matching_no_pattern_of_an_array_fake_throws(): void
    {
        Http::fake(['*/tasks/2.json' => Http::response(['data' => ['id' => 2]])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage(self::UNSTUBBED);

        Http::get(self::UNSTUBBED);
    }

    public function test_a_stubbed_request_is_answered_and_recorded(): void
    {
        // The presence witness for the two absence assertions above: without it, a guard
        // that refused EVERY request — including the faked ones — would pass them both and
        // the suite would be reporting on a plane where nothing can be exercised at all.
        Http::fake([self::UNSTUBBED => Http::response(['data' => ['id' => 1]])]);

        $this->assertSame(['data' => ['id' => 1]], Http::get(self::UNSTUBBED)->json());
        Http::assertSent(fn (Request $r) => $r->url() === self::UNSTUBBED);
    }
}
