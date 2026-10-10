<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            // card#11600 / DL-468: the 401 or 403 the last runs read answered, waiting for a read at
            // least 60 s later to confirm it. One such answer can be a secondary rate limit that
            // names no reset, or a token being rotated; only a confirming read ends the await.
            $table->unsignedSmallInteger('unconfirmed_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ci_awaits', function (Blueprint $table) {
            $table->dropColumn('unconfirmed_status');
        });
    }
};
