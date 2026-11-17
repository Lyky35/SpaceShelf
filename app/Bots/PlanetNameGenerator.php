<?php

namespace OGame\Bots;

use OGame\Models\Planet;
use OGame\Services\PlayerService;

/**
 * Gives bot planets human-looking names.
 *
 * The engine names fresh accounts' planets "Homeworld" and colonised planets
 * "Colony", which instantly marks an account as an NPC. This generator replaces
 * those defaults with invented OGame-flavoured names (adjective + noun, myth
 * names with designators, or coined alien words) and keeps the heartbeat
 * renaming colonies on the bot's next visit.
 *
 * Generated names honour the engine's own validation rules from
 * PlanetService::isValidPlanetName(): 2..20 characters of [a-zA-Z0-9-_ ], no
 * leading/trailing/consecutive separators.
 */
final class PlanetNameGenerator
{
    /**
     * Engine-assigned default planet names that mark a bot planet as unnamed.
     *
     * @var array<int, string>
     */
    private const DEFAULT_NAMES = ['Homeworld', 'Colony'];

    /**
     * Maximum number of uniqueness attempts before falling back to a digit suffix.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * @var array<int, string>
     */
    private const ADJECTIVES = [
        'Ancient', 'Amber', 'Azure', 'Bitter', 'Crimson', 'Dark', 'Distant', 'Drifting',
        'Ember', 'Endless', 'Frozen', 'Gilded', 'Golden', 'Hidden', 'Hollow', 'Jade',
        'Lush', 'Misty', 'Molten', 'Northern', 'Obsidian', 'Pale', 'Phantom', 'Radiant',
        'Riven', 'Royal', 'Rusty', 'Sapphire', 'Scarred', 'Shattered', 'Silent', 'Solar',
        'Sunken', 'Twilight', 'Violet', 'Void', 'Wandering', 'Whispering', 'Wild',
    ];

    /**
     * @var array<int, string>
     */
    private const NOUNS = [
        'Abyss', 'Anchor', 'Anvil', 'Beacon', 'Bastion', 'Citadel', 'Cradle', 'Crown',
        'Depths', 'Drift', 'Gate', 'Garrison', 'Haven', 'Hold', 'Hollow', 'Isle',
        'March', 'Nest', 'Oasis', 'Reach', 'Refuge', 'Rift', 'Sanctum', 'Shores',
        'Spire', 'Station', 'Throne', 'Vault', 'Watch',
    ];

    /**
     * @var array<int, string>
     */
    private const MYTHS = [
        'Acheron', 'Aegis', 'Alcedo', 'Amalthea', 'Anubis', 'Apollo', 'Arcadia', 'Ares',
        'Asteria', 'Atlas', 'Avalon', 'Babel', 'Bastet', 'Boreas', 'Calypso', 'Ceres',
        'Chaos', 'Charon', 'Cybele', 'Deimos', 'Delphi', 'Elysium', 'Enyo', 'Eos',
        'Erebus', 'Eris', 'Fenrir', 'Fornax', 'Halcyon', 'Hyperion', 'Iapetus', 'Io',
        'Isis', 'Janus', 'Kronos', 'Larissa', 'Lethe', 'Lynceus', 'Mimas', 'Morpheus',
        'Neso', 'Nereid', 'Nyx', 'Oberon', 'Ogygia', 'Orion', 'Pallas', 'Puck', 'Rhea',
        'Selene', 'Styx', 'Talos', 'Tartarus', 'Tethys', 'Thebe', 'Thule', 'Triton',
        'Typhon', 'Vesta', 'Vulcan', 'Zephyr',
    ];

    /**
     * @var array<int, string>
     */
    private const DESIGNATORS = ['', '', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'Prime', 'Minor', 'Major'];

    /**
     * @var array<int, string>
     */
    private const SYLLABLE_STARTS = [
        'Ka', 'Ze', 'Qu', 'Vor', 'Thal', 'El', 'An', 'Mor', 'Kel', 'Drav',
        'Umb', 'Xan', 'Lyr', 'Ves', 'Ob', 'Ash', 'Ith', 'Ur', 'Ny', 'Syl',
    ];

    /**
     * @var array<int, string>
     */
    private const SYLLABLE_MIDS = ['en', 'or', 'ia', 'an', 'el', 'on', 'yr', 'is', 'um'];

    /**
     * @var array<int, string>
     */
    private const SYLLABLE_ENDS = ['a', 'is', 'on', 'ia', 'um', 'ex', 'or', 'eth', 'yn', 'os'];

    /**
     * Rename every bot planet that still carries an engine default name.
     *
     * Called on bot creation and after each heartbeat "login", so colonies
     * that the engine auto-names "Colony" are renamed on the next visit.
     *
     * @param PlayerService $player
     * @return int Number of planets renamed.
     */
    public function nameAll(PlayerService $player): int
    {
        $renamed = 0;

        foreach ($player->planets->allPlanets() as $planet) {
            if (in_array($planet->getPlanetName(), self::DEFAULT_NAMES, true)) {
                $planet->setPlanetName($this->generate());
                $renamed++;
            }
        }

        return $renamed;
    }

    /**
     * Generate a unique, engine-valid planet name.
     *
     * @return string
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $name = $this->compose();

            if ($this->isValid($name) && !$this->isTaken($name)) {
                return $name;
            }
        }

        // Fallback: myth name plus a numeric designator; collision odds with a
        // three-digit suffix are negligible even on a crowded universe.
        return self::MYTHS[array_rand(self::MYTHS)] . ' ' . random_int(100, 999);
    }

    /**
     * Compose one random candidate name from the weighted templates.
     */
    private function compose(): string
    {
        $roll = mt_rand(1, 100);

        if ($roll <= 40) {
            return self::ADJECTIVES[array_rand(self::ADJECTIVES)] . ' ' . self::NOUNS[array_rand(self::NOUNS)];
        }

        if ($roll <= 70) {
            $designator = self::DESIGNATORS[array_rand(self::DESIGNATORS)];

            return $designator === ''
                ? self::MYTHS[array_rand(self::MYTHS)]
                : self::MYTHS[array_rand(self::MYTHS)] . ' ' . $designator;
        }

        $start = self::SYLLABLE_STARTS[array_rand(self::SYLLABLE_STARTS)];
        $end = self::SYLLABLE_ENDS[array_rand(self::SYLLABLE_ENDS)];

        if (mt_rand(0, 1) === 0) {
            $mid = self::SYLLABLE_MIDS[array_rand(self::SYLLABLE_MIDS)];
            return $start . $mid . $end;
        }

        return $start . $end;
    }

    /**
     * Mirror of PlanetService::isValidPlanetName() for generated candidates.
     */
    private function isValid(string $name): bool
    {
        return strlen($name) >= 2 && strlen($name) <= 20
            && preg_match('/^[a-zA-Z0-9-_ ]+$/', $name) === 1
            && preg_match('/^[-_ ]|[-_ ]$/', $name) === 0
            && preg_match('/[-_ ]{2,}/', $name) === 0;
    }

    /**
     * Whether the candidate name already belongs to a planet in the universe.
     */
    private function isTaken(string $name): bool
    {
        return Planet::query()->where('name', $name)->exists();
    }
}
