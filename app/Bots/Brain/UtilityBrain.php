<?php

namespace OGame\Bots\Brain;

use Exception;
use OGame\Bots\Personality;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ColonisationTargetFinder;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use OGame\Services\UnitQueueService;
use Throwable;

/**
 * Utility-scored brain, phase 1: economy buildings, research pacing, colony
 * ships + colonisation, solar satellites, a personality-scaled standing fleet
 * and tiered planetary defense for bunker-leaning personalities.
 *
 * Heuristics encode the classic OGame ratio rules (mine mix, power coverage,
 * 1:10 robot-factory pacing, storage vs. mine trade-off) and are tunable via
 * config/bots.php. War/scouting/expedition policies are later phases; see
 * docs/BOT-AI-PLAN.md.
 *
 * Every action is attempted through the same services the HTTP controllers
 * use, and every attempt is guarded: a failed action is logged by the caller
 * and skipped, never fatal to the heartbeat.
 */
final class UtilityBrain implements BotBrain
{
    /**
     * Mission type id for colonisation (OGame\GameMissions\ColonisationMission).
     */
    private const MISSION_TYPE_COLONISATION = 7;

    /**
     * Machine names of the three mines and their produced resource.
     *
     * @var array<string, string>
     */
    private const MINE_MAP = [
        'metal_mine' => 'metal',
        'crystal_mine' => 'crystal',
        'deuterium_synthesizer' => 'deuterium',
    ];

    /**
     * Machine names of the resource storages keyed by resource.
     *
     * @var array<string, string>
     */
    private const STORAGE_MAP = [
        'metal' => 'metal_store',
        'crystal' => 'crystal_store',
        'deuterium' => 'deuterium_store',
    ];

    /**
     * Defense unit tiers ordered strongest-first; the brain builds the
     * strongest tier whose shipyard and research requirements the planet
     * actually meets. Shield domes and missiles are left out of phase 1.
     *
     * @var array<int, string>
     */
    private const DEFENSE_TIERS = [
        'plasma_turret',
        'gauss_cannon',
        'ion_cannon',
        'heavy_laser',
        'light_laser',
        'rocket_launcher',
    ];

