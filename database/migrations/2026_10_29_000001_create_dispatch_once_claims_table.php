<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#12576: one row per (agent, once key) — which delivery is carrying a result that
        // more than one delivery can report, so the agent gets it once. Its first key is a
        // release landing (one merge arrives as a `push` and a `pull_request.closed`). Written
        // only by App\Bridge\Dispatch\OnceLedger, AFTER the dispatcher's gates; pruned on the
        // events' retention window.
        Schema::create('dispatch_once_claims', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191);
            $table->string('once_key', 255);
            // Which source holds it (`push` / `pull_request` for a release landing).
            $table->string('holder', 64);
            // The delivery that claimed it, so only that delivery can give it back.
            $table->unsignedBigInteger('webhook_event_id');
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['agent', 'once_key'], 'dispatch_once_claims_once');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_once_claims');
    }
};
