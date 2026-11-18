<?php

namespace Tests\Unit;

use OGame\Services\ObjectService;
use Tests\UnitTestCase;

/**
 * Unit tests for AttackPropertyService, covering the SpaceShelf balance tweak
 * that lets laser defense towers benefit from laser technology research
 * (+10% of base attack per research level, on top of the weapon technology bonus).
 */
class AttackPropertyServiceTest extends UnitTestCase
{
    public function testLaserTowerAttackScalesWithLaserTechnology(): void
    {
        $this->createAndSetUserTechModel([]);
        $lightLaser = ObjectService::getUnitObjectByMachineName('light_laser');

        $this->playerService->setResearchLevel('weapon_technology', 0, false);

        // Base attack is 100 at laser technology level 0.
        $this->playerService->setResearchLevel('laser_technology', 0, false);
        $this->assertEquals(100, $lightLaser->properties->attack->calculate($this->playerService)->totalValue);

        // Level 3: 100 + intdiv(100 * 30, 100) = 130.
        $this->playerService->setResearchLevel('laser_technology', 3, false);
        $this->assertEquals(130, $lightLaser->properties->attack->calculate($this->playerService)->totalValue);

        // Level 5: 100 + 50 = 150.
        $this->playerService->setResearchLevel('laser_technology', 5, false);
        $this->assertEquals(150, $lightLaser->properties->attack->calculate($this->playerService)->totalValue);

        // Level 10: 100 + 100 = 200.
        $this->playerService->setResearchLevel('laser_technology', 10, false);
        $this->assertEquals(200, $lightLaser->properties->attack->calculate($this->playerService)->totalValue);
    }

    public function testHeavyLaserTowerAttackScalesWithLaserTechnology(): void
    {
        $this->createAndSetUserTechModel([]);
        $heavyLaser = ObjectService::getUnitObjectByMachineName('heavy_laser');

        $this->playerService->setResearchLevel('weapon_technology', 0, false);
        $this->playerService->setResearchLevel('laser_technology', 7, false);

        // Base attack 250 + intdiv(250 * 70, 100) = 250 + 175 = 425.
        $this->assertEquals(425, $heavyLaser->properties->attack->calculate($this->playerService)->totalValue);
    }

    public function testLaserTechnologyStacksWithWeaponTechnologyOnLaserTowers(): void
    {
        $this->createAndSetUserTechModel([]);
        $lightLaser = ObjectService::getUnitObjectByMachineName('light_laser');

        // Weapon tech gives 50%, laser tech another 50% → +100% of base: 100 + 100 = 200.
        $this->playerService->setResearchLevel('weapon_technology', 5, false);
        $this->playerService->setResearchLevel('laser_technology', 5, false);
        $this->assertEquals(200, $lightLaser->properties->attack->calculate($this->playerService)->totalValue);
    }

    public function testLaserTechnologyDoesNotAffectNonLaserUnits(): void
    {
        $this->createAndSetUserTechModel([]);
        $rocketLauncher = ObjectService::getUnitObjectByMachineName('rocket_launcher');
        $plasmaTurret = ObjectService::getUnitObjectByMachineName('plasma_turret');

        // Laser technology must not change other defense units' attack.
        $this->playerService->setResearchLevel('weapon_technology', 0, false);
        $this->playerService->setResearchLevel('laser_technology', 10, false);

        $this->assertEquals(80, $rocketLauncher->properties->attack->calculate($this->playerService)->totalValue);
        $this->assertEquals(3000, $plasmaTurret->properties->attack->calculate($this->playerService)->totalValue);

        // Weapon technology bonus still applies to them as normal.
        $this->playerService->setResearchLevel('weapon_technology', 2, false);
        $this->assertEquals(96, $rocketLauncher->properties->attack->calculate($this->playerService)->totalValue);
    }
}
