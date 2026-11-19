<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OGame\Enums\Officer;
use OGame\Models\BuildingQueue;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\StarterAidClaim;
use OGame\Models\UnitQueue;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the Starter Aid rewards on the /rewards page: daily unlocking based on
 * the registration date, one-time claiming and the reward payloads (resources,
 * units, queue time reductions and the Commanding Staff officer grant).
 */
class StarterAidTest extends IsolatedAccountTestCase
{
    /**
     * Pretend the current user registered N days ago.
     */
    private function setRegistrationDaysAgo(int $days): void
    {
        $user = User::findOrFail($this->currentUserId);
        $user->created_at = Date::now()->subDays($days);
        $user->save();
    }

    /**
     * Claim the reward with the given value through the HTTP endpoint.
     */
    private function claimReward(string $rewardValue): TestResponse
    {
        return $this->post(route('rewards.claim', ['reward' => $rewardValue]), [
            '_token' => csrf_token(),
        ]);
    }

    /**
     * Get a column value of the test user's main planet directly from the database.
     */
    private function planetColumn(string $column): float
    {
        return (float) DB::table('planets')
            ->where('id', $this->currentPlanetId)
            ->value($column);
    }

    /**
     * Verify that a freshly registered player sees all rewards as not yet
     * fulfilled and no claim buttons.
     */
    public function testAllRewardsLockedOnRegistrationDay(): void
    {
        $response = $this->get('/rewards');
        $response->assertStatus(200);

        $content = $response->getContent() ?: '';

        $this->assertSame(7, substr_count($content, __('t_rewards.not_fulfilled')));
        $this->assertStringNotContainsString('claim-form-', $content);

        // All seven rewards are shown in the "Awards not yet reached" section.
        $this->assertSame(7, substr_count($content, 'rewardnotclaim'));
    }

    /**
     * Verify that rewards unlock based on the amount of days since registration:
     * day 2 unlocks the first reward, day 3 the second, and so on.
     */
    public function testRewardUnlockBasedOnRegistrationDay(): void
    {
        // Registered 2 days ago -> rewards for day 2 and day 3 are dispatched,
        // the day 4 reward is not yet.
        $this->setRegistrationDaysAgo(2);

        $content = $this->get('/rewards')->assertStatus(200)->getContent() ?: '';

        $this->assertStringContainsString('claim-form-supplies', $content);
        $this->assertStringContainsString('claim-form-growth', $content);
        $this->assertStringNotContainsString('claim-form-merchant', $content);

        $this->assertSame(2, substr_count($content, 'rewardcanclaim'));
        $this->assertSame(5, substr_count($content, 'rewardnotclaim'));
    }

    /**
     * Claiming the first reward delivers the resource package, records the claim
     * and sends the Starter Aid game message.
     */
    public function testClaimResourceReward(): void
    {
        $this->setRegistrationDaysAgo(1);
        $metalBefore = $this->planetColumn('metal');

        $response = $this->claimReward('supplies');
        $response->assertRedirect(route('rewards.index'));
        $response->assertSessionHas('rewardClaimed');

        $this->assertEqualsWithDelta($metalBefore + 50000, $this->planetColumn('metal'), 1);

        $this->assertSame(1, StarterAidClaim::where('user_id', $this->currentUserId)
            ->where('reward', 'supplies')
            ->count());

        // Claiming sends the Starter Aid message with the reward text to the inbox.
        $this->assertMessageReceivedAndContains('universe', 'universe', ['colony ship']);

        // The reward is now listed as collected and can no longer be claimed.
        $content = $this->get('/rewards')->assertStatus(200)->getContent() ?: '';
        $this->assertStringNotContainsString('claim-form-supplies', $content);
        $this->assertSame(1, substr_count($content, 'rewardclaimed'));
    }

    /**
     * A reward can only be claimed once, even across repeated requests.
     */
    public function testRewardCannotBeClaimedTwice(): void
    {
        $this->setRegistrationDaysAgo(1);

        $this->claimReward('supplies')->assertSessionHas('rewardClaimed');
        $metalAfterFirst = $this->planetColumn('metal');

        $this->claimReward('supplies')->assertSessionHas('rewardError');

        $this->assertEquals($metalAfterFirst, $this->planetColumn('metal'));
        $this->assertSame(1, StarterAidClaim::where('user_id', $this->currentUserId)->count());
        $this->assertSame(1, Message::where('user_id', $this->currentUserId)
            ->where('key', 'starter_aid')
            ->count());
    }

