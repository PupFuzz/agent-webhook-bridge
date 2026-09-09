<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#8974 / DL-364: the client-half row records WHICH DOOR opened (`transport`)
        // and how the serving process was started (`call_provenance`) — and nothing about
        // the VERSION of the channel server the seat is running. So a seat on a stale copy
        // was invisible: `bridge:check` knew the version this bridge BUNDLES and could
        // compare it against nothing. Measured on one install: a seat at 0.4.4 calling a
        // bridge at v0.82.0 bundling 0.9.12, with `board_correct_card` — a tool the 0.4.4
        // snapshot never advertised — reported "absent from my surface" and attributed to
        // the BRIDGE. The remedy for a stale seat (re-deploy the snapshot, restart the
        // session) and the remedy for a broken bridge half are opposites, which is the
        // same misattribution cost DL-313 exists to remove one layer up.
        //
        // ⛔ CALLER-SUPPLIED, WHICH MAKES IT DIFFERENT FROM EVERY OTHER COLUMN HERE. The
        // agent, transport and provenance are all established BY THE BRIDGE; this one is a
        // string the far end sends. The row is printed verbatim into a `bridge:check` line,
        // so `App\Bridge\Tools\ClientVersion` reduces it to a conservative version-shaped
        // token — or to NULL — before anything reaches this column, and 32 is that token's
        // cap rather than a guess about npm. ⛔ THE REDUCTION IS ANCHORED `^…\z` AND NOT
        // `^…$`: PCRE's `$` matches before a FINAL NEWLINE, so the first cut of that class
        // stored "0.4.4\n" and the check printed one finding as two lines. The rule this
        // column depends on is that nothing reaching it can contain a newline AT ANY
        // POSITION, trailing included.
        //
        // ⚑ NULLABLE, ADDITIVE, NOT BACKFILLED, AND NULL IS A REAL STATE. It is what a
        // client older than the first reporting snapshot sends (nothing), what a garbage
        // value is reduced to, and what every row written before this migration carries.
        // The reading check prints "client version not reported" for it and warns on none
        // of them: an absent report is not a measured old version.
        Schema::table('board_tools_client_calls', function (Blueprint $table) {
            $table->string('client_version', 32)->nullable()->after('call_provenance');
        });
    }

    public function down(): void
    {
        Schema::table('board_tools_client_calls', function (Blueprint $table) {
            $table->dropColumn('client_version');
        });
    }
};
