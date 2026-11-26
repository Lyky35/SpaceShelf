<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\AllianceHighscore;
use OGame\Models\Highscore;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the daily score history snapshots behind the highscore page
 * timeline graphs (per-tab score histories for players and alliances,
 * plus ships built/destroyed over time).
 */
class ScoreHistoryTest extends IsolatedAccountTestCase
{
    public function testRecordScoreHistorySnapshotsLiveCounters(): void
    {
        DB::table('users')->where('id', $this->currentUserId)->update([
            'ships_built' => 12,
            'ships_destroyed' => 3,
        ]);
        Highscore::updateOrCreate(['player_id' => $this->currentUserId], [
            'general' => 777,
            'economy' => 321,
            'research' => 123,
            'military' => 333,
            'military_built' => 40,
            'military_destroyed' => 15,
            'military_lost' => 7,
            'honor' => -5,
        ]);

        $this->artisan('spaceshelf:scheduler:record-score-history');

        $today = Date::now()->toDateString();
        $row = DB::table('score_histories')
            ->where('player_id', $this->currentUserId)
            ->where('date', $today)
            ->first();

        $this->assertNotNull($row, 'Snapshot row should exist for today');
        $this->assertSame(777, (int) $row->general);
        $this->assertSame(321, (int) $row->economy);
        $this->assertSame(123, (int) $row->research);
        $this->assertSame(333, (int) $row->military);
        $this->assertSame(40, (int) $row->military_built);
        $this->assertSame(15, (int) $row->military_destroyed);
        $this->assertSame(7, (int) $row->military_lost);
        $this->assertSame(-5, (int) $row->honor);
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

    public function testRecordAllianceScoreSnapshots(): void
    {
        $allianceService = resolve(AllianceService::class);
        $alliance = $allianceService->createAlliance(
            $this->currentUserId,
            'T' . substr(md5((string) mt_rand()), 0, 6),
            'Snapshot Alliance ' . substr(md5((string) mt_rand()), 0, 6)
        );

        AllianceHighscore::updateOrCreate(['alliance_id' => $alliance->id], [
            'general' => 1800,
            'economy' => 900,
            'research' => 550,
            'military' => 350,
        ]);

        $this->artisan('spaceshelf:scheduler:record-score-history');

        $today = Date::now()->toDateString();
        $row = DB::table('alliance_score_histories')
            ->where('alliance_id', $alliance->id)
            ->where('date', $today)
            ->first();

        $this->assertNotNull($row, 'Alliance snapshot row should exist for today');
        $this->assertSame(1800, (int) $row->general);
        $this->assertSame(900, (int) $row->economy);
        $this->assertSame(550, (int) $row->research);
        $this->assertSame(350, (int) $row->military);

        // Same-day rerun must upsert, not duplicate.
        $this->artisan('spaceshelf:scheduler:record-score-history');
        $this->assertSame(
            1,
            DB::table('alliance_score_histories')->where('alliance_id', $alliance->id)->count(),
            'Same-day rerun must upsert the existing alliance snapshot'
        );
    }

    public function testHighscorePageRendersStatsChartsWithData(): void
    {
        Highscore::updateOrCreate(['player_id' => $this->currentUserId], ['general' => 500, 'economy' => 200]);
        $this->artisan('spaceshelf:scheduler:record-score-history');

        $response = $this->get('/highscore');
        $response->assertStatus(200);
        $response->assertSee('statsScoreChart');
        $response->assertSee('statsShipChart');
        // The current player must show up in the score timeline payload.
        $response->assertSee($this->currentUsername, false);
        // The graphs cover every tab: player metrics, alliance metrics.
        $response->assertSee('player_military_destroyed');
        $response->assertSee('alliance_general');
    }

    public function testHighscorePageDoesNotAutoScrollToPlayerRank(): void
    {
        $response = $this->get('/highscore');
        $response->assertStatus(200);
        $response->assertSee('var userWantsFocus = false');

        // Explicit rank-search links (buddy list etc.) still focus the player.
        $response = $this->get('/highscore?searchRelId=' . $this->currentUserId);
        $response->assertStatus(200);
        $response->assertSee('var userWantsFocus = true');
    }
}
