<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#8973 / DL-360: the durable witness that tells a LOST `board_tools` block
        // from a seat that never had one. `bridge:check` reads the CURRENT config, so an
        // install whose block was dropped by a home-dir restore or a hand edit renders
        // identically to an install that was never provisioned — silence, exit 0. That is
        // what let a live install run ten days with dead two-way board tools and no check,
        // log or alert.
        //
        // ⛔ IT IS NOT `board_tools_client_calls`, AND THAT IS AN OPERATOR RULING, NOT AN
        // OVERSIGHT. A client-calls row records that the DOOR OPENED for an agent — which
        // `bridge:check --probe-tools`, `--self-cert` and a hand-run `bridge:tools-call`
        // all stamp — so ANY agent ever probed and later renamed or removed carries one
        // FOREVER. Reading that row as "this seat had a block" would flip `bridge:check`'s
        // exit code on installs nobody touched, the moment they upgraded. This table
        // records something narrower and answerable: a run of this install PARSED an
        // enabled block for this agent.
        //
        // ⚑ WHAT IT SURVIVES, UNDER A STATED CONDITION. The point of a DB row is that a
        // restore of the config tree does not bring it back, so its absence from the config
        // becomes visible. That holds for MariaDB and for a SQLite file OUTSIDE the
        // restored path; it does NOT hold for a SQLite file inside it, and
        // `docs/board-tools.md` says so rather than asserting survival.
        //
        // ⛔ NAMES AND TIMESTAMPS ONLY, plus the operator's own retirement sentence —
        // never a token, a secret or a config VALUE. Every column here is printed verbatim
        // into a `bridge:check` line, so anything stored is disclosed. The scope columns
        // are ids the operator already wrote in their own YAML.
        //
        // ⚑ IT WRITES `now()` UNDER THE CORRECTED CONFIG FROM DAY ONE, so
        // `2026_09_05_000001_correct_php_written_timestamps_to_utc`'s repair list does not
        // gain it: there is no window in which this table held a PHP-written local-zone
        // stamp.
        Schema::create('board_tools_config_seen', function (Blueprint $table) {
            $table->id();
            // The per-agent YAML name the block was parsed FOR — the same string
            // `board_tools_client_calls.agent` carries and the same one `bridge:check`
            // prints. UNIQUE because the question is "has this install ever seen a block
            // for this seat", which has exactly one answer.
            $table->string('agent', 191)->unique();
            // The scope AS SEEN at the last enabled sighting, so the LOST line can tell the
            // operator what to restore. Nullable because a row can be born by a RETIREMENT
            // of a seat this install never saw enabled.
            $table->string('transport', 16)->nullable();
            $table->unsignedInteger('board_id')->nullable();
            $table->unsignedInteger('swimlane_id')->nullable();
            // The window the block was observed over. `first_seen_at` is set on INSERT and
            // never moved — it is what makes the LOST line say "from X to Y" rather than
            // "at Y", which is the difference between a seat that was briefly configured
            // and one that ran for months.
            $table->timestamp('first_seen_at', 3)->nullable();
            $table->timestamp('last_seen_at', 3)->nullable();
            // The run that SAW the operator's `retired:` key, and the operator's own value
            // VERBATIM. Named for what it is: the operator's own date rides inside the
            // reason string, because a parsed date would be this program's reading of their
            // sentence rather than their statement.
            $table->timestamp('retired_seen_at', 3)->nullable();
            $table->text('retired_reason')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_tools_config_seen');
    }
};
