<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Highscore;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the daily score history snapshots behind the overview timeline
 * charts (score history of top players + ships built/destroyed over time).
 */
class ScoreHistoryTest extends IsolatedAccountTestCase
{
    public function testRecordScoreHistorySnapshotsLiveCounters(): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update([
            'ships_built' => 12,
            'ships_destroyed' => 3,
            'military_built' => 40,
            'military_destroyed' => 15,
        ]);
        Highscore::updateOrCreate(['player_id' => $this->currentUserId], ['general' => 777]);

        $this->artisan('spaceshelf:scheduler:record-score-history');

        $today = Date::now()->toDateString();
        $row = DB::table('score_histories')
            ->where('player_id', $this->currentUserId)
            ->where('date', $today)
            ->first();

        $this->assertNotNull($row, 'Snapshot row should exist for today');
        $this->assertSame(777, (int) $row->general);
        $this->assertSame(40, (int) $row->military_built);
        $this->assertSame(15, (int) $row->military_destroyed);
        $this->assertSame(12, (int) $row->ships_built);
        $this->assertSame(3, (int) $row->ships_destroyed);

        // Re-running the same day must update the row, not duplicate it.
        DB::table('users')->where('id', $this->currentUserId)->update(['ships_built' => 20]);
        $this->artisan('spaceshelf:scheduler:record-score-history');

        $this->assertSame(
            1,
            DB::table('score_histories')->where('player_id', $this->currentUserId)->count(),
            'Same-day rerun must upsert the existing snapshot'
        );
        $this->assertSame(
            20,
            (int) DB::table('score_histories')->where('player_id', $this->currentUserId)->value('ships_built')
        );
    }

    public function testOverviewPageRendersStatsChartsWithData(): void
    {
        Highscore::updateOrCreate(['player_id' => $this->currentUserId], ['general' => 500]);
        $this->artisan('spaceshelf:scheduler:record-score-history');

        $response = $this->get('/overview');
        $response->assertStatus(200);
        $response->assertSee('statsScoreChart');
        $response->assertSee('statsShipChart');
        // The current player must show up in the score timeline payload.
        $response->assertSee($this->currentUsername, false);
    }
}
