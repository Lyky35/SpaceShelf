<?php

namespace Tests\Feature;

use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for the officer recruitment flow: the premium page and hiring officers
 * with Dark Matter (debit, expiry stacking, insufficient funds, expiry deactivation).
 */
class OfficerHireTest extends IsolatedAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createAndLoginUser();
    }

    /**
     * Get the current test user freshly loaded from the database.
     */
    private function user(): User
    {
        return User::findOrFail($this->currentUserId);
    }

    /**
     * Give the test user a known amount of Dark Matter.
     */
    private function setDarkMatter(int $amount): void
    {
        $user = $this->user();
        $user->dark_matter = $amount;
        $user->save();
    }

    // ==================== Page rendering ====================

    public function testPremiumPageShowsOfficersAndPrices(): void
    {
        $response = $this->get('/premium');
        $response->assertStatus(200);
        $response->assertSee('Recruit Officers');

        // Weekly prices: Commander/Technocrat 10000, Admiral/Engineer 5000, Geologist 12500
        $response->assertSee('10.000');
        $response->assertSee('5.000');
        $response->assertSee('12.500');

        // No officers hired yet
        $response->assertSee('0 of 5');
        $response->assertSee('Not hired');
    }

    public function testPremiumPageShowsActiveOfficerStatus(): void
    {
        $user = $this->user();
        $user->admiral_expires_at = now()->addDays(5);
        $user->save();

        // Refresh the guard instance so the request works with the updated user
        $this->actingAs($user);

        $response = $this->get('/premium');
        $response->assertStatus(200);
        $response->assertSee('1 of 5');
        $response->assertSee('Active for 5 more days');
    }

    // ==================== Hire flow ====================

    public function testHireOfficerDebitsDarkMatterAndSetsExpiry(): void
    {
        $this->setDarkMatter(100000);

        $response = $this->post(route('premium.officer.hire'), ['officer' => 'admiral', 'weeks' => 1]);
        $response->assertRedirect(route('premium.index'));
        $response->assertSessionHas('status');

        $user = $this->user();
        $this->assertSame(95000, $user->dark_matter);
        $this->assertNotNull($user->admiral_expires_at);
        $this->assertTrue($user->admiral_expires_at->isFuture());

        // The officer is now active for gameplay purposes
        $playerService = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertTrue($playerService->hasAdmiral());

        // The Dark Matter transaction was recorded as a debit
        $this->assertDatabaseHas('dark_matter_transactions', [
            'user_id' => $this->currentUserId,
            'type' => 'commanding_staff',
            'amount' => -5000,
        ]);
    }

    public function testHiringOfficerAgainExtendsExpiry(): void
    {
        $this->setDarkMatter(100000);

        $this->post(route('premium.officer.hire'), ['officer' => 'admiral', 'weeks' => 1]);
        $expiryAfterFirstHire = $this->user()->admiral_expires_at;
        $this->assertNotNull($expiryAfterFirstHire);

        $this->post(route('premium.officer.hire'), ['officer' => 'admiral', 'weeks' => 1]);
        $expiryAfterSecondHire = $this->user()->admiral_expires_at;
        $this->assertNotNull($expiryAfterSecondHire);

        // The second week is added on top of the first one (stacking), not from now
        $expected = (clone $expiryAfterFirstHire)->addWeek();
        $this->assertLessThan(2, abs($expiryAfterSecondHire->diffInSeconds($expected)));
        $this->assertSame(90000, $this->user()->dark_matter);
    }

    public function testHireFailsWithInsufficientDarkMatter(): void
    {
        $this->setDarkMatter(4000);

        $response = $this->post(route('premium.officer.hire'), ['officer' => 'geologist', 'weeks' => 1]);
        $response->assertSessionHas('statusError');

        $user = $this->user();
        $this->assertSame(4000, $user->dark_matter);
        $this->assertNull($user->geologist_expires_at);

        $playerService = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertFalse($playerService->hasGeologist());
    }

    public function testHireWithUnknownOfficerFails(): void
    {
        $this->setDarkMatter(100000);

        $response = $this->post(route('premium.officer.hire'), ['officer' => 'wizard', 'weeks' => 1]);
        $response->assertSessionHas('statusError');
        $this->assertSame(100000, $this->user()->dark_matter);
    }

    public function testHireRejectsInvalidWeekAmount(): void
    {
        $this->setDarkMatter(1000000);

        $response = $this->post(route('premium.officer.hire'), ['officer' => 'admiral', 'weeks' => 99]);
        $response->assertSessionHasErrors('weeks');
        $this->assertSame(1000000, $this->user()->dark_matter);
        $this->assertNull($this->user()->admiral_expires_at);
    }

    // ==================== Expiry ====================

    public function testExpiredOfficerIsDeactivated(): void
    {
        $this->setDarkMatter(100000);
        $this->post(route('premium.officer.hire'), ['officer' => 'admiral', 'weeks' => 1]);

        // Simulate the hire period having elapsed
        $user = $this->user();
        $user->admiral_expires_at = now()->subMinute();
        $user->save();

        // Refresh the guard instance so the page request works with the expired user
        $this->actingAs($user);

        $playerService = resolve(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $this->assertFalse($playerService->hasAdmiral());

        $response = $this->get('/premium');
        $response->assertSee('Not hired');
        $response->assertSee('0 of 5');
    }
}
