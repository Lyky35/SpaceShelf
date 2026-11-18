<?php

namespace Tests\Feature\FleetDispatch;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\FleetDispatchTestCase;

/**
 * Tests for poison-pill isolation of fleet mission processing (upstream #1641):
 * - a mission whose processing throws must not roll back or block the other
 *   arrived missions of the same player, and must not make the caller throw;
 * - a mission that keeps failing is quarantined (marked processed) after
 *   three attempts so it stops being retried on every request.
 */
class FleetDispatchMissionFailureIsolationTest extends FleetDispatchTestCase
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
     * Consecutive failures after which PlayerService quarantines a mission.
     * Mirrors PlayerService::MAX_FLEET_MISSION_UPDATE_FAILURES.
     */
    private const QUARANTINE_THRESHOLD = 3;

    /**
     * Prepare the planet for the test, so it has the required buildings and research.
     *
     * @return void
     */
    protected function basicSetup(): void
    {
        $this->planetAddUnit('light_fighter', 5);
        $this->playerSetResearchLevel('computer_technology', objectLevel: 1);

        // Set the fleet speed to 1x for this test.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('fleet_speed_war', 1);
        $settingsService->set('fleet_speed_holding', 1);
        $settingsService->set('fleet_speed_peaceful', 1);
        $settingsService->set('attack_block_until', 0);

        // Add sufficient deuterium to the planet to ensure we don't run into fuel capacity restriction.
        $this->planetAddResources(new Resources(0, 0, 1000000, 0));
    }

    /**
     * Insert an unprocessed, already-arrived fleet mission with an unknown mission
     * type. getArrivedMissionsByPlanetIds() picks it up, but GameMissionFactory
     * throws on it deterministically — the perfect poison pill.
     */
    private function createPoisonMission(): FleetMission
    {
        $player = $this->planetService->getPlayer();
        if ($player === null) {
            $this->fail('Planet has no player.');
        }

        $now = (int) Date::now()->timestamp;

        return FleetMission::forceCreate([
            'user_id' => $player->getId(),
            'planet_id_from' => $this->planetService->getPlanetId(),
            'mission_type' => 999,
            'time_departure' => $now - 1000,
            'time_arrival' => $now - 1000,
            'processed' => 0,
        ]);
    }

    /**
     * Fresh PlayerService for the current player's account, as middleware would build one.
     */
    private function playerService(): PlayerService
    {
        $player = $this->planetService->getPlayer();
        if ($player === null) {
            $this->fail('Planet has no player.');
        }

        return resolve(PlayerService::class, ['player_id' => $player->getId()]);
    }

    /**
     * A broken mission must not block other arrived missions of the same player:
     * the valid attack still resolves (battle + return mission) while the poison
     * mission only accumulates a failure count.
     */
    public function testBrokenMissionDoesNotBlockOtherArrivedMissions(): void
    {
        $this->basicSetup();

        // Dispatch a normal attack fleet to another player's undefended planet so
        // the arrival resolves into a clean battle plus return mission.
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName('light_fighter'), 5);
        $this->sendMissionToOtherPlayerCleanPlanet($units, new Resources(0, 0, 0, 0));

        /** @var FleetMission $attackMission */
        $attackMission = FleetMission::where('mission_type', 1)->where('processed', 0)->sole();

        // The poison mission has an earlier arrival time, so the arrival-ordered
        // batch hits it first and must keep going afterwards.
        $poisonMission = $this->createPoisonMission();

        $this->travelTo(Date::createFromTimestamp($attackMission->time_arrival + 10));

        // Must not throw: before #1641 this poisoned the whole transaction into a
        // RuntimeException on every request, locking the player out of every page.
        $this->playerService()->updateFleetMissions();

        $poisonMission->refresh();
        $this->assertEquals(0, $poisonMission->processed, 'Poison mission must not be processed yet');
        $this->assertEquals(1, $poisonMission->update_failures, 'First failure must be recorded');

        $attackMission->refresh();
        $this->assertEquals(1, $attackMission->processed, 'Valid mission must be processed despite the poison one');
        $this->assertNotNull(
            FleetMission::where('parent_id', $attackMission->id)->first(),
            'Attack arrival must still create the return mission'
        );
    }

    /**
     * A mission that fails repeatedly is quarantined after the configured number
     * of attempts and is never retried again.
     */
    public function testRepeatedlyFailingMissionIsQuarantinedAfterThreeAttempts(): void
    {
        $this->basicSetup();

        $poisonMission = $this->createPoisonMission();

        // Exactly one error log per failing attempt; no fourth log after quarantine.
        Log::shouldReceive('error')->times(self::QUARANTINE_THRESHOLD);

        for ($attempt = 1; $attempt <= self::QUARANTINE_THRESHOLD; $attempt++) {
            $this->playerService()->updateFleetMissions();

            $poisonMission->refresh();
            $this->assertEquals($attempt, $poisonMission->update_failures);

            if ($attempt < self::QUARANTINE_THRESHOLD) {
                $this->assertEquals(0, $poisonMission->processed, 'Mission stays queued below the threshold');
            }
        }

        $this->assertEquals(1, $poisonMission->processed, 'Mission is quarantined at the threshold');

        // The quarantined mission is no longer picked up, so it is never processed
        // (or logged) again.
        $this->playerService()->updateFleetMissions();
        $poisonMission->refresh();
        $this->assertEquals(1, $poisonMission->processed);
        $this->assertEquals(self::QUARANTINE_THRESHOLD, $poisonMission->update_failures);
    }
}
