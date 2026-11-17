<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\BrainSnapshot;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Phase-1 brain smoke test: run a bot through ~30 simulated game hours of
 * heartbeats and assert the economy + research lines actually grow.
 *
 * The engine is timestamp driven via the Date facade, so Carbon test travel
 * between dispatched (sync) heartbeats lets queued items complete.
 */
class BotEconomyHeartbeatTest extends IsolatedAccountTestCase
{
    /**
     * Number of simulated heartbeats (~45 min apart ≈ 30 game hours).
     */
    private const HEARTBEAT_COUNT = 40;

    /**
     * Buildings counted toward the "structures grew" assertion.
     *
     * @var array<int, string>
     */
    private const TRACKED_STRUCTURES = [
        'metal_mine',
        'crystal_mine',
        'deuterium_synthesizer',
        'solar_plant',
        'robot_factory',
        'shipyard',
        'research_lab',
        'nano_factory',
    ];

    /**
     * Assert that repeated heartbeats queue and complete buildings and
     * research without a single failed heartbeat.
     */
    public function testHeartbeatsGrowEconomyAndResearch(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($bot->user_id, true);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $initialStructures = $this->sumStructureLevels($home);
        $initialResearch = $this->sumResearchLevels($player);

        for ($i = 1; $i <= self::HEARTBEAT_COUNT; $i++) {
            $this->travel(45)->minutes();
            Bus::dispatchSync(new BotHeartbeatJob($bot->id));

            $bot->refresh();
            $this->assertSame($i, (int) $bot->total_ticks);

            // A failed heartbeat records -1; the brain must not crash the bot.
            $this->assertGreaterThanOrEqual(
                0,
                (int) $bot->last_actions,
                sprintf('Heartbeat %d failed (last_actions=%s)', $i, $bot->last_actions)
            );
        }

        // Rethink state fresh: the factory may still hand out cached services.
        $player = $playerServiceFactory->make($bot->user_id, true);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $this->assertGreaterThan(
            $initialStructures,
            $this->sumStructureLevels($home),
            'Bot economy did not grow over the simulated period'
        );
        $this->assertGreaterThan(
            $initialResearch,
            $this->sumResearchLevels($player),
            'Bot research did not grow over the simulated period'
        );
    }

    /**
     * Assert that the very first heartbeat queues at least one action, which
     * catches brains that never become affordable or hit a service bug on a
     * fresh account.
     */
    public function testFirstHeartbeatQueuesAtLeastOneAction(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'discoverer']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
        $bot->refresh();

        $this->assertGreaterThanOrEqual(1, (int) $bot->last_actions);
        $this->assertSame(1, (int) $bot->total_ticks);

        // A successful heartbeat must reschedule itself into the future.
        $this->assertNotNull($bot->next_action_at);
        $this->assertGreaterThan(Date::now()->timestamp, $bot->next_action_at->timestamp);
    }

    /**
     * Assert that the snapshot builder returns a consistent aggregate view of
     * a freshly created bot account: one home planet, no moons, no fleets.
     */
    public function testSnapshotBuilderProducesConsistentAggregate(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'discoverer']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($bot->user_id, true);

        /** @var SnapshotBuilder $snapshotBuilder */
        $snapshotBuilder = resolve(SnapshotBuilder::class);
        $snapshot = $snapshotBuilder->build($player);

        $this->assertInstanceOf(BrainSnapshot::class, $snapshot);
        $this->assertNotEmpty($snapshot->planets);
        $this->assertFalse($snapshot->planets[0]->isMoon());
        $this->assertEmpty($snapshot->moons);
        $this->assertSame(0, $snapshot->colonyShipsInStock);
        $this->assertSame(0, $snapshot->militaryUnitCount);
        // Note: accounts start with `registration_planet_amount` planets which
        // can exceed getMaxPlanetAmount() until astrophysics is researched, so
        // planetCount > maxPlanets is a valid state for fresh accounts.
        $this->assertGreaterThan(0, $player->planets->planetCount());
    }

    /**
     * Assert that a heartbeat renames planets that carry the engine default
     * name (e.g. a freshly colonised planet the engine auto-named "Colony").
     */
    public function testHeartbeatRenamesEngineDefaultPlanets(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1]));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);

        $home = $playerServiceFactory->make($bot->user_id, true)->planets->first();
        $this->assertNotNull($home);
        $home->setPlanetName('Colony');

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));

        $home = $playerServiceFactory->make($bot->user_id, true)->planets->first();
        $this->assertNotNull($home);
        $this->assertNotSame('Colony', $home->getPlanetName());
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9-_ ]{2,20}$/', $home->getPlanetName());
    }

    /**
     * Sum the tracked building levels on a planet.
     */
    private function sumStructureLevels(PlanetService $planet): int
    {
        $sum = 0;
        foreach (self::TRACKED_STRUCTURES as $machineName) {
            $sum += $planet->getObjectLevel($machineName);
        }

        return $sum;
    }

    /**
     * Sum all research levels across the research lines the brain knows about.
     */
    private function sumResearchLevels(PlayerService $player): int
    {
        /** @var array<string, int> $caps */
        $caps = config('bots.research.caps', []);
        $sum = 0;
        foreach (array_keys($caps) as $machineName) {
            $sum += $player->getResearchLevel($machineName);
        }

        return $sum;
    }
}
