<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The current planet selection moved from the user record to the HTTP session,
     * so that each browser session (and each fresh login) has its own current planet
     * selection and defaults back to the home planet.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'planet_current')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['planet_current']);
                $table->dropColumn('planet_current');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('users', 'planet_current')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('planet_current', false, true)->nullable();
            });

            Schema::table('users', function (Blueprint $table) {
                $table->foreign('planet_current')->references('id')->on('planets');
            });
        }
    }
};
