<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Support\RedactedErrorText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which delivery carries each {@see OnceKey} to each agent (`dispatch_once_claims`, card#12576).
 *
 * ⭐ AT WORST TWO; ZERO ONLY ON THE RESIDUALS BELOW. A claim means THIS delivery is delivering, so
 * {@see DispatchService} takes it only after the echo / signal / strip gates — a delivery a gate
 * suppressed never holds a key it did not deliver (the zero-wake defect a claim taken inside
 * classify had: the gate dropped the claimant, then its twin was dropped as already carried).
 * And a delivery that fails after claiming — staging, or a durable handler, both a 5xx that
 * GitHub never retries — gives the claim back before the failure propagates, so a later twin
 * delivers.
 *
 * The claim is an `insertOrIgnore` against a unique (agent, key), so two concurrent deliveries
 * cannot both win. It is keyed on the HOLDER, not on the delivery: a replay of the delivery that
 * won delivers again, as replay is asked to, and a replay of the one that lost does not. A claim
 * that cannot be read or written (an update serves this code before `migrate` creates the table)
 * is logged and answered "deliver" — at worst twice.
 *
 * ⚠ RESIDUAL, named: a process that dies between the claim and the end of its durable phase (a
 * PHP fatal, an FPM kill) cannot give the claim back, and a twin classified after it is dropped.
 * So is a twin that LOST to a concurrent delivery which then failed and gave the claim back. And
 * a winner whose channel push fails keeps the claim — a best-effort handler's failure is a note,
 * not a throw — so its twin is dropped and the seat gets no live wake; the intent is still staged
 * in the inbox, which is where the seat reads it.
 */
final class OnceLedger
{
    public const TABLE = 'dispatch_once_claims';

    /** True when `$eventId`'s delivery carries `$once` to `$agent`: it claimed it now, or its holder holds it. */
    public static function claim(string $agent, OnceKey $once, int $eventId): bool
    {
        $key = ['agent' => $agent, 'once_key' => $once->key];
        try {
            DB::table(self::TABLE)->insertOrIgnore([$key + ['holder' => $once->holder, 'webhook_event_id' => $eventId, 'created_at' => now()]]);
            $holder = DB::table(self::TABLE)->where($key)->value('holder');
        } catch (Throwable $e) {
            Log::warning('bridge dispatch: a once key could not be claimed — this delivery delivers, so the agent may receive it twice', [
                'agent' => $agent, 'once_key' => $once->key, 'holder' => $once->holder,
            ] + RedactedErrorText::logContext($e));

            return true;
        }

        return $holder === null || $holder === $once->holder;
    }

    /** Give back a claim `$eventId`'s delivery took, because that delivery failed. Never throws: the failure it follows is the one to report. */
    public static function release(string $agent, OnceKey $once, int $eventId): void
    {
        try {
            DB::table(self::TABLE)->where(['agent' => $agent, 'once_key' => $once->key, 'webhook_event_id' => $eventId])->delete();
        } catch (Throwable $e) {
            Log::warning('bridge dispatch: a failed delivery could not give back its once key — a later delivery of the same result is dropped', [
                'agent' => $agent, 'once_key' => $once->key, 'holder' => $once->holder,
            ] + RedactedErrorText::logContext($e));
        }
    }
}
