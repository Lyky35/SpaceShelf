<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Defense depth: once a defensive personality's combat defense target
 * holds, the brain spends the surplus on shield dome layers, raises the
 * missile silo through the ordinary building pass, and stocks
 * anti-ballistic missiles capped by the lesser of `abm_target` and the
 * engine's silo capacity (10 slots per silo level).
 */
class BotDefenseDepthTest extends IsolatedAccountTestCase
{
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
     * Put the home planet behind every defense-depth gate except the depth
     * builds themselves: combat defense at (and above) the collector target
     * even with personality jitter, shield-dome research met, shipyard up,
     * and deep pockets so affordability never explains a missing build.
     */
    private function prepareDefensiveHome(Bot $bot): PlanetService
    {
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        $this->setPlanetLevel($home, 'shipyard', 1);
        $player->setResearchLevel('shielding_technology', 2);
        $home->addUnit('light_laser', 150);
        $home->addResources(new Resources(1000000, 1000000, 1000000, 0));

        return $home;
    }

    /**
     * Whether the planet has a queued unit build of this machine name.
     */
    private function unitQueuedOn(PlanetService $planet, string $machineName): bool
    {
        return UnitQueue::where('planet_id', $planet->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName($machineName)->id)
            ->exists();
    }

    /**
     * Whether the planet has a queued building of this machine name.
     */
    private function buildingQueuedOn(PlanetService $planet, string $machineName): bool
    {
        return BuildingQueue::where('planet_id', $planet->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName($machineName)->id)
            ->exists();
    }

    public function testQueuesShieldDomeOnceCombatDefenseHolds(): void
    {
        $bot = $this->createBot('collector');
        $home = $this->prepareDefensiveHome($bot);

        $this->playOnce($bot);

        $this->assertTrue(
            $this->unitQueuedOn($home, 'small_shield_dome'),
            'A defended bunker world should add shield dome layers to its defense stack.',
        );
    }

    public function testStopsQueueingDomesAtTarget(): void
    {
        $bot = $this->createBot('collector');
        $home = $this->prepareDefensiveHome($bot);
        $home->addUnit('small_shield_dome', 2);

        $this->playOnce($bot);

        $this->assertFalse($this->unitQueuedOn($home, 'small_shield_dome'));
        $this->assertFalse($this->unitQueuedOn($home, 'large_shield_dome'));
    }

    public function testRaisesSiloThenBuildsAbms(): void
    {
        $bot = $this->createBot('collector');
        $home = $this->prepareDefensiveHome($bot);

        // Domes already at target so the depth pass only has ABMs left to do.
        $home->addUnit('small_shield_dome', 2);

        // Silo level 0: the building pass raises it toward abm_silo_level
        // before any interceptor can be ordered.
        $this->playOnce($bot);

        $this->assertTrue(
            $this->buildingQueuedOn($home, 'missile_silo'),
            'ABM production must start by raising the missile silo.',
        );

        $this->assertFalse(
            $this->unitQueuedOn($home, 'anti_ballistic_missile'),
            'No ABMs while the silo is below the build prerequisite.',
        );

        // With the silo at the required level, the interceptor stock starts.
        $this->setPlanetLevel($home, 'missile_silo', 2);
        UnitQueue::where('planet_id', $home->getPlanetId())->delete();
        BuildingQueue::where('planet_id', $home->getPlanetId())->delete();

        $this->playOnce($bot);

        $this->assertTrue(
            $this->unitQueuedOn($home, 'anti_ballistic_missile'),
            'ABMs should be built once the silo supports them.',
        );
    }

    public function testStopsAbmsAtTargetAndCapsAtSiloCapacity(): void
    {
        $bot = $this->createBot('collector');
        $home = $this->prepareDefensiveHome($bot);
        $home->addUnit('small_shield_dome', 2);
        $this->setPlanetLevel($home, 'missile_silo', 2);
        $home->addUnit('anti_ballistic_missile', 20); // abm_target 20 == capacity

        $this->playOnce($bot);

        $this->assertFalse($this->unitQueuedOn($home, 'anti_ballistic_missile'));

        // Raising the stock target must not outgrow the 20-slot silo (2 levels).
        config()->set('bots.military.abm_target', 50);
        UnitQueue::where('planet_id', $home->getPlanetId())->delete();

        $this->playOnce($bot);

        $this->assertFalse($this->unitQueuedOn($home, 'anti_ballistic_missile'));
    }

    public function testGeneralNeverBuildsDefenseDepth(): void
    {
        $bot = $this->createBot('general');
        $home = $this->prepareDefensiveHome($bot);

        $this->playOnce($bot);

        $this->assertFalse($this->unitQueuedOn($home, 'small_shield_dome'));
        $this->assertFalse($this->unitQueuedOn($home, 'large_shield_dome'));
        $this->assertFalse($this->unitQueuedOn($home, 'anti_ballistic_missile'));
        $this->assertFalse($this->buildingQueuedOn($home, 'missile_silo'));
    }
}
