<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * PlayerService::delete() must remove all fleet missions involving the player's
 * planets, and it has to respect the non-cascading parent_id foreign key: a
 * return mission references its outbound mission and must therefore be deleted
 * before the outbound mission itself.
 */
class PlayerServiceDeleteTest extends IsolatedAccountTestCase
{
    public function testDeleteRemovesFleetMissionsAndTheirChildMissions(): void
    {
        $this->createAndLoginUser();

        // A foreign target planet, so one transport departs from the home planet
        // and another one lands on it.
        $targetUser = $this->createUser();
        $targetPlanetId = (int) Planet::where('user_id', $targetUser->id)->value('id');

        $homePlanetId = $this->currentPlanetId;

        // Outbound transport from the home planet plus its return mission. The
        // return mission has no planet references of its own; it can only be
        // found through the parent_id link.
        $outboundMission = $this->createMission([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $homePlanetId,
            'planet_id_to' => $targetPlanetId,
        ]);
        $returnMission = $this->createMission([
            'user_id' => $this->currentUserId,
            'parent_id' => $outboundMission->id,
            'planet_id_from' => $targetPlanetId,
            'planet_id_to' => $homePlanetId,
        ]);

        // Inbound transport to the home planet, owned by the foreign user.
        $inboundMission = $this->createMission([
            'user_id' => $targetUser->id,
            'planet_id_from' => $targetPlanetId,
            'planet_id_to' => $homePlanetId,
        ]);

        resolve(PlayerServiceFactory::class)->make($this->currentUserId, true)->delete();

        // All three missions are gone, including the child mission that is only
        // reachable via parent_id and the foreign-owned inbound mission.
        $this->assertFalse(FleetMission::where('id', $outboundMission->id)->exists());
        $this->assertFalse(FleetMission::where('id', $returnMission->id)->exists());
        $this->assertFalse(FleetMission::where('id', $inboundMission->id)->exists());

        // The player and their planets are gone.
        $this->assertNull(User::find($this->currentUserId));
        $this->assertNull(Planet::find($homePlanetId));

        // The foreign user and their planets survive.
        $this->assertNotNull(User::find($targetUser->id));
        $this->assertNotNull(Planet::find($targetPlanetId));
    }

    /**
     * Insert a minimal fleet mission row directly, mirroring AdminStuckFleetMissionTest.
     *
     * @param array<string, int|null> $attributes
     */
    private function createMission(array $attributes): FleetMission
    {
        $mission = new FleetMission();
        $mission->user_id = (int) $attributes['user_id'];
        $mission->parent_id = isset($attributes['parent_id']) ? (int) $attributes['parent_id'] : null;
        $mission->planet_id_from = isset($attributes['planet_id_from']) ? (int) $attributes['planet_id_from'] : null;
        $mission->planet_id_to = isset($attributes['planet_id_to']) ? (int) $attributes['planet_id_to'] : null;
        $mission->galaxy_from = 1;
        $mission->system_from = 1;
        $mission->position_from = 1;
        $mission->galaxy_to = 1;
        $mission->system_to = 2;
        $mission->position_to = 1;
        $mission->type_from = PlanetType::Planet->value;
        $mission->type_to = PlanetType::Planet->value;
        $mission->mission_type = 3;
        $mission->time_departure = (int) Date::now()->subMinutes(10)->timestamp;
        $mission->time_arrival = (int) Date::now()->subMinutes(5)->timestamp;
        $mission->processed = 0;
        $mission->processed_hold = 0;
        $mission->canceled = 0;
        $mission->metal = 0;
        $mission->crystal = 0;
        $mission->deuterium = 0;
        $mission->deuterium_consumption = 0;
        $mission->small_cargo = 2;
        $mission->save();

        return $mission;
    }
}
