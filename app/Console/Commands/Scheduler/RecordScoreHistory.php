<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Services\ScoreHistoryService;

#[Description('Records the score history snapshot for today (one row per day, refreshed hourly) used by the highscore timeline charts.')]
#[Signature('spaceshelf:scheduler:record-score-history')]
class RecordScoreHistory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ScoreHistoryService $scoreHistoryService): void
    {
        $this->info('Recording daily score history snapshots...');

        $count = $scoreHistoryService->recordDailySnapshots();
        $allianceCount = $scoreHistoryService->recordAllianceSnapshots();

        $this->info("Recorded score history for {$count} player(s) and {$allianceCount} alliance(s).");
    }
}
