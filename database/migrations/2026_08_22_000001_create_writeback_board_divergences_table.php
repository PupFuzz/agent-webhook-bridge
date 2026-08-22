<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // card#7212: the DURABLE half. The log line records the (card board, mapped board)
        // pair on both arms since #556, but a log line is retention-bounded (14 days, and
        // the receiver prunes on its own gate since DL-199) — so "has a cross-board write
        // ever landed here?" is answerable only for the last fortnight, which is not an
        // answer to a question about the past. This table is the part that outlives it.
        //
        // ⛔ A ROW IS WRITTEN ONLY WHEN THE PAIR DIVERGES (MappedBoardGuard::belongs() is
        // false). Nothing is persisted on the happy path — a row per successful writeback
        // was considered and refused: it would put the bridge's write rate into the DB to
        // record the one thing that is already implied by every OTHER row's absence.
        // Growth is therefore the signal, not the cost: an empty table is the healthy
        // state, and `bridge:prune` deliberately does not touch this one (DL-300).
        //
        // ⛔ ONE ROW PER DISTINCT OBSERVATION, RE-OBSERVED IN PLACE. That is what makes
        // "never pruned" affordable, and it is not decoration: `bridge:reconcile` is an
        // arm of this record since DL-301 and runs from an hourly cron, so an append-only
        // table would take N rows per hour, for as long as one misconfiguration lasted, in
        // the one table nothing can clean up — and a count that rises with the cron
        // frequency is not a signal about divergences at all. The identity is the whole
        // observation (disposition, card, both boards, site); a repeat bumps `observations`
        // and `last_seen_at` and adds no row. Growth still IS the signal — of a divergence
        // never seen before.
        Schema::create('writeback_board_divergences', function (Blueprint $table) {
            $table->id();
            // 'refused' — the guard stopped the write; nothing was written to that card.
            // 'recorded' — the pair was rendered for a record of a write the bridge MADE.
            // The vocabulary is App\Bridge\Writeback\BoardDivergenceLedger::DISPOSITION_*.
            $table->string('disposition', 16);
            $table->unsignedBigInteger('card_id')->nullable();   // null when the row kanban handed back carried no id
            // The card's RAW board as kanban spelled it, stringified — the pair is stored
            // exactly as it was OBSERVED, never normalised through the predicate (the
            // reason is MappedBoardGuard::boardContext()'s docblock). Nullable because a
            // row with no readable board is refused like a foreign one, and that absence
            // is the thing to record.
            $table->string('card_board', 64)->nullable();
            $table->unsignedInteger('mapped_board');
            // The writeback call site that observed it (`Class::method (File.php:NN)`),
            // resolved from the call stack rather than passed in by 11 call sites — a
            // per-site argument is a spelling that drifts, and the N+1th site would omit it.
            $table->string('site', 191)->nullable();
            // The dedup identity: a hash of the whole stored observation, computed in one
            // place (BoardDivergenceLedger) so the columns are never enumerated twice. A
            // hash rather than a composite UNIQUE over the columns themselves, because two
            // of those columns are NULLABLE and both MariaDB and SQLite treat NULLs in a
            // unique index as distinct — the bound would leak on exactly the unreadable
            // responses that accompany the failure this table exists to catch.
            $table->char('observation_key', 64)->unique();
            // How many times this same observation has been made, and when it last was.
            // `created_at` is therefore the FIRST sighting and never moves: "when did this
            // start", "is it still happening", "how often" — the three questions N
            // identical rows answered by making the reader count them.
            $table->unsignedInteger('observations')->default(1);
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('last_seen_at', 3)->useCurrent();

            // The reader's query: "is there any `recorded` row, and when?" — a `refused`
            // row means the guard did its job, a `recorded` one means a divergent card
            // reached a site that reports a write.
            $table->index(['disposition', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writeback_board_divergences');
    }
};
