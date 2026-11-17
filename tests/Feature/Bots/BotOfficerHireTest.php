<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * Bot officer upkeep (BOT-AI-PLAN.md §7): each behavior profile keeps its key
 * officer hired through OfficerService, gated by the Dark Matter reserve floor
 * and the renew window in config/bots.php.
 */
class BotOfficerHireTest extends IsolatedAccountTestCase
{
    /**
     * Create one bot with the given profile and return it.
     */
    private function createBot(string $profile): Bot
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => $profile]));

        return Bot::orderByDesc('id')->firstOrFail();
    }

    /**
     * Set the bot account's Dark Matter balance to a known amount.
     */
    private function setDarkMatter(Bot $bot, int $amount): void
    {
        $user = User::findOrFail($bot->user_id);
        $user->dark_matter = $amount;
        $user->save();
    }

    /**
     * Run exactly one heartbeat for the bot.
     */
    private function heartbeat(Bot $bot): void
    {
        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
    }

    /**
     * A collector with enough DM hires the geologist (its key officer) and the
     * debit is recorded as a commanding-staff transaction.
     */
    public function testCollectorHiresGeologistWhenAffordable(): void
    {
        $bot = $this->createBot('collector');
        $this->setDarkMatter($bot, 50000);

        $this->heartbeat($bot);

        $user = User::findOrFail($bot->user_id);
        $this->assertSame(37500, $user->dark_matter);
        $this->assertNotNull($user->geologist_expires_at);
        $this->assertTrue($user->geologist_expires_at->isFuture());
        $this->assertNull($user->admiral_expires_at);
        $this->assertNull($user->technocrat_expires_at);

        $this->assertDatabaseHas('dark_matter_transactions', [
            'user_id' => $bot->user_id,
            'type' => 'commanding_staff',
            'amount' => -12500,
        ]);

        // The bonus is live for gameplay purposes.
        $player = resolve(PlayerServiceFactory::class)->make($bot->user_id, true);
        $this->assertTrue($player->hasGeologist());
    }

    /**
     * The profile decides the officer: generals hire the admiral, not the
     * geologist.
     */
    public function testGeneralHiresAdmiralInsteadOfGeologist(): void
    {
        $bot = $this->createBot('general');
        $this->setDarkMatter($bot, 20000);

        $this->heartbeat($bot);

        $user = User::findOrFail($bot->user_id);
        $this->assertSame(15000, $user->dark_matter);
        $this->assertNotNull($user->admiral_expires_at);
        $this->assertNull($user->geologist_expires_at);
        $this->assertNull($user->technocrat_expires_at);
    }

    /**
     * Below the cost + reserve floor the bot keeps its hands off Dark Matter.
     */
    public function testOfficerNotHiredBelowReserveFloor(): void
    {
        $bot = $this->createBot('collector');
        // 12.500 costs + 999 reserve leaves one DM too little.
        $this->setDarkMatter($bot, 13499);

        $this->heartbeat($bot);

        $user = User::findOrFail($bot->user_id);
        $this->assertSame(13499, $user->dark_matter);
        $this->assertNull($user->geologist_expires_at);
    }

    /**
     * An officer still comfortably active is not renewed early.
     */
    public function testRenewalSkippedWhileOfficerStillActive(): void
    {
        $bot = $this->createBot('collector');
        $this->setDarkMatter($bot, 50000);

        $user = User::findOrFail($bot->user_id);
        $user->geologist_expires_at = Date::now()->addDays(5);
        $user->save();
        $expiryBefore = $user->geologist_expires_at;

        $this->heartbeat($bot);

        $user = User::findOrFail($bot->user_id);
        $this->assertSame(50000, $user->dark_matter);
        $expiryAfter = $user->geologist_expires_at;
        $this->assertNotNull($expiryAfter);
        $this->assertLessThan(2, abs($expiryAfter->diffInSeconds($expiryBefore)));
    }

    /**
     * Near expiry the officer is re-hired and the new week stacks on top of
     * the remaining time instead of restarting.
     */
    public function testRenewalNearExpiryExtendsExpiry(): void
    {
        $bot = $this->createBot('collector');
        $this->setDarkMatter($bot, 50000);

        $user = User::findOrFail($bot->user_id);
        $user->geologist_expires_at = Date::now()->addHours(12);
        $user->save();

        $this->heartbeat($bot);

        $user = User::findOrFail($bot->user_id);
        $this->assertSame(37500, $user->dark_matter);
        $expiryAfter = $user->geologist_expires_at;
        $this->assertNotNull($expiryAfter);
        // Stacked: 12h remaining + a fresh week, not a fresh week from now.
        $this->assertTrue($expiryAfter->greaterThan(Date::now()->addDays(7)));
    }
}
