<?php

namespace OGame\Bots;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OGame\Models\Bot;
use OGame\Models\User;

/**
 * Central bookkeeping for bot players: which bots are enabled and due for a
 * heartbeat, rescheduling after a heartbeat, and resolving the set of bot
 * user IDs (used to exclude bots from e.g. the bot-detection panel).
 */
final class BotRegistry
{
    /**
     * Cache key for the (rarely changing) set of bot user IDs.
     */
    private const CACHE_KEY_BOT_USER_IDS = 'bots:user_ids';

    /**
     * Bot user ID cache lifetime in seconds.
     */
    private const BOT_USER_IDS_CACHE_TTL = 300;

    /**
     * Return enabled bots whose next scheduled heartbeat is due.
     *
     * @param int $limit
     * @return Collection<int, Bot>
     */
    public function dueBots(int $limit): Collection
    {
        return Bot::query()
            ->where('enabled', true)
            ->where('next_action_at', '<=', now())
            ->oldest('next_action_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Record a finished heartbeat and (re)schedule the next one. Also called
     * for failed heartbeats so a failing bot keeps its cadence instead of
     * hammering the queue on every tick.
     *
     * @param Bot $bot
     * @param int $actionCount Number of game actions executed; -1 signals a
     *                        failed heartbeat.
     */
    public function markHeartbeatComplete(Bot $bot, int $actionCount): void
    {
        $min = (int) config('bots.heartbeat.next_delay_minutes.0', 45);
        $max = (int) config('bots.heartbeat.next_delay_minutes.1', 90);

        $bot->total_ticks = $bot->total_ticks + 1;
        $bot->last_action_at = now();
        $bot->last_actions = $actionCount;
        $bot->next_action_at = now()->addMinutes(random_int($min, $max));
        $bot->save();
    }

    /**
     * Return a randomized first-run delay for a freshly created bot.
     */
    public function firstActionDelayMinutes(): int
    {
        $min = (int) config('bots.heartbeat.first_delay_minutes.0', 1);
        $max = (int) config('bots.heartbeat.first_delay_minutes.1', 30);

        return random_int($min, $max);
    }

    /**
     * IDs of all users that drive a bot (role 'bot'). Used by admin tooling to
     * exclude NPC traffic from player analytics such as bot detection.
     *
     * @return array<int, int>
     */
    public function botUserIds(): array
    {
        return Cache::remember(self::CACHE_KEY_BOT_USER_IDS, self::BOT_USER_IDS_CACHE_TTL, function (): array {
            return User::query()
                ->whereHas('roles', function ($query): void {
                    $query->where('name', 'bot');
                })
                ->pluck('id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();
        });
    }

    /**
     * Forget the cached bot user ID set (call after creating/removing bots).
     */
    public function forgetBotUserIdsCache(): void
    {
        Cache::forget(self::CACHE_KEY_BOT_USER_IDS);
    }
}
