<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#10567 B4: the fleet ledger — one row per board-tools agent, holding what that
        // seat has REPORTED about its own channel-server client. App\Bridge\ClientUpdate\SeatClientLedger
        // owns every write and App\Bridge\ClientUpdate\ClientFleet derives each seat's state from it.
        //
        // ⛔ EVERY VALUE HERE IS THE SEAT'S OWN REPORT, never something the bridge read off the
        // seat: an account may only read its own files. It arrives two ways — a board-tools call
        // (`last_call_*`, `running_*`, `last_exempt_*`) and the updater's `client_report` of its
        // install log (`installed_*`, `last_launch_*`, `log_*`).
        //
        // ⛔ NO SECRET, TOKEN OR CONFIG VALUE. Names, versions, digests and timestamps — every
        // column is printed by `bridge:client-fleet`.
        Schema::create('seat_client_states', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191)->unique();
            // The install log this row's `log_*` columns follow (a seat mints it at bootstrap).
            $table->string('install_id', 64)->nullable();

            // The last EVIDENCE-CARRYING board-tools call: one that did not declare an exempt
            // `caller`. A null `last_call_launch_id` beside a non-null `last_call_at` is a
            // client with no launch identity — one not started by the updater's entry point.
            $table->timestamp('last_call_at', 3)->nullable();
            $table->string('last_call_client_version', 32)->nullable();
            $table->string('last_call_launch_id', 64)->nullable();

            // Written ONLY by a call carrying a `launch` object; a call without one never
            // touches these (card#10567 r2 M-3 / M-6).
            $table->string('running_launch_id', 64)->nullable();
            $table->string('running_bridge_release', 32)->nullable();
            $table->string('running_client_version', 32)->nullable();
            $table->timestamp('running_launch_first_seen_at', 3)->nullable();
            $table->timestamp('running_seen_at', 3)->nullable();

            // A call declaring an App\Bridge\ClientUpdate\ExemptCaller case (the enum is the list): stamped here and nowhere else.
            $table->timestamp('last_exempt_call_at', 3)->nullable();
            $table->string('last_exempt_caller', 16)->nullable();

            // From the install log: the last successful bootstrap/install the seat reported.
            $table->string('installed_bridge_release', 32)->nullable();
            $table->string('installed_client_version', 32)->nullable();
            $table->string('installed_files_json_sha256', 64)->nullable();

            // From the install log: the outcome of the most recent launch-time update it reported.
            $table->string('last_launch_id', 64)->nullable();
            $table->string('last_launch_result', 16)->nullable();
            $table->string('last_launch_error', 500)->nullable();
            // When this bridge FIRST received a report of `last_launch_id` — its clock, bounding when that launch began.
            $table->timestamp('last_launch_first_reported_at', 3)->nullable();
            $table->timestamp('last_report_at', 3)->nullable();

            // The head of the current install's log, and whether its stored log is not a whole,
            // unaltered chain (SeatClientLedger::chainBreak() is the one definition).
            $table->unsignedBigInteger('log_seq')->nullable();
            $table->string('log_head_sha256', 64)->nullable();
            $table->boolean('log_discontinuity')->default(false);
            $table->string('log_discontinuity_reason', 500)->nullable();

            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_client_states');
    }
};
