<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Moon play: the brain builds the lunar facility line on every owned moon,
 * defensive personalities garrison it with half their planetary combat
 * defense target, and the transport policy ships resources there because
 * moons produce nothing themselves.
 */
class BotMoonPlayTest extends IsolatedAccountTestCase
{
    private const MISSION_TYPE_TRANSPORT = 3;

    /**
     * Create one bot with the given profile and return it.
     */
    private function createBot(string $profile): Bot
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => $profile]));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        // Pin the quirks away: habits flip build orders and scale build
        // targets, and this suite asserts the default profile behavior.
        // Habit-specific tests live in BotHumanizationTest.
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

        if ($home === null) {
            $this->fail('Player has no planets to pick a home from.');
        }

        return $home;
    }

    /**
     * Give a planet a level of the given object (buildings and ship counts
     * both live on the planet model).
     */
    private function setPlanetLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
    }

    /**
     * Raise a moon over the bot's home planet (100% moon chance guarantees
     * creation) and return its service.
     */
    private function createMoon(PlayerService $player): PlanetService
    {
        return resolve(PlanetServiceFactory::class)->createMoonForPlanet(
            $this->homePlanet($player),
            150000,
            100,
        );
    }

    /**
     * Whether the moon has a queued build of this machine name.
     */
    private function moonQueues(PlanetService $moon, string $machineName): bool
    {
        $objectId = ObjectService::getObjectByMachineName($machineName)->id;

        return BuildingQueue::where('planet_id', $moon->getPlanetId())
                ->where('object_id', $objectId)
                ->exists()
            || UnitQueue::where('planet_id', $moon->getPlanetId())
                ->where('object_id', $objectId)
                ->exists();
    }

    public function testDevelopsLunarBaseLineOnMoon(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $moon = $this->createMoon($player);

        // Fund the moon directly: the transport test covers indirect funding.
        $moon->addResources(new Resources(2000000, 2000000, 1000000, 0));

        $this->playOnce($bot);

        $this->assertTrue(
            $this->moonQueues($moon, 'lunar_base'),
            'Every owned moon should get its lunar base line started.',
        );

        // A General garrisons nothing on rocks, even funded ones.
        $this->assertFalse(
            UnitQueue::where('planet_id', $moon->getPlanetId())->exists(),
            'General profiles must not spend moon resources on defense.',
        );
    }

    public function testBuildsDefenseGarrisonOnMoon(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $moon = $this->createMoon($player);

        // Facility prerequisites met so the garrison is what stands to build.
        $this->setPlanetLevel($moon, 'lunar_base', 3);
        $this->setPlanetLevel($moon, 'sensor_phalanx', 1);
        $this->setPlanetLevel($moon, 'robot_factory', 2);
        $this->setPlanetLevel($moon, 'shipyard', 2);
        $moon->addResources(new Resources(2000000, 2000000, 1000000, 0));

        $this->playOnce($bot);

        $this->assertTrue(
            UnitQueue::where('planet_id', $moon->getPlanetId())->exists(),
            'Defensive personalities should garrison their moons.',
        );
    }

    public function testStopsDevelopingAtPlannedLevels(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $moon = $this->createMoon($player);

        // Facility line complete; garrison above any jittered half-target a
        // collector could ask for (bias 0.9 +15% jitter → target 124 → 62).
        $this->setPlanetLevel($moon, 'lunar_base', 3);
        $this->setPlanetLevel($moon, 'sensor_phalanx', 1);
        $this->setPlanetLevel($moon, 'robot_factory', 2);
        $this->setPlanetLevel($moon, 'shipyard', 2);
        $moon->addUnit('rocket_launcher', 80);
        $moon->addResources(new Resources(2000000, 2000000, 1000000, 0));

        $this->playOnce($bot);

        $this->assertFalse(BuildingQueue::where('planet_id', $moon->getPlanetId())->exists());
        $this->assertFalse(UnitQueue::where('planet_id', $moon->getPlanetId())->exists());
    }

    public function testTransportFundsMoon(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);
        $moon = $this->createMoon($player);

        // Home storage-saturated with a transporter pool and enough fuel for
        // the jump; moon is broke, so the only demand signal in play is the
        // moon's development plan.
        $this->setPlanetLevel($home, 'metal_store', 2);
        $home->addUnit('large_cargo', 5);
        $home->addResources(new Resources(
            (int) ceil($home->metalStorage()->get() * 0.95),
            0,
            (int) ceil($home->deuteriumStorage()->get() * 0.5) + 50000,
            0,
        ));

        $this->playOnce($bot);

        $this->assertTrue(
            FleetMission::where('planet_id_to', $moon->getPlanetId())
                ->where('mission_type', self::MISSION_TYPE_TRANSPORT)
                ->exists(),
            'The transport policy must ship the moon development plan from a saturated planet.',
        );
    }
}
