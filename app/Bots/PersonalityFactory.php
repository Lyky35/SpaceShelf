<?php

namespace OGame\Bots;

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
            researchDrive: self::jitter((float) $base['research_drive']),
        );
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
