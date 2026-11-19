<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Enums\DirectiveProgressStatus;

/**
 * Progress of a single Directives (quest system) mission, or of the chapter bonus
 * when mission_id is an empty string.
 *
 * @property int $id
 * @property int $user_id
 * @property string $directive_id
 * @property string $mission_id
 * @property DirectiveProgressStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon|null $claimed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'directive_id',
    'mission_id',
    'status',
    'completed_at',
    'claimed_at',
])]
class DirectiveProgress extends Model
{
    /**
     * Get the user that owns the progress row.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DirectiveProgressStatus::class,
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }
}
