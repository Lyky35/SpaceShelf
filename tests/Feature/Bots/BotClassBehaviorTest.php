<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use OGame\Factories\PlayerServiceFactory;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

/**
 * Assert that the three behavior classes actually play differently: Collectors
 * grow planetary defense with a tiny fleet, Generals build fighters and never
 * build any defense, and Discoverers grow both.
 *
 * All bots receive the same preset (shipyard 4 + defense/fleet tech + deep
 * stockpiles) so every behavioral difference must come from the personality
 * class alone.
 */
class BotClassBehaviorTest extends IsolatedAccountTestCase
{
    /**
     * Heartbeats to run after setup: the first queues units, the later ones
     * complete the previous queue and refill, so built units become visible.
     */
    private const HEARTBEATS = 3;

    /**
     * Assert that a collector turns its homeworld into a bunker: defense
     * units get built, while the standing fleet stays tiny.
     */
    public function testCollectorBuildsDefenseWithTinyFleet(): void
    {
        $bot = $this->createPresetBot('collector');
        $home = $this->runHeartbeats($bot);

        $this->assertGreaterThan(
            0,
            $home->getDefenseUnits()->getAmount(),
            'Collector did not build any planetary defense'
        );
        $this->assertLessThan(
            50,
            $home->getShipUnits()->getAmountByMachineName('light_fighter'),
            'Collector fleet grew far past its tiny target'
        );
    }

    /**
     * Assert that a general spends on the fleet and never builds planetary
     * defense.
     */
    public function testGeneralBuildsFleetAndNoDefense(): void
    {
        $bot = $this->createPresetBot('general');
        $home = $this->runHeartbeats($bot);

        $this->assertGreaterThan(
            0,
            $home->getShipUnits()->getAmountByMachineName('light_fighter'),
            'General did not grow its fleet'
        );
        $this->assertSame(
            0,
            $home->getDefenseUnits()->getAmount(),
            'General must never build planetary defense'
        );
    }

    /**
     * Assert that a discoverer keeps a balanced approach: both defense and
     * fleet grow.
     */
    public function testDiscovererBuildsBothDefenseAndFleet(): void
    {
        $bot = $this->createPresetBot('discoverer');
        $home = $this->runHeartbeats($bot);

        $this->assertGreaterThan(
            0,
            $home->getDefenseUnits()->getAmount(),
            'Discoverer did not build planetary defense'
        );
        $this->assertGreaterThan(
            0,
            $home->getShipUnits()->getAmountByMachineName('light_fighter'),
            'Discoverer did not grow its fleet'
        );
    }

    /**
     * Create one bot of the given class and preset it with enough shipyard,
     * research and stockpile to reach the ion-cannon defense tier and the
     * light-fighter hull immediately, removing economy-pacing flakiness from
     * the behavior assertions.
     */
    private function createPresetBot(string $profile): Bot
    {
        $this->assertSame(
            0,
            Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => $profile])
        );

        $bot = Bot::orderByDesc('id')->firstOrFail();
        $this->assertSame($profile, $bot->profile);

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($bot->user_id, true);

        $home = $player->planets->first();
        $this->assertNotNull($home);

        // Skip several game hours of pacing: shipyard plus the tech levels
        // that unlock the ion-cannon tier, colony ships and light fighters.
        $home->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4);
        $player->setResearchLevel('energy_technology', 6);
        $player->setResearchLevel('laser_technology', 6);
        $player->setResearchLevel('shielding_technology', 1);
        $player->setResearchLevel('ion_technology', 4);
        $player->setResearchLevel('impulse_drive', 3);
        $player->setResearchLevel('combustion_drive', 1);

        // Deep enough for many batches of ion cannons and fighters.
        $home->addResources(new Resources(20_000_000, 20_000_000, 10_000_000, 0));

        return $bot;
    }

    /**
     * Run a few heartbeats with game time progressing, then hand back a
     * freshly loaded copy of the bot's homeworld.
     */
    private function runHeartbeats(Bot $bot): PlanetService
    {
        for ($i = 0; $i < self::HEARTBEATS; $i++) {
            $this->travel(45)->minutes();
            Bus::dispatchSync(new BotHeartbeatJob($bot->id));

            $bot->refresh();
            $this->assertGreaterThanOrEqual(
                0,
                (int) $bot->last_actions,
                sprintf('Heartbeat %d failed (last_actions=%s)', $i + 1, $bot->last_actions)
            );
        }

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);

        $home = $playerServiceFactory->make($bot->user_id, true)->planets->first();
        $this->assertNotNull($home);

        return $home;
    }
}
