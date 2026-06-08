<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-2 / DL-036. A dispatch's terminal state was recorded only as `processed_at`
 * (non-null) + `error_message` — so a GATE-DROP (an event the agent isn't an
 * addressee for → an empty/echo `ClassifyResult` → marked processed, no error)
 * was byte-identical in the ledger to a real DELIVERY. `bridge:inspect` couldn't
 * tell them apart, and `bridge:replay` silently skips processed rows — including
 * the gate-dropped ones, which are exactly what you want to replay after fixing a
 * gate bug.
 *
 * Add `outcome` (delivered | dropped | errored) + `reason` (short, for a drop).
 * Both NULL on pre-existing rows — their outcome is unknowable retroactively, so
 * `bridge:inspect` falls back to a legacy label. Forward-only + idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_dispatches', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_dispatches', 'outcome')) {
                $table->string('outcome', 16)->nullable()->after('agent_name'); // delivered|dropped|errored
            }
            if (! Schema::hasColumn('agent_dispatches', 'reason')) {
                $table->string('reason', 255)->nullable()->after('outcome');     // short drop annotation
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_dispatches', function (Blueprint $table) {
            foreach (['reason', 'outcome'] as $col) {
                if (Schema::hasColumn('agent_dispatches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
