<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-off activity marker for the Directives (quest system), e.g. "planet renamed" or
 * "buddy request sent". Marker rows are written when the action happens and are consumed
 * by activity conditions, which cannot be inferred from game state alone.
 *
 * @property int $id
 * @property int $user_id
 * @property string $activity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'activity',
])]
class DirectiveActivityMarker extends Model
{
    /**
     * Get the user that owns the marker.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
