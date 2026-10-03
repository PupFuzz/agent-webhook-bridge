<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#11200 / DL-452: a seat's declared wait for CI on one head SHA (`ci_await`). A row
        // lives from registration until the bridge emits `ci_settled` or `ci_await_expired` for
        // it, or the seat cancels it. The emit CLAIMS the row by deleting it, and only the
        // request whose delete removed the row emits (App\Bridge\CiAwait\CiAwaitService) — so
        // the row's existence is the whole once-only guarantee, and nothing else stores state.
        Schema::create('ci_awaits', function (Blueprint $table) {
            $table->id();
            // The agent the board-tools door resolved — never an argument (self-scoped).
            $table->string('agent', 191);
            // The configured GitHub subscription scope's spelling (`owner/name`), which is what
            // the receiver compares a delivery's `repository.full_name` against.
            $table->string('repo', 128);
            $table->char('head_sha', 40);
            $table->unsignedInteger('pr')->nullable();
            // ⛔ EVERY TIMESTAMP HERE IS NULLABLE OR CARRIES AN EXPLICIT DEFAULT. Under MariaDB's
            // `explicit_defaults_for_timestamp=OFF` (the default before 10.10) the first NOT NULL
            // TIMESTAMP with neither is given `ON UPDATE CURRENT_TIMESTAMP` — every later write to
            // the row (a read's `last_read_at`) would silently move it — and a second such column is
            // refused outright (1067). The app always writes `expires_at`; its default only exists
            // to take it out of that rule.
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->nullable();
            $table->timestamp('expires_at', 3)->useCurrent();
            // The last runs read for this head: when it ran and, when it FAILED, why. A null
            // error with a non-null time is a read that answered.
            $table->timestamp('last_read_at', 3)->nullable();
            $table->string('last_error', 1000)->nullable();

            // One await per seat per head; re-registering refreshes this row.
            $table->unique(['agent', 'repo', 'head_sha']);
            // The detector's "is anyone waiting on this head?" — run on every workflow_run.completed.
            $table->index(['repo', 'head_sha']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_awaits');
    }
};
