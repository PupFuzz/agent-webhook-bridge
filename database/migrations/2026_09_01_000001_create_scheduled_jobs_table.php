<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#8425 / DL-325: the periodic-job REGISTRY — the auditable boundary that
        // replaces "sweep N crontabs across N accounts". One row per job INSTANCE; the
        // handler it names is code, reviewed at code-review time (that is the governed
        // surface), and the row is the ungated, runtime-insertable half.
        //
        // ⛔ A TABLE AND NOT CONFIG, AND NOT CACHE. Two reasons, both load-bearing:
        //  - The inserter and the runner are DIFFERENT PROCESSES. A handler running inside
        //    the FPM worker's after-response callback may insert a job that the crontab's
        //    `php artisan bridge:tick` (a separate CLI process, possibly a different OS
        //    user) must then see. Config is frozen at deploy; an in-memory registry is
        //    per-process; a cache entry is evictable — and an evicted registry is a
        //    periodic population that silently stops existing, which is DL-012's failure
        //    with extra steps.
        //  - The registry is the ENUMERABLE audit surface. "GET the job list and you have
        //    named the entire periodic population of this install" is only true if the list
        //    is durable and single-homed.
        //
        // ⛔ NO SECRET, TOKEN, OR CONFIG VALUE IS STORED HERE. Every column is printed by
        // `bridge:jobs` and summarised by `bridge:check`. `payload` is handler input and is
        // documented as operator-visible for exactly that reason.
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();

            // The instance name — the handle every runtime caller uses to insert, remove,
            // enable or disable. UNIQUE because insert is an UPSERT by name: a caller that
            // re-inserts "the seat watchdog" on every boot must converge on one row, not
            // mint a duplicate schedule each time.
            $table->string('name', 120)->unique();

            // The code-registered handler this instance invokes. NOT a class name and not
            // a callable: a registry key resolved through JobHandlerRegistry, so what a job
            // CAN DO is fixed by what exists in bridge code. A name that resolves to
            // nothing is a LOUD refusal at insert AND at run — never a silent skip.
            $table->string('handler', 64);

            // Seconds between passes. The scheduler asks at most this often; a handler with
            // its own interval gate (the standup digest has one) may still decline to act.
            $table->unsignedInteger('interval_s');

            // Who to ask about this job. A NAME, in operator vocabulary — a seat, a person,
            // a team. Not an address, not a credential.
            $table->string('owner', 120);

            // Where this job is documented. A path or URL; enumerated output prints it so
            // an unexplained job cannot hide behind a plausible name.
            $table->string('docs_ref', 255);

            // ⭐ WHY THIS CANNOT BE EVENT-DRIVEN — REQUIRED, NOT NULLABLE, and the whole
            // reason the column exists. The operator's standing rule is that a periodic job
            // is the LAST resort and an agent must consider a non-cron answer first. This
            // is not an approval gate (nobody is in the loop; insertion stays programmatic
            // and runtime) — it is a required ARGUMENT, so the inserter pays one sentence
            // and the enumeration answers "why is this periodic?" for every row rather than
            // only "what runs". A registry that has grown for bad reasons is then visible
            // at a glance instead of by archaeology.
            $table->text('justification');

            // Instance-level off switch. Disabled rows stay enumerable — a job somebody
            // turned off is a fact about this install; deleting it to silence it loses it.
            $table->boolean('enabled')->default(true);

            // Handler input, JSON or null. Operator-visible (see the no-secrets rule above).
            $table->json('payload')->nullable();

            // The scheduler's own bookkeeping. `next_due_at` NULL means "never run, due
            // now": both SQLite and MariaDB sort NULL first on ASC, so a freshly inserted
            // job wins the oldest-first ordering, which is the behaviour a caller inserting
            // a job expects.
            $table->timestamp('last_run_at', 3)->nullable();
            $table->timestamp('next_due_at', 3)->nullable();
            $table->index(['enabled', 'next_due_at'], 'scheduled_jobs_due_idx');

            // `ok` | `failed` | `refused`, or NULL for never-run. THREE outcomes and not a
            // boolean: a REFUSAL (unknown handler, unarmed capability) is not a failing job,
            // it is a job that was never allowed to run, and the two take opposite remedies
            // — one is a bug to fix, the other is a name or an approval to correct.
            $table->string('last_status', 16)->nullable();

            // The handler's own one-line account of its last successful pass, or the reason
            // text for a failure/refusal. Printed verbatim by `bridge:jobs`.
            $table->string('last_summary', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();

            // Reset to 0 by any clean pass. It is what lets `bridge:check` distinguish "this
            // blipped once" from "this has not worked since Tuesday" without a log dive.
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_jobs');
    }
};
