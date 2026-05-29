<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Dedup gate. 64 fits UUID providers (kanban, GitHub); a longer opaque
            // id would silently truncate into a false collision — the adapter
            // asserts length at parse time (rejects > 64 with a 400).
            $table->string('delivery_id', 64)->unique();
            $table->string('provider', 32);
            $table->string('scope_id', 128);
            $table->string('event_type', 64);
            $table->string('actor_id', 64)->nullable();   // raw provider id; null for system events
            $table->json('payload');                       // parsed envelope; raw body not stored
            $table->timestamp('received_at', 3)->useCurrent();  // DB-side default; the parsed DTO carries none
            $table->timestamps();

            $table->index('received_at');                  // retention pruning
            $table->index(['provider', 'scope_id']);       // per-scope traversal, ops queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
