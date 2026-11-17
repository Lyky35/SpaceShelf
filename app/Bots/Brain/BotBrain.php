<?php

namespace OGame\Bots\Brain;

use OGame\Bots\Personality;

/**
 * Turns one world snapshot plus a personality into game actions through the
 * regular service layer (the same calls the HTTP controllers make).
 */
interface BotBrain
{
    /**
     * Execute one round of decision making for a player.
     *
     * @param BrainSnapshot $snapshot
     * @param Personality $personality
     * @return int Number of game actions executed (building/research/unit
     *             queue entries, fleet dispatches).
     */
    public function play(BrainSnapshot $snapshot, Personality $personality): int;
}
