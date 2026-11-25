<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cumulative combat statistics backing the military highscore sub-categories
     * (vanilla highscore types 4 = military lost, 5 = military built,
     * 6 = military destroyed, 7 = honor). The counters live on the users table
     * as the source of truth and are copied into the highscore tables by the
     * scheduler commands; honor may go negative (vanilla "bandit" territory).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('military_built')->default(0);
            $table->bigInteger('military_destroyed')->default(0);
            $table->bigInteger('military_lost')->default(0);
            $table->bigInteger('honor')->default(0);
        });

        Schema::table('highscores', function (Blueprint $table) {
            $table->bigInteger('military_built')->default(0);
            $table->bigInteger('military_destroyed')->default(0);
            $table->bigInteger('military_lost')->default(0);
            $table->bigInteger('honor')->default(0);
            $table->bigInteger('military_built_rank')->nullable();
            $table->bigInteger('military_destroyed_rank')->nullable();
            $table->bigInteger('military_lost_rank')->nullable();
            $table->bigInteger('honor_rank')->nullable();
        });

        Schema::table('alliance_highscores', function (Blueprint $table) {
            $table->bigInteger('military_built')->default(0);
            $table->bigInteger('military_destroyed')->default(0);
            $table->bigInteger('military_lost')->default(0);
            $table->bigInteger('honor')->default(0);
            $table->integer('military_built_rank')->nullable();
            $table->integer('military_destroyed_rank')->nullable();
            $table->integer('military_lost_rank')->nullable();
            $table->integer('honor_rank')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['military_built', 'military_destroyed', 'military_lost', 'honor']);
        });

        Schema::table('highscores', function (Blueprint $table) {
            $table->dropColumn([
                'military_built', 'military_destroyed', 'military_lost', 'honor',
                'military_built_rank', 'military_destroyed_rank', 'military_lost_rank', 'honor_rank',
            ]);
        });

        Schema::table('alliance_highscores', function (Blueprint $table) {
            $table->dropColumn([
                'military_built', 'military_destroyed', 'military_lost', 'honor',
                'military_built_rank', 'military_destroyed_rank', 'military_lost_rank', 'honor_rank',
            ]);
        });
    }
};
