<?php

use OGame\Console\Commands\Bots\BotPopulation;
use OGame\Console\Commands\Bots\TickBots;
use OGame\Console\Commands\Scheduler\CleanupDestroyedPlanets;
use OGame\Console\Commands\Scheduler\CleanupWreckFields;
use OGame\Console\Commands\Scheduler\DarkMatterRegenerateCommand;
use OGame\Console\Commands\Scheduler\DeleteOldMessages;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Console\Commands\Scheduler\RecordScoreHistory;
use OGame\Console\Commands\Scheduler\ResetDebrisFields;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command(GenerateHighscores::class)->everyFiveMinutes();
// Alliance highscores should run after player highscores since they depend on them
Schedule::command(GenerateAllianceHighscores::class)->everyFiveMinutes();
// Generates ranks for both player and alliance highscores
Schedule::command(GenerateHighscoreRanks::class)->everyFiveMinutes();

// Daily snapshot of scores and ship stats for the overview timeline charts
// One row per player per day, but refreshed every hour so today's graph
// point tracks live scores during the day instead of freezing at whatever
// state the 00:05 run happened to catch.
Schedule::command(RecordScoreHistory::class)->hourly()->withoutOverlapping();

// Reset empty debris fields weekly on Monday at 1:00 AM
Schedule::command(ResetDebrisFields::class)->weeklyOn(1, '1:00');

// Clean up wreck fields hourly
Schedule::command(CleanupWreckFields::class)->hourly()->withoutOverlapping();

// Delete messages once they have aged out of the seven-day retention window
Schedule::command(DeleteOldMessages::class)->hourly()->withoutOverlapping();

// Permanently delete destroyed planets/moons flagged for at least 24 hours (official 3:00 cycle)
Schedule::command(CleanupDestroyedPlanets::class)->dailyAt('03:00')->withoutOverlapping();

// Process Dark Matter regeneration every 5 minutes
Schedule::command(DarkMatterRegenerateCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Dispatch bot player heartbeats; individual bots reschedule themselves with
// randomized gaps so activity is naturally smeared across the day. The short
// overlap lock lets a killed tick process recover within minutes instead of
// silencing every bot until the stale lock ages out.
Schedule::command(TickBots::class)->everyMinute()->withoutOverlapping(5);

// Bot population lifecycle: weekly-per-bot hiatus dice, permanent-account
// deletions and pool top-up. Daily so hiatuses end and expiries purge on
// time while the roll itself is staggered per bot. Runs after the 03:00
// destroyed-planet purge.
Schedule::command(BotPopulation::class)->dailyAt('04:00')->withoutOverlapping();
