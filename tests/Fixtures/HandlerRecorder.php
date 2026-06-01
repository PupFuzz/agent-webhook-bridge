<?php

namespace Tests\Fixtures;

/** Shared call recorder for the recording handler fixtures (reset per test). */
class HandlerRecorder
{
    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}
