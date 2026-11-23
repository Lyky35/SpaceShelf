<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use OGame\Models\User;
use OGame\Services\InitialUserDataService;
use Tests\IsolatedAccountTestCase;

/**
 * The server administration page must show all fleet legs currently on the
 * board — in flight, holding, or awaiting processing — while hiding
 * completed and canceled history.
 */
class ServerAdministrationFleetsTest extends IsolatedAccountTestCase
{
    /**
     * In-flight and holding legs are listed with player, mission type,
     * coordinates and status; processed legs are not.
     */
    public function testActiveFleetLegsAreListedAndProcessedHistoryIsHidden(): void
    {
        $admin = auth()->user();
        $this->assertNotNull($admin);
        $this->artisan('spaceshelf:admin:assign-role', ['username' => $admin->username]);

        $player = $this->createPlayer('Fleet Owner');

        // In-flight transport leg arriving in one hour (destination 1:11:4).
        $this->insertMission((int) $player->id, 3, 11, time() + 3600, null, 0, 10000, 5000, 12);

        // Holding leg: already arrived, holds another hour (destination 1:12:4).
        $this->insertMission((int) $player->id, 3, 12, time() - 60, time() + 3600, 0, 0, 0, 4);

        // Processed attack leg to 1:13:4: history, must not show up.
        $this->insertMission((int) $player->id, 1, 13, time() - 600, null, 1, 0, 0, 30);

        $response = $this->get(route('admin.server-administration.index'));

        $response->assertOk();
        $response->assertSee('Active Fleet Missions');
        $response->assertSee('Fleet Owner');

        // In-flight leg: mission label, destination coordinate, ETA status.
        $response->assertSee('Transport');
        $response->assertSee('1:11:4');
        $response->assertSee('In flight');

        // Holding leg shows the hold status.
        $response->assertSee('1:12:4');
        $response->assertSee('Holding');

        // Processed leg is hidden entirely.
        $response->assertDontSee('1:13:4');
    }

    /**
     * An arrived leg whose owner never logged in must be flagged as awaiting
     * processing — this is the fleet traffic that only bot heartbeats or a
     * login will resolve.
     */
    public function testArrivedUnprocessedLegIsFlaggedAwaitingProcessing(): void
    {
        $admin = auth()->user();
        $this->assertNotNull($admin);
        $this->artisan('spaceshelf:admin:assign-role', ['username' => $admin->username]);

        $player = $this->createPlayer('Logged Off Owner');

        // Expedition leg (type 15) arrived two hours ago, unprocessed.
        $this->insertMission((int) $player->id, 15, 21, time() - 7200, null, 0, 0, 0, 6);

        $response = $this->get(route('admin.server-administration.index'));

        $response->assertOk();
        $response->assertSee('Logged Off Owner');
        $response->assertSee('Expedition');
        $response->assertSee('1:21:4');
        $response->assertSee('Awaiting processing');
        $response->assertSee('waiting 02:00');
    }

    /**
     * Create a plain (non-admin, non-bot) player account with starting data.
     */
    private function createPlayer(string $username): User
    {
        $user = User::withoutEvents(fn (): User => User::factory()->create([
            'username' => $username,
        ]));

        resolve(InitialUserDataService::class)->createFor($user);

        return $user;
    }

    /**
     * Raw fleet mission row so the test does not depend on dispatch rules.
     * The destination renders as the coordinate "1:<system>:4".
     *
     * @param int $userId owner
     * @param int $missionType fork mission type id (1 Attack, 3 Transport, 15 Expedition)
     * @param int $systemTo destination system
     * @param int $timeArrival arrival timestamp
     * @param int|null $timeHolding hold-until timestamp for holding legs
     * @param int $processed processed flag (1 = leg already resolved)
     * @param int $metal cargo metal
     * @param int $crystal cargo crystal
     * @param int $lightFighter ship count on the leg
     */
    private function insertMission(
        int $userId,
        int $missionType,
        int $systemTo,
        int $timeArrival,
        int|null $timeHolding,
        int $processed,
        int $metal,
        int $crystal,
        int $lightFighter,
    ): void {
        DB::table('fleet_missions')->insert([
            'user_id' => $userId,
            'mission_type' => $missionType,
            'type_from' => 1,
            'planet_id_from' => DB::table('planets')->where('user_id', $userId)->value('id'),
            'galaxy_from' => 1,
            'system_from' => 1,
            'position_from' => 1,
            'type_to' => 1,
            'galaxy_to' => 1,
            'system_to' => $systemTo,
            'position_to' => 4,
            'time_departure' => $timeArrival - 1800,
            'time_arrival' => $timeArrival,
            'time_holding' => $timeHolding,
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => 0,
            'light_fighter' => $lightFighter,
            'processed' => $processed,
            'canceled' => 0,
        ]);
    }
}
