<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\Bot;
use OGame\Models\User;
use OGame\Services\InitialUserDataService;
use Tests\IsolatedAccountTestCase;

/**
 * The bot-detection panel is meant to catch cheating humans; deliberately
 * bot-controlled accounts must never show up in it, even when their fleet
 * activity would otherwise trigger a detection signal.
 */
class BotDetectionExclusionTest extends IsolatedAccountTestCase
{
    /**
     * Raw fleet mission rows to insert per flagged user. Must exceed the
     * default thresholds: >= 50 total, >= 18 distinct hours of day, and
     * >= 18 missions per fleet slot per day (400 / 7 = ~57).
     */
    private const MISSIONS_PER_USER = 400;

    /**
     * Assert that a flagged human player appears on the server administration
     * page while a flagged bot account does not.
     */
    public function testFlaggedHumanIsListedAndFlaggedBotIsNot(): void
    {
        $admin = auth()->user();
        $this->assertNotNull($admin);
        $this->artisan('spaceshelf:admin:assign-role', ['username' => $admin->username]);

        $human = $this->createPlayerWithTech('Flagged Human');
        $this->insertRoundTheClockMissions($human->id);

        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'warrior']));

        $botUser = Bot::orderByDesc('id')->firstOrFail()->user;
        $this->assertNotNull($botUser);

        $this->insertRoundTheClockMissions((int) $botUser->id);

        $response = $this->get(route('admin.server-administration.index'));

        $response->assertOk();
        $response->assertSee('Flagged Human');
        $response->assertDontSee($botUser->username);
    }

    /**
     * Create a human player with the full tech + planet bootstrapping that a
     * real registration performs, but with no bot role.
     */
    private function createPlayerWithTech(string $username): User
    {
        $user = User::withoutEvents(fn (): User => User::factory()->create([
            'username' => $username,
        ]));

        resolve(InitialUserDataService::class)->createFor($user);

        // Single fleet slot keeps the mission-rate threshold deterministic
        // regardless of the default technology level on new accounts.
        DB::table('users_tech')->where('user_id', $user->id)->update(['computer_technology' => 0]);

        return $user;
    }

    /**
     * Insert raw fleet missions that trip the round-the-clock signal: spread
     * over 6 days across all 24 hour-of-day buckets.
     *
     * processed=1 keeps these rows out of the stuck-mission panel (which
     * renders usernames via a different query), while signal 1 does not look
     * at the processed flag at all.
     */
    private function insertRoundTheClockMissions(int $userId): void
    {
        $rows = [];

        for ($i = 0; $i < self::MISSIONS_PER_USER; $i++) {
            $departure = Date::now()
                ->subDays(1 + $i % 6)
                ->startOfDay()
                ->addMinutes(($i % 24) * 60 + ($i % 59));

            $rows[] = [
                'user_id' => $userId,
                'mission_type' => 3, // Transport, not an excluded signal type.
                'time_departure' => $departure->timestamp,
                'canceled' => 0,
                'processed' => 1,
            ];
        }

        DB::table('fleet_missions')->insert($rows);
    }
}
