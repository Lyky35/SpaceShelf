<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OGame\Bots\BotRegistry;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Ban;
use OGame\Models\Bot;
use Tests\IsolatedAccountTestCase;

/**
 * Heartbeat dispatch lifecycle guards: the tick command reserves a bot's
 * appointment before queueing its job (so queue lag cannot stack duplicate
 * visits), the job discards stale duplicates, the tick counter increments
 * atomically, and banned/vacationed bot accounts stay frozen while keeping
 * their cadence.
 */
class BotHeartbeatLifecycleTest extends IsolatedAccountTestCase
{
    /**
     * Run the tick command twice while the worker has not executed anything
     * and assert only one heartbeat job per bot is ever queued.
     */
    public function testTickReservesBotsSoRerunsDispatchNothing(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 2, '--profile' => 'discoverer']));

        $bots = Bot::orderByDesc('id')->limit(2)->get();

        foreach ($bots as $bot) {
            Bot::query()->whereKey($bot->id)->update(['next_action_at' => now()->subMinute()]);
        }

        Bus::fake();

        Artisan::call('spaceshelf:bot:tick');
        Bus::assertDispatchedTimes(BotHeartbeatJob::class, 2);

        // Second tick inside the lease window: the appointment was reserved
        // at dispatch, so no bot may look due again.
        Artisan::call('spaceshelf:bot:tick');
        Bus::assertDispatchedTimes(BotHeartbeatJob::class, 2);

        foreach ($bots as $bot) {
            $bot->refresh();
            $this->assertNotNull($bot->next_action_at);
            $this->assertGreaterThan(now()->timestamp, (int) $bot->next_action_at->timestamp, 'Reservation must be in the future');
            $this->assertLessThanOrEqual(now()->addMinutes(11)->timestamp, (int) $bot->next_action_at->timestamp, 'Reservation must stay within the lease window');
        }
    }

    /**
     * Assert an expired reservation (e.g. after a lost job or queue-worker
     * crash) makes the bot due again instead of silencing it.
     */
    public function testExpiredLeaseMakesTheBotDueAgain(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'discoverer']));

        $bot = Bot::orderByDesc('id')->firstOrFail();
        Bot::query()->whereKey($bot->id)->update(['next_action_at' => now()->subMinute()]);

        Bus::fake();

        Artisan::call('spaceshelf:bot:tick');
        Bus::assertDispatchedTimes(BotHeartbeatJob::class, 1);

        // Move past the lease without any job ever completing.
        $this->travel((int) config('bots.heartbeat.dispatch_lease_minutes', 10) + 5)->minutes();

        Artisan::call('spaceshelf:bot:tick');
        Bus::assertDispatchedTimes(BotHeartbeatJob::class, 2);
    }

    /**
     * A second job for a bot that already completed its heartbeat inside the
     * lease window must be discarded without playing a second visit.
     */
    public function testStaleDuplicateHeartbeatIsSkipped(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();
        Bot::query()->whereKey($bot->id)->update(['next_action_at' => now()->subMinute()]);

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
        $bot->refresh();

        $this->assertSame(1, (int) $bot->total_ticks);
        $completedActions = (int) $bot->last_actions;
        $completedAt = $bot->last_action_at;
        $this->assertNotNull($completedAt);

        // Queue backlog / retry / manual dispatch duplicate: no game effect.
        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
        $bot->refresh();

        $this->assertSame(1, (int) $bot->total_ticks, 'Duplicate heartbeat played a second visit');
        $this->assertSame($completedActions, (int) $bot->last_actions);
        $this->assertNotNull($bot->last_action_at);
        $this->assertSame($completedAt->timestamp, $bot->last_action_at->timestamp);
    }

    /**
     * Two heartbeat jobs racing to completion must both count: the tick
     * increment happens database-side, not off a stale model value.
     */
    public function testConcurrentCompletionsDoNotLoseTickCounts(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var BotRegistry $registry */
        $registry = resolve(BotRegistry::class);

        // Two stale model copies as two concurrent workers would hold them.
        $first = Bot::query()->findOrFail($bot->id);
        $second = Bot::query()->findOrFail($bot->id);

        $registry->markHeartbeatComplete($first, 3);
        $registry->markHeartbeatComplete($second, 2);

        $bot->refresh();
        $this->assertSame(2, (int) $bot->total_ticks);
    }

    /**
     * A banned bot account keeps its heartbeat cadence but performs no game
     * work: no simulated login and no brain actions.
     */
    public function testBannedBotHeartbeatStaysFrozenButKeepsCadence(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();
        Bot::query()->whereKey($bot->id)->update(['next_action_at' => now()->subMinute()]);

        Ban::create(['user_id' => $bot->user_id, 'reason' => 'test ban']);

        $lastLoginBefore = DB::table('users')->where('id', $bot->user_id)->value('time');

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
        $bot->refresh();

        // Frozen: recorded as a skipped visit, nothing queued, no login stamp.
        $this->assertSame(-1, (int) $bot->last_actions);
        $this->assertSame(1, (int) $bot->total_ticks, 'Skipped heartbeat must keep the cadence');
        $this->assertNotNull($bot->next_action_at);
        $this->assertGreaterThan(now()->timestamp, (int) $bot->next_action_at->timestamp);

        $planetIds = DB::table('planets')->where('user_id', $bot->user_id)->pluck('id')->all();
        $this->assertSame(0, DB::table('building_queues')->whereIn('planet_id', $planetIds)->count());

        $lastLoginAfter = DB::table('users')->where('id', $bot->user_id)->value('time');
        $this->assertSame($lastLoginBefore, $lastLoginAfter, 'Banned bot must not simulate a login');
    }
}
