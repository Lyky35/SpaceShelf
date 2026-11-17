<?php

namespace OGame\Services;

use OGame\Factories\PlanetServiceFactory;
use OGame\GameConstants\UniverseConstants;
use OGame\Models\Planet\Coordinate;

/**
 * Finds an empty, colonizable coordinate for a player. The engine has no
 * equivalent (the only position chooser is the new-player placement tier in
 * PlanetServiceFactory::determineNewPlanetPosition()), so bots need this.
 *
 * Preferred positions: the inner ring (6-9) first, then outward toward the
 * astrophysics-gated edge positions. Systems are picked near the home planet
 * with a bias toward closer ones.
 */
final class ColonisationTargetFinder
{
    /**
     * Number of systems to probe before giving up.
     */
    private const MAX_SYSTEM_ATTEMPTS = 40;

    /**
     * Position search order: center-out, most desirable positions first.
     *
     * @var array<int, int>
     */
    private const POSITION_ORDER = [7, 8, 6, 9, 5, 10, 4, 11, 3, 12, 2, 13, 1, 14, 15];

    public function __construct(
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    /**
     * Find a random empty colonizable coordinate within $maxSystemDistance
     * systems of the player's home planet.
     *
     * @param PlayerService $player
     * @param int $maxSystemDistance
     * @return Coordinate|null
     */
    public function findFor(PlayerService $player, int $maxSystemDistance = 12): Coordinate|null
    {
        $home = $player->planets->first()?->getPlanetCoordinates();
        if ($home === null) {
            return null;
        }

        $galaxy = $this->pickGalaxy($player, $home);

        for ($attempt = 0; $attempt < self::MAX_SYSTEM_ATTEMPTS; $attempt++) {
            $system = $this->pickSystem($home->system, $maxSystemDistance);

            foreach (self::POSITION_ORDER as $position) {
                if (!$player->canColonizePosition($position)) {
                    continue;
                }

                $coordinate = new Coordinate($galaxy, $system, $position);

                if ($this->isOccupied($coordinate)) {
                    continue;
                }

                // Slightly randomize which empty position is taken so multiple
                // bots targeting the same system do not always pick position 7.
                if ($this->shouldPreferOtherPosition($position)) {
                    continue;
                }

                return $coordinate;
            }
        }

        return null;
    }

    /**
     * @param PlayerService $player
     * @param Coordinate $home
     * @return int
     */
    private function pickGalaxy(PlayerService $player, Coordinate $home): int
    {
        // v1: bots colonize within their home galaxy only. Cross-galaxy
        // expedition-style expansion is a later-phase personality trait.
        return $home->galaxy;
    }

    /**
     * Pick a system near the home system. Bias toward short hops: distance
     * drawn from a squared distribution so most targets are close.
     *
     * @param int $homeSystem
     * @param int $maxSystemDistance
     * @return int
     */
    private function pickSystem(int $homeSystem, int $maxSystemDistance): int
    {
        $distance = (int) round($maxSystemDistance * (mt_rand(0, 1000) / 1000) ** 2);
        $system = $homeSystem + (mt_rand(0, 1) === 0 ? -1 : 1) * $distance;

        return max(UniverseConstants::MIN_SYSTEM, min(UniverseConstants::MAX_SYSTEM_COUNT, $system));
    }

    /**
     * Check whether a planet or moon already occupies the coordinate.
     *
     * @param Coordinate $coordinate
     * @return bool
     */
    private function isOccupied(Coordinate $coordinate): bool
    {
        return $this->planetServiceFactory->planetExistsAtCoordinate($coordinate);
    }

    /**
     * Reject ~55% of otherwise-perfect inner-ring candidates so that bot
     * colonization spreads out instead of stacking on position 7-8.
     */
    private function shouldPreferOtherPosition(int $position): bool
    {
        if ($position > 9) {
            return false;
        }

        return mt_rand(0, 100) < 55;
    }
}
