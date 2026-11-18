<?php

namespace OGame\Bots;

use OGame\Enums\CharacterClass;

/**
 * Creates bot personalities from the named profiles in config/bots.php, with
 * a small amount of random jitter per bot so that two bots of the same profile
 * do not play identically.
 */
final class PersonalityFactory
{
    /**
     * Maximum deviation (as fraction of the trait value) applied as jitter.
     */
    private const JITTER = 0.15;

    /**
     * Bot behavior profile → in-game character class. The class ships the
     * matching bonuses and class-ship entitlements, so a bot's brain profile
     * and its account must always agree. Used both on creation and when an
     * admin switches a bot's profile.
     *
     * @var array<string, CharacterClass>
     */
    public const CLASS_MAP = [
        'collector' => CharacterClass::COLLECTOR,
        'general' => CharacterClass::GENERAL,
        'discoverer' => CharacterClass::DISCOVERER,
    ];

    /**
     * Build a personality for the given profile, or a random profile when
     * $profile is null ("mixed").
     *
     * @param string|null $profile
     * @return Personality
     */
    public static function make(string|null $profile = null): Personality
    {
        /** @var array<string, array<string, float>> $profiles */
        $profiles = config('bots.personalities', []);

        if ($profile === null || $profile === 'mixed' || !array_key_exists($profile, $profiles)) {
            $profile = array_rand($profiles);
        }

        $base = $profiles[$profile];

        return new Personality(
            profile: $profile,
            aggression: self::jitter((float) $base['aggression']),
            colonisationBias: self::jitter((float) $base['colonisation_bias']),
            militaryShare: self::jitter((float) $base['military_share']),
            defenseBias: self::jitter((float) $base['defense_bias']),
            researchDrive: self::jitter((float) $base['research_drive']),
            habits: self::pickHabits(),
        );
    }

    /**
     * Pick this bot's persistent quirks from the configured habit pool:
     * every bot gets exactly one, and 30% of bots get a second. Habits are
     * stored with the bot, so the quirk is a lifetime trait, not a mood.
     *
     * @return array<int, string>
     */
    private static function pickHabits(): array
    {
        /** @var array<int, string> $pool */
        $pool = config('bots.habits.pool', []);

        if ($pool === []) {
            return [];
        }

        shuffle($pool);

        $count = random_int(1, 100) <= (int) config('bots.habits.double_chance_percent', 30) ? 2 : 1;
        $count = min($count, count($pool));

        return array_slice($pool, 0, $count);
    }

    /**
     * Jitter a trait value by +/- JITTER percent, clamped to the 0..1+ range
     * (research drive may exceed 1 on purpose, others are capped at 1).
     */
    private static function jitter(float $value): float
    {
        $factor = 1.0 + (mt_rand(-1000, 1000) / 1000) * self::JITTER;
        return max(0.0, round($value * $factor, 3));
    }
}
