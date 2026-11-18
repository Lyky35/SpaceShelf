<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Test that the current planet selection is per session: a fresh session always starts
 * on the home planet and planet switching does not leak into other sessions (or the
 * user record), as described in the original game's per-session planet selection.
 */
class CurrentPlanetSessionTest extends IsolatedAccountTestCase
{
    /**
     * Get the planet ID the game currently resolves for the logged in user.
     */
    private function resolvedCurrentPlanetId(): int
    {
        return resolve(PlayerService::class)->getCurrentPlanetId();
    }

    /**
     * Get the planet ID of the test user's second planet.
     */
    private function secondPlanetId(): int
    {
        $secondPlanet = $this->secondPlanetService;
        if ($secondPlanet === null) {
            $this->fail('Test account must have a second planet.');
        }

        return $secondPlanet->getPlanetId();
    }

    /**
     * Verify that switching the current planet persists within the same session.
     */
    public function testCurrentPlanetPersistsWithinSession(): void
    {
        $secondPlanetId = $this->secondPlanetId();

        $this->get('/overview?cp=' . $secondPlanetId);
        $this->assertSame($secondPlanetId, $this->resolvedCurrentPlanetId());

        // A follow-up request without a planet change query should keep the selection.
        $this->get('/overview');
        $currentPlanetId = $this->resolvedCurrentPlanetId();
        $this->assertSame($secondPlanetId, $currentPlanetId);
    }

    /**
     * Verify that a new session always starts on the home planet, regardless of which
     * planet was active in the previous session.
     */
    public function testCurrentPlanetResetsToHomePlanetOnNewSession(): void
    {
        $secondPlanetId = $this->secondPlanetId();

        $this->get('/overview?cp=' . $secondPlanetId);
        $this->assertSame($secondPlanetId, $this->resolvedCurrentPlanetId());

        // Simulate a fresh login session (e.g. log out + log in, or second device).
        $this->app['session']->driver()->flush();
        $this->app['session']->driver()->save();

        $this->get('/overview');
        $this->assertSame($this->planetService->getPlanetId(), $this->resolvedCurrentPlanetId());
    }

    /**
     * Verify that a stale current planet reference (e.g. the planet was destroyed or
     * abandoned) falls back to the home planet instead of an invalid planet.
     */
    public function testCurrentPlanetFallsBackToHomePlanetForUnknownPlanetId(): void
    {
        $unknownPlanetId = $this->secondPlanetId() + 1000000;

        $this->get('/overview?cp=' . $unknownPlanetId);
        $this->assertSame($this->planetService->getPlanetId(), $this->resolvedCurrentPlanetId());
    }

    /**
     * Verify the current planet selection is no longer stored on the user record, so it
     * cannot be shared across sessions or devices.
     */
    public function testCurrentPlanetIsNotStoredOnUserRecord(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'planet_current'));
    }
}
