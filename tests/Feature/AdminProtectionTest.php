<?php

namespace Tests\Feature;

use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

/**
 * Test that planets owned by admin-role users cannot be targeted by offensive
 * missions (upstream #1203: admin accounts were attackable because the check
 * keyed on a hardcoded 'Legor' username instead of the admin role, and
 * MissileMission did not check admin protection at all).
 */
class AdminProtectionTest extends IsolatedAccountTestCase
{
    private const ATTACK_MESSAGE = 'This planet belongs to an administrator and cannot be attacked.';
    private const ESPIONAGE_MESSAGE = 'This planet belongs to an administrator and cannot be probed.';

    /**
     * Prepare the attacker planet with units and plenty of fuel so that mission
     * rejections are solely due to admin protection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->planetAddUnit('light_fighter', 5);
        $this->planetAddUnit('espionage_probe', 5);
        $this->planetAddResources(new Resources(0, 0, 10000000, 0));
    }

    /**
     * Create a foreign player with a planet and grant them the admin role.
     */
    private function createAdminPlanet(): PlanetService
    {
        $adminPlanet = $this->createForeignPlanet();
        $adminPlayer = $adminPlanet->getPlayer();
        if ($adminPlayer === null) {
            $this->fail('Foreign planet has no player.');
        }

        $adminPlayer->getUser()->assignRole('admin');
        $this->assertTrue($adminPlayer->getUser()->hasRole('admin'));

        return $adminPlanet;
    }

    /**
     * Build the dispatchable units array (am<unitId> => amount) for a unit machine name.
     */
    private function unitsArray(string $machineName, int $quantity): array
    {
        $unitObject = ObjectService::getUnitObjectByMachineName($machineName);
        return ['am' . $unitObject->id => $quantity];
    }

    /**
     * Request the fleet target check for the given coordinates.
     *
     * @return array<string, mixed> JSON response
     */
    private function checkTarget(Coordinate $coordinates, int $missionType, array $units): array
    {
        $response = $this->post('/ajax/fleet/dispatch/check-target', [
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'position' => $coordinates->position,
            'type' => PlanetType::Planet->value,
            'mission' => $missionType,
            '_token' => csrf_token(),
            ...$units,
        ]);
        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * Attempt to dispatch a fleet and assert it is rejected with the expected message.
     */
    private function assertDispatchRejected(Coordinate $coordinates, int $missionType, array $units, string $expectedMessage): void
    {
        $response = $this->post('/ajax/fleet/dispatch/send-fleet', [
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'position' => $coordinates->position,
            'type' => PlanetType::Planet->value,
            'mission' => $missionType,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'holdingtime' => 0,
            'speed' => 10,
            '_token' => csrf_token(),
            ...$units,
        ]);
        $response->assertStatus(200);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('errors.0.message', $expectedMessage);
    }

    public function test_check_target_disables_offensive_missions_against_admin_planet(): void
    {
        $adminPlanet = $this->createAdminPlanet();
        $coordinates = $adminPlanet->getPlanetCoordinates();

        $json = $this->checkTarget($coordinates, 1, $this->unitsArray('light_fighter', 5));
        $this->assertFalse($json['orders'][1], 'Attack must be disabled against an admin planet.');
        $this->assertFalse($json['orders'][6], 'Espionage must be disabled against an admin planet.');
    }

    public function test_attack_dispatch_to_admin_planet_is_rejected(): void
    {
        $adminPlanet = $this->createAdminPlanet();

        $this->assertDispatchRejected(
            $adminPlanet->getPlanetCoordinates(),
            1,
            $this->unitsArray('light_fighter', 5),
            self::ATTACK_MESSAGE
        );
    }

    public function test_missile_dispatch_to_admin_planet_is_rejected(): void
    {
        $adminPlanet = $this->createAdminPlanet();

        $this->assertDispatchRejected(
            $adminPlanet->getPlanetCoordinates(),
            10,
            $this->unitsArray('light_fighter', 5),
            self::ATTACK_MESSAGE
        );
    }

    public function test_galaxy_missile_overlay_attack_against_admin_planet_is_rejected(): void
    {
        $adminPlanet = $this->createAdminPlanet();
        $this->planetAddUnit('interplanetary_missile', 10);
        $coordinates = $adminPlanet->getPlanetCoordinates();

        // The galaxy missile overlay creates the mission row directly instead of
        // going through the fleet dispatch service, so it needs its own guard.
        $response = $this->post('/ajax/galaxy/missile-attack', [
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'position' => $coordinates->position,
            'type' => PlanetType::Planet->value,
            'missile_count' => 5,
            'target_priority' => 0,
            '_token' => csrf_token(),
        ]);
        $response->assertStatus(403);
        $response->assertJsonPath('error', self::ATTACK_MESSAGE);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $this->currentUserId)
                ->where('mission_type', 10)
                ->count(),
            'No missile mission row must be created against an admin planet.'
        );
    }

    public function test_espionage_dispatch_to_admin_planet_is_rejected(): void
    {
        $adminPlanet = $this->createAdminPlanet();

        $this->assertDispatchRejected(
            $adminPlanet->getPlanetCoordinates(),
            6,
            $this->unitsArray('espionage_probe', 5),
            self::ESPIONAGE_MESSAGE
        );
    }

    public function test_non_admin_planet_remains_attackable(): void
    {
        $foreignPlanet = $this->createForeignPlanet();
        $this->assertFalse($foreignPlanet->getPlayer()?->getUser()->hasRole('admin'));
        $coordinates = $foreignPlanet->getPlanetCoordinates();

        $json = $this->checkTarget($coordinates, 1, $this->unitsArray('light_fighter', 5));
        $this->assertTrue($json['orders'][1], 'Attack must remain possible against a non-admin planet.');

        $response = $this->post('/ajax/fleet/dispatch/send-fleet', [
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'position' => $coordinates->position,
            'type' => PlanetType::Planet->value,
            'mission' => 1,
            'metal' => 0,
            'crystal' => 0,
            'deuterium' => 0,
            'holdingtime' => 0,
            'speed' => 10,
            '_token' => csrf_token(),
            ...$this->unitsArray('light_fighter', 5),
        ]);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }
}
