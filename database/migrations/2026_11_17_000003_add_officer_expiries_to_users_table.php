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
        Schema::table('users', function (Blueprint $table) {
            // Officer hire expiry timestamps (null = never hired, past = expired)
            $table->timestamp('commander_expires_at')->nullable()->after('character_class_changed_at');
            $table->timestamp('admiral_expires_at')->nullable()->after('commander_expires_at');
            $table->timestamp('engineer_expires_at')->nullable()->after('admiral_expires_at');
            $table->timestamp('geologist_expires_at')->nullable()->after('engineer_expires_at');
            $table->timestamp('technocrat_expires_at')->nullable()->after('geologist_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'commander_expires_at',
                'admiral_expires_at',
                'engineer_expires_at',
                'geologist_expires_at',
                'technocrat_expires_at',
            ]);
        });
    }
};