    public function __construct(
        private BuildingQueueService $buildingQueueService,
        private ResearchQueueService $researchQueueService,
        private UnitQueueService $unitQueueService,
        private ColonisationTargetFinder $colonisationTargetFinder,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function play(BrainSnapshot $snapshot, Personality $personality): int
    {
        $player = $snapshot->player;
        $actions = 0;

        foreach ($snapshot->planets as $planet) {
            $actions += $this->buildStructures($planet, $personality);
            $actions += $this->buildResearch($player, $planet, $personality);
            $actions += $this->buildUnits($snapshot, $planet, $personality);
            $actions += $this->buildDefense($planet, $personality);
        }

        $actions += $this->colonise($snapshot, $personality);

        return $actions;
    }

    /**
     * Queue up to `build_actions_per_visit` construction items on one planet.
     */
    private function buildStructures(PlanetService $planet, Personality $personality): int
    {
        $maxActions = (int) config('bots.economy.build_actions_per_visit', 3);
        $actions = 0;

        for ($i = 0; $i < $maxActions; $i++) {
            $added = false;

            foreach ($this->structureCandidateOrder($planet, $personality) as $machineName) {
                if (!$this->isAffordable($planet, $machineName, withReserve: false)) {
                    continue;
                }

                try {
                    $this->buildingQueueService->add($planet, ObjectService::getObjectByMachineName($machineName)->id);
                    $added = true;
                    break;
                } catch (Exception) {
                    // Queue full, requirements or planet fields exhausted for
                    // this candidate — fall through to the next one.
                    continue;
                }
            }

            if (!$added) {
                break;
            }

            $actions++;
        }

        return $actions;
    }

    /**
     * Score all structure candidates for one planet and return machine names
     * sorted by descending priority.
     *
     * @return array<int, string>
     */
    private function structureCandidateOrder(PlanetService $planet, Personality $personality): array
    {
        /** @var array<string, float> $score */
        $score = [];

        // Mines: chase the configured production mix. The resource furthest
        // below its target share gets the biggest bonus; unproduced resources
        // get a flat priority boost to bootstrap new planets.
        $production = [
            'metal' => $planet->getMetalProductionPerHour(),
            'crystal' => $planet->getCrystalProductionPerHour(),
            'deuterium' => $planet->getDeuteriumProductionPerHour(),
        ];
        $totalProduction = array_sum($production);
        /** @var array<string, float> $targets */
        $targets = config('bots.economy.targets', []);

        foreach (self::MINE_MAP as $machine => $resource) {
            $share = $totalProduction > 0 ? $production[$resource] / $totalProduction : 0.0;
            $target = $targets[$resource] ?? 0.0;
            $score[$machine] = ($target - $share) * 100 + ($production[$resource] <= 0 ? 40 : 0);
        }

        // Energy coverage: power plants first while the planet is not running
        // at full production factor.
        $energyFactor = $planet->getResourceProductionFactor();
        $energyFloor = (int) config('bots.economy.energy_floor_factor', 100);

        if ($energyFactor < $energyFloor) {
            $score['solar_plant'] = ($score['solar_plant'] ?? 0.0) + 100;
        }

        // Storage: overflow risk is worth a storage upgrade.
        $saturationThreshold = (float) config('bots.economy.storage_build_saturation', 0.85);

        foreach (self::STORAGE_MAP as $resource => $machine) {
            $saturation = $this->storageSaturation($planet, $resource);
            if ($saturation > $saturationThreshold) {
                $score[$machine] = ($score[$machine] ?? 0.0) + 50 + 50 * $saturation;
            }
        }

        // Robot factory pacing: roughly one robot factory level per N mine
        // levels keeps construction speed growing with the economy.
        $sumMineLevels = array_sum(
            array_map(fn (string $mine): int => $planet->getObjectLevel($mine), array_keys(self::MINE_MAP))
        );
        $ratio = max(1, (int) config('bots.economy.robot_factory_ratio', 10));
        $neededRobotFactory = intdiv($sumMineLevels, $ratio);

        if ($neededRobotFactory > $planet->getObjectLevel('robot_factory')) {
            $score['robot_factory'] = 55.0;
        }

        // Nano factory trails the robot factory by ~10 levels.
        $robotLevel = $planet->getObjectLevel('robot_factory');
        if ($robotLevel >= 10 && $planet->getObjectLevel('nano_factory') < $robotLevel - 9) {
            $score['nano_factory'] = 35.0;
        }

        // Shipyard keeps pace with the robot factory: without it the bot can
        // never build colony ships or a fleet.
        if ($planet->getObjectLevel('shipyard') < $planet->getObjectLevel('robot_factory')) {
            $score['shipyard'] = 45.0;
        }

        // Bunker-class personalities raise the shipyard to the ion-cannon tier
        // independent of robot factory pacing: defense units are built there.
        if (
            $personality->defenseBias >= $this->defenseMinBias()
            && $planet->getObjectLevel('shipyard') < 4
        ) {
            $score['shipyard'] = max($score['shipyard'] ?? 0.0, 50.0);
        }

        // Research lab must keep pace with the player's highest research so
        // research queue slots never stall the research line.
        $player = $planet->getPlayer();
        if ($player !== null) {
            $neededLabLevel = 1 + intdiv($this->highestResearchLevel($player), 3);
            if ($planet->getObjectLevel('research_lab') < $neededLabLevel) {
                $score['research_lab'] = 60.0;
            }
        }

        arsort($score);

        return array_keys($score);
    }

    /**
     * Queue the first viable research item for the player.
     */
    private function buildResearch(PlayerService $player, PlanetService $planet, Personality $personality): int
    {
        /** @var array<int, string> $priority */
        $priority = config('bots.research.priority', []);
        /** @var array<string, int> $caps */
        $caps = config('bots.research.caps', []);

        foreach ($priority as $machineName) {
            $cap = $this->effectiveResearchCap($machineName, $personality, $caps);
            if ($cap <= 0) {
                continue;
            }

            $currentLevel = $player->getResearchLevel($machineName);
            if ($currentLevel >= $cap) {
                continue;
            }

            if (!ObjectService::objectRequirementsMetWithQueue($machineName, $currentLevel + 1, $planet)) {
                continue;
            }

            if (!$this->isAffordable($planet, $machineName, withReserve: false)) {
                continue;
            }

            try {
                $this->researchQueueService->add(
                    $player,
                    $planet,
                    ObjectService::getObjectByMachineName($machineName)->id
                );

                return 1;
            } catch (Exception) {
                // Lab full or race on requirements — try the next research.
                continue;
            }
        }

        return 0;
    }

    /**
     * Cap a research level by the personality trait that drives it (see the
     * 'cap_scaling' section in config/bots.php).
     *
     * @param array<string, int> $caps
     */
    private function effectiveResearchCap(string $machineName, Personality $personality, array $caps): int
    {
        $cap = $caps[$machineName] ?? 0;

        /** @var array<string, array{group: array<int, string>, trait: string}> $scaling */
        $scaling = config('bots.research.cap_scaling', []);

        foreach ($scaling as $policy) {
            if (in_array($machineName, $policy['group'], true)) {
                return $personality->scaledCap($cap, (float) $personality->{$policy['trait']});
            }
        }

        return $cap;
    }

    /**
     * Queue units on one planet: colony ships when there are free slots, solar
     * satellites while power-starved, and a small standing fleet for
     * personality-driven military upkeep.
     */
    private function buildUnits(BrainSnapshot $snapshot, PlanetService $planet, Personality $personality): int
    {
        $player = $snapshot->player;
        $actions = 0;

        // One colony ship whenever the bot has room to grow and none pending
        // in stock. (Dispatched ships leave stock, so this naturally avoids
        // queueing duplicates while a colony is in flight.)
        $hasFreeColonySlot = $player->planets->planetCount() < $player->getMaxPlanetAmount();
        $colonyShip = $snapshot->colonyShipsInStock;

        if ($hasFreeColonySlot && $colonyShip < 1 && $planet->getObjectLevel('shipyard') >= 4) {
            $actions += $this->queueUnits($planet, 'colony_ship', 1, withReserve: false);
        }

        // Cheap energy fix once robot factories allow satellites.
        if (
            $planet->getResourceProductionFactor() < (int) config('bots.economy.energy_floor_factor', 100) &&
            $planet->getObjectLevel('robot_factory') >= 4
        ) {
            $actions += $this->queueUnits($planet, 'solar_satellite', 1, withReserve: false);
        }

        // Standing fleet: grow the military stock toward the personality's
        // share. Light fighters are the default filler until the war brain
        // implements unit-mix planning (phase 3).
        $targetFleet = (int) round(
            (int) config('bots.military.unit_value_base', 200) * $personality->militaryShare * max(1, $player->planets->planetCount())
        );

        if (
            $snapshot->militaryUnitCount < $targetFleet &&
            $planet->getObjectLevel('shipyard') >= 2
        ) {
            $actions += $this->queueUnits(
                $planet,
                'light_fighter',
                (int) config('bots.military.batch_size', 5),
                withReserve: true
            );
        }

        return $actions;
    }

    /**
     * Planetary defense for bunker-leaning personalities: grow each planet's
     * defense stock toward `defense_unit_base * defense_bias` units using the
     * strongest defense tier whose shipyard and research requirements the
     * planet currently meets. Personalities below `defense_min_bias` never
     * build defense at all (Generals spend everything on the fleet).
     */
    private function buildDefense(PlanetService $planet, Personality $personality): int
    {
        if ($personality->defenseBias < $this->defenseMinBias()) {
            return 0;
        }

        $target = (int) round(
            (int) config('bots.military.defense_unit_base', 120) * $personality->defenseBias
        );

        if (
            $planet->getDefenseUnits()->getAmount() >= $target
            || $planet->getObjectLevel('shipyard') < 1
        ) {
            return 0;
        }

        $batchSize = (int) config('bots.military.defense_batch_size', 10);

        // Strongest affordable tier wins; unmet research/shipyard gates or an
        // unaffordable batch fall through to the next, cheaper tier.
        foreach (self::DEFENSE_TIERS as $machineName) {
            if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                continue;
            }

            $queued = $this->queueUnits($planet, $machineName, $batchSize, withReserve: true);

            if ($queued > 0) {
                return $queued;
            }
        }

        return 0;
    }

