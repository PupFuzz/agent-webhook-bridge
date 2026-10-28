<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#12430: one row per (agent, head) whose `ci_await` the agent CANCELLED — the per-head
        // aggregate `ci_settled` sends that agent nothing more for the head. Written by
        // `ci_await_cancel` only when it removed an await, deleted by a new `ci_await` on the head,
        // and pruned with the aggregate's other bookkeeping on the retention window.
        Schema::create('ci_await_cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191);
            // Lower-cased lookup key, as `ci_awaits.repo` keeps it.
            $table->string('repo', 128);
            $table->char('head_sha', 40);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['agent', 'repo', 'head_sha'], 'ci_await_cancellations_once');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_await_cancellations');
    }
};
