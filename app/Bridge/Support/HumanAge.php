<?php

namespace App\Bridge\Support;

/**
 * A duration an operator reads at a glance — `45s`, `12m`, `3h`, `9d`.
 *
 * FLOORED, NEVER ROUNDED, so the printed number is a lower bound on the real age and "3h" can never be read off
 * something that happened four hours ago. The unit is the largest whole one, because the question this answers is
 * "recent, or not really", and a seat that last called `9d` ago is not made clearer by 217 hours.
 *
 * ⚠ A LOWER BOUND IS THE WRONG ROUNDING FOR A THRESHOLD printed beside an age it was compared with — "silent 3d,
 * threshold 3d" can be a true pair. A caller printing a comparand where that matters prints the seconds beside it.
 */
final class HumanAge
{
    public static function floored(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }
}
