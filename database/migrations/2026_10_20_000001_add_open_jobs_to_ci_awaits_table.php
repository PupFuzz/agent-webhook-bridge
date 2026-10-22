<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            // card#12089: what the last read that answered found still open when every deciding run
            // read completed — `{count, first: {workflow, run_id, html_url, job, status}}` — so
            // ci_await_overdue and ci_await_expired can name the job holding the wait. Null when that
            // read found no job open, read no jobs, or failed. Not `last_error`: an open job is not a
            // failed read, and a non-null `last_error` would break the registration cooldown and
            // bridge:check's failed-read warning, which both read it as one.
            $table->json('open_jobs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            $table->dropColumn('open_jobs');
        });
    }
};
