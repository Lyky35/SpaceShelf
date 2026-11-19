<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Power management: a bot must not let its planets drift into a negative
 * power balance, where the production factor penalty cripples every resource
 * building. While a planet is short on power the brain cancels the queued
 * mine backlog and only builds power plants, mirroring what a vanilla player
 * does the moment the power bar goes red.
 */
class BotEnergyBalanceTest extends IsolatedAccountTestCase
{
    /**
     * Machine names of energy-hungry mines the shortage policy must cancel.
     *
     * @var array<int, string>
     */
    private const CONSUMING_MINES = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'];

    /**
     * Create one calm bot (no personality quirks) and return it.
     */
    private function createBot(string $profile = 'collector'): Bot
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => $profile]));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        // Pin the quirks away: habits flip build orders, and this suite
        // asserts the default power-management behavior.
        $personality = $bot->personality;
        $personality['habits'] = [];
        $bot->personality = $personality;
        $bot->save();

        return $bot;
    }

    /**
     * Reload the bot's player service with fresh state.
     */
    private function reloadPlayer(Bot $bot): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($bot->user_id, true);
    }

    /**
     * The bot's (only) home planet.
     */
    private function homePlanet(PlayerService $player): PlanetService
    {
        $home = null;
        foreach ($player->planets->allPlanets() as $planet) {
            if ($home === null || $planet->getPlanetId() < $home->getPlanetId()) {
                $home = $planet;
            }
        }

        $this->assertNotNull($home);

        return $home;
    }

    /**
     * Run the brain exactly once against the bot's current state, mirroring
     * the heartbeat's "login" step first.
     */
    private function playOnce(Bot $bot): int
    {
        $player = $this->reloadPlayer($bot);

        $player->update();
        foreach ($player->planets->allPlanets() as $planet) {
            $planet->update();
        }

        return resolve(BotBrain::class)->play(
            resolve(SnapshotBuilder::class)->build($player),
            $bot->personalityObject(),
        );
    }

    /**
     * Set a building level directly (bypassing costs and requirements).
     */
    private function setLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
    }

    /**
     * Make the planet energy-negative: heavy mines over a token solar plant.
     * Stores are raised so resource drops are not clipped by capacity.
     */
    private function makePowerShort(PlanetService $planet): void
    {
        $this->setLevel($planet, 'metal_mine', 12);
        $this->setLevel($planet, 'crystal_mine', 12);
        $this->setLevel($planet, 'solar_plant', 3);
        $this->setLevel($planet, 'metal_store', 6);
        $this->setLevel($planet, 'crystal_store', 6);
        $this->setLevel($planet, 'deuterium_store', 6);
        $planet->addResources(new Resources(1_000_000, 1_000_000, 1_000_000, 0));

        $planet->update();
        $this->assertLessThan(100.0, $planet->getResourceProductionFactor());
    }

    /**
     * Machine names of the planet's pending (processed=0, canceled=0) building
     * queue items.
     *
     * @return array<int, string>
     */
    private function pendingQueue(PlanetService $planet): array
    {
        $objectIds = BuildingQueue::query()
            ->where('planet_id', $planet->getPlanetId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->pluck('object_id')
            ->all();

        $machineNames = [];
        foreach ($objectIds as $objectId) {
            $machineNames[] = ObjectService::getObjectById($objectId)->machine_name;
        }

        return $machineNames;
    }

    /**
     * While short on power, queued mines are cancelled even though they are
     * affordable, and the freed visit budget goes to a solar plant.
     */
    public function testPowerShortageCancelsQueuedMineBacklog(): void
    {
        $bot = $this->createBot();
        $planet = $this->homePlanet($this->reloadPlayer($bot));
        $this->makePowerShort($planet);

        $queueService = resolve(BuildingQueueService::class);
        foreach (['metal_mine', 'crystal_mine', 'metal_mine', 'crystal_mine'] as $machineName) {
            $queueService->add($planet, ObjectService::getObjectByMachineName($machineName)->id);
        }
        $this->assertCount(4, $this->pendingQueue($planet));

        $actions = $this->playOnce($bot);
        $this->assertGreaterThanOrEqual(1, $actions);

        // All four queued mines must be gone, and the brain must have queued
        // power instead — despite every candidate being trivially affordable.
        $pending = $this->pendingQueue($planet);
        foreach (self::CONSUMING_MINES as $mine) {
            $this->assertNotContains($mine, $pending, sprintf('Queued %s survived a power shortage', $mine));
        }
        $this->assertContains('solar_plant', $pending);
    }

    /**
     * While short on power, the brain queues no energy-hungry buildings from a
     * cold start — only power plants.
     */
    public function testPowerShortageOnlyQueuesPowerPlants(): void
    {
        $bot = $this->createBot();
        $planet = $this->homePlanet($this->reloadPlayer($bot));
        $this->makePowerShort($planet);

        $this->assertEmpty($this->pendingQueue($planet));

        $this->playOnce($bot);

        $pending = $this->pendingQueue($planet);
        $this->assertNotEmpty($pending, 'Power-starved planet must keep building power');
        foreach (self::CONSUMING_MINES as $mine) {
            $this->assertNotContains($mine, $pending);
        }
        $this->assertSame(['solar_plant'], array_values(array_unique($pending)));
    }

    /**
     * Nothing is cancelled while production covers consumption: the fix must
     * not neuter normal growth.
     */
    public function testMineBacklogSurvivesWhilePowerIsPositive(): void
    {
        $bot = $this->createBot();
        $planet = $this->homePlanet($this->reloadPlayer($bot));

        $this->setLevel($planet, 'metal_mine', 1);
        $this->setLevel($planet, 'crystal_mine', 1);
        $this->setLevel($planet, 'solar_plant', 6);
        $this->setLevel($planet, 'metal_store', 6);
        $this->setLevel($planet, 'crystal_store', 6);
        $planet->addResources(new Resources(100_000, 100_000, 100_000, 0));

        $planet->update();
        $this->assertGreaterThanOrEqual(100.0, $planet->getResourceProductionFactor());

        resolve(BuildingQueueService::class)->add(
            $planet,
            ObjectService::getObjectByMachineName('metal_mine')->id
        );

        $this->playOnce($bot);

        $pending = $this->pendingQueue($planet);
        $this->assertNotEmpty($pending, 'Healthy planet must keep building');
        $this->assertNotContains('solar_plant', $pending, 'No solar spam while power covers consumption');
    }
}
