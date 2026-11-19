<?php

namespace OGame\Services;

use OGame\Enums\FleetIntelLevel;
use OGame\Models\User;
use OGame\ViewModels\FleetIntel;

/**
 * Determines how much detail the current player may see about foreign
 * fleets shown in the fleet event list, the fleet movement page and the
 * sensor phalanx, based on their effective Espionage Technology level
 * (upstream #668, #1510).
 */
class IncomingFleetIntelService
{
    /**
     * Returns the intel the given viewer is entitled to see about a fleet
     * owned by the given player.
     *
     * @param PlayerService $viewer
     * @param int $fleetOwnerId User ID of the fleet's owner.
     * @return FleetIntel
     */
    public function getIntel(PlayerService $viewer, int $fleetOwnerId): FleetIntel
    {
        if ($fleetOwnerId <= 0) {
            return new FleetIntel(FleetIntelLevel::None, false);
        }

        if ($this->isOwnOrAllianceFleet($viewer, $fleetOwnerId)) {
            return new FleetIntel(FleetIntelLevel::ShipCounts, true);
        }

        $espionageLevel = $viewer->getEffectiveEspionageLevel();
        $intelLevel = match (true) {
            $espionageLevel >= 8 => FleetIntelLevel::ShipCounts,
            $espionageLevel >= 4 => FleetIntelLevel::ShipTypes,
            $espionageLevel >= 2 => FleetIntelLevel::UnitCount,
            default => FleetIntelLevel::None,
        };

        return new FleetIntel($intelLevel, false);
    }

    /**
     * Own and alliance member fleets are always fully visible (the vanilla
     * "gold fleet" rule); all other players' fleets are limited by the
     * viewer's espionage technology.
     */
    private function isOwnOrAllianceFleet(PlayerService $viewer, int $fleetOwnerId): bool
    {
        if ($viewer->getId() === $fleetOwnerId) {
            return true;
        }

        $viewerAllianceId = $viewer->getUser()->alliance_id;
        if ($viewerAllianceId === null) {
            return false;
        }

        // Read the owner's alliance directly from the database instead of
        // through the PlayerServiceFactory cache: a cached PlayerService may
        // hold a User object loaded before the owner joined their alliance.
        $ownerAllianceId = User::where('id', $fleetOwnerId)->value('alliance_id');

        return $ownerAllianceId !== null && (int) $ownerAllianceId === (int) $viewerAllianceId;
    }
}
