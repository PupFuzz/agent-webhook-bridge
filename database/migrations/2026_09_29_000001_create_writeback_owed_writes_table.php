<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#10849 / DL-440: the durable writebacks the bridge still OWES. Every durable
        // write is inserted here and applied by App\Bridge\Writeback\OwedWriteQueue::drain(),
        // which deletes the row the moment the write lands — so on the healthy path a row
        // lives for one request. A row that stays is a write a rate limit (408/429) deferred,
        // retried by the next drain of its subject and aged out by the watchdog job.
        //
        // ⭐ `id` IS THE ONLY ORDERING KEY. A subject's oldest row is its head, and drain()
        // never touches row N+1 before row N is gone — so a write that arrived later can
        // never land before one that arrived earlier. No computed sequence column: the
        // autoincrement is assigned by the database at insert, which is the one place the
        // order is not a race.
        //
        // ⛔ NO FOREIGN KEY ON `webhook_event_id`. It is provenance (and the `bridge:replay`
        // handle the give-up alert names); retention deleting the event must never delete a
        // write the bridge still owes.
        Schema::create('writeback_owed_writes', function (Blueprint $table) {
            $table->id();
            // sha1 of (provider, scope_id, handler, debounce_key) — install-level and
            // agent-independent: every agent that classifies one event to one handler +
            // debounceKey emits the SAME write (docs/writeback.md, OncePerKey).
            $table->char('subject_key', 40);
            $table->string('provider', 32);
            $table->string('scope_id', 128);
            $table->string('handler', 64);
            // TEXT, not a bounded string: both are classifier-derived and neither is indexed (the
            // subject hash is), so a length cap here could only 5xx a durable write.
            $table->text('debounce_key');
            // Stored, not derived from debounce_key: a handler may read it directly.
            $table->text('target_id');
            // PROVENANCE ONLY — never part of the identity. The agent whose dispatch inserted
            // the row; drain() resolves a loadable agent from it (OwedWriteQueue::resolveAgent).
            $table->string('agent_name', 191);
            $table->json('payload');
            $table->unsignedBigInteger('webhook_event_id');
            $table->timestamp('queued_at', 3);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('not_before', 3)->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 1000)->nullable();
            // Set when the last apply failed for a reason other than a rate limit (which sets
            // no not_before): the sweep ranks such a head last, so a few persistently failing
            // subjects cannot fill every pass (OwedWriteQueue::sweep).
            $table->timestamp('last_failed_at', 3)->nullable();
            $table->timestamps(3);

            // BOTH the multi-agent dedup (agent B's insert of agent A's write is a no-op) AND,
            // with FIFO-by-id, the whole ordering guarantee.
            $table->unique(['subject_key', 'webhook_event_id']);
            // "this subject's head" — the oldest id.
            $table->index(['subject_key', 'id']);
            // the scheduled sweep's due-row scan.
            $table->index('not_before');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writeback_owed_writes');
    }
};
