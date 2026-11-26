<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Bots\BotRegistry;

/**
 * Records and serves the daily score/ship snapshots behind the overview
 * page timeline charts.
 *
 * Snapshots copy the live counters (highscores.general plus the cumulative
 * combat counters on the users table) once per day; the charts therefore
 * cover everything since the first snapshot was taken. Ships built/destroyed
 * are raw unit amounts of ship type (defense excluded); note that in ACS
 * fights every participating attacker is credited with the full per-battle
 * losses, so the server-wide "destroyed" total can slightly overcount
 * (same convention as the military destroyed highscore).
 */
class ScoreHistoryService
{
    public function __construct(private BotRegistry $botRegistry)
    {
    }

    /**
     * Write one snapshot row per player for today. Re-running on the same
     * day just refreshes that day's row, so manual runs are safe.
     *
     * @return int Number of players snapshotted.
     */
    public function recordDailySnapshots(): int
    {
        $now = Date::now();
        $today = $now->toDateString();

        $users = DB::table('users')
            ->select('id', 'military_built', 'military_destroyed', 'ships_built', 'ships_destroyed')
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $generalScores = DB::table('highscores')
            ->whereIn('player_id', $users->pluck('id'))
            ->pluck('general', 'player_id');

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'player_id' => $user->id,
                'date' => $today,
                'general' => (int) ($generalScores[$user->id] ?? 0),
                'military_built' => (int) $user->military_built,
                'military_destroyed' => (int) $user->military_destroyed,
                'ships_built' => (int) $user->ships_built,
                'ships_destroyed' => (int) $user->ships_destroyed,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('score_histories')->upsert(
            $rows,
            ['player_id', 'date'],
            ['general', 'military_built', 'military_destroyed', 'ships_built', 'ships_destroyed', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * General-score timeline for the strongest players (bots included).
     *
     * The top N is picked from the latest snapshot; each player's history
     * is then rendered across every recorded date (null where the player
     * had no snapshot yet, e.g. accounts created later).
     *
     * @param int $limit Number of players to chart.
     * @return array{dates: array<int, string>, series: array<int, array{name: string, bot: bool, values: array<int, int|null>}>}
     */
    public function scoreTimeline(int $limit = 15): array
    {
        $latestDate = DB::table('score_histories')->max('date');
        if ($latestDate === null) {
            return ['dates' => [], 'series' => []];
        }

        $dates = DB::table('score_histories')->distinct()->orderBy('date')->pluck('date')->all();

        $topPlayers = DB::table('score_histories')
            ->join('users', 'users.id', '=', 'score_histories.player_id')
            ->where('score_histories.date', $latestDate)
            ->orderByDesc('score_histories.general')
            ->limit($limit)
            ->select('users.id', 'users.username')
            ->get();

        $botIds = $this->botRegistry->botUserIds();

        $history = DB::table('score_histories')
            ->whereIn('player_id', $topPlayers->pluck('id'))
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        $series = [];
        foreach ($topPlayers as $player) {
            $byDate = collect($history[$player->id] ?? [])->keyBy('date');
            $series[] = [
                'name' => $player->username,
                'bot' => in_array((int) $player->id, $botIds, true),
                'values' => array_map(
                    fn ($date) => isset($byDate[$date]) ? (int) $byDate[$date]->general : null,
                    $dates,
                ),
            ];
        }

        return ['dates' => $dates, 'series' => $series];
    }

    /**
     * Server-wide ships built/destroyed over time: the cumulative totals
     * are summed across all per-player snapshots of each recorded date.
     *
     * @return array{dates: array<int, string>, built: array<int, int>, destroyed: array<int, int>, total_built: int, total_destroyed: int}
     */
    public function shipTimeline(): array
    {
        $rows = DB::table('score_histories')
            ->select('date', DB::raw('SUM(ships_built) as ships_built, SUM(ships_destroyed) as ships_destroyed'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dates = [];
        $built = [];
        $destroyed = [];
        foreach ($rows as $row) {
            $dates[] = $row->date;
            $built[] = (int) $row->ships_built;
            $destroyed[] = (int) $row->ships_destroyed;
        }

        return [
            'dates' => $dates,
            'built' => $built,
            'destroyed' => $destroyed,
            'total_built' => $built === [] ? 0 : end($built),
            'total_destroyed' => $destroyed === [] ? 0 : end($destroyed),
        ];
    }
}
