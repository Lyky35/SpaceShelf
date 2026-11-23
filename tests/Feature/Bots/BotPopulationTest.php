<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * The bot population lifecycle (best-effort, NOT documented upstream):
 * weekly hiatus dice with leaderboard protection and a hiatus cap,
 * permanent escalations, account deletion past the retention window and
 * pool top-up. All probabilities are forced to 0/1 through config so the
 * rolls are deterministic.
 */
class BotPopulationTest extends IsolatedAccountTestCase
{
    /**
     * Create one bot and age it past the roll eligibility window.
     */
    private function createAgedBot(): Bot
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();
        Bot::query()->whereKey($bot->id)->update(['created_at' => now()->subDays(8)]);

        return $bot;
    }

    /**
     * Freeze the pool at its current size so a run never triggers top-up
     * unless the test wants it, and make every hiatus length explicit.
     * Leaderboard protection is off by default: fresh test databases rank
     * every new account in the top 25 (registration seeds ranks max+1),
     * which would silently shield the bots from the forced odds.
     */
    private function deterministicPopulation(array $overrides = []): void
    {
        config(array_merge([
            'bots.population.enabled' => true,
            'bots.population.target_size' => Bot::count(),
            'bots.population.short' => ['chance' => 1.0, 'days' => [2, 2]],
            'bots.population.long' => ['chance' => 0.0, 'days' => [7, 7]],
            'bots.population.protected' => ['top_rank' => 0, 'chance' => 0.0],
            'bots.population.permanent_chance' => 0.0,
        ], $overrides));
    }

    public function testEligibleBotStartsHiatusAndStopsTicking(): void
    {
        $this->deterministicPopulation();
        $bot = $this->createAgedBot();

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $bot->refresh();

        $this->assertNotNull($bot->inactive_since);
        $this->assertNotNull($bot->inactive_until);
        $this->assertSame(2, (int) $bot->inactive_since->diffInDays($bot->inactive_until));
        $this->assertNotNull($bot->last_roll_at);

        // Total inactivity: a due appointment while hiatused must not lead
        // to dispatch at all.
        Bus::fake();
        Bot::query()->whereKey($bot->id)->update(['next_action_at' => now()->subMinute()]);

        Artisan::call('spaceshelf:bot:tick');
        Bus::assertNotDispatched(BotHeartbeatJob::class);
    }

    public function testYoungBotsAndFreshRollClocksAreNeverRolled(): void
    {
        $this->deterministicPopulation();

        $young = $this->createAgedBot();
        Bot::query()->whereKey($young->id)->update(['created_at' => now()->subDay()]);

        $staleClock = $this->createAgedBot();
        Bot::query()->whereKey($staleClock->id)->update(['last_roll_at' => now()->subDay()]);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));

        $young->refresh();
        $staleClock->refresh();
        $this->assertNull($young->inactive_since, 'Bot younger than min_age went on hiatus.');
        $this->assertNull($staleClock->inactive_since, 'Bot rolled again before its weekly clock expired.');
    }

    public function testTopRankedBotsGetOnlyTheProtectedChance(): void
    {
        $this->deterministicPopulation([
            'bots.population.protected' => ['top_rank' => 25, 'chance' => 0.0],
        ]);

        $protected = $this->createAgedBot();
        $open = $this->createAgedBot();

        // Replace the registration-seeded rank rows so the ranks under test
        // are the only ones the protection lookup can see.
        Highscore::where('player_id', $protected->user_id)->delete();
        Highscore::where('player_id', $open->user_id)->delete();
        Highscore::forceCreate(['player_id' => $protected->user_id, 'general' => 900000, 'general_rank' => 5]);
        Highscore::forceCreate(['player_id' => $open->user_id, 'general' => 100000, 'general_rank' => 200]);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $protected->refresh();
        $open->refresh();

        $this->assertNull($protected->inactive_since, 'Top-ranked bot took the unprotected odds.');
        $this->assertNotNull($open->inactive_since, 'Low-ranked bot missed its forced hiatus.');
    }

    public function testHiatusCapBlocksNewHiatusesButKeepsTheWeeklyClock(): void
    {
        $this->deterministicPopulation(['bots.population.max_hiatus_bots' => 0]);
        $bot = $this->createAgedBot();

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $bot->refresh();

        $this->assertNull($bot->inactive_since, 'A new hiatus started while at the cap.');
        $this->assertNotNull($bot->last_roll_at, 'The weekly clock must still advance at the cap.');
    }

    public function testExpiredHiatusEndsAndTheBotStartsPlayingAgain(): void
    {
        $this->deterministicPopulation([
            // No re-roll right after reactivation, so the fresh state is observable.
            'bots.population.short' => ['chance' => 0.0, 'days' => [2, 2]],
        ]);
        $bot = $this->createAgedBot();

        Bot::query()->whereKey($bot->id)->update([
            'inactive_since' => now()->subDays(3),
            'inactive_until' => now()->subHour(),
            'next_action_at' => now()->subMinute(),
        ]);

        // While hiatused a queued heartbeat is discarded without a visit.
        BotHeartbeatJob::dispatchSync($bot->id);
        $bot->refresh();
        $this->assertSame(0, (int) $bot->total_ticks);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $bot->refresh();

        $this->assertNull($bot->inactive_since);
        $this->assertNull($bot->inactive_until);
        $this->assertNotNull($bot->next_action_at);
        $this->assertGreaterThan(now()->timestamp, (int) $bot->next_action_at->timestamp, 'Reactivation needs a fresh appointment.');
    }

    public function testLongHiatusBecomesPermanentAndIsDeletedAfterRetention(): void
    {
        $this->deterministicPopulation(['bots.population.permanent_chance' => 1.0]);
        $bot = $this->createAgedBot();

        // Someone spied on the bot (report pinned to its user, delivered to
        // the spy's messages) and an enemy fleet is inbound on its planet.
        $spy = User::factory()->create();
        $report = EspionageReport::forceCreate([
            'planet_galaxy' => 1,
            'planet_system' => 1,
            'planet_position' => 4,
            'planet_type' => 1,
            'planet_user_id' => $bot->user_id,
            'resources' => ['metal' => 1, 'crystal' => 1, 'deuterium' => 1],
            'debris' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'buildings' => [],
            'research' => [],
            'ships' => [],
            'defense' => [],
            'player_info' => [],
        ]);
        $spyMessage = Message::forceCreate(['user_id' => $spy->id, 'key' => 'espionage_report', 'espionage_report_id' => $report->id]);

        $botPlanetId = DB::table('planets')->where('user_id', $bot->user_id)->value('id');
        $inboundMissionId = DB::table('fleet_missions')->insertGetId([
            'user_id' => $spy->id,
            'mission_type' => 1,
            'type_from' => 1,
            'planet_id_from' => $botPlanetId,
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 6,
            'type_to' => 1,
            'planet_id_to' => $botPlanetId,
            'galaxy_to' => 1,
            'system_to' => 1,
            'position_to' => 4,
            'time_departure' => time() - 600,
            'time_arrival' => time() + 600,
            'light_fighter' => 10,
            'processed' => 0,
            'canceled' => 0,
        ]);

        // Put the bot on a long hiatus that has passed the escalation age.
        Bot::query()->whereKey($bot->id)->update([
            'inactive_since' => now()->subDays(8),
            'inactive_until' => now()->addDays(10),
        ]);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $bot->refresh();

        $this->assertNotNull($bot->inactive_since);
        $this->assertNull($bot->inactive_until, 'A 7+ day hiatus missed its forced permanent escalation.');

        // Past the retention window the whole account is purged, including
        // the spy reports pinned to it and the inbound fleet leg.
        $this->travel(28)->days();

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));

        $this->assertNull(Bot::find($bot->id), 'The bot meta row survived the purge.');
        $this->assertNull(User::find($bot->user_id), 'The bot account survived the purge.');
        $this->assertFalse(EspionageReport::whereKey($report->id)->exists(), 'Spy report about the bot survived the purge.');
        $this->assertFalse(Message::whereKey($spyMessage->id)->exists(), 'Message referencing the report survived the purge.');
        $this->assertFalse(FleetMission::whereKey($inboundMissionId)->exists(), 'Inbound fleet leg survived the purge.');
        $this->assertSame(0, DB::table('planets')->where('user_id', $bot->user_id)->count());
    }

    public function testPoolTopUpRefillsAfterDeletions(): void
    {
        $before = Bot::count();
        $this->createAgedBot();

        config([
            'bots.population.enabled' => true,
            'bots.population.target_size' => Bot::count() + 3,
            'bots.population.top_up_per_pass' => 2,
            'bots.population.short' => ['chance' => 0.0, 'days' => [2, 2]],
            'bots.population.long' => ['chance' => 0.0, 'days' => [7, 7]],
        ]);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));

        // Per-pass cap: only two of the three missing bots appear.
        $this->assertSame($before + 3, Bot::count());

        $fresh = Bot::orderByDesc('id')->limit(2)->get();
        foreach ($fresh as $bot) {
            $this->assertTrue($bot->enabled);
            $this->assertNotNull($bot->next_action_at);
            $this->assertNotNull($bot->user, 'Top-up bot has no account.');
        }

        $usernames = User::whereIn('id', Bot::pluck('user_id'))->pluck('username');
        $this->assertSame($usernames->count(), $usernames->unique()->count(), 'Top-up produced duplicate usernames.');

        // Next pass closes the remaining gap.
        config(['bots.population.target_size' => $before + 4]);
        $this->assertSame(0, Artisan::call('spaceshelf:bot:population'));
        $this->assertSame($before + 4, Bot::count());
    }
}
