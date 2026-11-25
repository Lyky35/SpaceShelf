<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Tests that attack battles feed the cumulative military combat statistics
 * (destroyed / lost / honor) that back the military highscore sub-categories.
 */
class FleetDispatchCombatStatsTest extends FleetDispatchTestCase
{
    /**
     * @var int The mission type for the test.
     */
    protected int $missionType = 1;

    /**
     * @var string The mission name for the test, displayed in UI.
     */
    protected string $missionName = 'Attack';

    protected function tearDown(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('attack_block_until', 0);
        parent::tearDown();
    }

    /**
     * @throws BindingResolutionException
     */
    protected function basicSetup(): void
    {
        $this->playerSetResearchLevel('computer_technology', objectLevel: 1);
        $this->planetAddResources(new Resources(10000, 10000, 10000, 0));

        // Deterministic defense losses: no post-battle repairs (the default
        // 70% repair rate would eat the destroyed-defense honor gains).
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('defense_repair_rate', 0);
    }

    /**
     * A battle between near-equal military powers is honourable: both sides
     * get their destroyed/lost counters updated and the attacker gains honor
     * points, while the defender never ends up with negative honor.
     */
    public function testAttackBattleUpdatesCombatStats(): void
    {
        $this->basicSetup();

        // 10 cruisers on the attacker side (290 military points).
        $this->planetAddUnit('cruiser', 10);

        // Foreign planet with 300 rocket launchers (600 military points as
        // well, so the fight counts as honourable).
        $foreignPlanet = $this->createForeignPlanet();
        DB::table('planets')->where('id', $foreignPlanet->getPlanetId())->update(['rocket_launcher' => 300]);
        $defenderPlayer = $foreignPlanet->getPlayer();
        if ($defenderPlayer === null) {
            $this->fail('Foreign planet must have an owner');
        }
        $defenderUserId = $defenderPlayer->getId();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 10);

        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $units, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $mission = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($mission, 'Attack mission should exist');

        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 10));
        $this->get('/overview')->assertStatus(200);

        $attackerId = $this->currentUserId;
        $destroyed = (int) DB::table('users')->where('id', $attackerId)->value('military_destroyed');
        $attackerLost = (int) DB::table('users')->where('id', $attackerId)->value('military_lost');
        $defenderLost = (int) DB::table('users')->where('id', $defenderUserId)->value('military_lost');
        $attackerHonor = (int) DB::table('users')->where('id', $attackerId)->value('honor');
        $defenderHonor = (int) DB::table('users')->where('id', $defenderUserId)->value('honor');

        // The cruiser wave is expected to break through the launcher wall;
        // assert the guaranteed parts: at least one rocket launcher dies
        // (2 points each) and the attacker loses at least one cruiser.
        $this->assertGreaterThanOrEqual(2, $destroyed, 'Attacker should be credited for destroyed defenses');
        $this->assertGreaterThanOrEqual(2, $defenderLost, 'Defender losses must be tracked');
        $this->assertGreaterThanOrEqual(5, $attackerLost, 'Attacker fleet losses must be tracked');

        // Honourable fight: attacker gains HP for the destroyed (permanent)
        // defenses; the defender can only ever gain or keep honor.
        $this->assertGreaterThan(0, $attackerHonor, 'Honourable fight should award attacker honor points');
        $this->assertGreaterThanOrEqual(0, $defenderHonor, 'Defender never loses honor points');
    }

    /**
     * Farming a much weaker target is dishonourable: the attacker loses honor
     * points while the counters still move.
     */
    public function testDishonourableFarmPenalisesAttackerHonor(): void
    {
        $this->basicSetup();

        // Overwhelming attacker fleet (400 military points).
        $this->planetAddUnit('light_fighter', 100);

        // Weak target: 20 rocket launchers = 40 military points, far below
        // the honourability thresholds. Enough destroyed defense (40k raw
        // cost) for the honor penalty formula to yield at least 1 HP.
        $foreignPlanet = $this->createForeignPlanet();
        DB::table('planets')->where('id', $foreignPlanet->getPlanetId())->update(['rocket_launcher' => 20]);
        $defenderPlayer = $foreignPlanet->getPlayer();
        if ($defenderPlayer === null) {
            $this->fail('Foreign planet must have an owner');
        }
        $defenderUserId = $defenderPlayer->getId();

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 100);

        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $units, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);
        $mission = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($mission, 'Attack mission should exist');
        $this->travelTo(Date::createFromTimestamp($mission->time_arrival + 10));
        $this->get('/overview')->assertStatus(200);

        $attackerHonor = (int) DB::table('users')->where('id', $this->currentUserId)->value('honor');
        $destroyed = (int) DB::table('users')->where('id', $this->currentUserId)->value('military_destroyed');

        $this->assertLessThan(0, $attackerHonor, 'Farming a weak target must deduct honor points');
        $this->assertGreaterThanOrEqual(2, $destroyed, 'Destroyed defenses still count towards stats');
    }
}
