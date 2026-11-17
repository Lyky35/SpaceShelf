<?php

namespace OGame\Enums;

enum Officer: string
{
    case COMMANDER = 'commander';
    case ADMIRAL = 'admiral';
    case ENGINEER = 'engineer';
    case GEOLOGIST = 'geologist';
    case TECHNOCRAT = 'technocrat';

    /**
     * Get the display name of the officer.
     */
    public function getName(): string
    {
        return match ($this) {
            self::COMMANDER => 'Commander',
            self::ADMIRAL => 'Admiral',
            self::ENGINEER => 'Engineer',
            self::GEOLOGIST => 'Geologist',
            self::TECHNOCRAT => 'Technocrat',
        };
    }

    /**
     * Get the cost to hire this officer for one week (in Dark Matter).
     *
     * Prices follow the original OGame officer recruitment prices.
     */
    public function getCostPerWeek(): int
    {
        return match ($this) {
            self::COMMANDER => 10000,
            self::ADMIRAL => 5000,
            self::ENGINEER => 5000,
            self::GEOLOGIST => 12500,
            self::TECHNOCRAT => 10000,
        };
    }

    /**
     * Get the users table column that stores the officer's expiry timestamp.
     */
    public function getExpiresAtColumn(): string
    {
        return $this->value . '_expires_at';
    }
}
