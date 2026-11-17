<?php

namespace OGame\Bots\Brain;

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

        foreach ($planets as $planet) {
            $shipUnits = $planet->getShipUnits();
            $colonyShips += $shipUnits->getAmountByMachineName('colony_ship');

            foreach (self::MILITARY_UNITS as $unit) {
                $militaryUnits += $shipUnits->getAmountByMachineName($unit);
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
        );
    }
}
