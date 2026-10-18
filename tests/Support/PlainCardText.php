<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Card text that passes the plain-language rules (`App\Bridge\Support\CardTextCheck`), for a test
 * whose subject is something else that `board_create_card` / `board_correct_card` does — and the
 * `custom_fields.json` answer for a board that registers NONE of the bridge's payload keys, so the
 * create stamps the legacy tags a test written before card#11987 still asserts.
 *
 * ⚠ Register {@see legacyBoardFields} in `setUp()`, BEFORE the test's own `Http::fake()`: the first
 * matching stub wins (G-020), so it answers only the field read and the test's own stubs keep every
 * other URL. A test about the payload keys therefore lives in a class that does NOT register it — or
 * calls {@see passFieldReadsThrough}, as a test must whose subject is EVERY request a call sends: an
 * answer here is a request that test's own counting fake never sees.
 */
final class PlainCardText
{
    private static bool $passThrough = false;

    public const DESCRIPTION = "What this card is for, in plain words.\n\nTechnical notes (for agents)\nIMPACT: RUNTIME";

    public const FIELDS_URL = '*/boards/*/custom_fields.json';

    public static function legacyBoardFields(): void
    {
        self::$passThrough = false;
        Http::fake([self::FIELDS_URL => static fn () => self::$passThrough ? null : Http::response(['data' => []])]);
    }

    /** From here on in this test the field read falls through to the test's own stubs. */
    public static function passFieldReadsThrough(): void
    {
        self::$passThrough = true;
    }
}
