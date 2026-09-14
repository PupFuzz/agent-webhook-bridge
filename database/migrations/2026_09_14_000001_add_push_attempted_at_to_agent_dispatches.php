<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `agent_dispatches.push_attempted_at` (card#9422 / DL-380): when this dispatch's best-effort
 * handlers — every live `channel_push` among them — last FINISHED, on the DATABASE's clock.
 *
 * ⛔ WHY A NEW COLUMN. The idle nudge must age a staged intent from when it was last PUSHED, and
 * no existing column says that on the clock the inbox `ts` is on: `webhook_events.received_at`
 * is the ORIGINAL receipt (a redelivery or `bridge:replay` re-uses the row and pushes again
 * now), and `processed_at` / `updated_at` are PHP-written. Additive and nullable; NULL means
 * "no push time recorded" (every row written before this migration), which the nudge reads as
 * unmeasured, never as old. No backfill: there is no honest value to backfill with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_dispatches', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_dispatches', 'push_attempted_at')) {
                $table->timestamp('push_attempted_at', 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_dispatches', function (Blueprint $table) {
            if (Schema::hasColumn('agent_dispatches', 'push_attempted_at')) {
                $table->dropColumn('push_attempted_at');
            }
        });
    }
};
