<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OGame\Bots\BotRegistry;

/**
 * Records and serves the daily score snapshots behind the highscore page
 * timeline graphs (one graph per highscore tab: points, economy, research,
 * military incl. its sub-categories, and alliances).
 *
 * Snapshots copy the live highscore tables plus the raw ship counters on
 * the users table once per day; the graphs therefore cover everything
 * since the first snapshot was taken. Ships built/destroyed are raw unit
 * amounts of ship type (defense excluded); note that in ACS fights every
 * participating attacker is credited with the full per-battle losses, so
 * the server-wide "destroyed" total can slightly overcount (same
 * convention as the military destroyed highscore).
 */
class ScoreHistoryService
{
    /**
     * Player metrics available for timelines, mapped to their score_histories column.
     */
    public const METRICS = [
        'general' => 'general',
        'economy' => 'economy',
        'research' => 'research',
        'military' => 'military',
        'military_built' => 'military_built',
        'military_destroyed' => 'military_destroyed',
        'military_lost' => 'military_lost',
        'honor' => 'honor',
    ];

    /**
     * Alliance metrics, mapped to their alliance_score_histories column.
     */
    public const ALLIANCE_METRICS = [
        'general' => 'general',
        'economy' => 'economy',
        'research' => 'research',
        'military' => 'military',
    ];

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
            ->select('id', 'ships_built', 'ships_destroyed')
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $highscores = DB::table('highscores')
            ->whereIn('player_id', $users->pluck('id'))
            ->get()
            ->keyBy('player_id');

        $rows = [];
        foreach ($users as $user) {
            $highscore = $highscores->get($user->id);

            $rows[] = [
                'player_id' => $user->id,
                'date' => $today,
                'general' => (int) ($highscore->general ?? 0),
                'economy' => (int) ($highscore->economy ?? 0),
                'research' => (int) ($highscore->research ?? 0),
                'military' => (int) ($highscore->military ?? 0),
                'military_built' => (int) ($highscore->military_built ?? 0),
                'military_destroyed' => (int) ($highscore->military_destroyed ?? 0),
                'military_lost' => (int) ($highscore->military_lost ?? 0),
                'honor' => (int) ($highscore->honor ?? 0),
                'ships_built' => (int) $user->ships_built,
                'ships_destroyed' => (int) $user->ships_destroyed,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('score_histories')->upsert(
            $rows,
            ['player_id', 'date'],
            [
                'general', 'economy', 'research', 'military',
                'military_built', 'military_destroyed', 'military_lost', 'honor',
                'ships_built', 'ships_destroyed', 'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * Write one alliance snapshot row for today from the alliance
     * highscores. Same-day reruns update rather than duplicate.
     *
     * @return int Number of alliances snapshotted.
     */
    public function recordAllianceSnapshots(): int
    {
        $now = Date::now();
        $today = $now->toDateString();

        $highscores = DB::table('alliance_highscores')->get();

        if ($highscores->isEmpty()) {
            return 0;
        }

        $rows = [];
        foreach ($highscores as $highscore) {
            $rows[] = [
                'alliance_id' => $highscore->alliance_id,
                'date' => $today,
                'general' => (int) $highscore->general,
                'economy' => (int) $highscore->economy,
                'research' => (int) $highscore->research,
                'military' => (int) $highscore->military,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('alliance_score_histories')->upsert(
            $rows,
            ['alliance_id', 'date'],
            ['general', 'economy', 'research', 'military', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * Metric timeline for the strongest players (bots included).
     *
     * The top N is picked from the latest snapshot ordered by the requested
     * metric; each player's history is then rendered across every recorded
     * date (null where the player had no snapshot yet, e.g. accounts
     * created later).
     *
     * @param int $limit Number of players to chart.
     * @param string $metric One of the METRICS keys.
     * @return array{dates: array<int, string>, series: array<int, array{name: string, bot: bool, values: array<int, int|null>}>}
     */
    public function scoreTimeline(int $limit = 15, string $metric = 'general'): array
    {
        $column = self::METRICS[$metric] ?? throw new InvalidArgumentException("Unknown score history metric: {$metric}");

        $botIds = $this->botRegistry->botUserIds();

        return $this->buildTimeline(
            'score_histories',
            'player_id',
            'users',
            'username',
            $column,
            $limit,
            fn (int $id): bool => in_array($id, $botIds, true),
        );
    }

    /**
     * Metric timeline for the strongest alliances.
     *
     * @param int $limit Number of alliances to chart.
     * @param string $metric One of the ALLIANCE_METRICS keys.
     * @return array{dates: array<int, string>, series: array<int, array{name: string, bot: bool, values: array<int, int|null>}>}
     */
    public function allianceTimeline(int $limit = 15, string $metric = 'general'): array
    {
        $column = self::ALLIANCE_METRICS[$metric] ?? throw new InvalidArgumentException("Unknown alliance score history metric: {$metric}");

        return $this->buildTimeline(
            'alliance_score_histories',
            'alliance_id',
            'alliances',
            'alliance_name',
            $column,
            $limit,
            fn (int $id): bool => false,
        );
    }

    /**
     * Shared timeline builder for the per-player and per-alliance tables.
     *
     * @param string $historyTable Snapshot table holding (subject_id, date, column).
     * @param string $subjectIdColumn Subject key column in the snapshot table.
     * @param string $subjectTable Table providing the display name (with id).
     * @param string $nameColumn Display name column of the subject table.
     * @param string $column Metric column to chart.
     * @param int $limit Number of subjects to chart.
     * @param callable(int): bool $isBot Marks legend entries as bot-driven.
     * @return array{dates: array<int, string>, series: array<int, array{name: string, bot: bool, values: array<int, int|null>}>}
     */
    private function buildTimeline(
        string $historyTable,
        string $subjectIdColumn,
        string $subjectTable,
        string $nameColumn,
        string $column,
        int $limit,
        callable $isBot,
    ): array {
        $latestDate = DB::table($historyTable)->max('date');
        if ($latestDate === null) {
            return ['dates' => [], 'series' => []];
        }

        $dates = DB::table($historyTable)->distinct()->orderBy('date')->pluck('date')->all();

        $topSubjects = DB::table($historyTable)
            ->join($subjectTable, "{$subjectTable}.id", '=', "{$historyTable}.{$subjectIdColumn}")
            ->where("{$historyTable}.date", $latestDate)
            ->orderByDesc("{$historyTable}.{$column}")
            ->limit($limit)
            ->select("{$subjectTable}.id", "{$subjectTable}.{$nameColumn}")
            ->get();

        $history = DB::table($historyTable)
            ->whereIn($subjectIdColumn, $topSubjects->pluck('id'))
            ->orderBy('date')
            ->get()
            ->groupBy($subjectIdColumn);

        $series = [];
        foreach ($topSubjects as $subject) {
            $byDate = collect($history[(int) $subject->id] ?? [])->keyBy('date');
            $series[] = [
                'name' => (string) $subject->{$nameColumn},
                'bot' => $isBot((int) $subject->id),
                'values' => array_map(
                    fn ($date) => isset($byDate[$date]) ? (int) $byDate[$date]->{$column} : null,
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