    /**
     * Claiming a reward that has not been dispatched yet is rejected without side effects.
     */
    public function testClaimBeforeDispatchDayRejected(): void
    {
        $metalBefore = $this->planetColumn('metal');

        $this->claimReward('supplies')->assertSessionHas('rewardError');

        $this->assertEquals($metalBefore, $this->planetColumn('metal'));
        $this->assertSame(0, StarterAidClaim::where('user_id', $this->currentUserId)->count());
    }

    /**
     * Claiming an unknown reward id returns a 404.
     */
    public function testClaimUnknownRewardReturnsNotFound(): void
    {
        $this->claimReward('nonexistent')->assertStatus(404);
    }

    /**
     * Claiming the KRAKEN reward reduces the currently running building order by
     * up to 30 minutes. Orders shorter than 30 minutes are reduced entirely, so
     * the order's end time moves into the present and it is awarded on the next
     * planet update.
     */
    public function testKrakenRewardReducesBuildingQueue(): void
    {
        $this->planetAddResources(new Resources(300000, 0, 0, 0));

        // A building order is started immediately when the request is placed.
        $this->addResourceBuildRequest('solar_plant');

        $queueItem = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('processed', 0)
            ->firstOrFail();

        $queueItemId = (int) $queueItem->id;
        $timeStart = (int) $queueItem->time_start;
        $timeEnd = (int) $queueItem->time_end;
        $reduction = min(1800, max(0, $timeEnd - (int) Date::now()->timestamp));
        $this->assertGreaterThan(0, $reduction);

        // KRAKEN reward is dispatched on day 3.
        $this->setRegistrationDaysAgo(2);
        $this->claimReward('growth')->assertSessionHas('rewardClaimed');

        $queueItem = BuildingQueue::where('id', $queueItemId)->firstOrFail();

        $this->assertEquals($timeStart - $reduction, (int) $queueItem->time_start);
        $this->assertEquals($timeEnd - $reduction, (int) $queueItem->time_end);

        // The reduction pulled the order's end time into the present, so the next
        // planet update completes it immediately.
        $this->get('/overview')->assertStatus(200);
        $this->assertEquals(1, (int) Planet::findOrFail($this->currentPlanetId)->solar_plant);
    }

    /**
     * Claiming the DETROID reward while the first shipyard order has less than
     * 30 minutes remaining carries the rest of the reduction over into the next
     * order in the queue.
     */
    public function testDetroidRewardCarriesOverToNextShipyardOrder(): void
    {
        $this->planetSetObjectLevel('robot_factory', 2);
        $this->planetSetObjectLevel('shipyard', 1);
        $this->planetSetObjectLevel('research_lab', 1);
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->playerSetResearchLevel('combustion_drive', 1);
        $this->planetAddResources(new Resources(300000, 300000, 300000, 0));

        $this->addShipyardBuildRequest('light_fighter', 4);
        $this->addShipyardBuildRequest('light_fighter', 100);

        $queueItems = UnitQueue::where('planet_id', $this->currentPlanetId)
            ->where('processed', 0)
            ->orderBy('time_start')
            ->get()
            ->all();
        $this->assertCount(2, $queueItems);

        $now = (int) Date::now()->timestamp;
        $item1TimeStart = (int) $queueItems[0]->time_start;
        $item1TimeEnd = (int) $queueItems[0]->time_end;
        $item2TimeStart = (int) $queueItems[1]->time_start;
        $item2TimeEnd = (int) $queueItems[1]->time_end;

        // The first order ends before the 30 minutes are used up, so its remaining
        // time is fully reduced and the spill-over is applied to the second order.
        $reduction1 = min(1800, max(0, $item1TimeEnd - $now));
        $reduction2 = min(1800 - $reduction1, $item2TimeEnd - $item2TimeStart);
        $this->assertLessThan(1800, $reduction1);
        $this->assertGreaterThan(0, $reduction2);

        // DETROID reward is dispatched on day 7.
        $this->setRegistrationDaysAgo(6);
        $this->claimReward('conquest')->assertSessionHas('rewardClaimed');

        $queueItems = UnitQueue::where('planet_id', $this->currentPlanetId)
            ->where('processed', 0)
            ->orderBy('time_start')
            ->get()
            ->all();
        $this->assertCount(2, $queueItems);

        $this->assertEquals($item1TimeStart - $reduction1, (int) $queueItems[0]->time_start);
        $this->assertEquals($item1TimeEnd - $reduction1, (int) $queueItems[0]->time_end);
        $this->assertEquals($item2TimeStart - ($reduction1 + $reduction2), (int) $queueItems[1]->time_start);
        $this->assertEquals($item2TimeEnd - ($reduction1 + $reduction2), (int) $queueItems[1]->time_end);
    }

