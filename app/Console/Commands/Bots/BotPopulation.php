<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OGame\Bots\BotFactory;
use OGame\Bots\BotRegistry;
use OGame\Bots\BotRetirementService;
use OGame\Models\Bot;
use OGame\Models\Highscore;

/**
 * Population lifecycle (best-effort, NOT documented upstream): keeps a
 * standing pool of bot accounts with organic churn, once per day.
 *
 * Phases, in order:
 *  1. Delete permanent-inactive accounts once their hiatus started
 *     `deletion_after_days` ago (whole account purge, bots row cascades).
 *  2. Reactivate bots whose hiatus has run its course.
 *  3. Escalate hiatuses that reached 7 days: with `permanent_chance` they
 *     become permanent (never return, wait on deletion).
 *  4. Weekly dice (staggered per bot via last_roll_at): bots older than
 *     `min_age_days` go on a short or long hiatus; top-ranked visible bots
 *     only with `protected.chance`; while `max_hiatus_bots` bots are
 *     already hiatused no NEW hiatuses start.
 *  5. Top-up: spawn fresh bots (capped per pass) until the pool holds
 *     `target_size` non-deleted accounts again.
 *
 * Hiatused bots simply stop logging in: no heartbeats, fleets sit wherever
 * they were, planets freeze at their last update — like any lapsed player.
 */
#[Description('Run the bot population lifecycle: hiatuses, permanent retirements and pool top-up')]
#[Signature('spaceshelf:bot:population')]
class BotPopulation extends Command
{
    /**
     * Execute the console command.
     *
     * @param BotRegistry $botRegistry
     * @param BotRetirementService $retirementService
     * @param BotFactory $botFactory
     * @return int
     */
    public function handle(
        BotRegistry $botRegistry,
        BotRetirementService $retirementService,
        BotFactory $botFactory,
    ): int {
        if (!config('bots.enabled') || !config('bots.population.enabled')) {
            $this->line('Bot population lifecycle disabled.');

            return self::SUCCESS;
        }

        $deleted = $this->deleteExpiredPermanent();
        $reactivated = $this->reactivateExpiredHiatus();
        $madePermanent = $this->escalateLongHiatus();
        [$rolled, $hiatusStarted] = $this->rollWeeklyHiatus();
        $toppedUp = $this->topUpPool($botFactory);

        if ($toppedUp > 0) {
            $botRegistry->forgetBotUserIdsCache();
        }

        $pool = Bot::count();
        $hiatus = Bot::whereNotNull('inactive_since')->count();

        $summary = sprintf(
            'Bot population: pool %d (target %d) | hiatused %d (capped at %d) | deleted %d, permanent %d, reactivated %d, rolled %d, new hiatuses %d, top-up %d.',
            $pool,
            (int) config('bots.population.target_size'),
            $hiatus,
            (int) config('bots.population.max_hiatus_bots'),
            $deleted,
            $madePermanent,
            $reactivated,
            $rolled,
            $hiatusStarted,
            $toppedUp,
        );

        Log::info($summary);
        $this->line($summary);

        return self::SUCCESS;
    }

