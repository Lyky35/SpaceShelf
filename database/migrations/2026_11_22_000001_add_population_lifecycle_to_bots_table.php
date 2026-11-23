<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bot population lifecycle columns: hiatus bookkeeping (when a bot went
     * silent, when it is expected back; a null return time on a hiatused bot
     * marks it permanently inactive) and the per-bot weekly dice clock used
     * by the population scheduler.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table): void {
            $table->timestamp('inactive_since')->nullable()->index();
            $table->timestamp('inactive_until')->nullable();
            $table->timestamp('last_roll_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table): void {
            $table->dropColumn(['inactive_since', 'inactive_until', 'last_roll_at']);
        });
    }
};
