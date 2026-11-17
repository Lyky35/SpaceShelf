<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Class-ship production (companion to phase 4): the brain must grow a small
 * stock of the engine's class-locked ships on the home planet — Reapers for
 * Generals, Pathfinders for Discoverers — and stop at the configured stock
 * target. Asserted on unit-queue rows only; the engine refuses the builds
 * outright for the wrong class, so the brain blocks are the tested surface.
 */
class BotClassShipsTest extends IsolatedAccountTestCase
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
     * Meet every research requirement of both class ships, raise the home
     * shipyard to the brain's gate and hand it deep pockets: whatever the
     * economy blocks queue first, a class ship must stay affordable.
     *
     * @param array<string, int> $levels Machine name to research level.
     */
    private function equipForClassShips(PlayerService $player, array $levels): PlanetService
    {
        foreach ($levels as $machineName => $level) {
            $player->setResearchLevel($machineName, $level);
        }

        $home = $this->homePlanet($player);
        $this->setPlanetLevel($home, 'shipyard', 6);
        $home->addResources(new Resources(500000, 500000, 500000, 0));

        return $home;
    }

    /**
     * Whether the home planet has a queued build of this object id.
     */
    private function queuedOnHome(PlanetService $planet, string $machineName): bool
    {
        return UnitQueue::where('planet_id', $planet->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName($machineName)->id)
            ->exists();
    }

    public function testGeneralQueuesReaperAtHome(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        $home = $this->equipForClassShips($player, [
            'impulse_drive' => 6,
            'hyperspace_drive' => 4,
            'weapon_technology' => 8,
            'shielding_technology' => 6,
        ]);

        $this->playOnce($bot);

        $this->assertTrue(
            $this->queuedOnHome($home, 'reaper'),
            'An equipped General world should start building a Reaper.',
        );
    }

    public function testGeneralStopsAtReaperStockTarget(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        $home = $this->equipForClassShips($player, [
            'impulse_drive' => 6,
            'hyperspace_drive' => 4,
            'weapon_technology' => 8,
            'shielding_technology' => 6,
        ]);

        // Stock the harbour exactly at the configured target (2).
        $home->addUnit('reaper', 2);
        UnitQueue::where('planet_id', $home->getPlanetId())->delete();

        $this->playOnce($bot);

        $this->assertFalse(
            $this->queuedOnHome($home, 'reaper'),
            'The brain must stop ordering Reapers once the stock target is met.',
        );
    }

    public function testDiscovererQueuesPathfinderAtHome(): void
    {
        $bot = $this->createBot('discoverer');
        $player = $this->reloadPlayer($bot);

        $home = $this->equipForClassShips($player, [
            'combustion_drive' => 6,
            'shielding_technology' => 4,
            'hyperspace_technology' => 2,
        ]);

        $this->playOnce($bot);

        $this->assertTrue(
            $this->queuedOnHome($home, 'pathfinder'),
            'An equipped Discoverer world should start building a Pathfinder.',
        );
    }

    public function testDiscovererStopsAtPathfinderStockTarget(): void
    {
        $bot = $this->createBot('discoverer');
        $player = $this->reloadPlayer($bot);

        $home = $this->equipForClassShips($player, [
            'combustion_drive' => 6,
            'shielding_technology' => 4,
            'hyperspace_technology' => 2,
        ]);

        // Stock the harbour at the configured target (3).
        $home->addUnit('pathfinder', 3);
        UnitQueue::where('planet_id', $home->getPlanetId())->delete();

        $this->playOnce($bot);

        $this->assertFalse(
            $this->queuedOnHome($home, 'pathfinder'),
            'The brain must stop ordering Pathfinders once the stock target is met.',
        );
    }

    public function testCollectorNeverQueuesClassShips(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);

        // Same gear as the two class tests: the only thing missing is the
        // character class, which must keep the brain from ordering at all.
        $home = $this->equipForClassShips($player, [
            'impulse_drive' => 6,
            'hyperspace_drive' => 4,
            'weapon_technology' => 8,
            'shielding_technology' => 8,
            'combustion_drive' => 6,
            'hyperspace_technology' => 2,
        ]);

        $this->playOnce($bot);

        $this->assertFalse($this->queuedOnHome($home, 'reaper'));
        $this->assertFalse($this->queuedOnHome($home, 'pathfinder'));
    }
}
