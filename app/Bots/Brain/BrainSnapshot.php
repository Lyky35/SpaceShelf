<?php

namespace OGame\Bots\Brain;

use OGame\Models\Resources;
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
     * @param array<int, Resources> $queuedBacklog Sum of unfinished build/research/unit
     *        queue costs per owned planet or moon, keyed by planet id.
     * @param array<int, Resources> $debrisFields Debris field resources at owned
     *        planets' coordinates, keyed by planet id. Only planets with debris
     *        are present.
     */
    public function __construct(
        public PlayerService $player,
        public array $planets,
        public array $moons,
        public int $colonyShipsInStock,
        public int $militaryUnitCount,
        public array $queuedBacklog = [],
        public array $debrisFields = [],
    ) {
    }
}
