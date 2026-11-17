<?php

namespace OGame\Bots\Brain;

use OGame\Models\BuildingQueue;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\DebrisFieldService;
use OGame\Services\PlayerService;

/**
 * Loads the BrainSnapshot for a player that has already been updated by the
 * heartbeat ("login" step).
 */
final class SnapshotBuilder
{
    /**
     * Combat ship machine names that count toward the "standing fleet" target.
     *
     * @var array<int, string>
     */
    private const MILITARY_UNITS = [
        'light_fighter',
        'heavy_fighter',
        'cruiser',
        'battlecruiser',
        'battleship',
        'battle_ship',
        'destroyer',
        'bomber',
        'reaper',
    ];

    /**
     * @param PlayerService $player
     * @return BrainSnapshot
     */
    public function build(PlayerService $player): BrainSnapshot
    {
        $planets = $player->planets->allPlanets();
        $moons = $player->planets->allMoons();

        $colonyShips = 0;
        $militaryUnits = 0;

        $queuedBacklog = [];
        $debrisFields = [];
        $debrisFieldService = resolve(DebrisFieldService::class, ['playerService' => $player]);

        foreach ($planets as $planet) {
            $shipUnits = $planet->getShipUnits();
            $colonyShips += $shipUnits->getAmountByMachineName('colony_ship');

            foreach (self::MILITARY_UNITS as $unit) {
                $militaryUnits += $shipUnits->getAmountByMachineName($unit);
            }

            $queuedBacklog[$planet->getPlanetId()] = $this->queuedBacklog($planet->getPlanetId());

            if ($debrisFieldService->loadForCoordinates($planet->getPlanetCoordinates())) {
                $debrisFields[$planet->getPlanetId()] = $debrisFieldService->getResources();
            }
        }

        foreach ($moons as $moon) {
            $shipUnits = $moon->getShipUnits();
            foreach (self::MILITARY_UNITS as $unit) {
                $militaryUnits += $shipUnits->getAmountByMachineName($unit);
            }
        }

        return new BrainSnapshot(
            player: $player,
            planets: $planets,
            moons: $moons,
            colonyShipsInStock: $colonyShips,
            militaryUnitCount: $militaryUnits,
            queuedBacklog: $queuedBacklog,
            debrisFields: $debrisFields,
        );
    }

    /**
     * Sum the costs of all unfinished queue items on a planet across the three
     * construction queues. This is the planet's resource demand signal for the
     * inter-planet transport policy.
     */
    private function queuedBacklog(int $planetId): Resources
    {
        $sum = fn (string $resource): int => (int) BuildingQueue::where('planet_id', $planetId)->sum($resource)
            + (int) ResearchQueue::where('planet_id', $planetId)->sum($resource)
            + (int) UnitQueue::where('planet_id', $planetId)->sum($resource);

        return new Resources($sum('metal'), $sum('crystal'), $sum('deuterium'), 0);
    }
}
