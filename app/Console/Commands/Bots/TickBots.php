<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Bots\BotRegistry;
use OGame\Jobs\BotHeartbeatJob;

/**
 * Scheduler entry point: finds bot players whose heartbeat is due and
 * dispatches a BotHeartbeatJob for each, spread over a few seconds so bots do
 * not wake in the exact same second.
 */
#[Description('Dispatch bot heartbeat jobs for all bots that are due')]
#[Signature('spaceshelf:bot:tick')]
class TickBots extends Command
{
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(BotRegistry $botRegistry): int
    {
        if (!(bool) config('bots.enabled', true)) {
            return self::SUCCESS;
        }

        $dueBots = $botRegistry->dueBots((int) config('bots.tick_max_dispatch', 25));

        if ($dueBots->isEmpty()) {
            return self::SUCCESS;
        }

        $spreadSeconds = (int) config('bots.dispatch_spread_seconds', 40);

        $dispatched = 0;

        foreach ($dueBots as $bot) {
            // Reserve the appointment before dispatching: without this, a
            // lagging worker leaves the bot "due" and every later tick
            // stacks another heartbeat job for the same visit.
            if (!$botRegistry->reserveForDispatch($bot)) {
                continue;
            }

            BotHeartbeatJob::dispatch($bot->id)->delay(
                now()->addSeconds(random_int(0, max(0, $spreadSeconds)))
            );
            $dispatched++;
        }

        $this->line(sprintf('Dispatched %d bot heartbeats.', $dispatched));

        return self::SUCCESS;
    }
}
