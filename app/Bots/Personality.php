<?php

namespace OGame\Bots;

/**
 * Numeric play-style weights for a single bot, derived from a named profile
 * (see config/bots.php) plus random jitter at creation time.
 *
 * All traits are normalized to the 0..1 range.
 */
final readonly class Personality
{
    public function __construct(
        public string $profile,
        public float $aggression,
        public float $colonisationBias,
        public float $militaryShare,
        public float $defenseBias,
        public float $researchDrive,
    ) {
    }

    /**
     * Build a personality from its stored array representation.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            profile: (string) ($data['profile'] ?? 'discoverer'),
            aggression: (float) ($data['aggression'] ?? 0.0),
            colonisationBias: (float) ($data['colonisation_bias'] ?? 0.5),
            militaryShare: (float) ($data['military_share'] ?? 0.1),
            defenseBias: (float) ($data['defense_bias'] ?? 0.0),
            researchDrive: (float) ($data['research_drive'] ?? 1.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'aggression' => $this->aggression,
            'colonisation_bias' => $this->colonisationBias,
            'military_share' => $this->militaryShare,
            'defense_bias' => $this->defenseBias,
            'research_drive' => $this->researchDrive,
        ];
    }

    /**
     * Scale a research cap with one of the personality traits. A trait of 0.5
     * means "half as ambitious as the profile default", 1.0 is the full cap.
     */
    public function scaledCap(int $cap, float $trait): int
    {
        return (int) round($cap * $trait);
    }
}
