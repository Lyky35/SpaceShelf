<?php

namespace OGame\Bots\Brain;

use OGame\Models\Planet\Coordinate;

/**
 * A candidate foreign planet for the war policy: location, owner metadata and
 * highscore snapshot, resolved once per visit from the planets and highscores
 * tables and already filtered by the policy's protection rules.
 */
final readonly class WarTarget
{
    public function __construct(
        public Coordinate $coordinate,
        public int $userId,
        public bool $isBot,
        public int $generalScore,
    ) {
    }
}
