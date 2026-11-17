<?php

namespace Tests\Unit;

use OGame\Enums\Officer;
use OGame\GameObjects\Models\BuildingObject;
use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Tests for the officer bonuses (Commander, Admiral, Engineer, Geologist, Technocrat).
 *
 * Officers are activated by setting their expiry timestamp on the user model; this is the
 * same state the hire flow produces after paying Dark Matter via OfficerService.
 */
class OfficerBonusTest extends UnitTestCase
{
    /**
     * Activate an officer for the test player.
     *
     * @param Officer $officer
     * @param int $weeks
     */
    private function activateOfficer(Officer $officer, int $weeks = 1): void
    {
        $user = $this->playerService->getUser();
        $user->{$officer->getExpiresAtColumn()} = now()->addWeeks($weeks);
    }

    /**
     * Activate all officers for the test player (grants Commanding Staff bonus).
     */
    private function activateAllOfficers(): void
    {
        foreach (Officer::cases() as $officer) {
            $this->activateOfficer($officer);
        }
    }

    /**
     * Configure metal mine object with services required for production calculations.
     */
    private function getConfiguredBuilding(int $id): BuildingObject
    {
        $objectService = resolve(ObjectService::class);
        $object = $objectService->getObjectById($id);
        assert($object instanceof BuildingObject);
        $object->production->planetService = $this->planetService;
        $object->production->playerService = $this->playerService;
        $object->production->characterClassService = app(\OGame\Services\CharacterClassService::class);
        $object->production->universe_speed = $this->settingsService->economySpeed();
        return $object;
    }

    // ==================== Officer activation switches ====================

    public function testOfficerSwitchesReflectExpiry(): void
    {
        // All officers inactive by default
        $this->assertFalse($this->playerService->hasCommander());
        $this->assertFalse($this->playerService->hasAdmiral());
        $this->assertFalse($this->playerService->hasEngineer());
        $this->assertFalse($this->playerService->hasGeologist());
        $this->assertFalse($this->playerService->hasTechnocrat());
        $this->assertFalse($this->playerService->hasCommandingStaff());

        // Activating one officer does not grant Commanding Staff
        $this->activateOfficer(Officer::COMMANDER);
        $this->assertTrue($this->playerService->hasCommander());
        $this->assertFalse($this->playerService->hasAdmiral());
        $this->assertFalse($this->playerService->hasCommandingStaff());

        // All five officers active grants Commanding Staff
        $this->activateAllOfficers();
        $this->assertTrue($this->playerService->hasCommandingStaff());
    }

    public function testExpiredOfficerIsNotActive(): void
    {
        $user = $this->playerService->getUser();
        $user->admiral_expires_at = now()->subDay();

        $this->assertFalse($this->playerService->hasAdmiral());
    }

    // ==================== Admiral: fleet + expedition slots ====================

    public function testAdmiralAddsFleetSlots(): void
    {
        $this->createAndSetUserTechModel(['computer_technology' => 5]);
        $baseSlots = $this->playerService->getFleetSlotsMax();

        $this->activateOfficer(Officer::ADMIRAL);
        $this->assertSame($baseSlots + 2, $this->playerService->getFleetSlotsMax());

        // With all officers hired: Admiral (+2) and Commanding Staff (+1) stack
        $this->activateAllOfficers();
        $this->assertSame($baseSlots + 3, $this->playerService->getFleetSlotsMax());
    }

    public function testAdmiralAddsExpeditionSlot(): void
    {
        $this->createAndSetUserTechModel(['astrophysics' => 5]);
        $baseSlots = $this->playerService->getExpeditionSlotsMax();

        $this->activateOfficer(Officer::ADMIRAL);
        $this->assertSame($baseSlots + 1, $this->playerService->getExpeditionSlotsMax());
    }

    // ==================== Technocrat: research time + espionage ====================

