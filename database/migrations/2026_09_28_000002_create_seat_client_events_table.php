<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#10567 B4: the append-only client-update event log. Two writers, both in
        // App\Bridge\ClientUpdate\SeatClientLedger:
        //   - a seat's install-log entries, received by `client_report` (install_id = the
        //     seat's own, seq = the seat's own, line_sha256 = sha256 of the exact line);
        //   - the bridge's own events (install_id = 'bridge', seq = per agent from 1):
        //     `approve` (`bridge:client-approve`), `rebootstrap` (a seat reported a new
        //     install id) and `resend_conflict` (a held seq arrived again with different
        //     bytes; `source` names the seat install it is about).
        //
        // ⛔ APPEND-ONLY BY CONVENTION, CHECKED STATICALLY: nothing in app/ updates or deletes a
        // row (SeatClientEventsNoWriterTest greps for it). The database does not enforce it.
        // Retention does not touch it.
        Schema::create('seat_client_events', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 191);
            $table->string('install_id', 64);
            $table->unsignedBigInteger('seq');
            $table->string('action', 32);
            $table->string('from_bridge_release', 32)->nullable();
            $table->string('to_bridge_release', 32)->nullable();
            $table->string('client_version', 32)->nullable();
            $table->string('pack_sha256', 64)->nullable();
            // The pack's content digest — what an approval is keyed on (operator ruling, card#10567 comment 6774).
            $table->string('files_json_sha256', 64)->nullable();
            $table->string('source', 255)->nullable();
            $table->string('actor', 64)->nullable();
            $table->string('result', 16)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('launch_id', 64)->nullable();
            $table->string('prev_sha256', 64)->nullable();
            $table->string('line_sha256', 64)->nullable();
            // The seat's own clock, as it wrote it; `received_at` is this bridge's.
            $table->string('occurred_at', 40)->nullable();
            $table->timestamp('received_at', 3)->useCurrent();
            $table->unique(['agent', 'install_id', 'seq']);
            $table->index(['agent', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_client_events');
    }
};