    /**
     * Phase 1: purge permanently inactive accounts past the deletion window.
     */
    private function deleteExpiredPermanent(): int
    {
        /** @var BotRetirementService $retirementService */
        $retirementService = resolve(BotRetirementService::class);

        $cutoff = now()->subDays((int) config('bots.population.deletion_after_days', 35));

        $expired = Bot::query()
            ->whereNotNull('inactive_since')
            ->whereNull('inactive_until')
            ->where('inactive_since', '<=', $cutoff)
            ->get();

        $deleted = 0;

        foreach ($expired as $bot) {
            if ($retirementService->retire($bot)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Phase 2: bots whose fixed-length hiatus has run end their silence and
     * get a fresh near-term appointment.
     */
    private function reactivateExpiredHiatus(): int
    {
        $due = Bot::query()
            ->whereNotNull('inactive_since')
            ->whereNotNull('inactive_until')
            ->where('inactive_until', '<=', now())
            ->get();

        foreach ($due as $bot) {
            Bot::query()
                ->whereKey($bot->id)
                ->update([
                    'inactive_since' => null,
                    'inactive_until' => null,
                    'next_action_at' => now()->addMinutes(random_int(1, 120)),
                ]);
        }

        return $due->count();
    }

    /**
     * Phase 3: a hiatus that reached 7 days rolls `permanent_chance` to
     * never end — the account goes quiet for good and waits on deletion.
     */
    private function escalateLongHiatus(): int
    {
        $threshold = now()->subDays((int) config('bots.population.permanent_after_days', 7));
        $chancePercent = (int) round((float) config('bots.population.permanent_chance', 0.25) * 100);

        $longRunning = Bot::query()
            ->whereNotNull('inactive_since')
            ->whereNotNull('inactive_until')
            ->where('inactive_since', '<=', $threshold)
            ->get();

        $madePermanent = 0;

        foreach ($longRunning as $bot) {
            if (random_int(1, 100) > $chancePercent) {
                continue;
            }

            Bot::query()->whereKey($bot->id)->update(['inactive_until' => null]);
            $madePermanent++;
        }

        return $madePermanent;
    }

    /**
     * Phase 4: the weekly dice. Long hiatus odds are checked first, short
     * otherwise; leaderboard-protected bots roll with `protected.chance`
     * split across the tiers proportionally. While the hiatus cap is
     * reached the dice still advance per-bot clocks but no new hiatuses
     * start — existing ones still end and get deleted on schedule.
     *
     * @return array{int, int} [bots rolled, new hiatuses started]
     */
    private function rollWeeklyHiatus(): array
    {
        $ageCutoff = now()->subDays((int) config('bots.population.min_age_days', 7));
        $rollCutoff = now()->subDays((int) config('bots.population.roll_interval_days', 7));

        $candidates = Bot::query()
            ->where('enabled', true)
            ->whereNull('inactive_since')
            ->where('created_at', '<=', $ageCutoff)
            ->where(function ($query) use ($rollCutoff): void {
                $query->whereNull('last_roll_at')->orWhere('last_roll_at', '<=', $rollCutoff);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return [0, 0];
        }

        $capReached = Bot::whereNotNull('inactive_since')->count()
            >= (int) config('bots.population.max_hiatus_bots', 100);

        $shortChance = (float) config('bots.population.short.chance', 0.20);
        $longChance = (float) config('bots.population.long.chance', 0.05);

        // Leaderboard protection: visible bots inside the top ranks.
        $protectedRank = (int) config('bots.population.protected.top_rank', 25);
        $protectedChance = (float) config('bots.population.protected.chance', 0.01);
        $protectedUserIds = $protectedRank > 0 && $protectedChance < $shortChance + $longChance
            ? Highscore::query()
                ->whereIn('player_id', $candidates->pluck('user_id')->all())
                ->whereNotNull('general_rank')
                ->where('general_rank', '<=', $protectedRank)
                ->pluck('player_id')
                ->flip()
                ->all()
            : [];

        $started = 0;

        foreach ($candidates as $bot) {
            [$tierShort, $tierLong] = isset($protectedUserIds[$bot->user_id])
                ? $this->splitChance($protectedChance, $shortChance, $longChance)
                : [$shortChance, $longChance];

            // Advance the weekly clock either way, cap or no cap.
            Bot::query()->whereKey($bot->id)->update(['last_roll_at' => now()]);

            if ($capReached) {
                continue;
            }

            // One uniform draw decides the tier: long band first, then short.
            $draw = random_int(1, 1_000_000) / 1_000_000;

            $hiatusDays = null;

            if ($draw <= $tierLong) {
                $hiatusDays = $this->randomDays((array) config('bots.population.long.days', [7, 30]));
            } elseif ($draw <= $tierLong + $tierShort) {
                $hiatusDays = $this->randomDays((array) config('bots.population.short.days', [2, 5]));
            }

            if ($hiatusDays === null) {
                continue;
            }

            Bot::query()
                ->whereKey($bot->id)
                ->update([
                    'inactive_since' => now(),
                    'inactive_until' => now()->addDays($hiatusDays),
                ]);

            $started++;
        }

        return [$candidates->count(), $started];
    }

    /**
     * Phase 5: refill the pool after deletions (or a fresh backfill),
     * capped per pass so large gaps are closed gently.
     */
    private function topUpPool(BotFactory $botFactory): int
    {
        $target = (int) config('bots.population.target_size', 0);
        $deficit = $target - Bot::count();

        if ($deficit < 1) {
            return 0;
        }

        $batch = min($deficit, (int) config('bots.population.top_up_per_pass', 25));

        for ($i = 0; $i < $batch; $i++) {
            $botFactory->create('mixed');
        }

        return $batch;
    }

    /**
     * Scale the short/long tier chances down to a combined protected
     * chance, keeping the configured short:long ratio.
     *
     * @return array{float, float}
     */
    private function splitChance(float $combined, float $shortChance, float $longChance): array
    {
        $total = $shortChance + $longChance;

        if ($total <= 0) {
            return [0.0, 0.0];
        }

        return [
            $combined * ($shortChance / $total),
            $combined * ($longChance / $total),
        ];
    }

    /**
     * Random day count from a [min, max] config pair.
     */
    private function randomDays(array $range): int
    {
        $min = max(1, (int) ($range[0] ?? 2));
        $max = max($min, (int) ($range[1] ?? $min));

        return random_int($min, $max);
    }
}
