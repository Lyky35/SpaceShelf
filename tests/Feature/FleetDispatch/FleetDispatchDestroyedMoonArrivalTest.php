<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use Tests\FleetDispatchTestCase;

/**
 * Tests for fleets in flight when their destination moon gets destroyed
 * (upstream #1639 / #1640):
 * - foreign fleets must not battle at a destroyed moon; they return intact;
 * - own return fleets reroute to the parent planet of a destroyed moon;
 * - the parent planet of a destroyed moon must still resolve via
 *   planet()/hasPlanet() even when it is destroyed itself (#1640).
 */
class FleetDispatchDestroyedMoonArrivalTest extends FleetDispatchTestCase
{
    /**
     * @var int The mission type for the test.
     */
    protected int $missionType = 1;

    /**
     * @var string The mission name for the test, displayed in UI.
     */
    protected string $missionName = 'Attack';

    /**
     * Prepare the planet for the test, so it has the required buildings and research.
     *
     * @return void
     */
    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 20);
    }

    /**
     * Current destroyed flag of a planet row, failing the test if it vanished.
     */
    private function destroyedFlag(int $planetId): int
    {
        $planet = Planet::find($planetId);
        $this->assertNotNull($planet);

        return (int) $planet->destroyed;
    }

    /**
     * Current light fighter count of a planet row, failing the test if it vanished.
     */
    private function lightFighters(int $planetId): int
    {
        $planet = Planet::find($planetId);
        $this->assertNotNull($planet);

        return (int) $planet->light_fighter;
    }

    /**
     * Test that a foreign attack fleet arriving at a moon that was abandoned
     * mid-flight does not fight a battle and returns with its full fleet intact.
     *
     * Before the fix this replayed a battle against the destroyed moon and
     * crashed the fleet update chain with "No planet found for this moon.".
     */
    public function testForeignFleetArrivingAtAbandonedMoonReturnsIntact(): void
    {
        $this->basicSetup();

        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlayer = $foreignPlanet->getPlayer();
        if ($foreignPlayer === null) {
            $this->fail('Foreign planet has no player.');
        }

        $moonId = $this->moonService->getPlanetId();
        $coordinates = $this->moonService->getPlanetCoordinates();

        // In-flight attack fleet against the test player's moon.
        $attackMission = FleetMission::forceCreate([
            'user_id' => $foreignPlayer->getId(),
            'planet_id_from' => $foreignPlanet->getPlanetId(),
            'planet_id_to' => $moonId,
            'type_from' => PlanetType::Planet->value,
            'type_to' => PlanetType::Moon->value,
            'galaxy_to' => $coordinates->galaxy,
            'system_to' => $coordinates->system,
            'position_to' => $coordinates->position,
            'mission_type' => 1,
            'time_departure' => (int) Date::now()->timestamp,
            'time_arrival' => (int) Date::now()->addHour()->timestamp,
            'light_fighter' => 10,
            'processed' => 0,
        ]);

        // The target moon is abandoned while the fleet is still en route.
        $this->moonService->markAsDestroyed();
        $this->assertGreaterThan(0, $this->destroyedFlag($moonId));

        $this->travelTo(Date::createFromTimestamp($attackMission->time_arrival + 10));

        // Must not throw: pre-fix this processed a battle at the destroyed moon.
        $attackerService = resolve(PlayerService::class, ['player_id' => $foreignPlayer->getId()]);
        $attackerService->updateFleetMissions();

        $attackMission->refresh();
        $this->assertEquals(1, $attackMission->processed, 'Arrival must be closed out at the destroyed moon');
        $this->assertEquals(0, $attackMission->update_failures, 'No exceptions may leak into the quarantine handler');
        $this->assertEquals(0, BattleReport::count(), 'No battle may be fought at a destroyed moon');

        $returnMission = FleetMission::where('parent_id', $attackMission->id)->first();
        $this->assertNotNull($returnMission, 'Fleet must be sent home intact');
        $this->assertEquals(10, (int) $returnMission->light_fighter, 'Full fleet must return, nothing is lost');

        // Land the fleet back at its home planet.
        $this->travelTo(Date::createFromTimestamp($returnMission->time_arrival + 10));
        $attackerService = resolve(PlayerService::class, ['player_id' => $foreignPlayer->getId()]);
        $attackerService->updateFleetMissions();

        $this->assertEquals(10, $this->lightFighters($foreignPlanet->getPlanetId()));
    }

    /**
     * Test that an own fleet returning to a moon that was abandoned mid-trip is
     * rerouted to the moon's parent planet instead of landing on a ghost body.
     *
     * The return fleet orbits the owner's second planet while heading back to the
     * moon, so its mission is picked up by the owner's regular fleet update; a
     * mission whose only live endpoint were the destroyed moon itself is handled
     * by the daily purge's mission rebind instead.
     */
    public function testReturnFleetToAbandonedMoonReroutesToParentPlanet(): void
    {
        $this->basicSetup();

        $player = $this->planetService->getPlayer();
        if ($player === null) {
            $this->fail('Main planet has no player.');
        }

        $secondPlanet = resolve(PlanetServiceFactory::class)
            ->createAdditionalPlanetForPlayer($player, $this->getNearbyEmptyCoordinate());

        $moonId = $this->moonService->getPlanetId();
        $mainPlanetId = $this->planetService->getPlanetId();
        $coordinates = $this->moonService->getPlanetCoordinates();

        // The fleet departed from the moon to the second planet (already processed).
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 10);
        $this->moonService->addUnits($units);

        $outboundMission = FleetMission::forceCreate([
            'user_id' => $player->getId(),
            'planet_id_from' => $moonId,
            'planet_id_to' => $secondPlanet->getPlanetId(),
            'type_from' => PlanetType::Moon->value,
            'type_to' => PlanetType::Planet->value,
            'galaxy_to' => $secondPlanet->getPlanetCoordinates()->galaxy,
            'system_to' => $secondPlanet->getPlanetCoordinates()->system,
            'position_to' => $secondPlanet->getPlanetCoordinates()->position,
            'mission_type' => 3,
            'time_departure' => (int) Date::now()->subHours(3)->timestamp,
            'time_arrival' => (int) Date::now()->subHours(2)->timestamp,
            'light_fighter' => 10,
            'processed' => 1,
        ]);

        // Return fleet is en route back to the moon, currently heading out from
        // the (alive) second planet.
        $returnMission = FleetMission::forceCreate([
            'parent_id' => $outboundMission->id,
            'user_id' => $player->getId(),
            'planet_id_from' => $secondPlanet->getPlanetId(),
            'planet_id_to' => $moonId,
            'type_from' => PlanetType::Planet->value,
            'type_to' => PlanetType::Moon->value,
            'galaxy_to' => $coordinates->galaxy,
            'system_to' => $coordinates->system,
            'position_to' => $coordinates->position,
            'mission_type' => 3,
            'time_departure' => (int) Date::now()->timestamp,
            'time_arrival' => (int) Date::now()->addHour()->timestamp,
            'light_fighter' => 10,
            'processed' => 0,
        ]);

        // Moon is abandoned while the fleet is still heading back to it.
        $this->moonService->markAsDestroyed();
        $this->assertGreaterThan(0, $this->destroyedFlag($moonId));

        $mainUnitsBefore = $this->lightFighters($mainPlanetId);

        $this->travelTo(Date::createFromTimestamp($returnMission->time_arrival + 10));

        $service = resolve(PlayerService::class, ['player_id' => $player->getId()]);
        $service->updateFleetMissions();

        $returnMission->refresh();
        $this->assertEquals(1, $returnMission->processed);
        $this->assertEquals(0, $returnMission->update_failures, 'No exceptions may leak into the quarantine handler');
        $this->assertEquals($mainPlanetId, (int) $returnMission->planet_id_to, 'Fleet rerouted to the parent planet');
        $this->assertEquals(PlanetType::Planet->value, (int) $returnMission->type_to);

        // Units land on the parent planet instead of vanishing at the ghost moon.
        $this->assertEquals($mainUnitsBefore + 10, $this->lightFighters($mainPlanetId));
    }

    /**
     * Test that the parent planet of a destroyed moon still resolves via
     * hasPlanet()/planet() when the parent planet is destroyed as well (the
     * abandon-planet cascade flags the moon with the same timestamp).
     *
     * Before the fix these methods resolved through the player's planet list,
     * which filters destroyed bodies out, so fleet processing crashed with
     * "No planet found for this moon." (#1640).
     */
    public function testPlanetLookupFindsDestroyedParentPlanet(): void
    {
        $this->basicSetup();

        $mainPlanetId = $this->planetService->getPlanetId();
        $moonId = $this->moonService->getPlanetId();
        $coordinates = $this->moonService->getPlanetCoordinates();

        // Abandon the main planet; the moon gets flagged destroyed with it.
        $this->planetService->markAsDestroyed();

        $this->assertGreaterThan(0, $this->destroyedFlag($mainPlanetId));
        $this->assertGreaterThan(0, $this->destroyedFlag($moonId));

        $moonService = resolve(PlanetServiceFactory::class)->makeMoonForCoordinate($coordinates, false);
        $this->assertNotNull($moonService);

        $this->assertTrue($moonService->hasPlanet(), 'Destroyed parent planet must still resolve');
        $this->assertEquals($mainPlanetId, $moonService->planet()->getPlanetId());
    }
}
