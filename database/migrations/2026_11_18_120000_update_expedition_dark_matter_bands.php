<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-base expedition dark matter finds from 150-200/300-400 to
     * 1200-3200/2400-6400. Astrophysics research now scales the find by
     * +25% per level at runtime (DarkMatterService::calculateExpeditionReward).
     */
    public function up(): void
    {
        $settings = [
            'expedition_dark_matter_min_no_pathfinder' => '1200',
            'expedition_dark_matter_max_no_pathfinder' => '3200',
            'expedition_dark_matter_min_pathfinder' => '2400',
            'expedition_dark_matter_max_pathfinder' => '6400',
        ];

        foreach ($settings as $key => $value) {
            DB::table('settings')->where('key', $key)->update(['value' => $value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $settings = [
            'expedition_dark_matter_min_no_pathfinder' => '150',
            'expedition_dark_matter_max_no_pathfinder' => '200',
            'expedition_dark_matter_min_pathfinder' => '300',
            'expedition_dark_matter_max_pathfinder' => '400',
        ];

        foreach ($settings as $key => $value) {
            DB::table('settings')->where('key', $key)->update(['value' => $value]);
        }
    }
};
