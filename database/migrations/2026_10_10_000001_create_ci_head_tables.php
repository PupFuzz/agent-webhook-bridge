<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#11667: every workflow run the bridge has been TOLD about by a `workflow_run`
        // delivery (requested / in_progress / completed), one row per run id — the state the
        // per-head aggregate `ci_settled` is decided from, so deciding it costs no GitHub read.
        // Written only by App\Bridge\CiAwait\CiHeadRunTracker; a delivery never moves a row
        // backwards (an earlier attempt, or a lower status of the same attempt, is ignored).
        Schema::create('ci_head_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->unique();
            // Lower-cased lookup key and configured spelling, as `ci_awaits` keeps them.
            $table->string('repo', 128);
            $table->string('repo_name', 128);
            $table->char('head_sha', 40);
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->string('workflow', 255);
            $table->unsignedInteger('run_number')->nullable();
            $table->unsignedInteger('run_attempt')->nullable();
            $table->string('event', 64);
            $table->string('status', 32);
            $table->string('conclusion', 32)->nullable();
            $table->string('html_url', 512);
            $table->unsignedInteger('pr')->nullable();
            // See the ci_awaits migration: every timestamp is nullable or carries a default.
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->nullable();

            $table->index(['repo', 'head_sha']);
            $table->index('updated_at');
        });

        // card#11667: one row per aggregate `ci_settled` delivered to an agent — the dedupe that
        // keeps one settled state (the head's deciding runs, by id, attempt and conclusion) from
        // reaching the same agent twice, whether the aggregate or that agent's `ci_await` sent it.
        Schema::create('ci_head_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191);
            $table->string('repo', 128);
            $table->char('head_sha', 40);
            $table->char('fingerprint', 40);
            $table->timestamp('created_at', 3)->useCurrent();

            $table->unique(['agent', 'repo', 'head_sha', 'fingerprint'], 'ci_head_settlements_once');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_head_settlements');
        Schema::dropIfExists('ci_head_runs');
    }
};
