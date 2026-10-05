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
            // Minted at insert and never reused: the inbox line id of the event this row emits, so a
            // recreated table cannot reissue an id a seat's seen file already holds.
            $table->char('uuid', 36)->unique();
            // The agent the board-tools door resolved — never an argument (self-scoped).
            $table->string('agent', 191);
            // The lookup key: the repo LOWER-CASED, so every lookup (which lower-cases its input) finds
            // the same rows on SQLite's case-sensitive `=` as on MariaDB's case-insensitive collation.
            $table->string('repo', 128);
            // The configured GitHub subscription spelling (`owner/name`) — what the token resolver's
            // case-sensitive credential map, the runs read and the emitted events use.
            $table->string('repo_name', 128);
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
            // The last runs read for this head: when it ran and, when it FAILED, why. The sweep selects
            // on `last_read_at` (a head whose oldest read is stale is read again); `last_error` is
            // diagnostic only — carried on `ci_await_expired` and named by `bridge:check`.
            $table->timestamp('last_read_at', 3)->nullable();
            $table->string('last_error', 1000)->nullable();
            // When a RATE-LIMITED read said its quota returns; no read of the head is made before it.
            $table->timestamp('retry_not_before', 3)->nullable();
            // When this await's event last failed to reach its seat's inbox. The row is kept and
            // emitted again on a later pass; expiry does not retry it within one sweep interval, and
            // drops it, logged as an error, once it is far enough past `expires_at`.
            $table->timestamp('emit_failed_at', 3)->nullable();

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
