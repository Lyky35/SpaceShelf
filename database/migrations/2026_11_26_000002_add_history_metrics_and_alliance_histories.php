<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends the daily snapshots for the highscore page timeline graphs:
     * every score_histories row now also carries the full highscore metric
     * set (economy/research/military plus the military sub-counters and
     * honor), and a parallel alliance_score_histories table snapshots the
     * alliance highscores once per day.
     */
    public function up(): void
    {
        Schema::table('score_histories', function (Blueprint $table) {
            $table->bigInteger('economy')->default(0);
            $table->bigInteger('research')->default(0);
            $table->bigInteger('military')->default(0);
            // military_built / military_destroyed already exist from the
            // table's creation migration; complete the set here:
            $table->bigInteger('military_lost')->default(0);
            $table->bigInteger('honor')->default(0);
        });

        Schema::create('alliance_score_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            $table->foreign('alliance_id')->references('id')->on('alliances')->onDelete('cascade');
            $table->date('date');
            $table->bigInteger('general')->default(0);
            $table->bigInteger('economy')->default(0);
            $table->bigInteger('research')->default(0);
            $table->bigInteger('military')->default(0);
            $table->timestamps();

            $table->unique(['alliance_id', 'date']);
            $table->index(['date', 'general']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alliance_score_histories');

        Schema::table('score_histories', function (Blueprint $table) {
            $table->dropColumn(['economy', 'research', 'military', 'military_lost', 'honor']);
        });
    }
};
