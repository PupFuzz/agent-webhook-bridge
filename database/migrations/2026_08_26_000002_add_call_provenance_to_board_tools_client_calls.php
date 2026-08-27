<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#7836 / DL-316: the client-half row records WHICH DOOR opened (`transport`)
        // but not how the serving PROCESS was started — so `bridge:check --probe-tools`,
        // `provision-board-tools.py --self-cert` and an operator hand-running
        // `php artisan bridge:tools-call --agent=X` on the bridge host were one row with the
        // seat's own call. A review REPRODUCED a green line from a process with no keypair,
        // no known_hosts, no .mcp.json and no channel server. DL-313 shipped the honest
        // wording; this column is the discrimination that wording stood in for.
        //
        // ⭐ WHAT IT CAN SAY: sshd exports SSH_CONNECTION into the forced command's
        // environment, so `bridge:tools-call` spawned BY sshd is distinguishable from the
        // same command hand-run in a shell on this host. ⛔ WHAT IT STILL CANNOT: which
        // ssh CLIENT — `--probe-tools-ssh` and `--self-cert` drive real ssh round-trips and
        // land here identically. The reading check prints that remainder.
        //
        // ⛔ NAMES AND BOOLEANS ONLY — never a value, exactly as the create migration says
        // of every other column here. SSH_CONNECTION is `<client-ip> <client-port>
        // <server-ip> <server-port>`; NONE of it is stored. `App\Bridge\Tools\CallProvenance`
        // reduces it to one of two NAMES before anything reaches this column, and the row is
        // printed verbatim into a `bridge:check` line, which is why that reduction happens
        // at the read and not here.
        //
        // ⚑ NULLABLE, ADDITIVE, AND DELIBERATELY NOT BACKFILLED. Every row written before
        // this migration was recorded by a writer that never asked the question, so its
        // provenance is UNKNOWN — and the two backfills available are both lies: `not_sshd`
        // would assert a measurement nobody took, and `sshd` would silently upgrade exactly
        // the historic rows this card exists to stop over-reading. NULL is the third state,
        // it is the honest one, and `BoardToolsClientHalfCheck` treats it as unproven —
        // an old row keeps the weaker verdict it already had until the seat calls again.
        Schema::table('board_tools_client_calls', function (Blueprint $table) {
            $table->string('call_provenance', 16)->nullable()->after('transport');
        });
    }

    public function down(): void
    {
        Schema::table('board_tools_client_calls', function (Blueprint $table) {
            $table->dropColumn('call_provenance');
        });
    }
};
