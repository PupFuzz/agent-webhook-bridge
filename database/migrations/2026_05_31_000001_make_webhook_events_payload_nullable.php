<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let bridge:prune null out the stored payload past the replay window (DL-012):
 * a GitHub push payload is 50–100 KB of diff, and after the row is too old to
 * replay there is no reason to keep it — but the row itself stays (the
 * delivery_id dedup gate + audit metadata). So `payload` must be nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->json('payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Best-effort revert; any rows whose payload was nulled by a prune would
        // block this, which is the correct signal not to silently re-tighten.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->json('payload')->nullable(false)->change();
        });
    }
};
