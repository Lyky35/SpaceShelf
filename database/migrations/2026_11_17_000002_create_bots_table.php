<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            // users.id is an unsignedInteger in this schema (legacy), so the
            // FK column must use the same type or the constraint fails to form.
            $table->unsignedInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Personality profile key (see config/bots.php) plus the jittered
            // numeric weights actually used by the brain.
            $table->string('profile');
            $table->json('personality');

            $table->boolean('enabled')->default(true);

            // Scheduling bookkeeping for the bot tick command.
            $table->timestamp('next_action_at')->nullable()->index();
            $table->timestamp('last_action_at')->nullable();
            $table->unsignedInteger('total_ticks')->default(0);

            // Number of game actions executed in the last heartbeat, for
            // debugging. Null when a heartbeat failed with an exception.
            $table->integer('last_actions')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
