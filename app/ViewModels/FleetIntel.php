<?php

namespace OGame\ViewModels;

use OGame\Enums\FleetIntelLevel;

/**
 * Value object describing how much detail a viewing player is entitled to
 * see about another player's fleet: the allowed composition detail level
 * plus whether the fleet shipment (cargo) may be shown. Cargo is always
 * hidden for foreign fleets regardless of the intel level; vanilla only
 * reveals shipment for own and alliance (gold) fleets.
 */
final readonly class FleetIntel
{
    public function __construct(
        public FleetIntelLevel $level,
        public bool $showCargo,
    ) {
    }
}
