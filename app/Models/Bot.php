<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Bots\Personality;

/**
 * Scheduling and personality data for an autonomous bot player.
 *
 * The bot's game state itself lives in the regular player tables (users,
 * planets, fleet_missions, ...); this table only holds bot-specific meta data
 * and is linked 1:1 to a user that carries the "bot" role.
 *
 * @property int $id
 * @property int $user_id
 * @property string $profile
 * @property array<string, mixed> $personality
 * @property bool $enabled
 * @property bool $hide_from_highscore
 * @property Carbon|null $next_action_at
 * @property Carbon|null $last_action_at
 * @property int $total_ticks
 * @property int|null $last_actions
 * @property Carbon|null $inactive_since Set while the bot is on a population hiatus (null = playing)
 * @property Carbon|null $inactive_until When a hiatused bot resumes; null on a hiatus = permanently inactive
 * @property Carbon|null $last_roll_at Per-bot weekly dice clock for the population scheduler
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'profile',
    'personality',
    'enabled',
    'hide_from_highscore',
    'next_action_at',
    'last_action_at',
    'total_ticks',
    'last_actions',
    'inactive_since',
    'inactive_until',
    'last_roll_at',
])]
class Bot extends Model
{
    /**
     * Get the user this bot drives.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Rehydrate the stored personality weights into a value object.
     *
     * @return Personality
     */
    public function personalityObject(): Personality
    {
        return Personality::fromArray((array) $this->personality);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personality' => 'array',
            'enabled' => 'boolean',
            'hide_from_highscore' => 'boolean',
            'next_action_at' => 'datetime',
            'last_action_at' => 'datetime',
            'inactive_since' => 'datetime',
            'inactive_until' => 'datetime',
            'last_roll_at' => 'datetime',
        ];
    }
}
