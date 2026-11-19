<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('directive_progress', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id', false, true);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('directive_id', 64);
            $table->string('mission_id', 64)->default('');
            $table->string('status', 16);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            // An empty mission_id is the chapter completion bonus row. Using '' instead of NULL
            // keeps the unique index effective (MySQL allows multiple NULLs in unique indexes).
            $table->unique(['user_id', 'directive_id', 'mission_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('directive_progress');
    }
};
