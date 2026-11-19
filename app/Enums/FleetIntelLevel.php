<?php

namespace OGame\Enums;

/**
 * Enum that represents how much detail a viewing player is allowed to see
 * about a foreign fleet, based on their effective Espionage Technology level.
 *
 * Vanilla OGame tiers (OGame wiki, Espionage Technology): espionage 0-1
 * shows only the attack notification, 2-3 additionally the total number of
 * ships, 4-7 the ship types and 8+ the exact number of each ship type. Own
 * and alliance member fleets are always fully visible.
 */
enum FleetIntelLevel: int
{
    case None = 0;
    case UnitCount = 1;
    case ShipTypes = 2;
    case ShipCounts = 3;
}