    /**
     * Minimum defense_bias at which a personality builds any planetary defense.
     */
    private function defenseMinBias(): float
    {
        return (float) config('bots.military.defense_min_bias', 0.2);
    }

    /**
     * Dispatch one colonisation mission from the first planet that holds a
     * colony ship, targeting a nearby empty position.
     */
    private function colonise(BrainSnapshot $snapshot, Personality $personality): int
    {
        $player = $snapshot->player;

        if ($player->planets->planetCount() >= $player->getMaxPlanetAmount()) {
            return 0;
        }

        // Colonisation bias gates how eager the bot is about expansion.
        if (mt_rand(0, 100) > $personality->colonisationBias * 100) {
            return 0;
        }

        $colonyShipObject = ObjectService::getUnitObjectByMachineName('colony_ship');

        foreach ($snapshot->planets as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName('colony_ship') < 1) {
                continue;
            }

            $target = $this->colonisationTargetFinder->findFor(
                $player,
                (int) config('bots.colonisation.max_system_distance', 12)
            );

            if ($target === null) {
                return 0;
            }

            $units = new UnitCollection();
            $units->addUnit($colonyShipObject, 1);

            try {
                /** @var FleetMissionService $fleetMissionService */
                $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);
                $fleetMissionService->createNewFromPlanet(
                    $planet,
                    $target,
                    PlanetType::Planet,
                    self::MISSION_TYPE_COLONISATION,
                    $units,
                    new Resources(0, 0, 0, 0),
                    10.0
                );

                return 1;
            } catch (Throwable) {
                // Target race (someone colonized first) or missing fuel —
                // simply try again on the next heartbeat.
                return 0;
            }
        }

        return 0;
    }

    /**
     * Queue N units of a machine name, pre-checked for affordability so a
     * silent UnitQueueService::add() no-op is never mistaken for success.
     */
    private function queueUnits(PlanetService $planet, string $machineName, int $amount, bool $withReserve): int
    {
        if ($amount <= 0 || !$this->isAffordable($planet, $machineName, $withReserve, $amount)) {
            return 0;
        }

        try {
            $this->unitQueueService->add($planet, ObjectService::getObjectByMachineName($machineName)->id, $amount);
            return 1;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Check that the planet can pay the full build price, optionally keeping a
     * reserve buffer so bot economies never run their stocks to zero.
     */
    private function isAffordable(
        PlanetService $planet,
        string $machineName,
        bool $withReserve,
        int $amount = 1
    ): bool {
        try {
            $price = ObjectService::getObjectPrice($machineName, $planet);
        } catch (Throwable) {
            return false;
        }

        $current = $planet->getResources();
        $reserveFraction = $withReserve ? (float) config('bots.economy.reserve_fraction', 0.1) : 0.0;

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $balance = $current->$resource->get() - $price->$resource->get() * $amount;

            if ($balance < 0 || $balance < $current->$resource->get() * $reserveFraction) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fraction of a resource storage that is currently full.
     */
    private function storageSaturation(PlanetService $planet, string $resource): float
    {
        $capacity = match ($resource) {
            'metal' => $planet->metalStorage()->get(),
            'crystal' => $planet->crystalStorage()->get(),
            'deuterium' => $planet->deuteriumStorage()->get(),
            default => 0.0,
        };

        if ($capacity <= 0) {
            return 0.0;
        }

        return min(1.0, $planet->getResources()->$resource->get() / $capacity);
    }

    /**
     * Highest research level the player has across all capped research lines.
     */
    private function highestResearchLevel(PlayerService $player): int
    {
        /** @var array<string, int> $caps */
        $caps = config('bots.research.caps', []);
        $highest = 0;

        foreach (array_keys($caps) as $machineName) {
            $highest = max($highest, $player->getResearchLevel($machineName));
        }

        return $highest;
    }
}