    public function testTechnocratReducesResearchTimeByTwentyFivePercent(): void
    {
        $this->createAndSetPlanetModel(['research_lab' => 5]);

        $timeWithout = $this->planetService->getTechnologyResearchTime('energy_technology');
        $this->assertGreaterThan(0, $timeWithout);

        $this->activateOfficer(Officer::TECHNOCRAT);
        $timeWith = $this->planetService->getTechnologyResearchTime('energy_technology');

        $this->assertSame((int)($timeWithout * 0.75), (int)$timeWith);
    }

    public function testEffectiveEspionageLevelIncludesOfficerBonuses(): void
    {
        $this->createAndSetUserTechModel(['espionage_technology' => 3]);

        // No officers: research level only
        $this->assertSame(3, $this->playerService->getEffectiveEspionageLevel());

        // Technocrat: +2 levels
        $this->activateOfficer(Officer::TECHNOCRAT);
        $this->assertSame(5, $this->playerService->getEffectiveEspionageLevel());

        // All officers hired: Technocrat (+2) and Commanding Staff (+1) stack
        $this->activateAllOfficers();
        $this->assertSame(6, $this->playerService->getEffectiveEspionageLevel());
    }

    // ==================== Engineer: energy production ====================

    public function testEngineerBoostsEnergyProduction(): void
    {
        $this->createAndSetPlanetModel(['solar_plant' => 10]);

        $indexWithout = $this->getConfiguredBuilding(4)->production->calculate(10);
        $this->assertEquals(0, $indexWithout->engineer->energy->get(), 'Engineer bonus must be zero without officer');
        $this->assertGreaterThan(0, $indexWithout->mine->energy->get());

        $this->activateOfficer(Officer::ENGINEER);
        $indexWith = $this->getConfiguredBuilding(4)->production->calculate(10);

        $this->assertGreaterThan(0, $indexWith->engineer->energy->get());
        $this->assertGreaterThan($indexWithout->total->energy->get(), $indexWith->total->energy->get());
    }

    // ==================== Geologist: mine production ====================

    public function testGeologistBoostsMineProduction(): void
    {
        $this->createAndSetPlanetModel(['metal_mine' => 10, 'crystal_mine' => 10, 'deuterium_synthesizer' => 10]);

        $indexWithout = $this->getConfiguredBuilding(1)->production->calculate(10);
        $this->assertEquals(0, $indexWithout->geologist->metal->get(), 'Geologist bonus must be zero without officer');

        $this->activateOfficer(Officer::GEOLOGIST);
        $indexWith = $this->getConfiguredBuilding(1)->production->calculate(10);

        $this->assertGreaterThan(0, $indexWith->geologist->metal->get());
        $this->assertGreaterThan($indexWithout->total->metal->get(), $indexWith->total->metal->get());
    }

    // ==================== Commanding Staff: all officers active ====================

    public function testCommandingStaffAddsProductionBonuses(): void
    {
        $this->createAndSetPlanetModel([
            'metal_mine' => 10,
            'crystal_mine' => 10,
            'deuterium_synthesizer' => 10,
            'solar_plant' => 10,
        ]);

        $indexWithout = $this->getConfiguredBuilding(1)->production->calculate(10);
        $this->assertEquals(0, $indexWithout->commanding_staff->metal->get());

        // Commanding Staff bonus only applies when all five officers are hired
        $this->activateOfficer(Officer::ADMIRAL);
        $indexPartial = $this->getConfiguredBuilding(1)->production->calculate(10);
        $this->assertEquals(0, $indexPartial->commanding_staff->metal->get());

        $this->activateAllOfficers();
        $indexWith = $this->getConfiguredBuilding(1)->production->calculate(10);
        $this->assertGreaterThan(0, $indexWith->commanding_staff->metal->get());

        // Energy bonus is verified on an energy producing building (metal mines only consume energy)
        $indexEnergy = $this->getConfiguredBuilding(4)->production->calculate(10);
        $this->assertGreaterThan(0, $indexEnergy->commanding_staff->energy->get());
    }
}