    /**
     * Claiming the DETROID reward shifts the running shipyard order back by
     * up to 30 minutes, so the corresponding units are awarded on the next update.
     */
    public function testDetroidRewardReducesUnitQueue(): void
    {
        $this->planetSetObjectLevel('robot_factory', 2);
        $this->planetSetObjectLevel('shipyard', 1);
        $this->planetSetObjectLevel('research_lab', 1);
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->playerSetResearchLevel('combustion_drive', 1);
        $this->planetAddResources(new Resources(300000, 100000, 0, 0));

        $this->addShipyardBuildRequest('light_fighter', 100);

        $queueItem = UnitQueue::where('planet_id', $this->currentPlanetId)
            ->where('processed', 0)
            ->firstOrFail();

        $now = (int) Date::now()->timestamp;
        $timePerUnit = ((int) $queueItem->time_end - (int) $queueItem->time_start) / 100;
        $available = (int) $queueItem->time_end - $now;
        $reduction = min(1800, $available);

        $queueItemId = (int) $queueItem->id;
        $timeStart = (int) $queueItem->time_start;
        $timeEnd = (int) $queueItem->time_end;

        // DETROID reward is dispatched on day 7.
        $this->setRegistrationDaysAgo(6);
        $this->claimReward('conquest')->assertSessionHas('rewardClaimed');

        $queueItem = UnitQueue::where('id', $queueItemId)->firstOrFail();

        // Start and end are shifted equally so the units-per-second rate is intact.
        $this->assertEquals($timeStart - $reduction, (int) $queueItem->time_start);
        $this->assertEquals($timeEnd - $reduction, (int) $queueItem->time_end);

        // The next planet update awards the units corresponding to the reduced time.
        $expectedUnits = (int) floor($reduction / $timePerUnit);
        $this->get('/rewards')->assertStatus(200);

        $this->assertEquals(
            $expectedUnits,
            (int) Planet::findOrFail($this->currentPlanetId)->light_fighter
        );
    }

    /**
     * Claiming the NEWTRON reward reduces the current research order by up to
     * 30 minutes.
     */
    public function testNewtronRewardReducesResearchQueue(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(300000, 300000, 300000, 0));

        $this->addResearchBuildRequest('energy_technology');

        $queueItem = ResearchQueue::where('planet_id', $this->currentPlanetId)
            ->where('processed', 0)
            ->firstOrFail();

        $now = (int) Date::now()->timestamp;
        $available = (int) $queueItem->time_end - $now;
        $reduction = min(1800, $available);

        $queueItemId = (int) $queueItem->id;
        $timeStart = (int) $queueItem->time_start;
        $timeEnd = (int) $queueItem->time_end;

        // NEWTRON reward is dispatched on day 6.
        $this->setRegistrationDaysAgo(5);
        $this->claimReward('science')->assertSessionHas('rewardClaimed');

        $queueItem = ResearchQueue::where('id', $queueItemId)->firstOrFail();

        $this->assertEquals($timeStart - $reduction, (int) $queueItem->time_start);
        $this->assertEquals($timeEnd - $reduction, (int) $queueItem->time_end);
    }

    /**
     * Claiming the "Expansion of the empire" reward grants all officers for 3 days.
     */
    public function testEmpireRewardGrantsAllOfficersForThreeDays(): void
    {
        // Commanding Staff reward is dispatched on day 8.
        $this->setRegistrationDaysAgo(7);

        $this->claimReward('empire')->assertSessionHas('rewardClaimed');

        $user = User::findOrFail($this->currentUserId);
        $now = (int) Date::now()->timestamp;

        foreach (Officer::cases() as $officer) {
            $expiresAt = $user->{$officer->getExpiresAtColumn()};
            $this->assertNotNull($expiresAt, $officer->getName() . ' officer should be granted');

            $expiresTimestamp = $expiresAt->getTimestamp();
            $this->assertGreaterThanOrEqual($now + (3 * 86400) - 60, $expiresTimestamp);
            $this->assertLessThanOrEqual($now + (3 * 86400) + 60, $expiresTimestamp);
        }
    }

    /**
     * Claiming the rocket launcher reward grants 20 rocket launchers.
     */
    public function testDefenseRewardGrantsRocketLaunchers(): void
    {
        // Defense reward is dispatched on day 5.
        $this->setRegistrationDaysAgo(4);

        $this->claimReward('defense')->assertSessionHas('rewardClaimed');

        $this->assertEquals(
            20,
            (int) Planet::findOrFail($this->currentPlanetId)->rocket_launcher
        );
    }
}
