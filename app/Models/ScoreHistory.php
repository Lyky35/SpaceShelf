<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily snapshot of a player's score and combat counters, used to render
 * the timeline charts on the overview page. One row per player per day,
 * written by the `spaceshelf:scheduler:record-score-history` command.
 *
 * @property int $id
 * @property int $player_id
 * @property string $date
 * @property int $general
 * @property int $military_built
 * @property int $military_destroyed
 * @property int $ships_built
 * @property int $ships_destroyed
 * @property-read User $player
 */
#[Fillable([
    'player_id',
    'date',
    'general',
    'military_built',
    'military_destroyed',
    'ships_built',
    'ships_destroyed',
])]
class ScoreHistory extends Model
{
    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }
}
