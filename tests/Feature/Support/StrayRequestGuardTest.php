<?php

namespace Tests\Feature\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The stray-request guard `Tests\TestCase` installs (DL-303), pinned where it can FAIL.
 *
 * The guard is two calls in a base class — the refusal and the recorder that makes a
 * swallowed refusal loud — and nothing else in the suite reds if either is deleted: every
 * test would simply go back to letting an unstubbed request leave the process, which is the
 * state card#7300 measured and which stayed green for the life of the repo. A posture that no
 * test asserts is a posture the next refactor drops silently, so it is asserted here — by
 * exercising it, not by reading a flag off the factory.
 *
 * The array-fake case is the one that matters for the REFUSAL: a bare `Http::fake()` and a
 * `'*'` closure answer everything, so it is the URL-PATTERN ARRAY form — the form nearly every
 * test in this repo uses — that lets an unmatched request fall through, and that is the form
 * pinned here. The swallowed case is the one that matters for the RECORDER: the refusal
 * reaches a `catch (Throwable)` unchanged, so on its own it leaves the test green.
 * `tests/TestCase.php` holds the guard itself; `CLAUDE_GOTCHAS.md` G-020 says why a later
 * `Http::fake()` cannot be relied on to add the missing pattern.
 */
class StrayRequestGuardTest extends TestCase
{
    private const UNSTUBBED = 'https://kanban.example.com/api/v3/tasks/1.json';

    public function test_an_unstubbed_request_throws_instead_of_leaving_the_process(): void
    {
        $this->expectStrayRequest(self::UNSTUBBED);      // this test's SUBJECT is the refusal

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage(self::UNSTUBBED);   // the guard NAMES the url it stopped

        Http::get(self::UNSTUBBED);
    }

    public function test_a_request_matching_no_pattern_of_an_array_fake_throws(): void
    {
        $this->expectStrayRequest(self::UNSTUBBED);

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

        // …and the control for the recorder below: an ANSWERED request is not a finding.
        $this->assertSame([], $this->undeclaredStrayRequests());
    }

    public function test_a_swallowed_refusal_is_still_recorded_as_a_finding(): void
    {
        // The shape the whole mechanism exists for, reproduced with the app's own posture:
        // `catch (Throwable)` is the correct production behaviour for a degradable read, and
        // it swallows the guard's refusal exactly as it swallowed the connection error before
        // the guard existed. Of the 57 requests card#7300's guard refused on its first
        // suite-wide run, exactly 2 reached a red test; the rest were this shape, and their
        // legs were GREEN.
        try {
            Http::get(self::UNSTUBBED);
        } catch (\Throwable) {
            // degraded, and said nothing — as the callers do
        }

        // What tearDown() reports. Asserted BEFORE the declaration on purpose: declaring
        // first would empty the very list this test exists to witness.
        $this->assertSame([self::UNSTUBBED], $this->undeclaredStrayRequests());

        $this->expectStrayRequest(self::UNSTUBBED);
        $this->assertSame([], $this->undeclaredStrayRequests());
    }
}
