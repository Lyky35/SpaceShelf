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
 * War texture: the standing fleet grows into a hull mix instead of a wall of
 * identical light fighters, once the research and shipyard unlock the
 * heavier hulls.
 */
class BotFleetMixTest extends IsolatedAccountTestCase
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
     * The bot's home planet: always the lowest planet id.
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
     * Give a planet a level of the given object.
     */
    private function setPlanetLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
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
     * All hull machine names from the standing-fleet mix that have a queued
     * build on this planet.
     *
     * @return array<int, string>
     */
    private function queuedHulls(PlanetService $planet): array
    {
        $machineNameByObject = [];
        foreach ((array) config('bots.military.fleet_mix') as $machineName => $weight) {
            $machineNameByObject[ObjectService::getObjectByMachineName($machineName)->id] = (string) $machineName;
        }

        $objectIds = UnitQueue::where('planet_id', $planet->getPlanetId())
            ->whereIn('object_id', array_keys($machineNameByObject))
            ->pluck('object_id')
            ->unique()
            ->all();

        return array_values(array_map(
            static fn (int $objectId): string => $machineNameByObject[$objectId],
            $objectIds,
        ));
    }

    /**
     * The standing fleet is a hull mix, not a wall of identical light
     * fighters: once the tech tree unlocks them, heavier hulls join the
     * batches too (weighted draw — light fighters stay the bulk).
     */
    public function testFleetMixGrowsBeyondLightFighters(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        // Unlock every hull in the configured mix (requirements read from
        // this fork's ship tables).
        $this->setPlanetLevel($home, 'shipyard', 8);
        $player->setResearchLevel('combustion_drive', 3);
        $player->setResearchLevel('armor_technology', 3);
        $player->setResearchLevel('impulse_drive', 6);
        $player->setResearchLevel('ion_technology', 3);
        $player->setResearchLevel('hyperspace_drive', 5);
        $player->setResearchLevel('laser_technology', 12);
        $player->setResearchLevel('plasma_technology', 5);
        $home->addResources(new Resources(200000000, 200000000, 200000000, 100000000));

        for ($visit = 0; $visit < 40; $visit++) {
            $this->playOnce($bot);
        }

        $home = $this->homePlanet($this->reloadPlayer($bot));
        $hulls = $this->queuedHulls($home);

        $this->assertContains(
            'light_fighter',
            $hulls,
            'Light fighters must stay in the mix — they are the fleet bulk.',
        );
        $this->assertGreaterThan(
            1,
            count($hulls),
            'A player fleet is a mix of hulls; the standing fleet must grow beyond light fighters.',
        );

        // Statistically the heavy hulls are each drawn on well under half of
        // the visits — assert only variety here, never a specific hull.
    }
}
