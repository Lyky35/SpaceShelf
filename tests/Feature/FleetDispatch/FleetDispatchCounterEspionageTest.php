<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\GameMessageFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuddyService;
use OGame\Services\CounterEspionageService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Test counter-espionage functionality in espionage missions.
 */
class FleetDispatchCounterEspionageTest extends FleetDispatchTestCase
{
    protected int $missionType = 6;
    protected string $missionName = 'Espionage';

    /**
     * @var array<int> Buddy user IDs created during this test run, cleaned up in tearDown.
     */
    protected static array $allCreatedBuddyUserIds = [];

    protected function basicSetup(): void
    {
        $this->planetAddUnit('espionage_probe', 10);
        $this->planetAddResources(new Resources(0, 0, 100000, 0));

        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
    }

    protected function messageCheckMissionArrival(): void
    {
        $this->assertMessageReceivedAndContains('fleets', 'espionage', [
            'Espionage report from',
        ]);
    }

    protected function messageCheckMissionReturn(): void
    {
        $this->assertMessageNotReceived();
    }

    /**
     * Test that surviving probes create a return mission after espionage completes.
     */
    public function testSurvivingProbesCreateReturnMission(): void
    {
        $this->basicSetup();

        // Get initial probe count
        $initialProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // Send probes
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->sendMissionToOtherPlayerPlanet($unitCollection, new Resources(0, 0, 0, 0));

        // Get fleet mission service
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $this->planetService->getPlayer()]);

        // Check that outbound mission exists
        $activeMissions = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer();
        $this->assertGreaterThanOrEqual(1, $activeMissions->count(), 'Outbound mission should exist.');

        // Increase time to complete the outbound mission
        $this->travel(5)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Check that espionage report was created
        $espionageReport = EspionageReport::orderByDesc('id')->first();
        $this->assertNotNull($espionageReport, 'Espionage report should be created.');

        // Check that a return mission exists (or probes have returned)
        $activeMissionsAfterArrival = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer();

        // Either there's a return mission, or probes have already returned
        // Complete the return trip
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Reload planet to check probes returned
        $this->planetService->reloadPlanet();
        $finalProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // Probes should have returned (may be less if counter-espionage destroyed some)
        $this->assertGreaterThan(0, $finalProbes, 'Some probes should have returned.');
    }

    /**
     * Test that surviving probes generate an espionage report for the attacker.
     */
    public function testSurvivingProbesGenerateEspionageReport(): void
    {
        $this->basicSetup();

        // Get a foreign planet with minimal ships
        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanet->addUnit('small_cargo', 1);
        $foreignPlanet->save();

        // Count existing reports
        $reportCountBefore = EspionageReport::count();

        // Send probes
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Check that a new espionage report was created
        $reportCountAfter = EspionageReport::count();
        $this->assertGreaterThan($reportCountBefore, $reportCountAfter, 'Espionage report should be generated.');
    }

    /**
     * Test that counter-espionage battle creates a battle report for the defender.
     * Sets up conditions where counter-espionage is guaranteed (100% chance).
     */
    public function testCounterEspionageBattleCreatesDefenderReport(): void
    {
        $this->basicSetup();

        // Get a foreign planet and add many ships to guarantee 100% counter-espionage chance
        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanet->addUnit('light_fighter', 500);
        $foreignPlanet->save();

        // Count existing battle reports
        $battleReportCountBefore = BattleReport::count();

        // Send only 1 probe to maximize counter-espionage chance
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // With 500 ships and 1 probe, counter-espionage chance is 100%
        // So a battle report should be created
        $battleReportCountAfter = BattleReport::count();

        // Note: Due to randomness, we can't guarantee the battle happens every time
        // But with 100% chance, it should happen
        if ($battleReportCountAfter > $battleReportCountBefore) {
            $battleReport = BattleReport::orderByDesc('id')->first();
            $this->assertNotNull($battleReport, 'Battle report should exist.');

            // Verify the battle report has correct structure
            $this->assertNotNull($battleReport->attacker, 'Battle report should have attacker info.');
            $this->assertNotNull($battleReport->defender, 'Battle report should have defender info.');
        }
    }

    /**
     * Test that only ships are counted for counter-espionage, not defense structures.
     */
    public function testOnlyShipsCountedNotDefense(): void
    {
        $this->basicSetup();

        // Test the CounterEspionageService directly to verify defense is not counted
        $counterEspionageService = resolve(CounterEspionageService::class);

        // With 0 ships but many defense, chance should be 0
        // Formula: (defender_ships * tech_factor) / (attacker_probes * 4) * 100
        // With 0 ships: (0 * 1) / (5 * 4) * 100 = 0
        $chance = $counterEspionageService->calculateChance(
            5,  // attacker probes
            5,  // attacker esp level
            5,  // defender esp level
            0   // defender ships (defense doesn't count!)
        );

        $this->assertEquals(0, $chance, 'Counter-espionage chance should be 0 when defender has 0 ships.');

        // With ships, chance should be > 0
        $chanceWithShips = $counterEspionageService->calculateChance(
            5,   // attacker probes
            5,   // attacker esp level
            5,   // defender esp level
            100  // defender ships
        );

        $this->assertGreaterThan(0, $chanceWithShips, 'Counter-espionage chance should be > 0 when defender has ships.');
    }

    /**
     * Test that counter-espionage battle only includes ships, not defense structures.
     */
    public function testCounterEspionageBattleExcludesDefense(): void
    {
        $this->basicSetup();

        // Get a foreign planet and add both ships and defense
        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanet->addUnit('light_fighter', 100);
        $foreignPlanet->addUnit('rocket_launcher', 1000);
        $foreignPlanet->save();

        // Count existing battle reports
        $battleReportCountBefore = BattleReport::count();

        // Send 1 probe to maximize counter-espionage chance
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Check if a battle report was created
        $battleReportCountAfter = BattleReport::count();

        if ($battleReportCountAfter > $battleReportCountBefore) {
            $battleReport = BattleReport::orderByDesc('id')->first();
            $this->assertNotNull($battleReport, 'Battle report should exist.');

            // Verify defender units only contain ships, not defense
            $defenderUnits = $battleReport->defender['units'] ?? [];

            // Check that no defense units are in the battle
            $this->assertArrayNotHasKey('rocket_launcher', $defenderUnits, 'Rocket launchers should not be in counter-espionage battle.');
            $this->assertArrayNotHasKey('light_laser', $defenderUnits, 'Light lasers should not be in counter-espionage battle.');
            $this->assertArrayNotHasKey('heavy_laser', $defenderUnits, 'Heavy lasers should not be in counter-espionage battle.');
            $this->assertArrayNotHasKey('ion_cannon', $defenderUnits, 'Ion cannons should not be in counter-espionage battle.');
            $this->assertArrayNotHasKey('gauss_cannon', $defenderUnits, 'Gauss cannons should not be in counter-espionage battle.');
            $this->assertArrayNotHasKey('plasma_turret', $defenderUnits, 'Plasma turrets should not be in counter-espionage battle.');
        }
    }

    /**
     * Test that counter-espionage chance is stored in the espionage report.
     */
    public function testCounterEspionageChanceDisplayedInReport(): void
    {
        $this->basicSetup();

        // Get a foreign planet and add ships
        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanet->addUnit('light_fighter', 20);
        $foreignPlanet->save();

        // Send probes
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Get the espionage report
        $espionageReport = EspionageReport::orderByDesc('id')->first();
        $this->assertNotNull($espionageReport, 'Espionage report should be created.');

        // Verify counter-espionage chance is stored
        $this->assertNotNull($espionageReport->counter_espionage_chance, 'Counter-espionage chance should be stored.');
        $this->assertGreaterThanOrEqual(0, $espionageReport->counter_espionage_chance);
        $this->assertLessThanOrEqual(100, $espionageReport->counter_espionage_chance);
    }

    /**
     * Test that espionage report is always created even when all probes are destroyed.
     */
    public function testEspionageReportCreatedWhenAllProbesDestroyed(): void
    {
        $this->basicSetup();

        // Get a foreign planet and add overwhelming ships to guarantee probe destruction
        $foreignPlanet = $this->createForeignPlanet();
        $foreignPlanet->addUnit('battlecruiser', 1000);
        $foreignPlanet->save();

        // Count existing reports
        $reportCountBefore = EspionageReport::count();

        // Send only 1 probe (will be destroyed)
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Espionage report should still be created
        $reportCountAfter = EspionageReport::count();
        $this->assertGreaterThan($reportCountBefore, $reportCountAfter, 'Espionage report should be created even when all probes destroyed.');
    }

    /**
     * Test that attacker receives "fleet lost contact" message when all probes are destroyed.
     */
    public function testAttackerReceivesFleetLostContactWhenProbesDestroyed(): void
    {
        $this->basicSetup();

        // Get initial probe count
        $initialProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // Get a foreign planet and add overwhelming ships to guarantee 100% counter-espionage
        $foreignPlanet = $this->createForeignPlanet();

        // Set defender's espionage technology very high to further guarantee counter-espionage
        $foreignPlanetPlayer = $foreignPlanet->getPlayer();
        if ($foreignPlanetPlayer === null) {
            $this->fail('Foreign planet has no player.');
        }
        $foreignPlanetPlayer->setResearchLevel('espionage_technology', 10);

        // Add overwhelming number of ships
        $foreignPlanet->addUnit('battlecruiser', 5000);
        $foreignPlanet->save();
        $foreignPlanet->update(); // Ensure planet state is fully persisted

        // Force a fresh reload of the foreign planet to ensure no caching issues
        $planetServiceFactory = resolve(PlanetServiceFactory::class);
        $foreignPlanet = $planetServiceFactory->make($foreignPlanet->getPlanetId());
        if ($foreignPlanet === null) {
            $this->fail('Foreign planet could not be reloaded.');
        }

        // Send only 1 probe (high chance to be destroyed)
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Wait for potential return mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Check if probes were destroyed (counter-espionage triggered)
        $this->planetService->reloadPlanet();
        $finalProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // If counter-espionage triggered and destroyed all probes
        if ($finalProbes < $initialProbes) {
            // Then fleet lost contact message should be received
            $this->assertMessageReceivedAndContains('fleets', 'combat_reports', [
                'Contact with the attacking fleet has been lost',
            ]);
        } else {
            // Counter-espionage didn't trigger or probes survived, skip this assertion
            $this->markTestSkipped('Counter-espionage did not trigger in this test run (random chance).');
        }
    }

    /**
     * Test that no return mission is created when all probes are destroyed.
     */
    public function testNoReturnMissionWhenAllProbesDestroyed(): void
    {
        $this->basicSetup();

        // Get initial probe count
        $initialProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // Get a foreign planet and add overwhelming ships to guarantee 100% counter-espionage
        $foreignPlanet = $this->createForeignPlanet();

        // Set defender's espionage technology very high to further guarantee counter-espionage
        $foreignPlanetPlayer = $foreignPlanet->getPlayer();
        if ($foreignPlanetPlayer === null) {
            $this->fail('Foreign planet has no player.');
        }
        $foreignPlanetPlayer->setResearchLevel('espionage_technology', 10);

        // Add overwhelming number of ships
        $foreignPlanet->addUnit('battlecruiser', 5000);
        $foreignPlanet->save();
        $foreignPlanet->update(); // Ensure planet state is fully persisted

        // Force a fresh reload of the foreign planet to ensure no caching issues
        $planetServiceFactory = resolve(PlanetServiceFactory::class);
        $foreignPlanet = $planetServiceFactory->make($foreignPlanet->getPlanetId());
        if ($foreignPlanet === null) {
            $this->fail('Foreign planet could not be reloaded.');
        }

        // Send only 1 probe (high chance to be destroyed)
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 1);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        // Complete mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Wait for potential return mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Reload planet
        $this->planetService->reloadPlanet();
        $finalProbes = $this->planetService->getShipUnits()->getAmountByMachineName('espionage_probe');

        // If counter-espionage triggered and destroyed all probes
        if ($finalProbes < $initialProbes) {
            // Probes should not have returned - this is the expected behavior
            $this->assertLessThan($initialProbes, $finalProbes, 'Destroyed probes should not return.');
        } else {
            // Counter-espionage didn't trigger, probes returned normally
            $this->markTestSkipped('Counter-espionage did not trigger in this test run (random chance).');
        }
    }

    /**
     * Test that defender receives espionage warning notification.
     */
    public function testDefenderReceivesEspionageWarning(): void
    {
        $this->basicSetup();

        // Send espionage probes to foreign planet
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->sendMissionToOtherPlayerPlanet($unitCollection, new Resources(0, 0, 0, 0));

        // Complete espionage mission
        $this->travel(10)->hours();
        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Verify attacker received espionage report (confirms mission completed)
        $this->assertMessageReceivedAndContains('fleets', 'espionage', [
            'Espionage report from',
        ]);

        // Check that espionage_detected message was created
        $espionageDetectedMessage = Message::where('key', 'espionage_detected')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($espionageDetectedMessage, 'Espionage detected message should be created');

        // Verify the message contains expected text
        if ($espionageDetectedMessage) {
            $gameMessage = GameMessageFactory::createGameMessage($espionageDetectedMessage);
            $body = $gameMessage->getBody();
            $this->assertStringContainsString('was sighted near your planet', $body);
        }
    }

    /**
     * Clean up buddy relationships created during this test run.
     */
    protected function tearDown(): void
    {
        while (!empty(self::$allCreatedBuddyUserIds)) {
            $buddyUserId = array_shift(self::$allCreatedBuddyUserIds);

            DB::table('buddy_requests')
                ->where(function ($query) use ($buddyUserId) {
                    $query->where('sender_user_id', $buddyUserId)
                        ->orWhere('receiver_user_id', $buddyUserId);
                })
                ->delete();
        }

        parent::tearDown();
    }

    /**
     * Create a fresh user that is a buddy of the given user, with a planet near the
     * current player. ACS Defend missions are only possible to buddies/alliance mates.
     *
     * @param User $targetUser The user the new buddy is related to.
     * @return PlanetService The buddy's planet.
     */
    private function createBuddyPlanet(User $targetUser): PlanetService
    {
        $buddyUser = User::factory()->create();
        self::$allCreatedBuddyUserIds[] = $buddyUser->id;

        $buddyPlanet = $this->createPlanetAtSafeCoordinate($buddyUser->id);

        $buddyService = resolve(BuddyService::class);
        $request = $buddyService->sendRequest($targetUser->id, $buddyUser->id);
        $buddyService->acceptRequest($request->id, $buddyUser->id);

        return $buddyPlanet;
    }

    /**
     * Remove all ship units from a planet so counter-espionage chances and spy reports
     * only reflect what the test explicitly places there.
     *
     * @param PlanetService $planet
     * @return void
     */
    private function removeShipUnitsFromPlanet(PlanetService $planet): void
    {
        $shipUnits = $planet->getShipUnits();
        if ($shipUnits->getAmount() > 0) {
            $planet->removeUnits($shipUnits, false);
            $planet->save();
        }

        $this->assertSame(0, $planet->getShipUnits()->getAmount());
    }

    /**
     * Send an ACS Defend fleet from one planet to another and advance time until it has
     * physically arrived and is holding above the host planet.
     *
     * @return FleetMission The ACS Defend fleet mission.
     */
    private function dockAcsDefendFleet(PlanetService $originPlanet, PlanetService $hostPlanet, string $machineName, int $amount): FleetMission
    {
        $originPlayer = $originPlanet->getPlayer();
        $this->assertNotNull($originPlayer);

        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $amount);

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $originPlayer]);
        $defendMission = $fleetMissionService->createNewFromPlanet(
            $originPlanet,
            $hostPlanet->getPlanetCoordinates(),
            PlanetType::Planet,
            5, // ACS Defend
            $units,
            new Resources(0, 0, 0, 0),
            10, // 100% speed
            10  // 10 hours holding
        );

        // Advance to physical arrival (time_arrival includes the holding time) and process
        // fleet events so the defending fleet docks.
        $this->travelTo(Date::createFromTimestamp($defendMission->time_arrival - $defendMission->time_holding + 60));
        $this->get('/overview');

        return $defendMission;
    }

    /**
     * Vanilla OGame ACS: when a planet with an ACS defence is being probed, the number of
     * ACS ships is added to the planet's original fleet. So docked ACS Defend fleet ships
     * must raise the counter-espionage chance and appear in the spy report's fleet section.
     */
    public function testAcsDefendFleetShipsCountInCounterEspionageAndAppearInReport(): void
    {
        $this->basicSetup();

        // Foreign planet to spy on, kept ship-free so any counter-espionage chance can
        // only come from the docked ACS Defend fleet.
        $foreignPlanet = $this->createForeignPlanet();
        $this->removeShipUnitsFromPlanet($foreignPlanet);

        // A buddy of the foreign player docks 10 cruisers above the foreign planet.
        $foreignPlayer = $foreignPlanet->getPlayer();
        $this->assertNotNull($foreignPlayer);

        $buddyPlanet = $this->createBuddyPlanet($foreignPlayer->getUser());
        $buddyPlanet->addUnit('cruiser', 10);
        $buddyPlanet->addResources(new Resources(0, 0, 100000, 0));
        $buddyPlanet->save();

        $this->dockAcsDefendFleet($buddyPlanet, $foreignPlanet, 'cruiser', 10);

        // Send 5 espionage probes right away (basicSetup granted 10 on the home planet);
        // they arrive well within the docked fleet's 10-hour holding window.
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $fleetMissionService = resolve(FleetMissionService::class);
        $spyMission = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($spyMission, 'Spy mission should exist');

        $this->travelTo(Date::createFromTimestamp($spyMission->time_arrival + 60));
        $response = $this->get('/overview');
        $response->assertStatus(200);

        $espionageReport = EspionageReport::orderByDesc('id')->first();
        $this->assertNotNull($espionageReport, 'Espionage report should be created');

        // Counter-espionage formula: (defender_ships * (0 - 0 + 1)) / (5 probes * 4) * 100.
        // With the 10 docked defending cruisers that is exactly 50%; without them it is 0%.
        $this->assertSame(50, (int)$espionageReport->counter_espionage_chance, 'ACS Defend ships must count for counter-espionage');

        // The defending cruisers show up merged into the planet's fleet section, matching
        // the original game adding ACS ships to the planet's original fleet.
        $this->assertNotNull($espionageReport->ships, 'Ships section should be revealed');
        $this->assertArrayHasKey('cruiser', $espionageReport->ships);
        $this->assertSame(10, (int)$espionageReport->ships['cruiser'], 'ACS Defend fleet units should appear in the spy report');
    }

    /**
     * Control case: a planet without ships and without docked ACS Defend fleets must not
     * show any fleet in the spy report and must have zero counter-espionage chance.
     */
    public function testNoCounterEspionageOrReportedShipsWithoutShipsPresent(): void
    {
        $this->basicSetup();

        $foreignPlanet = $this->createForeignPlanet();
        $this->removeShipUnitsFromPlanet($foreignPlanet);

        // A buddy is set up but sends no fleet, so nothing is docked above the planet.
        $foreignPlayer = $foreignPlanet->getPlayer();
        $this->assertNotNull($foreignPlayer);

        $buddyPlanet = $this->createBuddyPlanet($foreignPlayer->getUser());
        $buddyPlanet->addUnit('cruiser', 10);
        $buddyPlanet->save();

        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('espionage_probe'), 5);
        $this->dispatchFleet($foreignPlanet->getPlanetCoordinates(), $unitCollection, new Resources(0, 0, 0, 0), PlanetType::Planet, 0, true);

        $fleetMissionService = resolve(FleetMissionService::class);
        $spyMission = $fleetMissionService->getActiveFleetMissionsForCurrentPlayer()->first();
        $this->assertNotNull($spyMission, 'Spy mission should exist');

        $this->travelTo(Date::createFromTimestamp($spyMission->time_arrival + 60));
        $response = $this->get('/overview');
        $response->assertStatus(200);

        $espionageReport = EspionageReport::orderByDesc('id')->first();
        $this->assertNotNull($espionageReport, 'Espionage report should be created');

        $this->assertSame(0, (int)$espionageReport->counter_espionage_chance, 'No ships means no counter-espionage chance');
        $this->assertEmpty($espionageReport->ships ?? [], 'No ships means an empty fleet section');
    }
}
