<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A small per-agent ledger, NOT a state machine: a dispatch is either
        // done (processed_at set) or errored (error_message set, processed_at
        // null). No status enum / attempt_count — an errored row is re-attempted
        // by the next kanban-board redelivery or an operator `bridge:replay`.
        Schema::create('agent_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_event_id')->constrained()->cascadeOnDelete();
            $table->string('agent_name', 64);
            $table->timestamp('processed_at', 3)->nullable();  // non-null = succeeded
            $table->text('error_message')->nullable();         // set + processed_at null = replayable
            $table->timestamps();

            $table->unique(['webhook_event_id', 'agent_name']); // per-(event,agent) idempotency
            $table->index('processed_at');                      // find-errored / stats queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_dispatches');
    }
};
