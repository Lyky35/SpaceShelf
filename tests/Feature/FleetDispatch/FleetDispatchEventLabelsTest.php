<?php

namespace Tests\Feature\FleetDispatch;

use Exception;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Verify that the fleet event list labels each fleet with its correct ownership
 * in the tooltip ("Own fleet" for the sender, "Enemy fleet" for the target)
 * instead of hardcoding "Own fleet" for every row (upstream #1216).
 */
class FleetDispatchEventLabelsTest extends FleetDispatchTestCase
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
     * Prepare the planet for the test, so it can dispatch an attack fleet.
     */
    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 5);
        $this->playerSetResearchLevel('computer_technology', objectLevel: 1);

        // Set the fleet speed to 1x for this test.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(0, 0, 100000, 0));
    }

    /**
     * Verify that a dispatched mission is still labelled "Own fleet" for the sender.
     * @throws Exception
     */
    public function testEventListLabelsOwnFleetForSender(): void
    {
        $this->basicSetup();

        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $this->sendMissionToOtherPlayerPlanet($unitCollection, new Resources(0, 0, 0, 0));

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);
        $response->assertSee('title="Own fleet | Attack"', false);
        $response->assertDontSee('Enemy fleet', false);
    }

    /**
     * Verify that an incoming hostile mission is labelled "Enemy fleet" for the
     * target player — the tooltip used to be hardcoded to "Own fleet" for every
     * row (upstream #1216).
     * @throws Exception
     */
    public function testEventListLabelsIncomingAttackAsEnemyFleet(): void
    {
        $this->basicSetup();

        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $targetPlanet = $this->sendMissionToOtherPlayerPlanet($unitCollection, new Resources(0, 0, 0, 0));

        // View the event list from the attacked player's point of view.
        $targetPlayer = $targetPlanet->getPlayer();
        $this->assertNotNull($targetPlayer, 'Target planet should have an owner.');
        $this->be($targetPlayer->getUser());

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);
        $response->assertSee('title="Enemy fleet | Attack"', false);
        $response->assertDontSee('Own fleet', false);
    }
}
