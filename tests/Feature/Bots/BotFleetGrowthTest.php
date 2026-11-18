<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ColonisationTargetFinder;
use OGame\Services\DebrisFieldService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\UnitQueueService;
use Tests\IsolatedAccountTestCase;

/**
 * Phase-2 brain policies (docs/BOT-AI-PLAN.md §5 + BOT-AI-PLAN.md fleet &
 * growth roadmap): inter-planet resource transports, debris recycling and
 * crawler harvesting, driven through the real brain against a real bot
 * account.
 */
class BotFleetGrowthTest extends IsolatedAccountTestCase
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
     * Give a planet a level of the given object (buildings and ship counts
     * both live on the planet model).
     */
    private function setPlanetLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
    }

    /**
     * Remove a planet's metal and crystal stocks exactly. Unit-queue orders
     * are paid for at order time, so this must deduct only what is left on
     * hand rather than a fixed amount.
     */
    private function stripMetalAndCrystal(PlanetService $planet): void
    {
        $current = $planet->getResources();

        $planet->deductResources(new Resources(
            (int) $current->metal->get(),
            (int) $current->crystal->get(),
            0,
            0,
        ));
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
     * Create a second owned planet for the bot at a free coordinate.
     */
    private function createSecondPlanet(PlayerService $player): PlanetService
    {
        $coordinate = resolve(ColonisationTargetFinder::class)->findFor($player, 12);
        $this->assertNotNull($coordinate, 'No free coordinate found to host the bot\'s second planet.');

        return resolve(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, $coordinate);
    }

    /**
     * A saturated planet with large transports in harbor ships its surplus to
     * a bare planet whose unit-queue backlog exceeds its own stock, as a
     * transport mission carrying both scarce resources.
     */
    public function testTransportsSurplusResourcesToBackloggedPlanet(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $second = $this->createSecondPlanet($player);

        // Home: saturated stocks and large transports in the harbor.
        $home->addResources(new Resources(500000, 500000, 300000, 0));
        $home->addUnit('large_cargo', 10);

        // Second planet: queue a batch of small transports it can pay for,
        // then strip its stocks so the queued cost becomes real demand.
        $this->setPlanetLevel($second, 'shipyard', 2);
        $player->setResearchLevel('combustion_drive', 2);
        $second->addResources(new Resources(300000, 300000, 50000, 0));
        resolve(UnitQueueService::class)->add(
            $second,
            ObjectService::getObjectByMachineName('small_cargo')->id,
            40,
        );
        $this->stripMetalAndCrystal($second);

        $this->playOnce($bot);

        $mission = FleetMission::where('mission_type', 3)
            ->where('planet_id_from', $home->getPlanetId())
            ->where('planet_id_to', $second->getPlanetId())
            ->first();

        $this->assertNotNull($mission, 'No transport mission was dispatched to the backlogged planet.');
        $this->assertGreaterThan(0, $mission->metal, 'Transport carries no metal.');
        $this->assertGreaterThan(0, $mission->crystal, 'Transport carries no crystal.');
    }

    /**
     * Transport cargo below the configured minimum never leaves the source
     * planet; the same fixture dispatches once the minimum is lowered.
     */
    public function testTransportSkippedWhenCargoBelowMinimum(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $second = $this->createSecondPlanet($player);

        $home->addResources(new Resources(500000, 500000, 300000, 0));
        $home->addUnit('large_cargo', 10);

        // A single small cargo's cost (~4000 total) stays below the 5000
        // minimum transport amount.
        $this->setPlanetLevel($second, 'shipyard', 2);
        $player->setResearchLevel('combustion_drive', 2);
        $second->addResources(new Resources(100000, 100000, 50000, 0));
        resolve(UnitQueueService::class)->add(
            $second,
            ObjectService::getObjectByMachineName('small_cargo')->id,
            1,
        );
        $this->stripMetalAndCrystal($second);

        $this->playOnce($bot);

        $this->assertFalse(
            FleetMission::where('mission_type', 3)
                ->where('planet_id_from', $home->getPlanetId())
                ->where('planet_id_to', $second->getPlanetId())
                ->exists(),
            'A transport below the configured minimum amount was dispatched.',
        );

        // Same fixture, minimum lowered: the mission now goes out.
        config(['bots.transport.min_amount' => 1000]);
        $this->playOnce($bot);

        $this->assertTrue(
            FleetMission::where('mission_type', 3)
                ->where('planet_id_from', $home->getPlanetId())
                ->where('planet_id_to', $second->getPlanetId())
                ->exists(),
            'No transport dispatched even though the cargo now exceeds the lowered minimum.',
        );
    }

    /**
     * Battle debris at the bot's own planet is harvested with recyclers from
     * the planet's harbor.
     */
    public function testRecyclesDebrisOnOwnPlanet(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $home->addResources(new Resources(0, 0, 60000, 0));
        $home->addUnit('recycler', 2);

        $debrisField = resolve(DebrisFieldService::class, ['playerService' => $player]);
        $debrisField->loadOrCreateForCoordinates($home->getPlanetCoordinates());
        $debrisField->appendResources(new Resources(40000, 20000, 0, 0));
        $debrisField->save();

        $this->playOnce($bot);

        $mission = FleetMission::where('mission_type', 8)
            ->where('planet_id_from', $home->getPlanetId())
            ->where('galaxy_to', $home->getPlanetCoordinates()->galaxy)
            ->where('system_to', $home->getPlanetCoordinates()->system)
            ->where('position_to', $home->getPlanetCoordinates()->position)
            ->where('type_to', PlanetType::DebrisField->value)
            ->first();

        $this->assertNotNull($mission, 'No recycle mission was dispatched to the planet\'s own debris field.');
    }

    /**
     * Debris on a planet without recyclers in stock results in a recycler
     * build order instead of a (impossible) fleet dispatch.
     */
    public function testBuildsRecyclersWhenDebrisExistsButNoStock(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        // Meet the recycler build requirements so the queue order is real.
        $this->setPlanetLevel($home, 'shipyard', 4);
        $player->setResearchLevel('combustion_drive', 6);
        $player->setResearchLevel('shielding_technology', 2);
        $home->addResources(new Resources(200000, 200000, 200000, 0));

        $debrisField = resolve(DebrisFieldService::class, ['playerService' => $player]);
        $debrisField->loadOrCreateForCoordinates($home->getPlanetCoordinates());
        $debrisField->appendResources(new Resources(40000, 20000, 0, 0));
        $debrisField->save();

        $this->playOnce($bot);

        $this->assertTrue(
            UnitQueue::where('planet_id', $home->getPlanetId())
                ->where('object_id', ObjectService::getObjectByMachineName('recycler')->id)
                ->exists(),
            'No recyclers were queued despite harvestable debris and empty harbors.',
        );
    }

    /**
     * Crawlers are queued up to the engine's usable bonus cap (8 per total
     * mine level) and stop once the planet's crawler stock reaches it.
     */
    public function testQueuesCrawlersUpToUsableCap(): void
    {
        // Crawlers are Collector-class ships; the engine refuses the build
        // order for any other class.
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        // Meet the crawler requirements and give the planet a small mine base.
        $this->setPlanetLevel($home, 'shipyard', 4);
        $this->setPlanetLevel($home, 'metal_mine', 5);
        $this->setPlanetLevel($home, 'crystal_mine', 5);
        $this->setPlanetLevel($home, 'deuterium_synthesizer', 5);
        $player->setResearchLevel('combustion_drive', 4);
        $player->setResearchLevel('armor_technology', 4);
        $player->setResearchLevel('laser_technology', 4);
        $home->addResources(new Resources(300000, 300000, 300000, 0));

        $crawlerId = ObjectService::getObjectByMachineName('crawler')->id;

        $this->playOnce($bot);

        $this->assertTrue(
            UnitQueue::where('planet_id', $home->getPlanetId())
                ->where('object_id', $crawlerId)
                ->exists(),
            'No crawlers were queued on a mining planet with room in the crawler cap.',
        );

        // Stock the planet at exactly the usable cap (15 mine levels * 8) and
        // make sure no further crawlers are ordered.
        $home->addUnit('crawler', 120);
        UnitQueue::where('planet_id', $home->getPlanetId())->delete();

        $this->playOnce($bot);

        $this->assertFalse(
            UnitQueue::where('planet_id', $home->getPlanetId())
                ->where('object_id', $crawlerId)
                ->exists(),
            'Crawlers beyond the usable bonus cap were queued.',
        );
    }
}
