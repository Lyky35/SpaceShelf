<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            // Counts consecutive processing failures per mission. A mission whose
            // processing throws is retried on the next update, but once it reaches
            // the quarantine threshold PlayerService marks it processed so a single
            // broken mission can no longer block the whole account (#1641).
            $table->unsignedTinyInteger('update_failures')->default(0)->after('processed_hold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_missions', function (Blueprint $table) {
            $table->dropColumn('update_failures');
        });
    }
};
