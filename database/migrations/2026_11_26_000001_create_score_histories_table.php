<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily snapshots of every player's score and cumulative combat counters,
     * powering the overview page timeline charts (score history since the
     * first snapshot, ships built/destroyed over time). Snapshots are written
     * once per day by `spaceshelf:scheduler:record-score-history`.
     *
     * The raw ship counters on the users table count unit amounts of ship
     * type only (defense structures are intentionally excluded), unlike the
     * points-based military_built / military_destroyed counters.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('ships_built')->default(0);
            $table->unsignedBigInteger('ships_destroyed')->default(0);
        });

        Schema::create('score_histories', function (Blueprint $table) {
            $table->id();
            // Must match the users.id column type (INT UNSIGNED) for the FK.
            $table->integer('player_id', false, true);
            $table->foreign('player_id')->references('id')->on('users')->onDelete('cascade');
            $table->date('date');
            $table->bigInteger('general')->default(0);
            $table->bigInteger('military_built')->default(0);
            $table->bigInteger('military_destroyed')->default(0);
            $table->unsignedBigInteger('ships_built')->default(0);
            $table->unsignedBigInteger('ships_destroyed')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'date']);
            $table->index(['date', 'general']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_histories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ships_built', 'ships_destroyed']);
        });
    }
};
