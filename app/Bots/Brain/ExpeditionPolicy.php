<?php

namespace OGame\Bots\Brain;

use Illuminate\Support\Facades\Date;
use OGame\Bots\Personality;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Throwable;

/**
 * Phase 4 expedition policy (docs/BOT-AI-PLAN.md §6): keep expedition slots
 * busy at a personality-scaled rate and salvage the debris of deep space
 * battles at position 16 with Pathfinders when they are in harbour.
 *
 * All expedition "memory" — in-use slots, redispatch cooldowns and salvage
 * deduplication — is derived from the game's own tables (fleet missions and
 * debris fields), so like WarPolicy the policy carries no bot-specific
 * persistence.
 */
final class ExpeditionPolicy
{
    private const MISSION_TYPE_RECYCLE = 8;
    private const MISSION_TYPE_EXPEDITION = 15;

    /**
     * OGame's deep space position: expeditions sail there and their
     * combat debris lands there (and only Pathfinders can harvest it).
     */
    private const DEEP_SPACE_POSITION = 16;

    /**
     * Military ships that may ride along on an expedition, strongest
     * machine first — the same hulls the war policy raids with, just a
     * small slice of them. Civil ships stay home; the expedition is a
     * military errand with real combat risk.
     *
     * @var array<int, string>
     */
    private const EXPEDITION_SHIPS = [
        'battlecruiser',
        'battle_ship',
        'destroyer',
        'cruiser',
        'bomber',
        'reaper',
        'heavy_fighter',
        'light_fighter',
    ];

    /**
     * Run one pass of the expedition policy: dispatch expeditions while
     * slots are free, and pick up position-16 battle debris with
     * Pathfinders. Returns the number of fleet missions dispatched.
     */
    public function conduct(BrainSnapshot $snapshot, Personality $personality): int
    {
        if (!config('bots.expedition.enabled', true)) {
            return 0;
        }

        $actions = $this->expeditions($snapshot, $personality);
        $actions += $this->salvageDebris($snapshot);

        return $actions;
    }

    /**
     * Expedition loop: when astrophysics, an expedition slot and fuel all
     * agree, send a personality-sized slice of the fleet into deep space
     * for the maximum hold time the astrophysics level allows.
     */
    private function expeditions(BrainSnapshot $snapshot, Personality $personality): int
    {
        $probability = (float) config("bots.expedition.probability.{$personality->profile}", 0.5);
        if (random_int(1, 100) > (int) round($probability * 100)) {
            return 0;
        }

        $player = $snapshot->player;

        // The engine gates expeditions on astrophysics >= 1, and the fleet
        // UI caps expedition hold time at that level, so level also fixes
        // how long our fleet is committed per expedition.
        $astrophysics = $player->getResearchLevel('astrophysics');
        if ($astrophysics < 1) {
            return 0;
        }

        if ($player->getExpeditionSlotsInUse() >= $player->getExpeditionSlotsMax()) {
            return 0;
        }

        // A return visit that lands immediately after a fleet gets back
        // looks machine-made; sit it out until the cooldown has passed.
        if ($this->returnedRecently($player)) {
            return 0;
        }

        $fleetShare = (float) config('bots.expedition.fleet_share', 0.15);
        $minFleet = (int) config('bots.expedition.min_fleet_size', 4);
        $reserve = (float) config('bots.expedition.deuterium_reserve', 1000);
        $holdingHours = $astrophysics;
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);

        // Launch from the planet that can field the biggest expedition
        // fleet and still pay for the trip out of its own deuterium.
        $launch = null;
        $bestUnits = null;
        $bestFleet = 0;

        foreach ($snapshot->planets as $planet) {
            $units = $this->expeditionFleet($planet, $fleetShare);
            $amount = $units->getAmount();

            if ($amount < $minFleet || $amount <= $bestFleet) {
                continue;
            }

            $coordinates = $this->deepSpaceCoordinate($planet->getPlanetCoordinates());

            // calculateConsumption prices the whole mission including the
            // hours held in deep space; the launch planet must afford it
            // and keep its configured war-fuel reserve.
            $consumption = $fleetMissionService->calculateConsumption(
                $planet,
                $units,
                $coordinates,
                $holdingHours,
                10.0,
            );
            if ($planet->getResources()->deuterium->get() - $consumption < $reserve) {
                continue;
            }

            $launch = $planet;
            $bestUnits = $units;
            $bestFleet = $amount;
        }

        if ($launch === null || $bestUnits === null) {
            return 0;
        }

