<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            // card#11674: when this wait passes the repo's normal CI time, and where that time came
            // from (`history`, `default` or `override` — App\Bridge\CiAwait\OverdueDeadline). Null on
            // a row stored before this column; a refresh fills it.
            $table->timestamp('overdue_at', 3)->nullable();
            $table->string('overdue_basis', 16)->nullable();
            // When the ONE `ci_await_overdue` for this wait was staged. Set in the transaction that
            // stages it, only where it was still null, so a wait gets at most one; the row is kept.
            $table->timestamp('overdue_sent_at', 3)->nullable();
            $table->index('overdue_at');
        });
    }

    public function down(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            $table->dropIndex(['overdue_at']);
            $table->dropColumn(['overdue_at', 'overdue_basis', 'overdue_sent_at']);
        });
    }
};
