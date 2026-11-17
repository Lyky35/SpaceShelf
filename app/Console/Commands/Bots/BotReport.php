<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Bot;

/**
 * Phase 6 tuning harness (docs/BOT-AI-PLAN.md §8): aggregates what the bots
 * actually do on a live server — scores, mission mix, expedition Dark Matter
 * income — per behavior profile, so config/bots.php weights can be tuned
 * against evidence instead of guesswork. Read-only.
 */
#[Description('Per-profile bot activity metrics for brain tuning')]
#[Signature('spaceshelf:bot:report {--hours=24 : Look-back window for mission and dark matter metrics}')]
class BotReport extends Command
{
    /**
     * Mission types shown as columns → the engine's mission type id.
     *
     * @var array<string, int>
     */
    private const MISSION_COLUMNS = [
        'raids' => 1,
        'transports' => 3,
        'spies' => 6,
        'recycles' => 8,
        'expeditions' => 15,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = (int) Date::now()->subHours($hours)->timestamp;

        /** @var array<string, mixed> $personalities */
        $personalities = config('bots.personalities', []);

        $rows = [];
        foreach (array_keys((array) $personalities) as $profile) {
            $rows[] = [
                $profile,
                Bot::where('profile', $profile)->count(),
                Bot::where('profile', $profile)->where('enabled', true)->count(),
                number_format((int) $this->averageScore($profile)),
                ...array_values($this->missionCounts($profile, $since)),
                number_format((int) $this->darkMatterIncome($profile, $hours)),
            ];
        }

        $headers = [
            'profile',
            'bots',
            'enabled',
            'avg score',
            ...array_keys(self::MISSION_COLUMNS),
            'DM income',
        ];

        $this->info(sprintf('Bots report, window: last %d hour(s).', $hours));
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Mean general highscore of all bots on this profile.
     */
    private function averageScore(string $profile): float
    {
        return (float) DB::table('highscores')
            ->join('bots', 'bots.user_id', '=', 'highscores.player_id')
            ->where('bots.profile', $profile)
            ->avg('highscores.general');
    }

    /**
     * Dispatched missions per tracked mission type in the look-back window.
     *
     * @return array<string, int>
     */
    private function missionCounts(string $profile, int $sinceTimestamp): array
    {
        $counts = DB::table('fleet_missions')
            ->join('bots', 'bots.user_id', '=', 'fleet_missions.user_id')
            ->where('bots.profile', $profile)
            ->where('fleet_missions.time_departure', '>=', $sinceTimestamp)
            ->selectRaw('mission_type, COUNT(*) as cnt')
            ->groupBy('mission_type')
            ->pluck('cnt', 'mission_type');

        $row = [];
        foreach (self::MISSION_COLUMNS as $label => $typeId) {
            $row[$label] = (int) ($counts[$typeId] ?? 0);
        }

        return $row;
    }

    /**
     * Positive Dark Matter gains (expedition finds, officer upkeep is
     * negative and stays out) across this profile's bots.
     */
    private function darkMatterIncome(string $profile, int $hours): float
    {
        return (float) DB::table('dark_matter_transactions')
            ->join('bots', 'bots.user_id', '=', 'dark_matter_transactions.user_id')
            ->where('bots.profile', $profile)
            ->where('dark_matter_transactions.amount', '>', 0)
            ->where('dark_matter_transactions.created_at', '>=', Date::now()->subHours($hours))
            ->sum('dark_matter_transactions.amount');
    }
}
