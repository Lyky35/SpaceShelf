<?php

namespace Tests\Feature\FleetDispatch;

use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\AllianceService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Verify that incoming foreign fleet details are gated by the viewing
 * player's Espionage Technology level (upstream #668, #1510): at espionage
 * 0-1 the target sees only the attack notification, at 2-3 additionally the
 * total number of ships, at 4-7 the ship types without counts and at 8+ the
 * exact per-type counts. Cargo is never shown for foreign fleets. Own and
 * alliance member fleets stay fully visible at any espionage level.
 */
class IncomingFleetEspionageIntelTest extends FleetDispatchTestCase
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
     * Prepare the planet for the test, so it can dispatch an attack fleet
     * of exactly 7 ships (5 light fighters + 2 cruisers) with a cargo of
     * 1,000 metal and 500 crystal.
     */
    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 5);
        $this->planetAddUnit('cruiser', 2);
        $this->playerSetResearchLevel('computer_technology', objectLevel: 1);
        $this->playerSetResearchLevel('espionage_technology', objectLevel: 0);

        // Set the fleet speed to 1x for this test.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('attack_block_until', 0);

        $this->planetAddResources(new Resources(5000, 5000, 100000, 0));
    }

    /**
     * Dispatch the 7-ship attack fleet (with cargo) to a foreign player's
     * planet and return the target player service.
     */
    private function dispatchAttackAndGetTarget(): PlayerService
    {
        $unitCollection = new UnitCollection();
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $unitCollection->addUnit(ObjectService::getUnitObjectByMachineName('cruiser'), 2);

        $targetPlanet = $this->sendMissionToOtherPlayerPlanet(
            $unitCollection,
            new Resources(1000, 500, 0, 0)
        );

        $targetPlayer = $targetPlanet->getPlayer();
        $this->assertNotNull($targetPlayer, 'Target planet should have an owner.');

        return $targetPlayer;
    }

    /**
     * Verify that a foreign incoming attack reveals nothing but the attack
     * notification at espionage technology 0: no ship count and no fleet
     * details tooltip (upstream #668, #1510).
     */
    public function testForeignIncomingAttackIsFullyRedactedAtEspionageZero(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 0);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);
        $response->assertSee('title="Enemy fleet | Attack"', false);

        // Total ship count must not be shown.
        $response->assertDontSee('<span>7</span>', false);

        // Fleet details tooltip must not be rendered at all.
        $response->assertDontSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertDontSee('Light Fighter:', false);
        $response->assertDontSee('Cruiser:', false);
        $response->assertDontSee('Shipment', false);
    }

    /**
     * Verify that espionage technology 2 reveals only the total number of
     * ships of a foreign incoming fleet, but no composition.
     */
    public function testForeignIncomingAttackShowsUnitCountAtEspionageTwo(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 2);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);

        // Total ship count is visible now.
        $response->assertSee('<span>7</span>', false);

        // But no composition: still no fleet details tooltip.
        $response->assertDontSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertDontSee('Light Fighter:', false);
        $response->assertDontSee('Cruiser:', false);
    }

    /**
     * Verify that espionage technology 4 reveals the ship types of a foreign
     * incoming fleet but not the exact per-type counts, and no cargo.
     */
    public function testForeignIncomingAttackShowsShipTypesWithoutCountsAtEspionageFour(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 4);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);

        // Total ship count stays visible.
        $response->assertSee('<span>7</span>', false);

        // Ship types are visible with empty value cells.
        $response->assertSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertSee('Light Fighter:', false);
        $response->assertSee('Cruiser:', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;&lt;/td&gt;', false);

        // Exact per-type counts (5 light fighters, 2 cruisers) stay hidden.
        $response->assertDontSee('&lt;td class=&quot;value&quot;&gt;5&lt;/td&gt;', false);
        $response->assertDontSee('&lt;td class=&quot;value&quot;&gt;2&lt;/td&gt;', false);

        // Cargo is never shown for foreign fleets.
        $response->assertDontSee('Shipment', false);
    }

    /**
     * Verify that espionage technology 8 reveals exact per-type ship counts
     * of a foreign incoming fleet, but still never the cargo.
     */
    public function testForeignIncomingAttackShowsShipCountsButNoCargoAtEspionageEight(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 8);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);

        // Full composition with exact per-type counts.
        $response->assertSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;5&lt;/td&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;2&lt;/td&gt;', false);

        // Cargo is never shown for foreign fleets, even at espionage 8+.
        $response->assertDontSee('Shipment', false);
    }

    /**
     * Verify that the sender always sees full details of their own fleet
     * (composition and cargo), regardless of espionage technology.
     */
    public function testOwnFleetRemainsFullyVisibleAtEspionageZero(): void
    {
        $this->basicSetup();
        $this->dispatchAttackAndGetTarget();

        // Still the sender's session.
        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);
        $response->assertSee('title="Own fleet | Attack"', false);

        $response->assertSee('<span>7</span>', false);
        $response->assertSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;5&lt;/td&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;2&lt;/td&gt;', false);
        $response->assertSee('Shipment', false);
    }

    /**
     * Verify that a fleet belonging to an alliance member of the viewer is
     * fully visible (vanilla "gold fleet" rule), even at espionage 0.
     */
    public function testAllianceMemberIncomingFleetIsFullyVisibleAtEspionageZero(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 0);

        // Put the target into the viewer's alliance; alliance member fleets
        // must stay fully visible regardless of espionage technology.
        $allianceService = resolve(AllianceService::class);
        $alliance = $allianceService->createAlliance((int) auth()->id(), 'INTL', 'Intel Test Alliance');
        $targetUser = $targetPlayer->getUser();
        $targetUser->alliance_id = $alliance->id;
        $targetUser->save();

        $this->be($targetUser);

        $response = $this->get('/ajax/fleet/eventlist/fetch');
        $response->assertStatus(200);

        $response->assertSee('<span>7</span>', false);
        $response->assertSee('&lt;h1&gt;Fleet details:&lt;/h1&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;5&lt;/td&gt;', false);
        $response->assertSee('&lt;td class=&quot;value&quot;&gt;2&lt;/td&gt;', false);
        $response->assertSee('Shipment', false);
    }

    /**
     * Verify that the fleet movement page hides foreign fleet composition
     * at espionage 2: the row itself is visible, but not the fleet details
     * button nor any ship breakdown.
     */
    public function testMovementPageRedactsForeignFleetDetailsAtEspionageTwo(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 2);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/fleet/movement');
        $response->assertStatus(200);

        // The incoming hostile fleet row itself is still shown.
        $response->assertSee('Attack', false);

        // But no fleet details button, tooltip or composition.
        $response->assertDontSee('fleetDetailButton', false);
        $response->assertDontSee('Light Fighter', false);
        $response->assertDontSee('Cruiser', false);
        $response->assertDontSee('Shipment', false);
    }

    /**
     * Verify that the fleet movement page shows exact foreign fleet
     * composition at espionage 8, but never the cargo.
     */
    public function testMovementPageShowsForeignCompositionButNoCargoAtEspionageEight(): void
    {
        $this->basicSetup();
        $targetPlayer = $this->dispatchAttackAndGetTarget();
        $targetPlayer->setResearchLevel('espionage_technology', 8);

        $this->be($targetPlayer->getUser());

        $response = $this->get('/fleet/movement');
        $response->assertStatus(200);

        // The hidden fleet details table contains raw HTML here, unlike the
        // HTML-entity escaped tooltip title attribute in the event list.
        $response->assertSee('fleetDetailButton', false);
        $response->assertSee('Light Fighter:', false);
        $response->assertSee('<td class="value">5</td>', false);
        $response->assertSee('<td class="value">2</td>', false);

        // Cargo is never shown for foreign fleets.
        $response->assertDontSee('Shipment', false);
    }
}
