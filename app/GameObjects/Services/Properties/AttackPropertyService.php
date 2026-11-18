<?php

namespace OGame\GameObjects\Services\Properties;

use OGame\GameObjects\Services\Properties\Abstracts\ObjectPropertyService;
use OGame\Services\PlayerService;

/**
 * Class AttackPropertyService.
 *
 * @package OGame\Services
 */
class AttackPropertyService extends ObjectPropertyService
{
    protected string $propertyName = 'attack';

    /**
     * Defense units whose attack power additionally scales with laser technology level.
     *
     * @var string[]
     */
    private const LASER_TECH_BONUS_UNITS = ['light_laser', 'heavy_laser'];

    /**
     * @inheritdoc
     */
    protected function getBonusPercentage(PlayerService $player): int
    {
        $weapons_technology_level = $player->getResearchLevel('weapon_technology');
        // Every level technology gives 10% bonus.
        $bonus_percentage = $weapons_technology_level * 10;

        // SpaceShelf balance tweak: laser towers additionally benefit from laser
        // technology research, gaining 10% of their base attack per research level.
        if (in_array($this->parent_object->machine_name, self::LASER_TECH_BONUS_UNITS, true)) {
            $bonus_percentage += $player->getResearchLevel('laser_technology') * 10;
        }

        return $bonus_percentage;
    }
}
