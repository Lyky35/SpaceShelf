<?php

namespace OGame\Bots\Brain;

use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Immutable world view handed to the brain for one heartbeat. Everything the
 * brain may read lives here; the brain itself performs no database queries.
 */
final readonly class BrainSnapshot
{
    /**
     * @param PlayerService $player The bot's player service (already updated).
     * @param array<PlanetService> $planets All owned planets.
     * @param array<PlanetService> $moons All owned moons.
     * @param int $colonyShipsInStock Total colony ships across all planets.
     * @param int $militaryUnitCount Total combat ships across all planets.
     */
    public function __construct(
        public PlayerService $player,
        public array $planets,
        public array $moons,
        public int $colonyShipsInStock,
        public int $militaryUnitCount,
    ) {
    }
}
