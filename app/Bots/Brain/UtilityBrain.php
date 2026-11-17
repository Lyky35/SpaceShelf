<?php

namespace OGame\Bots\Brain;

use Exception;
use Illuminate\Support\Facades\DB;
use OGame\Bots\Personality;
use OGame\Enums\Officer;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\BuildingQueueService;
use OGame\Services\CharacterClassService;
use OGame\Services\ColonisationTargetFinder;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\OfficerService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use OGame\Services\UnitQueueService;
use Throwable;

/**
 * Utility-scored brain, phase 4: economy buildings, research pacing, colony
 * ships + colonisation, solar satellites, a personality-scaled standing fleet,
 * tiered planetary defense for bunker-leaning personalities, crawler-based
 * mine harvesting, inter-planet resource transports, debris recycling and —
 * through WarPolicy — fleet saving, espionage scouting and loot raids, plus
 * a deep space expedition loop with Pathfinder debris salvage through
 * ExpeditionPolicy.
 *
 * Heuristics encode the classic OGame ratio rules (mine mix, power coverage,
 * 1:10 robot-factory pacing, storage vs. mine trade-off) and are tunable via
 * config/bots.php. Officer upkeep follows the per-profile policy in
 * config/bots.php; war policy tuning lives under bots.war and expedition
 * tuning under bots.expedition. See docs/BOT-AI-PLAN.md.
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
     * Mission type id for transport (OGame\GameMissions\TransportMission).
     */
    private const MISSION_TYPE_TRANSPORT = 3;

    /**
     * Mission type id for recycling (OGame\GameMissions\RecycleMission).
     */
    private const MISSION_TYPE_RECYCLE = 8;

    /**
     * Tradable resources, in dispatch cargo order.
     *
     * @var array<int, string>
     */
    private const RESOURCES = ['metal', 'crystal', 'deuterium'];

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
        private OfficerService $officerService,
        private WarPolicy $warPolicy,
        private ExpeditionPolicy $expeditionPolicy,
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
        $actions += $this->transportResources($snapshot);
        $actions += $this->recycleDebris($snapshot);
        $actions += $this->warPolicy->conduct($snapshot, $personality);
        $actions += $this->expeditionPolicy->conduct($snapshot, $personality);
        $actions += $this->hireOfficer($player, $personality);

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

        // Defensive worlds want an ABM stock, and the missile silo is both
        // its prerequisite building and its storage. Riding the scored list
        // (instead of queuing the silo from the defense pass) lets it win a
        // slot against the economy instead of being starved by it forever.
        // Gated on the combat tier target so early growth is not traded away
        // for depth, and priced far above every other candidate because it
        // only needs two levels — an unmissable one-off detour, not a
        // permanent rival for queue slots.
        if (
            $personality->defenseBias >= $this->defenseMinBias()
            && (int) config('bots.military.abm_target', 20) > 0
            && $planet->getObjectLevel('missile_silo') < (int) config('bots.military.abm_silo_level', 2)
            && $this->combatDefenseAmount($planet) >= $this->defenseTarget($personality)
        ) {
            $score['missile_silo'] = 1000.0;
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

        // Crawlers raise mine output; the engine caps the bonus at 8 crawlers
        // per total mine level, so grow the stock toward that usable cap.
        // Only Collector accounts may build them (engine character-class
        // gate in ObjectService::objectCharacterClassMet).
        $crawlerCap = $this->crawlerCap($planet);

        if (
            $crawlerCap > 0 &&
            $this->isCollectorClass($snapshot) &&
            $planet->getShipUnits()->getAmountByMachineName('crawler') < $crawlerCap
        ) {
            $actions += $this->queueUnits($planet, 'crawler', (int) config('bots.harvest.batch_size', 10), withReserve: true);
        }

        // Espionage probes keep the war policy's scouting loop fed. Stock
        // them on the home planet only (smallest planet id) so probe
        // spending stays in one place.
        if (
            (int) $planet->getPlanetId() === $this->homePlanetId($snapshot) &&
            $personality->aggression >= (float) config('bots.war.min_aggression_spy', 0.2) &&
            $snapshot->player->getEffectiveEspionageLevel() >= (int) config('bots.war.spy.min_espionage_level', 1) &&
            $this->probeStock($snapshot) < (int) config('bots.war.spy.probe_stock_target', 3)
        ) {
            $actions += $this->queueUnits($planet, 'espionage_probe', 1, withReserve: true);
        }

        // Class ships: Reapers (Generals) and Pathfinders (Discoverers) are
        // class-locked hulls in the engine, so only the matching bot can
        // build them. Keep a small pack at the home planet: the war policy
        // raids with the strongest hulls the class offers, and the
        // expedition policy salvages and doubles its Dark Matter income
        // with Pathfinders.
        if (
            (int) $planet->getPlanetId() === $this->homePlanetId($snapshot) &&
            $planet->getObjectLevel('shipyard') >= 6
        ) {
            $characterClassService = app(CharacterClassService::class);
            $user = $player->getUser();

            if (
                $characterClassService->isGeneral($user) &&
                $planet->getShipUnits()->getAmountByMachineName('reaper') <
                (int) config('bots.classships.reaper_stock_target', 2)
            ) {
                $actions += $this->queueUnits($planet, 'reaper', 1, withReserve: true);
            }

            if (
                $characterClassService->isDiscoverer($user) &&
                $planet->getShipUnits()->getAmountByMachineName('pathfinder') <
                (int) config('bots.classships.pathfinder_stock_target', 3)
            ) {
                $actions += $this->queueUnits($planet, 'pathfinder', 1, withReserve: true);
            }
        }

        return $actions;
    }

    /**
     * The bot's home planet id. Registration always places the home colony
     * first, so the smallest planet id is the home.
     */
    private function homePlanetId(BrainSnapshot $snapshot): int
    {
        $ids = array_map(
            static fn (PlanetService $owned): int => $owned->getPlanetId(),
            $snapshot->planets,
        );

        return $ids === [] ? 0 : (int) min($ids);
    }

    /**
     * Total espionage probes across the bot's planets.
     */
    private function probeStock(BrainSnapshot $snapshot): int
    {
        $stock = 0;
        foreach ($snapshot->planets as $planet) {
            $stock += $planet->getShipUnits()->getAmountByMachineName('espionage_probe');
        }

        return $stock;
    }

    /**
     * Number of crawlers whose production bonus is still effective on this
     * planet (8 per total mine level, see the production engine). Zero means
     * the planet's mining is too small to pay off crawlers yet.
     */
    private function crawlerCap(PlanetService $planet): int
    {
        $mineLevels = array_sum(
            array_map(fn (string $mine): int => $planet->getObjectLevel($mine), array_keys(self::MINE_MAP))
        );

        return $mineLevels * (int) config('bots.harvest.crawlers_per_mine_level', 8);
    }

    /**
     * Whether the bot plays the Collector character class — the only class
     * the engine lets build crawlers.
     */
    private function isCollectorClass(BrainSnapshot $snapshot): bool
    {
        return app(CharacterClassService::class)->isCollector($snapshot->player->getUser());
    }

    /**
     * Planetary defense for bunker-leaning personalities: grow each planet's
     * combat defense stock toward `defense_unit_base * defense_bias` units
     * using the strongest defense tier whose shipyard and research
     * requirements the planet currently meets, then spend the surplus on
     * depth (shield domes, missile silo, anti-ballistic missiles).
     * Personalities below `defense_min_bias` never build defense at all
     * (Generals spend everything on the fleet).
     */
    private function buildDefense(PlanetService $planet, Personality $personality): int
    {
        if ($personality->defenseBias < $this->defenseMinBias()) {
            return 0;
        }

        if ($planet->getObjectLevel('shipyard') < 1) {
            return 0;
        }

        $target = $this->defenseTarget($personality);

        if ($this->combatDefenseAmount($planet) < $target) {
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
        }

        return $this->buildDefenseDepth($planet);
    }

    /**
     * Standing combat defense: the shooting tiers only. Shield domes and
     * missiles are excluded — they are depth, not the counted body, and
     * letting them count toward `defense_unit_base` would hollow out the
     * turret line once they start getting built.
     */
    private function combatDefenseAmount(PlanetService $planet): int
    {
        $defense = $planet->getDefenseUnits();

        $amount = 0;
        foreach (self::DEFENSE_TIERS as $machineName) {
            $amount += $defense->getAmountByMachineName($machineName);
        }

        return $amount;
    }

    /**
     * Defense depth, for worlds whose combat tier target already holds: a
     * couple of shield domes (in the original game each dome shields the
     * whole defense stack for one more regenerating round, so a pair of
     * layers covers the usual opening salvos) and an anti-ballistic missile
     * stock for missile interception.
     */
    private function buildDefenseDepth(PlanetService $planet): int
    {
        $domeTarget = (int) config('bots.military.shield_dome_target', 2);
        $defense = $planet->getDefenseUnits();
        $domeCount = $defense->getAmountByMachineName('small_shield_dome')
            + $defense->getAmountByMachineName('large_shield_dome');

        if (
            $domeCount
                + $this->queuedUnitAmount($planet, ['small_shield_dome', 'large_shield_dome'])
                < $domeTarget
        ) {
            // Prefer the strongest dome the planet has researched; small
            // domes are the early layer, large ones the late upgrade.
            foreach (['large_shield_dome', 'small_shield_dome'] as $machineName) {
                if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                    continue;
                }

                $queued = $this->queueUnits($planet, $machineName, 1, withReserve: true);

                if ($queued > 0) {
                    return $queued;
                }
            }
        }

        return $this->buildAbmStock($planet);
    }

    /**
     * Anti-ballistic missile stock. The engine keeps ABMs in the missile
     * silo (10 slots per silo level) and makes silo level 2 the build
     * prerequisite, so the brain raises the silo toward `abm_silo_level`
     * before ordering missiles; the stock is capped by whichever of
     * `abm_target` and the silo capacity comes first.
     */
    private function buildAbmStock(PlanetService $planet): int
    {
        $abmTarget = (int) config('bots.military.abm_target', 20);

        if ($abmTarget <= 0) {
            return 0;
        }

        $siloTarget = (int) config('bots.military.abm_silo_level', 2);
        $siloLevel = (int) $planet->getObjectLevel('missile_silo');

        // Silo levels are raised by the building pass (structureCandidateOrder
        // scores the silo while depth is wanted), so the economy queue is
        // never fought over. Until the silo reaches the missile tier, there
        // is nothing to order.
        if ($siloLevel < $siloTarget) {
            return 0;
        }

        $siloCapacity = $siloLevel * 10;
        $held = $planet->getDefenseUnits()->getAmountByMachineName('anti_ballistic_missile');
        $target = min($abmTarget, $siloCapacity);

        if ($held + $this->queuedUnitAmount($planet, ['anti_ballistic_missile']) >= $target) {
            return 0;
        }

        $batchSize = (int) config('bots.military.defense_batch_size', 10);

        return $this->queueUnits(
            $planet,
            'anti_ballistic_missile',
            min($batchSize, $target - $held),
            withReserve: true,
        );
    }

    /**
     * Not-yet-finished unit builds of these machine names on one planet, so
     * single-item depth orders are not stacked while the first is queued.
     * Completed rows (processed = 1) are excluded and partially built
     * batches only count their remainder.
     *
     * @param array<int, string> $machineNames
     */
    private function queuedUnitAmount(PlanetService $planet, array $machineNames): int
    {
        $objectIds = array_map(
            static fn (string $machineName): int => ObjectService::getObjectByMachineName($machineName)->id,
            $machineNames,
        );

        return (int) UnitQueue::where('planet_id', $planet->getPlanetId())
            ->where('processed', 0)
            ->whereIn('object_id', $objectIds)
            ->sum(DB::raw('object_amount - object_amount_progress'));
    }

    /**
     * Combat defense unit target for one personality: the counted shooting
     * tiers only, `defense_unit_base` scaled by the personality's bias.
     */
    private function defenseTarget(Personality $personality): int
    {
        return (int) round(
            (int) config('bots.military.defense_unit_base', 120) * $personality->defenseBias
        );
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
     * Inter-planet logistics (docs/BOT-AI-PLAN.md §5): ship resources from
     * planets whose storage of a resource is nearly full (and which have no
     * use for that resource themselves) to planets whose unfinished
     * construction backlog exceeds what their own stock plus a few hours of
     * production can cover. A planet with scarce resources never ships them
     * out, so transports never starve their own economy.
     */
    private function transportResources(BrainSnapshot $snapshot): int
    {
        $maxMissions = (int) config('bots.transport.actions_per_visit', 1);
        $coverHours = (float) config('bots.transport.demand_cover_hours', 6);
        $sourceSaturation = (float) config('bots.transport.source_saturation', 0.8);
        $keepFraction = (float) config('bots.transport.source_keep_fraction', 0.4);
        $minAmount = (float) config('bots.transport.min_amount', 5000);

        /** @var array<int, array<string, float>> $demand Per planet id: resource => needed amount. */
        $demand = [];
        /** @var array<int, array<string, float>> $supply Per planet id: resource => sendable amount. */
        $supply = [];

        foreach ($snapshot->planets as $planet) {
            $planetId = $planet->getPlanetId();
            $backlog = $snapshot->queuedBacklog[$planetId] ?? new Resources(0, 0, 0, 0);

            foreach (self::RESOURCES as $resource) {
                $stock = $planet->getResources()->$resource->get();
                $shortfall = $backlog->$resource->get()
                    - $stock
                    - $this->productionPerHour($planet, $resource) * $coverHours;

                if ($shortfall > 0) {
                    $demand[$planetId][$resource] = $shortfall;

                    continue;
                }

                if ($this->storageSaturation($planet, $resource) >= $sourceSaturation) {
                    $sendable = $stock - $this->storageCapacity($planet, $resource) * $keepFraction;

                    if ($sendable > 0) {
                        $supply[$planetId][$resource] = $sendable;
                    }
                }
            }
        }

        if ($demand === [] || $supply === []) {
            return 0;
        }

        $transporter = ObjectService::getUnitObjectByMachineName('large_cargo');
        $capacity = $transporter->properties->capacity->calculate($snapshot->player)->totalValue;

        if ($capacity <= 0) {
            return 0;
        }

        $missions = 0;
        $actions = 0;

        /** @var PlanetService $source */
        foreach ($snapshot->planets as $source) {
            if ($missions >= $maxMissions) {
                break;
            }

            $sourceId = $source->getPlanetId();

            if (!isset($supply[$sourceId])) {
                continue;
            }

            /** @var PlanetService $target */
            foreach ($snapshot->planets as $target) {
                if ($missions >= $maxMissions) {
                    break;
                }

                $targetId = $target->getPlanetId();

                if ($targetId === $sourceId) {
                    continue;
                }

                $targetDemand = $demand[$targetId] ?? [];

                if ($targetDemand === []) {
                    continue;
                }

                $cargo = [];
                $total = 0.0;

                foreach ($targetDemand as $resource => $needed) {
                    // Read the live supply counter: earlier missions on this
                    // visit already reduced it.
                    $available = $supply[$sourceId][$resource] ?? 0.0;

                    if ($available <= 0) {
                        continue;
                    }

                    $amount = min($needed, $available);
                    $cargo[$resource] = $amount;
                    $total += $amount;
                }

                if ($total < $minAmount) {
                    continue;
                }

                $shipsNeeded = (int) ceil($total / $capacity);
                $shipsAvailable = $source->getShipUnits()->getAmountByMachineName('large_cargo');

                if ($shipsAvailable < 1) {
                    // No ships to move the cargo: grow the transporter pool
                    // instead, the mission goes out on a later heartbeat.
                    $actions += $this->queueUnits(
                        $source,
                        'large_cargo',
                        min($shipsNeeded, (int) config('bots.transport.transporter_batch_size', 20)),
                        withReserve: true
                    );

                    continue;
                }

                $ships = min($shipsAvailable, $shipsNeeded);
                $shipCapacity = $ships * $capacity;

                // Cap the cargo to what the dispatched ships can carry.
                if ($total > $shipCapacity) {
                    $scale = $shipCapacity / $total;

                    foreach ($cargo as $resource => $amount) {
                        $cargo[$resource] = floor($amount * $scale);
                    }

                    $total = (float) array_sum($cargo);

                    if ($total < $minAmount) {
                        continue;
                    }
                }

                $units = new UnitCollection();
                $units->addUnit($transporter, $ships);

                try {
                    /** @var FleetMissionService $fleetMissionService */
                    $fleetMissionService = resolve(FleetMissionService::class, ['player' => $snapshot->player]);
                    $fleetMissionService->createNewFromPlanet(
                        $source,
                        $target->getPlanetCoordinates(),
                        PlanetType::Planet,
                        self::MISSION_TYPE_TRANSPORT,
                        $units,
                        new Resources(
                            (int) ($cargo['metal'] ?? 0),
                            (int) ($cargo['crystal'] ?? 0),
                            (int) ($cargo['deuterium'] ?? 0),
                            0,
                        ),
                        10.0,
                    );

                    $missions++;

                    // Bookkeeping so a source is not drained twice within one
                    // visit and a target does not receive duplicate cargo.
                    foreach ($cargo as $resource => $amount) {
                        $supply[$sourceId][$resource] -= $amount;
                        $demand[$targetId][$resource] -= $amount;

                        if ($supply[$sourceId][$resource] <= 0) {
                            unset($supply[$sourceId][$resource]);
                        }

                        if (($demand[$targetId][$resource] ?? 0) <= 0) {
                            unset($demand[$targetId][$resource]);
                        }
                    }
                } catch (Throwable) {
                    // Not enough fuel or a race on the target planet; retry on
                    // the next heartbeat.
                }
            }
        }

        return $actions + $missions;
    }

    /**
     * Debris harvesting: dispatch the planet's recyclers onto the debris
     * field sitting at the planet's own coordinates (battle debris is free
     * money that otherwise decays). When debris exists but the planet has no
     * recyclers in stock, a small batch is queued instead.
     */
    private function recycleDebris(BrainSnapshot $snapshot): int
    {
        $maxMissions = (int) config('bots.recycle.actions_per_visit', 1);
        $minDebrisValue = (float) config('bots.recycle.min_debris_value', 10000);

        $recycler = ObjectService::getUnitObjectByMachineName('recycler');
        $capacity = $recycler->properties->capacity->calculate($snapshot->player)->totalValue;

        if ($capacity <= 0) {
            return 0;
        }

        $missions = 0;
        $actions = 0;

        /** @var PlanetService $planet */
        foreach ($snapshot->planets as $planet) {
            if ($missions >= $maxMissions) {
                break;
            }

            $debris = $snapshot->debrisFields[$planet->getPlanetId()] ?? null;

            if ($debris === null) {
                continue;
            }

            $value = $debris->sum();

            if ($value < $minDebrisValue) {
                continue;
            }

            $available = $planet->getShipUnits()->getAmountByMachineName('recycler');

            if ($available < 1) {
                $actions += $this->queueUnits(
                    $planet,
                    'recycler',
                    (int) config('bots.recycle.recycler_batch_size', 3),
                    withReserve: true
                );

                continue;
            }

            // One hour of recycler collection covers the whole field.
            $shipsNeeded = (int) ceil($value / $capacity);
            $units = new UnitCollection();
            $units->addUnit($recycler, min($available, $shipsNeeded));

            try {
                /** @var FleetMissionService $fleetMissionService */
                $fleetMissionService = resolve(FleetMissionService::class, ['player' => $snapshot->player]);
                $fleetMissionService->createNewFromPlanet(
                    $planet,
                    $planet->getPlanetCoordinates(),
                    PlanetType::DebrisField,
                    self::MISSION_TYPE_RECYCLE,
                    $units,
                    new Resources(0, 0, 0, 0),
                    10.0,
                );

                $missions++;
            } catch (Throwable) {
                // Fuel shortage or the debris got harvested by someone else
                // first; retry on the next heartbeat.
            }
        }

        return $actions + $missions;
    }

    /**
     * Hourly production of a resource on a planet.
     */
    private function productionPerHour(PlanetService $planet, string $resource): float
    {
        return match ($resource) {
            'metal' => $planet->getMetalProductionPerHour(),
            'crystal' => $planet->getCrystalProductionPerHour(),
            'deuterium' => $planet->getDeuteriumProductionPerHour(),
            default => 0.0,
        };
    }

    /**
     * Storage capacity of a resource on a planet.
     */
    private function storageCapacity(PlanetService $planet, string $resource): float
    {
        return match ($resource) {
            'metal' => $planet->metalStorage()->get(),
            'crystal' => $planet->crystalStorage()->get(),
            'deuterium' => $planet->deuteriumStorage()->get(),
            default => 0.0,
        };
    }

    /**
     * Keep the profile's key officer hired (docs/BOT-AI-PLAN.md §7): renew
     * once the current period is down to the configured window, and never
     * spend Dark Matter that would take the bot below the DM reserve floor.
     */
    private function hireOfficer(PlayerService $player, Personality $personality): int
    {
        $officer = Officer::tryFrom(
            (string) config('bots.officers.profile_mapping.' . $personality->profile, '')
        );
        if ($officer === null) {
            return 0;
        }

        $user = $player->getUser();

        $renewWindow = (int) config('bots.officers.renew_within_days', 2);
        if ($this->officerService->getRemainingDays($user, $officer) > $renewWindow) {
            return 0;
        }

        $cost = $this->officerService->getHireCost($officer);
        if ($user->dark_matter < $cost + (int) config('bots.officers.dm_reserve', 1000)) {
            return 0;
        }

        try {
            $this->officerService->hire($user, $officer);
            return 1;
        } catch (Exception) {
            // Lost the Dark Matter to a concurrent debit; retry next heartbeat.
            return 0;
        }
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
