<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#7756 / DL-313: the bridge's ONLY observation of the CALLING SEAT's half of
        // the board-tools door. `bridge:check`'s board-tools legs all look at the BRIDGE
        // side — the pinned `authorized_keys` line, the transport default, the writeback
        // token's board window — so a seat that was never wired client-side and a seat
        // whose pinned line is merely absent rendered identically, and the two take
        // OPPOSITE remedies.
        //
        // ⛔ THE BRIDGE MAY NOT READ THE SEAT'S OWN FILES to close that gap (an account may
        // only read its own; the same rule that keeps `channel.server_path` an operator
        // DECLARATION rather than an inference off the agent's `.mcp.json` — DL-229). So
        // the seat SELF-REPORTS, and the report is a successful board-tools call: reaching
        // `BoardToolDispatcher` at all requires the whole client chain (keypair, seeded
        // known_hosts, BRIDGE_TOOLS_* in `.mcp.json`, a deployed channel server, the pinned
        // forced command). One row per agent, written at that success point.
        //
        // ⛔ A DB TABLE AND NOT A STATE-DIR FILE, and the reason is the two front doors'
        // OS users. `bridge:tools-call --agent=X` is spawned by sshd as `board_tools
        // .ssh_account`; `POST /agent-tools/call` runs as the PHP-FPM pool user serving the
        // vhost. A file written by one is at best unreadable and at worst unwritable by the
        // other (the state dir is 0700 under a 0700 config dir), so the two transports would
        // keep two different answers — or one would silently keep none. Both users already
        // hold the same DB credential out of the one `.env`, which is what makes the row the
        // only surface both doors can actually share.
        //
        // ⛔ NO SECRET, TOKEN OR CONFIG VALUE IS STORED HERE. The row is a NAME (the agent),
        // a NAME (the transport) and two timestamps — nothing that could leak into a
        // `bridge:check` line, a log, or a JSON document.
        Schema::create('board_tools_client_calls', function (Blueprint $table) {
            $table->id();
            // The per-agent YAML name the call was dispatched FOR — the same string
            // `bridge:check` prints and the same one the pinned forced command carries in
            // `--agent=`. UNIQUE because the question is "when did this seat last call",
            // which has exactly one answer: a row per CALL would put the board-tools call
            // rate into a table with no retention, to record a fact each new row destroys.
            $table->string('agent', 191)->unique();
            // `ssh` or `http` — which door the last successful call came through. A NAME,
            // never an endpoint or a credential.
            $table->string('transport', 16);
            // `created_at` is the FIRST successful call this install ever recorded for the
            // agent and never moves; `last_success_at` is the freshest one. The check reads
            // the second and prints its AGE, so an operator judges the number rather than a
            // boolean; the freshness TTL only stops an ancient stamp reading as current.
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('last_success_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_tools_client_calls');
    }
};
