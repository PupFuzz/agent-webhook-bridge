<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            // card#12197: the ids of every run a commit started that a complete read of this head
            // has counted so far — a list of integers, only ever grown. A later list that omits one
            // is a partial list, a failed read, never a settle. Null until the first complete read.
            $table->json('known_run_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            $table->dropColumn('known_run_ids');
        });
    }
};