        return $this->dispatchMission(
            $launch,
            self::MISSION_TYPE_EXPEDITION,
            $this->deepSpaceCoordinate($launch->getPlanetCoordinates()),
            PlanetType::DeepSpace,
            $bestUnits,
            $holdingHours,
        ) ? 1 : 0;
    }

    /**
     * Pathfinder salvage loop: expedition battles leave debris fields at
     * position 16, which regular recyclers cannot touch. Bots that have
     * Pathfinders in harbour (expedition ship rewards or later class-ship
     * production) hoover those fields up.
     */
    private function salvageDebris(BrainSnapshot $snapshot): int
    {
        if (!config('bots.expedition.debris.enabled', true)) {
            return 0;
        }

        $minValue = (float) config('bots.expedition.debris.min_value', 10000);
        $maxMissions = (int) config('bots.expedition.debris.actions_per_visit', 1);
        $player = $snapshot->player;
        $missions = 0;

        foreach ($snapshot->planets as $planet) {
            if ($missions >= $maxMissions) {
                break;
            }

            $pathfinders = $planet->getShipUnits()->getAmountByMachineName('pathfinder');
            if ($pathfinders <= 0) {
                continue;
            }

            $home = $planet->getPlanetCoordinates();
            $field = DebrisField::where('galaxy', $home->galaxy)
                ->where('system', $home->system)
                ->where('planet', self::DEEP_SPACE_POSITION)
                ->first();

            if ($field === null) {
                continue;
            }

            $value = (float) $field->metal + (float) $field->crystal;
            if ($value < $minValue) {
                continue;
            }

            // Our own salvage fleet already en route covers the field; two
            // planets in one system share the same deep space field.
            if ($this->hasActiveSalvage($player, $home)) {
                continue;
            }

            $pathfinder = ObjectService::getUnitObjectByMachineName('pathfinder');
            $capacity = $pathfinder->properties->capacity->calculate($player)->totalValue;
            if ($capacity <= 0) {
                continue;
            }

            $units = new UnitCollection();
            $units->addUnit($pathfinder, (int) min($pathfinders, (int) ceil($value / $capacity)));

            if (
                $this->dispatchMission(
                    $planet,
                    self::MISSION_TYPE_RECYCLE,
                    $this->deepSpaceCoordinate($home),
                    PlanetType::DebrisField,
                    $units,
                )
            ) {
                $missions++;
            }
        }

        return $missions;
    }

    /**
     * Deep space position 16 in the system of the given coordinates.
     */
    private function deepSpaceCoordinate(Coordinate $coordinates): Coordinate
    {
        return new Coordinate($coordinates->galaxy, $coordinates->system, self::DEEP_SPACE_POSITION);
    }

    /**
     * Build an expedition-sized military fleet from a planet's harbour
     * stock at the given fraction (always at least one of each ship type
     * present, same sizing rule as the war policy's strike fleets).
     */
    private function expeditionFleet(PlanetService $planet, float $fraction): UnitCollection
    {
        $units = new UnitCollection();
        $shipUnits = $planet->getShipUnits();

        foreach (self::EXPEDITION_SHIPS as $machineName) {
            $amount = $shipUnits->getAmountByMachineName($machineName);
            if ($amount <= 0) {
                continue;
            }

            $units->addUnit(
                ObjectService::getUnitObjectByMachineName($machineName),
                max(1, (int) floor($amount * $fraction)),
            );
        }

        return $units;
    }

    /**
     * Whether one of our expeditions came home within the redispatch
     * cooldown. Completed return missions carry the home planet id, which
     * outbound expedition missions never have (deep space is not a planet
     * row), so this matches exactly the finished round trips.
     */
    private function returnedRecently(PlayerService $player): bool
    {
        $seconds = (int) config('bots.expedition.redispatch_cooldown_hours', 4) * 3600;

        return FleetMission::where('user_id', $player->getId())
            ->where('mission_type', self::MISSION_TYPE_EXPEDITION)
            ->whereNotNull('planet_id_to')
            ->where('processed', 1)
            ->where('time_arrival', '>=', (int) Date::now()->timestamp - $seconds)
            ->exists();
    }

    /**
     * Whether a Pathfinder salvage mission to this system's position 16
     * is already in flight.
     */
    private function hasActiveSalvage(PlayerService $player, Coordinate $home): bool
    {
        return FleetMission::where('user_id', $player->getId())
            ->where('mission_type', self::MISSION_TYPE_RECYCLE)
            ->where('galaxy_to', $home->galaxy)
            ->where('system_to', $home->system)
            ->where('position_to', self::DEEP_SPACE_POSITION)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->exists();
    }

    /**
     * Dispatch one fleet mission with empty cargo at full speed. Returns
     * false (instead of throwing) when the engine rejects the order, so
     * one rejected expedition never interrupts the whole brain visit.
     */
    private function dispatchMission(
        PlanetService $from,
        int $missionType,
        Coordinate $destination,
        PlanetType $destinationType,
        UnitCollection $units,
        int $holdingHours = 0,
    ): bool {
        $player = $from->getPlayer();
        if ($player === null) {
            return false;
        }

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);

        try {
            $fleetMissionService->createNewFromPlanet(
                $from,
                $destination,
                $destinationType,
                $missionType,
                $units,
                new Resources(0, 0, 0, 0),
                10.0,
                $holdingHours,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
