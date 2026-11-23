<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OGame\Bots\BotRegistry;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Bots\PlanetNameGenerator;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Services\PlayerService;
use Throwable;

/**
 * One "session" of a bot player: it logs in (processes its game state), plays
 * a round via the brain, and logs off again (reschedules the next visit).
 *
 * Mirrors what the GlobalGame middleware does on each authenticated HTTP
 * request, so queued builds/research/units and arriving fleets progress even
 * though bots never touch the web UI.
 */
class BotHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Number of times the job may be attempted. Failed heartbeats are
     * rescheduled by the registry instead of retried immediately, so a broken
     * bot does not hammer the queue.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * @param int $botId
     */
    public function __construct(public int $botId)
    {
    }

    /**
     * Execute the job.
     *
     * @param PlayerServiceFactory $playerServiceFactory
     * @param SnapshotBuilder $snapshotBuilder
     * @param BotBrain $botBrain
     * @param BotRegistry $botRegistry
     * @return void
     */
    public function handle(
        PlayerServiceFactory $playerServiceFactory,
        SnapshotBuilder $snapshotBuilder,
        BotBrain $botBrain,
        BotRegistry $botRegistry,
        PlanetNameGenerator $planetNameGenerator,
    ): void {
        $bot = Bot::find($this->botId);
        if ($bot === null || !$bot->enabled) {
            return;
        }

        // Stale duplicate guard: bot:tick reserves the appointment at
        // dispatch time, so a heartbeat that completed (or failed) inside the
        // lease window means any further job for this bot is a leftover from
        // queue lag, a retry, or a manual dispatch — skip it, do not play a
        // second visit.
        $leaseMinutes = (int) config('bots.heartbeat.dispatch_lease_minutes', 10);
        if ($bot->last_action_at !== null && $bot->last_action_at->gt(now()->subMinutes($leaseMinutes))) {
            Log::debug(sprintf(
                'SpaceShelf bot %d: skipping stale duplicate heartbeat (last completed %s).',
                $bot->id,
                $bot->last_action_at->toIso8601String()
            ));

            return;
        }

        // Banned or vacationed bot accounts are frozen: the engine gates
        // production and mission dispatch for them, so playing the brain
        // would only pile up queue items the bot can never use. Keep the
        // appointment cadence but skip the visit entirely.
        $botUser = $bot->user;
        if ($botUser === null || $botUser->isBanned() || (bool) $botUser->vacation_mode) {
            $botRegistry->markHeartbeatComplete($bot, -1);

            Log::debug(sprintf(
                'SpaceShelf bot %d: heartbeat skipped (user banned or on vacation).',
                $bot->id
            ));

            return;
        }

        try {
            // Fresh service instances per heartbeat: never trust state cached
            // in long-lived queue worker singletons.
            $player = $playerServiceFactory->make($bot->user_id, true);

            $this->simulateLogin($player);

            // Rename planets the engine auto-labelled (e.g. freshly colonised
            // "Colony" planets) so the bot empire looks organic.
            $renamedPlanets = $planetNameGenerator->nameAll($player);

            $actions = $botBrain->play(
                $snapshotBuilder->build($player),
                $bot->personalityObject()
            );

            $botRegistry->markHeartbeatComplete($bot, $actions);

            Log::debug(sprintf(
                'SpaceShelf bot %d: heartbeat completed with %d actions (%d planets renamed).',
                $bot->id,
                $actions,
                $renamedPlanets
            ));
        } catch (Throwable $exception) {
            Log::warning(sprintf(
                'SpaceShelf bot %d: heartbeat failed: %s',
                $bot->id,
                $exception->getMessage()
            ));

            // Reschedule anyway so the bot keeps its cadence.
            $botRegistry->markHeartbeatComplete($bot, -1);
        }
    }

    /**
     * Replicate the per-request game state updates that the GlobalGame
     * middleware performs for a real player: player tick, all planet queue +
     * production updates and fleet mission arrivals.
     *
     * @param PlayerService $player
     * @return void
     */
    private function simulateLogin(PlayerService $player): void
    {
        $player->update();

        foreach ($player->planets->allPlanets() as $planet) {
            $planet->update();
        }

        foreach ($player->planets->allMoons() as $moon) {
            $moon->update();
        }

        $player->updateFleetMissions();
    }
}
