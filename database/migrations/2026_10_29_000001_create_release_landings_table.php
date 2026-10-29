<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#12576: which delivery carries each release landing to each agent. One merge into
        // the release branch arrives as a `push` AND a `pull_request.closed`, in either order; the
        // first to be classified claims the landing and the other wakes nobody. Written only by
        // App\Bridge\Support\ReleaseLandingLedger; pruned on the events' retention window.
        Schema::create('release_landings', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191);
            // Lower-cased lookup key, as `ci_awaits.repo` keeps it.
            $table->string('repo', 128);
            $table->char('head_sha', 40);
            // The top-level GitHub event that claimed it: `push` or `pull_request`.
            $table->string('source', 32);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['agent', 'repo', 'head_sha'], 'release_landings_once');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_landings');
    }
};
